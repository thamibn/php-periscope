<?php

declare(strict_types=1);

namespace Periscope\Laravel\Hooks;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Log\Events\MessageLogged;
use Periscope\Laravel\Bridge\ExtensionBridge;
use Periscope\Laravel\Detection\AiAdvisor;
use Periscope\Laravel\Support\CallSiteResolver;
use Throwable;

/**
 * Headline differentiator: every reported Throwable lands on the timeline
 * with a full call site, the exact frame it was thrown from, and the chain
 * of `previous` exceptions. Powers the AI co-pilot's "why did this fail?"
 * analysis without the user pasting the stack into the prompt.
 *
 * Captures via two paths:
 *   1. ExceptionHandler::reportable() — fires for every report() call.
 *   2. Log::listen / MessageLogged with `exception` in context — catches
 *      cases where code logs an exception manually.
 */
final readonly class ExceptionHook implements Hook
{
    public function __construct(
        private ExtensionBridge $bridge,
        private CallSiteResolver $callSites,
        private ExceptionHandler $handler,
        private Dispatcher $events,
        private AiAdvisor $ai,
    ) {}

    public function register(): void
    {
        if (!$this->bridge->isAvailable()) {
            return;
        }

        if (method_exists($this->handler, 'reportable')) {
            $this->handler->reportable(function (Throwable $e): void {
                $this->emit($e);
            });
        }

        $this->events->listen(MessageLogged::class, function (MessageLogged $event): void {
            $exception = $event->context['exception'] ?? null;
            if ($exception instanceof Throwable) {
                $this->emit($exception);
            }
        });
    }

    private function emit(Throwable $e): void
    {
        $callSite = $this->callSites->resolve();
        $trace    = $this->summariseTrace($e);

        $this->bridge->recordEvent('exception', [
            'class'    => $e::class,
            'message'  => $e->getMessage(),
            'code'     => $e->getCode(),
            'file'     => $e->getFile(),
            'line'     => $e->getLine(),
            'trace'    => $trace,
            'previous' => $this->previousChain($e),
        ], $callSite);

        $this->ai->advise(
            kind:     'exception',
            title:    sprintf('%s thrown at %s:%d', $e::class, $e->getFile(), $e->getLine()),
            body:     $e->getMessage() . "\n\n" . $this->renderTrace($trace),
            callSite: $callSite,
        );
    }

    /** @param list<array{file: string, line: int, function: string}> $frames */
    private function renderTrace(array $frames): string
    {
        return implode("\n", array_map(
            static fn (array $f): string => "  at {$f['function']} ({$f['file']}:{$f['line']})",
            $frames,
        ));
    }

    /** @return list<array{file: string, line: int, function: string}> */
    private function summariseTrace(Throwable $e): array
    {
        $frames = [];
        foreach (array_slice($e->getTrace(), 0, 30) as $frame) {
            $frames[] = [
                'file'     => (string) ($frame['file']     ?? ''),
                'line'     => (int)    ($frame['line']     ?? 0),
                'function' => (string) ($frame['function'] ?? ''),
            ];
        }
        return $frames;
    }

    /** @return list<array{class: string, message: string}> */
    private function previousChain(Throwable $e): array
    {
        $chain = [];
        $cursor = $e->getPrevious();
        while ($cursor instanceof Throwable && count($chain) < 5) {
            $chain[] = ['class' => $cursor::class, 'message' => $cursor->getMessage()];
            $cursor = $cursor->getPrevious();
        }
        return $chain;
    }
}
