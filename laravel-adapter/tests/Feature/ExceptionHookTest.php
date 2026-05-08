<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Periscope\Laravel\Detection\AiAdvisor;
use Periscope\Laravel\Hooks\ExceptionHook;
use Periscope\Laravel\Support\CallSiteResolver;

it('records exceptions logged via MessageLogged', function (): void {
    $bridge = periscopeRecordingBridge();

    $hook = new ExceptionHook(
        bridge:    $bridge,
        callSites: new CallSiteResolver(snippetLines: 0),
        handler:   app(ExceptionHandler::class),
        events:    app(Dispatcher::class),
        ai:        new AiAdvisor($bridge, false, 3),
    );
    $hook->register();

    $previous = new \DomainException('inner reason');
    $exception = new \RuntimeException('boom', 0, $previous);

    Event::dispatch(new MessageLogged('error', 'something failed', [
        'exception' => $exception,
    ]));

    expect($bridge->events)->toHaveCount(1);

    $payload = $bridge->events[0]['payload'];
    expect($bridge->events[0]['type'])->toBe('exception')
        ->and($payload['class'])->toBe(\RuntimeException::class)
        ->and($payload['message'])->toBe('boom')
        ->and($payload['previous'])->toHaveCount(1)
        ->and($payload['previous'][0])->toMatchArray([
            'class'   => \DomainException::class,
            'message' => 'inner reason',
        ]);
});
