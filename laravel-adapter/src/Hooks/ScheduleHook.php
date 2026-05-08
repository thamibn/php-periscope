<?php

declare(strict_types=1);

namespace Periscope\Laravel\Hooks;

use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Periscope\Laravel\Bridge\ExtensionBridge;
use Periscope\Laravel\Support\CallSiteResolver;

final readonly class ScheduleHook implements Hook
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

        $this->events->listen(ScheduledTaskStarting::class, $this->onStarting(...));
        $this->events->listen(ScheduledTaskFinished::class, $this->onFinished(...));
    }

    private function onStarting(ScheduledTaskStarting $event): void
    {
        $this->bridge->recordEvent('schedule', [
            'phase'       => 'starting',
            'description' => $event->task->description,
            'expression'  => $event->task->expression,
            'command'     => $event->task->command,
        ], $this->callSites->resolve());
    }

    private function onFinished(ScheduledTaskFinished $event): void
    {
        $this->bridge->recordEvent('schedule', [
            'phase'       => 'finished',
            'description' => $event->task->description,
            'runtime_ms'  => (float) $event->runtime * 1000.0,
        ], $this->callSites->resolve());
    }
}
