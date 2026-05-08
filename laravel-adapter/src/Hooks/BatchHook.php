<?php

declare(strict_types=1);

namespace Periscope\Laravel\Hooks;

use Illuminate\Bus\Events\BatchDispatched;
use Illuminate\Contracts\Events\Dispatcher;
use Periscope\Laravel\Bridge\ExtensionBridge;
use Periscope\Laravel\Support\CallSiteResolver;

final readonly class BatchHook implements Hook
{
    public function __construct(
        private ExtensionBridge $bridge,
        private CallSiteResolver $callSites,
        private Dispatcher $events,
    ) {}

    public function register(): void
    {
        if (!$this->bridge->isAvailable() || !class_exists(BatchDispatched::class)) {
            return;
        }

        $this->events->listen(BatchDispatched::class, $this->onDispatched(...));
    }

    private function onDispatched(BatchDispatched $event): void
    {
        $batch = $event->batch;

        $this->bridge->recordEvent('batch', [
            'id'             => $batch->id,
            'name'           => $batch->name,
            'total_jobs'     => $batch->totalJobs,
            'pending_jobs'   => $batch->pendingJobs,
            'failed_jobs'    => $batch->failedJobs,
            'options'        => $batch->options ?? [],
        ], $this->callSites->resolve());
    }
}
