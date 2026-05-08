<?php

declare(strict_types=1);

namespace Periscope\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use Periscope\Laravel\Bridge\ExtensionBridge;
use Periscope\Laravel\Hooks\Hook;
use Periscope\Laravel\Hooks\QueryHook;
use Periscope\Laravel\Support\CallSiteResolver;

/**
 * Boot point for the Laravel adapter.
 *
 * Auto-discovered via composer.json's `extra.laravel.providers`, so a
 * fresh `composer require thamibn/periscope-laravel` is the only setup
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
        $config = $this->app['config'];

        if ($config->get('periscope.hooks.queries', true)) {
            yield new QueryHook(
                bridge:       $this->app->make(ExtensionBridge::class),
                callSites:    $this->app->make(CallSiteResolver::class),
                db:           $this->app->make(DatabaseManager::class),
                slowQueryMs:  (int) $config->get('periscope.slow_query_ms', 100),
            );
        }

        // Phase 5c will yield the remaining 17 watchers here.
    }
}
