<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Periscope\Laravel\Hooks\HttpClientHook;
use Periscope\Laravel\Support\CallSiteResolver;

it('records both sending and received phases for HTTP client', function (): void {
    $bridge = periscopeRecordingBridge();

    $hook = new HttpClientHook($bridge, new CallSiteResolver(snippetLines: 0), app(Dispatcher::class));
    $hook->register();

    Http::fake(['example.test/*' => Http::response(['ok' => true], 200)]);
    Http::get('https://example.test/data');

    $phases = array_map(fn ($e) => $e['payload']['phase'], $bridge->events);
    expect($phases)->toContain('sending')->toContain('received');

    $received = collect($bridge->events)->firstWhere('payload.phase', 'received');
    expect($received['payload']['status'])->toBe(200);
});
