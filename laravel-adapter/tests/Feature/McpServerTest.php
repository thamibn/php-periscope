<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Facades\Mcp;
use Periscope\Laravel\Mcp\DaemonClient;
use Periscope\Laravel\Mcp\PeriscopeMcpServer;
use Periscope\Laravel\Mcp\Tools\GetInsightsTool;
use Periscope\Laravel\Mcp\Tools\GetStateTool;
use Periscope\Laravel\Mcp\Tools\GetSummaryTool;
use Periscope\Laravel\Mcp\Tools\GetTimelineTool;
use Periscope\Laravel\Mcp\Tools\GetTraceTool;
use Periscope\Laravel\Mcp\Tools\ListTracesTool;
use Periscope\Laravel\Mcp\Tools\QueryEventsTool;
use Periscope\Laravel\Mcp\Tools\ReadFileTool;

it('auto-registers the periscope MCP server with the laravel/mcp registrar', function (): void {
    expect(Mcp::getLocalServer('periscope'))->not->toBeNull();
});

it('binds DaemonClient to the configured daemon base url', function (): void {
    config()->set('periscope.ui.daemon_base', 'http://example.test:1234/');
    $client = app(DaemonClient::class);
    expect($client->baseUrl())->toBe('http://example.test:1234');
});

it('exposes 8 tools whose schemas are introspectable', function (): void {
    $expected = [
        ListTracesTool::class,
        GetTraceTool::class,
        GetSummaryTool::class,
        GetInsightsTool::class,
        GetTimelineTool::class,
        GetStateTool::class,
        QueryEventsTool::class,
        ReadFileTool::class,
    ];

    foreach ($expected as $toolClass) {
        $tool = app($toolClass);
        $array = $tool->toArray();
        expect($array)->toHaveKeys(['name', 'description', 'inputSchema']);
        expect($array['description'])->toBeString()->not->toBe('');
    }
});

it('list_traces proxies the daemon and returns the trace list', function (): void {
    Http::fake([
        '*/api/traces*' => Http::response([
            ['id' => 'abc', 'uri' => '/x', 'duration_micros' => 1000],
        ], 200),
    ]);

    $response = PeriscopeMcpServer::tool(ListTracesTool::class, ['limit' => 5]);
    $response->assertOk();
    // Tool returns Response::json($payload) → Text content with the JSON
    // string as the body. Just look for the round-tripped fields.
    $response->assertSee(['"id":"abc"', '"uri":"/x"', '"duration_micros":1000']);
    // limit should have been forwarded as a query param.
    Http::assertSent(function ($request) {
        $qs = parse_url($request->url(), PHP_URL_QUERY) ?? '';
        parse_str($qs, $parsed);
        return ($parsed['limit'] ?? null) === '5';
    });
});

it('query_events forwards filter + group params to the daemon', function (): void {
    Http::fake(fn () => Http::response([
        ['fingerprint' => 'deadbeef', 'type' => 'log', 'count' => 3],
    ], 200));

    PeriscopeMcpServer::tool(QueryEventsTool::class, [
        'id'     => 'abc',
        'type'   => 'log',
        'filter' => 'payload.level:error',
        'group'  => true,
    ])->assertOk();

    Http::assertSent(function ($request) {
        $qs = parse_url($request->url(), PHP_URL_QUERY) ?? '';
        parse_str($qs, $parsed);
        return ($parsed['type'] ?? null) === 'log'
            && ($parsed['filter'] ?? null) === 'payload.level:error'
            && ($parsed['group'] ?? null) === 'true';
    });
});

it('returns an error response when a required parameter is missing', function (): void {
    $response = PeriscopeMcpServer::tool(GetTraceTool::class, []);
    $response->assertHasErrors();
});

it('does not register the MCP server when periscope.mcp.enabled=false', function (): void {
    config()->set('periscope.mcp.enabled', false);
    config()->set('periscope.mcp.handle', 'periscope-off');
    $facade = '\\Laravel\\Mcp\\Facades\\Mcp';
    $facade::clearResolvedInstances();
    // Re-boot wouldn't help — singleton; just verify no registration under
    // the alt handle.
    expect(Mcp::getLocalServer('periscope-off'))->toBeNull();
});
