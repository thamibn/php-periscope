<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Periscope\Laravel\Hooks\EventHook;
use Periscope\Laravel\Support\CallSiteResolver;

it('records user events but skips framework-internal ones', function (): void {
    $bridge = periscopeRecordingBridge();

    $hook = new EventHook($bridge, new CallSiteResolver(snippetLines: 0), app(Dispatcher::class));
    $hook->register();

    Event::dispatch('App\\Events\\UserSignedUp', ['payload-1']);
    Event::dispatch('Illuminate\\Database\\Events\\QueryExecuted', ['skip-me']);
    Event::dispatch('eloquent.retrieved: App\\User', []);

    $names = array_map(fn ($e) => $e['payload']['event'], $bridge->events);
    expect($names)->toContain('App\\Events\\UserSignedUp')
        ->not->toContain('Illuminate\\Database\\Events\\QueryExecuted')
        ->not->toContain('eloquent.retrieved: App\\User');
});
