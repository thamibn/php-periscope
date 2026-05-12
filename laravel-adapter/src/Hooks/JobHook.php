<?php

declare(strict_types=1);

namespace Periscope\Laravel\Hooks;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Periscope\Laravel\Bridge\ExtensionBridge;
use Periscope\Laravel\Bus\JobDispatchTracker;
use Periscope\Laravel\Support\CallSiteResolver;

final readonly class JobHook implements Hook
{
    public function __construct(
        private ExtensionBridge $bridge,
        private CallSiteResolver $callSites,
        private Dispatcher $events,
        private ?JobDispatchTracker $dispatchTracker = null,
    ) {}

    public function register(): void
    {
        if (!$this->bridge->isAvailable()) {
            return;
        }

        if (class_exists(JobQueued::class)) {
            $this->events->listen(JobQueued::class, $this->onQueued(...));
        }
        $this->events->listen(JobProcessing::class, $this->onProcessing(...));
        $this->events->listen(JobProcessed::class,  $this->onProcessed(...));
        $this->events->listen(JobFailed::class,     $this->onFailed(...));
    }

    private function onQueued(JobQueued $event): void
    {
        $this->bridge->recordEvent('job', [
            'phase'       => 'queued',
            'connection'  => $event->connectionName,
            'queue'       => method_exists($event, 'getQueue') ? $event->getQueue() : null,
            'class'       => $this->jobClass($event->job),
            'id'          => (string) $event->id,
        ], $this->callSites->resolve());
    }

    private function onProcessing(JobProcessing $event): void
    {
        // Prefer the dispatch site (e.g. the controller line that called
        // `MyJob::dispatch(...)`) over the worker / kernel-terminate frame
        // that the live backtrace would resolve to. Falls back to live
        // resolution for jobs we didn't see dispatched (e.g. workers).
        $callSite = $this->dispatchTracker?->peek() ?? $this->callSites->resolve();

        $this->bridge->recordEvent('job', [
            'phase'      => 'processing',
            'connection' => $event->connectionName,
            'queue'      => $event->job->getQueue(),
            'class'      => $event->job->resolveName(),
            'id'         => $event->job->getJobId(),
            'attempts'   => $event->job->attempts(),
        ], $callSite);
    }

    private function onProcessed(JobProcessed $event): void
    {
        $this->bridge->recordEvent('job', [
            'phase'      => 'processed',
            'connection' => $event->connectionName,
            'queue'      => $event->job->getQueue(),
            'class'      => $event->job->resolveName(),
            'id'         => $event->job->getJobId(),
        ], $this->callSites->resolve());
    }

    private function onFailed(JobFailed $event): void
    {
        $this->bridge->recordEvent('job', [
            'phase'        => 'failed',
            'connection'   => $event->connectionName,
            'queue'        => $event->job->getQueue(),
            'class'        => $event->job->resolveName(),
            'id'           => $event->job->getJobId(),
            'exception'    => [
                'class'   => $event->exception::class,
                'message' => $event->exception->getMessage(),
            ],
        ], $this->callSites->resolve());
    }

    private function jobClass(mixed $job): string
    {
        if (is_object($job)) {
            return $job::class;
        }
        return is_string($job) ? $job : get_debug_type($job);
    }
}
