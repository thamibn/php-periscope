<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Periscope\Laravel\Bridge\ExtensionBridge;
use Periscope\Laravel\Http\InjectToolbar;

function makeToolbar(array $config = []): InjectToolbar
{
    $bridge = new ExtensionBridge(enabled: true);
    return new InjectToolbar($bridge, array_merge([
        'enabled'    => true,
        'open_url'   => '/periscope',
        'mount_path' => '/periscope',
    ], $config));
}

it('injects the chip into HTML responses with </body>', function () {
    $mw = makeToolbar();
    $req = Request::create('/dashboard', 'GET');
    $next = fn () => new Response(
        '<html><body><h1>hi</h1></body></html>',
        200,
        ['content-type' => 'text/html; charset=utf-8'],
    );

    $resp = $mw->handle($req, $next);

    expect($resp->getStatusCode())->toBe(200);
    $body = (string) $resp->getContent();
    expect($body)->toContain('window.__PERISCOPE_TB__');
    expect($body)->toContain('data-periscope-toolbar');
    // Chip must come *before* </body>, never after it.
    expect(strpos($body, 'window.__PERISCOPE_TB__'))->toBeLessThan(strpos($body, '</body>'));
});

it('passes through when disabled', function () {
    $mw = makeToolbar(['enabled' => false]);
    $req = Request::create('/dashboard', 'GET');
    $next = fn () => new Response('<html><body>x</body></html>', 200, ['content-type' => 'text/html']);

    $body = (string) $mw->handle($req, $next)->getContent();
    expect($body)->not->toContain('window.__PERISCOPE_TB__');
});

it('passes through JSON responses untouched', function () {
    $mw = makeToolbar();
    $req = Request::create('/api/users', 'GET');
    $next = fn () => new Response('{"x":1}', 200, ['content-type' => 'application/json']);

    $body = (string) $mw->handle($req, $next)->getContent();
    expect($body)->toBe('{"x":1}');
});

it('passes through AJAX/XHR requests untouched', function () {
    $mw = makeToolbar();
    $req = Request::create('/dashboard', 'GET', server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    $next = fn () => new Response('<html><body>x</body></html>', 200, ['content-type' => 'text/html']);

    $body = (string) $mw->handle($req, $next)->getContent();
    expect($body)->not->toContain('window.__PERISCOPE_TB__');
});

it('does not inject into responses missing </body>', function () {
    $mw = makeToolbar();
    $req = Request::create('/dashboard', 'GET');
    $next = fn () => new Response('<div>fragment</div>', 200, ['content-type' => 'text/html']);

    $body = (string) $mw->handle($req, $next)->getContent();
    expect($body)->toBe('<div>fragment</div>');
});

it('skips its own mount path', function () {
    $mw = makeToolbar(['mount_path' => '/periscope']);
    $req = Request::create('/periscope', 'GET');
    $next = fn () => new Response('<html><body>periscope ui</body></html>', 200, ['content-type' => 'text/html']);

    $body = (string) $mw->handle($req, $next)->getContent();
    expect($body)->not->toContain('window.__PERISCOPE_TB__');
});

it('reflects the response status in the chip payload', function () {
    $mw = makeToolbar();
    $req = Request::create('/dashboard', 'GET');
    $next = fn () => new Response('<html><body>err</body></html>', 503, ['content-type' => 'text/html']);

    $body = (string) $mw->handle($req, $next)->getContent();
    expect($body)->toMatch('/"status"\s*:\s*503/');
});

it('counts queries from the bridge in the chip payload', function () {
    $bridge = new ExtensionBridge(enabled: true);
    // Force the bridge into "available" state via an anon class so recordEvent
    // increments counters even without the extension.
    $bridge = new class extends ExtensionBridge {
        public function __construct() { parent::__construct(enabled: true); }
        public function isAvailable(): bool { return true; }
        public function recordEvent(string $type, array $payload, ?array $callSite = null): bool
        {
            // Call parent to keep counters incrementing; periscope_record_event
            // is unavailable in tests so we short-circuit before that.
            // Re-implement the counter bump directly.
            $reflection = new ReflectionProperty(ExtensionBridge::class, 'counters');
            $current = $reflection->getValue($this);
            $current[$type] = ($current[$type] ?? 0) + 1;
            $reflection->setValue($this, $current);
            return true;
        }
    };
    $bridge->recordEvent('sql', []);
    $bridge->recordEvent('sql', []);
    $bridge->recordEvent('exception', []);

    $mw = new InjectToolbar($bridge, ['enabled' => true, 'open_url' => '/x', 'mount_path' => '/y']);
    $req = Request::create('/dashboard', 'GET');
    $next = fn () => new Response('<html><body>x</body></html>', 200, ['content-type' => 'text/html']);

    $body = (string) $mw->handle($req, $next)->getContent();
    expect($body)->toMatch('/"queries"\s*:\s*2/');
    expect($body)->toMatch('/"exceptions"\s*:\s*1/');
});
