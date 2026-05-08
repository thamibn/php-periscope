<?php

declare(strict_types=1);

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Periscope\Laravel\Hooks\CacheHook;
use Periscope\Laravel\Support\CallSiteResolver;

it('records cache hit/miss/write/forget actions', function (): void {
    $bridge = periscopeRecordingBridge();

    $hook = new CacheHook($bridge, new CallSiteResolver(snippetLines: 0), app(Dispatcher::class));
    $hook->register();

    Event::dispatch(new CacheHit('redis',  'foo', 1, ['t']));
    Event::dispatch(new CacheMissed('redis', 'bar', ['t']));
    Event::dispatch(new KeyWritten('redis', 'baz', 'v', 60, ['t']));
    Event::dispatch(new KeyForgotten('redis', 'qux', ['t']));

    expect($bridge->events)->toHaveCount(4);

    $actions = array_map(fn ($e) => $e['payload']['action'], $bridge->events);
    expect($actions)->toBe(['hit', 'miss', 'write', 'forget']);

    expect($bridge->events[2]['payload'])
        ->toMatchArray(['action' => 'write', 'key' => 'baz', 'seconds' => 60]);
});
