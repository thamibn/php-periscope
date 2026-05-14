<?php

declare(strict_types=1);

namespace Periscope\Laravel;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Bus\QueueingDispatcher;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Periscope\Laravel\Bridge\ExtensionBridge;
use Periscope\Laravel\Bus\JobDispatchTracker;
use Periscope\Laravel\Bus\TerminatingTracker;
use Periscope\Laravel\Http\InjectToolbar;
use Periscope\Laravel\Http\UiController;
use Periscope\Laravel\Mcp\McpServiceProvider;
use Periscope\Laravel\Detection\AiAdvisor;
use Periscope\Laravel\Detection\NPlusOneDetector;
use Periscope\Laravel\Detection\SlowQueryAnalyzer;
use Periscope\Laravel\Hooks\BatchHook;
use Periscope\Laravel\Hooks\CacheHook;
use Periscope\Laravel\Hooks\CommandHook;
use Periscope\Laravel\Hooks\DumpHook;
use Periscope\Laravel\Hooks\EventHook;
use Periscope\Laravel\Hooks\ExceptionHook;
use Periscope\Laravel\Hooks\GateHook;
use Periscope\Laravel\Hooks\Hook;
use Periscope\Laravel\Hooks\HttpClientHook;
use Periscope\Laravel\Hooks\JobHook;
use Periscope\Laravel\Hooks\LogHook;
use Periscope\Laravel\Hooks\MailHook;
use Periscope\Laravel\Hooks\ModelHook;
use Periscope\Laravel\Hooks\NotificationHook;
use Periscope\Laravel\Hooks\QueryHook;
use Periscope\Laravel\Hooks\RedisHook;
use Periscope\Laravel\Hooks\RequestHook;
use Periscope\Laravel\Hooks\ScheduleHook;
use Periscope\Laravel\Hooks\ViewHook;
use Periscope\Laravel\Support\CallSiteResolver;

/**
 * Boot point for the Laravel adapter.
 *
 * Auto-discovered via composer.json's `extra.laravel.providers`, so a
 * fresh `composer require thamibn/php-periscope-laravel` is the only setup
 * step. All runtime knobs live in .env (see config/periscope.php).
 */
final class PeriscopeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/periscope.php', 'periscope');

        $this->app->singleton(ExtensionBridge::class, fn (Application $app): ExtensionBridge =>
            new ExtensionBridge(
                enabled: (bool) $app['config']->get('periscope.enabled', true),
            )
        );

        $this->app->singleton(CallSiteResolver::class, fn (Application $app): CallSiteResolver =>
            new CallSiteResolver(
                vendorSkip:   (array) $app['config']->get('periscope.vendor_skip', []),
                snippetLines: (int)   $app['config']->get('periscope.snippet_lines', 2),
            )
        );

        $this->app->singleton(NPlusOneDetector::class, fn (Application $app): NPlusOneDetector =>
            new NPlusOneDetector(
                bridge:    $app->make(ExtensionBridge::class),
                ai:        $app->make(AiAdvisor::class),
                threshold: (int) $app['config']->get('periscope.n_plus_one_threshold', 4),
            )
        );

        $this->app->singleton(SlowQueryAnalyzer::class, fn (Application $app): SlowQueryAnalyzer =>
            new SlowQueryAnalyzer(
                bridge: $app->make(ExtensionBridge::class),
            )
        );

        $this->app->singleton(AiAdvisor::class, fn (Application $app): AiAdvisor =>
            new AiAdvisor(
                bridge:        $app->make(ExtensionBridge::class),
                enabled:       (bool) $app['config']->get('periscope.ai.enabled', false),
                maxPerRequest: (int)  $app['config']->get('periscope.ai.max_suggestions_per_request', 3),
            )
        );

        $this->app->singleton(TerminatingTracker::class, fn (Application $app): TerminatingTracker =>
            new TerminatingTracker(
                app:      $app,
                resolver: $app->make(CallSiteResolver::class),
            )
        );

        // Delegate MCP wiring to a dedicated sub-provider (SRP).
        $this->app->register(McpServiceProvider::class);

        $this->registerJobDispatchTracker();
    }

    /**
     * Wrap Laravel's bus dispatcher so we can record the user's call site at
     * dispatch time and surface it on JobProcessing rows. Without this, sync
     * jobs dispatched from `afterResponse` / `terminating` show only
     * `index.php:75` because the original dispatch frames have already
     * unwound by the time `JobProcessing` fires.
     */
    private function registerJobDispatchTracker(): void
    {
        $config = $this->app['config'];
        if (!$config->get('periscope.enabled', true) || !$config->get('periscope.hooks.jobs', true)) {
            return;
        }

        $this->app->singleton(JobDispatchTracker::class, function (Application $app): JobDispatchTracker {
            $inner = $app->make(QueueingDispatcher::class);
            return new JobDispatchTracker($inner, $app->make(CallSiteResolver::class));
        });

        // Replace the contract bindings so calls to `dispatch()` / Bus facade
        // route through the tracker. The concrete `Bus\Dispatcher` class
        // remains untouched — only the contract aliases are decorated.
        $this->app->extend(BusDispatcher::class, fn ($_, Application $app) => $app->make(JobDispatchTracker::class));
        $this->app->extend(QueueingDispatcher::class, fn ($_, Application $app) => $app->make(JobDispatchTracker::class));
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/periscope.php' => $this->app->configPath('periscope.php'),
        ], 'periscope-config');

        $config = $this->app['config'];
        if (!$config->get('periscope.enabled', true)) {
            return;
        }

        /** @var ExtensionBridge $bridge */
        $bridge = $this->app->make(ExtensionBridge::class);

        // Tell the bridge which paths to short-circuit. Engine-level
        // `periscope.path_ignore` ought to handle the same list, but on
        // multi-php-fpm setups (8.3 + 8.4 masters sharing a socket, for
        // instance) the engine filter can be bypassed; the PHP-side guard
        // here is the belt-and-braces backup.
        $bridge->setPathIgnore((array) $config->get('periscope.path_ignore', []));

        // Install the terminating tracker before any other service provider
        // gets a chance to register a terminating callback — the proxy only
        // wraps callbacks added *after* it replaces the array. Then link it
        // to the bridge so every event flowing through recordEvent gets the
        // after-response attribution and badge.
        $terminating = $this->app->make(TerminatingTracker::class);
        $terminating->install();
        $bridge->setTerminatingTracker($terminating);

        if (!$bridge->isAvailable()) {
            // C extension not loaded; adapter is a no-op. Nothing to register.
            return;
        }

        foreach ($this->resolveHooks() as $hook) {
            $hook->register();
        }

        $this->registerUiRoutes();
        $this->registerToolbarMiddleware();
    }

    /**
     * Push the InjectToolbar middleware onto the `web` group when enabled.
     * No-op when disabled, so production deployments that haven't opted in
     * never pay the resolve cost.
     */
    private function registerToolbarMiddleware(): void
    {
        $config = $this->app['config'];
        if (!$config->get('periscope.toolbar.enabled', false)) {
            return;
        }

        $mountPath = '/' . trim((string) $config->get('periscope.ui.path', 'periscope'), '/');
        $openUrl = $config->get('periscope.toolbar.open_url');
        if (!is_string($openUrl) || $openUrl === '') {
            // Default open target: the in-Laravel UI mount when enabled,
            // otherwise the daemon's HTTP UI. Either way the user lands on
            // the latest trace.
            $openUrl = $config->get('periscope.ui.enabled')
                ? $mountPath
                : (string) $config->get('periscope.ui.daemon_base', 'http://127.0.0.1:9999');
        }

        $daemonBase = rtrim((string) $config->get('periscope.ui.daemon_base', 'http://127.0.0.1:9999'), '/');
        $metricsEndpoint = $daemonBase . '/api/client-metrics';

        $bridge = $this->app->make(ExtensionBridge::class);
        $middleware = new InjectToolbar($bridge, [
            'enabled'          => true,
            'open_url'         => $openUrl,
            'mount_path'       => $mountPath,
            'metrics_endpoint' => $metricsEndpoint,
        ]);
        $this->app->instance(InjectToolbar::class, $middleware);

        try {
            /** @var Router $router */
            $router = $this->app->make(Router::class);
            $router->pushMiddlewareToGroup('web', InjectToolbar::class);
        } catch (\Throwable) {
            // Some test harnesses don't bind a router. Toolbar is opt-in;
            // failing to wire it should never break the app.
        }
    }

    /**
     * Mount the SolidJS UI inside the Laravel app at a configurable prefix.
     * Off by default so the package never collides with app routes.
     */
    private function registerUiRoutes(): void
    {
        $config = $this->app['config'];
        if (!$config->get('periscope.ui.enabled', false)) {
            return;
        }

        $bundleDir = $this->resolveBundleDir((string) $config->get('periscope.ui.bundle_dir', '') ?: null);
        $daemonBase = (string) $config->get('periscope.ui.daemon_base', 'http://127.0.0.1:9999');
        $prefix = trim((string) $config->get('periscope.ui.path', 'periscope'), '/');
        $gateConfig = [
            'allow_in_production' => (bool) $config->get('periscope.ui.allow_in_production', false),
            'token'               => $config->get('periscope.ui.token'),
        ];

        $this->app->singleton(UiController::class, fn (): UiController => new UiController(
            bundleDir: $bundleDir,
            daemonBase: $daemonBase,
            mountPrefix: $prefix,
            gateConfig: $gateConfig,
        ));
        $middleware = (array) $config->get('periscope.ui.middleware', ['web']);

        /** @var Router $router */
        $router = $this->app->make(Router::class);
        $router
            ->middleware($middleware)
            ->prefix($prefix === '' ? '/' : $prefix)
            ->group(function (Router $r): void {
                $r->get('/', [UiController::class, 'index'])->name('periscope.ui.index');
                $r->get('api/settings', [UiController::class, 'settings'])->name('periscope.ui.settings');
                $r->get('assets/{path}', [UiController::class, 'asset'])
                    ->where('path', '.*')
                    ->name('periscope.ui.asset');
            });
    }

    /**
     * Probe a few sensible locations for `ui/dist/`, falling back to whatever
     * the user passed explicitly. Returns the first directory that exists.
     */
    private function resolveBundleDir(?string $explicit): string
    {
        if ($explicit !== null && $explicit !== '' && is_dir($explicit)) {
            return $explicit;
        }
        $candidates = [
            // Sibling to the package (path-repo / monorepo development)
            __DIR__ . '/../../../ui/dist',
            // composer-installed: vendor/thamibn/php-periscope-laravel/ → vendor/../../ui/dist
            base_path('ui/dist'),
            // app's own published copy
            base_path('public/vendor/periscope'),
        ];
        foreach ($candidates as $c) {
            $real = realpath($c);
            if ($real !== false && is_dir($real)) {
                return $real;
            }
        }
        return $explicit ?? ($candidates[0] ?? '');
    }

    /**
     * @return iterable<Hook>
     */
    private function resolveHooks(): iterable
    {
        $config       = $this->app['config'];
        $bridge       = $this->app->make(ExtensionBridge::class);
        $callSites    = $this->app->make(CallSiteResolver::class);
        $events       = $this->app->make(Dispatcher::class);

        if ($config->get('periscope.hooks.queries', true)) {
            yield new QueryHook(
                bridge:       $bridge,
                callSites:    $callSites,
                db:           $this->app->make(DatabaseManager::class),
                nPlusOne:     $this->app->make(NPlusOneDetector::class),
                slowAnalyzer: $this->app->make(SlowQueryAnalyzer::class),
                aiAdvisor:    $this->app->make(AiAdvisor::class),
                slowQueryMs:  (int) $config->get('periscope.slow_query_ms', 100),
            );
        }

        if ($config->get('periscope.hooks.cache', true)) {
            yield new CacheHook($bridge, $callSites, $events);
        }

        if ($config->get('periscope.hooks.commands', true)) {
            yield new CommandHook($bridge, $callSites, $events);
        }

        if ($config->get('periscope.hooks.dump', false)) {
            yield new DumpHook($bridge, $callSites);
        }

        if ($config->get('periscope.hooks.events', true)) {
            yield new EventHook($bridge, $callSites, $events);
        }

        if ($config->get('periscope.hooks.exceptions', true)) {
            yield new ExceptionHook(
                bridge:    $bridge,
                callSites: $callSites,
                handler:   $this->app->make(ExceptionHandler::class),
                events:    $events,
                ai:        $this->app->make(AiAdvisor::class),
            );
        }

        if ($config->get('periscope.hooks.gates', true)) {
            yield new GateHook(
                bridge:    $bridge,
                callSites: $callSites,
                gate:      $this->app->make(Gate::class),
            );
        }

        if ($config->get('periscope.hooks.http', true)) {
            yield new HttpClientHook($bridge, $callSites, $events);
        }

        if ($config->get('periscope.hooks.jobs', true)) {
            yield new JobHook(
                bridge:          $bridge,
                callSites:       $callSites,
                events:          $events,
                dispatchTracker: $this->app->bound(JobDispatchTracker::class)
                    ? $this->app->make(JobDispatchTracker::class)
                    : null,
            );
        }

        if ($config->get('periscope.hooks.logs', true)) {
            yield new LogHook($bridge, $callSites, $events, $this->app->make(AiAdvisor::class));
        }

        if ($config->get('periscope.hooks.mail', true)) {
            yield new MailHook($bridge, $callSites, $events);
        }

        if ($config->get('periscope.hooks.models', true)) {
            yield new ModelHook($bridge, $callSites, $events, $this->app);
        }

        if ($config->get('periscope.hooks.notifications', true)) {
            yield new NotificationHook($bridge, $callSites, $events);
        }

        if ($config->get('periscope.hooks.redis', true)) {
            yield new RedisHook(
                bridge:    $bridge,
                callSites: $callSites,
                events:    $events,
                redis:     $this->app->make(RedisFactory::class),
            );
        }

        if ($config->get('periscope.hooks.requests', true)) {
            yield new RequestHook($bridge, $callSites, $events);
        }

        if ($config->get('periscope.hooks.batch', true)) {
            yield new BatchHook($bridge, $callSites, $events);
        }

        if ($config->get('periscope.hooks.schedule', true)) {
            yield new ScheduleHook($bridge, $callSites, $events);
        }

        if ($config->get('periscope.hooks.views', true)) {
            yield new ViewHook(
                bridge:    $bridge,
                callSites: $callSites,
                views:     $this->app->make(ViewFactory::class),
            );
        }
    }
}
