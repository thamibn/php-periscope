<?php

declare(strict_types=1);

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Support\Facades\View;
use Periscope\Laravel\Hooks\ViewHook;
use Periscope\Laravel\Support\CallSiteResolver;

it('detects an Inertia render via the root view payload', function (): void {
    $bridge = periscopeRecordingBridge();

    $factory = app(ViewFactory::class);
    $factory->addNamespace('periscope-test', __DIR__ . '/../fixtures/views');

    $hook = new ViewHook($bridge, new CallSiteResolver(snippetLines: 0), $factory);
    $hook->register();

    // Simulate an Inertia render via root-view data.
    View::composer('periscope-test::root', function ($view): void {
        // composer fires before render — nothing to do here, the hook's
        // own composer also fires.
    });

    $factory->make('periscope-test::root', [
        'page' => [
            'component' => 'Listing/Show',
            'props'     => ['listing' => ['id' => 42]],
            'url'       => '/listings/42',
            'version'   => 'abc123',
        ],
    ])->render();

    $inertia = collect($bridge->events)->firstWhere('type', 'inertia');
    expect($inertia)->not->toBeNull()
        ->and($inertia['payload'])->toMatchArray([
            'component'  => 'Listing/Show',
            'url'        => '/listings/42',
            'version'    => 'abc123',
            'prop_count' => 1,
        ])
        ->and($inertia['payload']['prop_keys'])->toBe(['listing']);

    $view = collect($bridge->events)->firstWhere('type', 'view');
    expect($view['payload']['name'])->toBe('periscope-test::root');
});

it('does not emit an inertia event for a normal Blade render', function (): void {
    $bridge = periscopeRecordingBridge();

    $factory = app(ViewFactory::class);
    $factory->addNamespace('periscope-test', __DIR__ . '/../fixtures/views');

    $hook = new ViewHook($bridge, new CallSiteResolver(snippetLines: 0), $factory);
    $hook->register();

    $factory->make('periscope-test::plain', ['title' => 'Hello'])->render();

    expect(collect($bridge->events)->pluck('type')->all())->toContain('view')
        ->and(collect($bridge->events)->pluck('type')->all())->not->toContain('inertia');
});
