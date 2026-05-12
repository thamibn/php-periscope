<?php

declare(strict_types=1);

namespace Periscope\Laravel\Bus;

use Illuminate\Contracts\Bus\QueueingDispatcher;
use Periscope\Laravel\Support\CallSiteResolver;

/**
 * Decorates Laravel's bus dispatcher so that every entry into dispatch /
 * dispatchSync / dispatchNow / dispatchToQueue / dispatchAfterResponse pushes
 * the user's call site onto a stack, then pops it on return.
 *
 * The hook for `JobProcessing` peeks the top of the stack so the source
 * snippet on a job row points at the dispatch site (e.g. the controller line
 * that called `MyJob::dispatch(...)`), instead of the kernel terminate frame
 * — which is the only "user" frame left on the backtrace once Laravel's
 * worker / sync queue machinery has unwound the original call.
 *
 * Sync jobs nest dispatch and processing inside the same PHP call, so a stack
 * is the right structure: `processing` always fires while `dispatch` is still
 * on the stack. Queued jobs (redis/database/sqs) already get the right call
 * site via JobQueued at queue-time, so the stack value there is harmless.
 */
final class JobDispatchTracker implements QueueingDispatcher
{
    /**
     * @var list<array{file:string, line:int, snippet: list<array{number:int, source:string}>, frame_stack: list<int>, stack: list<array{file:string, line:int, function:string}>}|null>
     */
    private array $stack = [];

    public function __construct(
        private readonly QueueingDispatcher $inner,
        private readonly CallSiteResolver $callSites,
    ) {}

    /**
     * @return array{file:string, line:int, snippet: list<array{number:int, source:string}>, frame_stack: list<int>, stack: list<array{file:string, line:int, function:string}>}|null
     */
    public function peek(): ?array
    {
        return $this->stack === [] ? null : end($this->stack);
    }

    public function dispatch($command)
    {
        $this->stack[] = $this->callSites->resolve();
        try {
            return $this->inner->dispatch($command);
        } finally {
            array_pop($this->stack);
        }
    }

    public function dispatchSync($command, $handler = null)
    {
        $this->stack[] = $this->callSites->resolve();
        try {
            return $this->inner->dispatchSync($command, $handler);
        } finally {
            array_pop($this->stack);
        }
    }

    public function dispatchNow($command, $handler = null)
    {
        $this->stack[] = $this->callSites->resolve();
        try {
            return $this->inner->dispatchNow($command, $handler);
        } finally {
            array_pop($this->stack);
        }
    }

    public function dispatchToQueue($command)
    {
        $this->stack[] = $this->callSites->resolve();
        try {
            return $this->inner->dispatchToQueue($command);
        } finally {
            array_pop($this->stack);
        }
    }

    /**
     * Catches `dispatchAfterResponse` (not on the interface but on the concrete
     * Bus\Dispatcher) and any future Laravel additions. Calls without
     * "dispatch" in the name pass through untouched.
     */
    public function __call(string $name, array $arguments): mixed
    {
        if (str_starts_with($name, 'dispatch')) {
            $this->stack[] = $this->callSites->resolve();
            try {
                return $this->inner->{$name}(...$arguments);
            } finally {
                array_pop($this->stack);
            }
        }

        return $this->inner->{$name}(...$arguments);
    }

    public function hasCommandHandler($command)
    {
        return $this->inner->hasCommandHandler($command);
    }

    public function getCommandHandler($command)
    {
        return $this->inner->getCommandHandler($command);
    }

    public function pipeThrough(array $pipes)
    {
        $this->inner->pipeThrough($pipes);
        return $this;
    }

    public function map(array $map)
    {
        $this->inner->map($map);
        return $this;
    }

    public function findBatch(string $batchId)
    {
        return $this->inner->findBatch($batchId);
    }

    public function batch($jobs)
    {
        return $this->inner->batch($jobs);
    }
}
