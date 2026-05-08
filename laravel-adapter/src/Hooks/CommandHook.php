<?php

declare(strict_types=1);

namespace Periscope\Laravel\Hooks;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Periscope\Laravel\Bridge\ExtensionBridge;
use Periscope\Laravel\Support\CallSiteResolver;

final readonly class CommandHook implements Hook
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

        $this->events->listen(CommandStarting::class, $this->onStarting(...));
        $this->events->listen(CommandFinished::class, $this->onFinished(...));
    }

    private function onStarting(CommandStarting $e): void
    {
        $this->bridge->recordEvent('command', [
            'phase'     => 'starting',
            'command'   => $e->command ?? '',
            'arguments' => $e->input?->getArguments() ?? [],
            'options'   => $e->input?->getOptions() ?? [],
        ], $this->callSites->resolve());
    }

    private function onFinished(CommandFinished $e): void
    {
        $this->bridge->recordEvent('command', [
            'phase'     => 'finished',
            'command'   => $e->command ?? '',
            'exit_code' => $e->exitCode,
        ], $this->callSites->resolve());
    }
}
