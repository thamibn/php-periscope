<?php

declare(strict_types=1);

it('does not register UI routes when ui.enabled is false (default)', function () {
    config(['periscope.ui.enabled' => false]);
    $routes = collect(app('router')->getRoutes())->map(fn ($r) => $r->uri())->all();
    expect($routes)->not->toContain('periscope');
});

it('UiController injects the daemon base meta tag into the bundle html', function () {
    $tmp = sys_get_temp_dir() . '/periscope-ui-test-' . uniqid();
    @mkdir($tmp, 0755, true);
    file_put_contents($tmp . '/index.html', '<html><head><title>x</title></head><body>periscope</body></html>');

    $controller = new \Periscope\Laravel\Http\UiController(
        bundleDir: $tmp,
        daemonBase: 'http://app.test:9999',
    );
    $response = $controller->index();

    expect($response->getStatusCode())->toBe(200);
    expect($response->getContent())->toContain('<meta name="periscope-daemon-base" content="http://app.test:9999">');
    expect($response->getContent())->toContain('<title>x</title>');
});

it('UiController returns a friendly fallback when the bundle is missing', function () {
    $controller = new \Periscope\Laravel\Http\UiController(
        bundleDir: '/this/does/not/exist',
        daemonBase: 'http://127.0.0.1:9999',
    );
    $response = $controller->index();

    expect($response->getStatusCode())->toBe(200);
    expect($response->getContent())->toContain('UI bundle not built');
});
