<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Periscope\Laravel\Hooks\JobHook;
use Periscope\Laravel\Support\CallSiteResolver;

it('emits processing/processed/failed phases', function (): void {
    $bridge = periscopeRecordingBridge();

    $hook = new JobHook($bridge, new CallSiteResolver(snippetLines: 0), app(Dispatcher::class));
    $hook->register();

    $job = Mockery::mock(JobContract::class);
    $job->shouldReceive('getQueue')->andReturn('default');
    $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\SyncListings');
    $job->shouldReceive('getJobId')->andReturn('job-1');
    $job->shouldReceive('attempts')->andReturn(1);
    $job->shouldReceive('payload')->andReturn([]);

    Event::dispatch(new JobProcessing('redis', $job));
    Event::dispatch(new JobProcessed('redis', $job));
    Event::dispatch(new JobFailed('redis', $job, new \RuntimeException('explode')));

    $phases = array_map(fn ($e) => $e['payload']['phase'], $bridge->events);
    expect($phases)->toBe(['processing', 'processed', 'failed']);

    expect($bridge->events[2]['payload']['exception'])
        ->toMatchArray(['class' => \RuntimeException::class, 'message' => 'explode']);
});
