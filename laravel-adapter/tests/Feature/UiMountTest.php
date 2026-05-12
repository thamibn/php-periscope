<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Periscope\Laravel\Http\UiController;
use Periscope\Laravel\Http\UiGate;

afterEach(function () {
    UiGate::reset();
});

it('does not register UI routes when ui.enabled is false (default)', function () {
    config(['periscope.ui.enabled' => false]);
    $routes = collect(app('router')->getRoutes())->map(fn ($r) => $r->uri())->all();
    expect($routes)->not->toContain('periscope');
});

it('serves the bundle html when APP_DEBUG=true', function () {
    config(['app.debug' => true]);
    $tmp = sys_get_temp_dir() . '/periscope-ui-test-' . uniqid();
    @mkdir($tmp, 0755, true);
    file_put_contents($tmp . '/index.html', '<html><head><title>x</title></head><body>periscope</body></html>');

    $controller = new UiController(
        bundleDir: $tmp,
        daemonBase: 'http://app.test:9999',
    );
    $response = $controller->index(Request::create('/periscope', 'GET'));

    expect($response->getStatusCode())->toBe(200);
    expect($response->getContent())->toContain('<meta name="periscope-daemon-base" content="http://app.test:9999">');
    expect($response->getContent())->toContain('<title>x</title>');
});

it('returns a friendly fallback when the bundle is missing', function () {
    config(['app.debug' => true]);
    $controller = new UiController(
        bundleDir: '/this/does/not/exist',
        daemonBase: 'http://127.0.0.1:9999',
    );
    $response = $controller->index(Request::create('/periscope', 'GET'));

    expect($response->getStatusCode())->toBe(200);
    expect($response->getContent())->toContain('UI bundle not built');
});

it('locks the UI in production by default', function () {
    config(['app.debug' => false]);
    $controller = new UiController(
        bundleDir: '/anywhere',
        daemonBase: 'http://127.0.0.1:9999',
    );
    $response = $controller->index(Request::create('/periscope', 'GET'));

    expect($response->getStatusCode())->toBe(403);
    expect($response->getContent())->toContain('locked in production');
});

it('unlocks in production when allow_in_production + matching token is supplied', function () {
    config(['app.debug' => false]);
    $tmp = sys_get_temp_dir() . '/periscope-ui-test-' . uniqid();
    @mkdir($tmp, 0755, true);
    file_put_contents($tmp . '/index.html', '<html><head><title>x</title></head><body>periscope</body></html>');

    $controller = new UiController(
        bundleDir: $tmp,
        daemonBase: 'http://127.0.0.1:9999',
        gateConfig: [
            'allow_in_production' => true,
            'token' => str_repeat('a', 32),
        ],
    );

    // Wrong token → still locked.
    $bad = $controller->index(Request::create('/periscope?token=wrong', 'GET'));
    expect($bad->getStatusCode())->toBe(403);

    // Right token → 200 + sets the cookie.
    $ok = $controller->index(Request::create('/periscope?token=' . str_repeat('a', 32), 'GET'));
    expect($ok->getStatusCode())->toBe(200);
    $cookies = $ok->headers->getCookies();
    expect($cookies)->toHaveCount(1);
    expect($cookies[0]->getName())->toBe(UiGate::COOKIE);
});

it('refuses to unlock with a short or missing token', function () {
    config(['app.debug' => false]);
    $controller = new UiController(
        bundleDir: '/whatever',
        daemonBase: 'http://127.0.0.1:9999',
        gateConfig: [
            'allow_in_production' => true,
            'token' => 'tiny',  // < 16 chars → refused
        ],
    );
    $response = $controller->index(Request::create('/periscope?token=tiny', 'GET'));
    expect($response->getStatusCode())->toBe(403);
});

it('honours a custom UiGate::authorize closure', function () {
    config(['app.debug' => false]);
    UiGate::authorize(fn (Request $r) => $r->ip() === '127.0.0.1');

    $tmp = sys_get_temp_dir() . '/periscope-ui-test-' . uniqid();
    @mkdir($tmp, 0755, true);
    file_put_contents($tmp . '/index.html', '<html><head></head><body>x</body></html>');

    $controller = new UiController(bundleDir: $tmp, daemonBase: 'http://x:1');

    $request = Request::create('/periscope', 'GET');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');
    expect($controller->index($request)->getStatusCode())->toBe(200);

    $request = Request::create('/periscope', 'GET');
    $request->server->set('REMOTE_ADDR', '8.8.8.8');
    expect($controller->index($request)->getStatusCode())->toBe(403);
});
