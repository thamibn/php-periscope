<?php

declare(strict_types=1);

namespace Periscope\Laravel\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Periscope\Laravel\PeriscopeServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        $providers = [
            PeriscopeServiceProvider::class,
        ];
        // laravel/mcp is a suggested dependency — register its provider so
        // Pest tests for the MCP server have its container bindings active.
        if (class_exists(\Laravel\Mcp\Server\McpServiceProvider::class)) {
            $providers[] = \Laravel\Mcp\Server\McpServiceProvider::class;
        }
        return $providers;
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }
}
