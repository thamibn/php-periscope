<?php

declare(strict_types=1);

namespace Periscope\Laravel\Hooks;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Periscope\Laravel\Bridge\ExtensionBridge;
use Periscope\Laravel\Support\CallSiteResolver;

final readonly class JobHook implements Hook
{
    public function __construct(
        private ExtensionBridge $bridge,
        private CallSiteResolver $callSites,
        private Dispatcher $events,
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
        $this->bridge->recordEvent('job', [
            'phase'      => 'processing',
            'connection' => $event->connectionName,
            'queue'      => $event->job->getQueue(),
            'class'      => $event->job->resolveName(),
            'id'         => $event->job->getJobId(),
            'attempts'   => $event->job->attempts(),
        ], $this->callSites->resolve());
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
