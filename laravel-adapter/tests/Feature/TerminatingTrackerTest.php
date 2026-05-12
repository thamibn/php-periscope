<?php

declare(strict_types=1);

use Illuminate\Contracts\Foundation\Application;
use Periscope\Laravel\Bus\TerminatingTracker;

it('flips inTerminating + captures registration site while a callback runs', function (): void {
    /** @var Application $app */
    $app = app();

    // The service provider has already installed a tracker on this app
    // instance. Resolve THAT tracker — registering a fresh one would only
    // wrap callbacks added to a fresh proxy, which the app never reads.
    $tracker = $app->make(TerminatingTracker::class);

    $sawInTerminating = false;
    $capturedSite     = null;

    $app->terminating(function () use ($tracker, &$sawInTerminating, &$capturedSite): void {
        $sawInTerminating = $tracker->inTerminating();
        $capturedSite     = $tracker->peekSite();
    });

    expect($tracker->inTerminating())->toBeFalse();

    $app->terminate();

    expect($sawInTerminating)->toBeTrue();
    expect($capturedSite)->not->toBeNull();
    expect($tracker->inTerminating())->toBeFalse();
});

it('records after_response on events fired from a terminating callback', function (): void {
    /** @var Application $app */
    $app = app();

    $bridge = periscopeRecordingBridge();
    $tracker = $app->make(TerminatingTracker::class);
    $bridge->setTerminatingTracker($tracker);

    $app->terminating(function () use ($bridge): void {
        $bridge->recordEvent('test', ['hello' => 'world'], null);
    });

    $bridge->recordEvent('before', ['hello' => 'before'], null);
    $app->terminate();
    $bridge->recordEvent('after', ['hello' => 'after'], null);

    expect($bridge->events[0]['payload'])->not->toHaveKey('after_response');
    expect($bridge->events[1]['payload']['after_response'])->toBeTrue();
    expect($bridge->events[1]['payload']['hello'])->toBe('world');
    expect($bridge->events[2]['payload'])->not->toHaveKey('after_response');
});
