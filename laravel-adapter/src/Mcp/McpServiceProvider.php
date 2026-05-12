<?php

declare(strict_types=1);

namespace Periscope\Laravel\Mcp;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpClientFactory;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

/**
 * Dedicated sub-provider for the periscope MCP integration. Booted from
 * `Periscope\Laravel\PeriscopeServiceProvider::boot()` so the MCP wiring
 * is isolated from the observability hooks (SRP). No-op when
 * `laravel/mcp` is absent — the dependency is `suggest`-only.
 */
final class McpServiceProvider extends ServiceProvider
{
    /** Fully-qualified Mcp facade name; checked lazily so the package
     *  doesn't hard-depend on laravel/mcp. */
    private const MCP_FACADE = '\\Laravel\\Mcp\\Facades\\Mcp';

    public function register(): void
    {
        $this->app->singleton(DaemonClient::class, static fn (Application $app): DaemonClient =>
            new DaemonClient(
                http:    $app->make(HttpClientFactory::class),
                baseUrl: (string) Config::get('periscope.ui.daemon_base', 'http://127.0.0.1:9999'),
            )
        );
    }

    public function boot(): void
    {
        if (!Config::boolean('periscope.mcp.enabled', true)) {
            return;
        }
        if (!class_exists(self::MCP_FACADE)) {
            return;
        }
        $handle = Str::of(Config::get('periscope.mcp.handle', 'periscope'))->trim()->toString();
        if ($handle === '') {
            return;
        }
        rescue(
            fn () => self::MCP_FACADE::local($handle, PeriscopeMcpServer::class),
            report: false,
        );
    }
}
