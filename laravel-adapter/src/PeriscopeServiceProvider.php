<?php

declare(strict_types=1);

namespace Periscope\Laravel;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use Periscope\Laravel\Bridge\ExtensionBridge;
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
 * fresh `composer require periscopephp/laravel` is the only setup
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
        if (!$bridge->isAvailable()) {
            // C extension not loaded; adapter is a no-op. Nothing to register.
            return;
        }

        foreach ($this->resolveHooks() as $hook) {
            $hook->register();
        }
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
            yield new JobHook($bridge, $callSites, $events);
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
