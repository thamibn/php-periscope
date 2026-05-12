<?php

declare(strict_types=1);

use Illuminate\Contracts\Bus\QueueingDispatcher;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Periscope\Laravel\Bus\JobDispatchTracker;
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

it('uses the dispatch site as the call site for sync jobs', function (): void {
    $bridge = periscopeRecordingBridge();

    $resolver = new CallSiteResolver(
        vendorSkip:   ['/vendor/', '/laravel-adapter/src/'],
        snippetLines: 0,
    );

    $inner = Mockery::mock(QueueingDispatcher::class);
    // dispatchToQueue is invoked by SyncQueue::push internally — for this
    // test we drive the tracker by hand. The tracker pushes the call site
    // when `dispatch()` is entered and pops on return; in real life
    // JobProcessing fires nested inside that try block.
    $inner->shouldReceive('dispatch')->andReturnUsing(function ($cmd) use ($bridge): void {
        $job = Mockery::mock(JobContract::class);
        $job->shouldReceive('getQueue')->andReturn('sync');
        $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\Faux');
        $job->shouldReceive('getJobId')->andReturn('');
        $job->shouldReceive('attempts')->andReturn(1);
        $job->shouldReceive('payload')->andReturn([]);

        Event::dispatch(new JobProcessing('sync', $job));
    });

    $tracker = new JobDispatchTracker($inner, $resolver);

    $hook = new JobHook(
        bridge:          $bridge,
        callSites:       $resolver,
        events:          app(Dispatcher::class),
        dispatchTracker: $tracker,
    );
    $hook->register();

    // The line below is the dispatch site we expect to be captured.
    $tracker->dispatch(new stdClass);

    $row = $bridge->events[0];
    expect($row['payload']['phase'])->toBe('processing');
    // The recorded call site must be from this test file (the dispatch
    // site), not from inside the test runner / vendor.
    expect($row['callSite']['file'])->toEndWith('JobHookTest.php');
});
