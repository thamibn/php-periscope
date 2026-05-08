<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Periscope\Laravel\Detection\AiAdvisor;
use Periscope\Laravel\Hooks\LogHook;
use Periscope\Laravel\Support\CallSiteResolver;

it('records every log line and replaces exception objects with their class names', function (): void {
    $bridge = periscopeRecordingBridge();
    $ai     = new AiAdvisor($bridge, false, 3);

    $hook = new LogHook($bridge, new CallSiteResolver(snippetLines: 0), app(Dispatcher::class), $ai);
    $hook->register();

    Event::dispatch(new MessageLogged('warning', 'disk almost full', ['percent' => 92]));

    $exception = new \RuntimeException('boom');
    Event::dispatch(new MessageLogged('error', 'op failed', ['exception' => $exception, 'op' => 'sync']));

    expect($bridge->events)->toHaveCount(2);

    expect($bridge->events[0]['payload'])->toMatchArray([
        'level'   => 'warning',
        'message' => 'disk almost full',
        'context' => ['percent' => 92],
    ]);

    expect($bridge->events[1]['payload']['context'])->toMatchArray([
        'exception' => \RuntimeException::class,
        'op'        => 'sync',
    ]);
});
