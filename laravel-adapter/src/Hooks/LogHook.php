<?php

declare(strict_types=1);

namespace Periscope\Laravel\Hooks;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Log\Events\MessageLogged;
use Periscope\Laravel\Bridge\ExtensionBridge;
use Periscope\Laravel\Detection\AiAdvisor;
use Periscope\Laravel\Support\CallSiteResolver;
use Throwable;

final readonly class LogHook implements Hook
{
    private const AI_LEVELS = ['error', 'critical', 'alert', 'emergency'];

    public function __construct(
        private ExtensionBridge $bridge,
        private CallSiteResolver $callSites,
        private Dispatcher $events,
        private AiAdvisor $ai,
    ) {}

    public function register(): void
    {
        if (!$this->bridge->isAvailable()) {
            return;
        }

        $this->events->listen(MessageLogged::class, $this->onLogged(...));
    }

    private function onLogged(MessageLogged $event): void
    {
        $context = $event->context;
        $exception = $context['exception'] ?? null;
        if ($exception instanceof Throwable) {
            // Strip exception object — ExceptionHook handles it; we keep the class name.
            $context['exception'] = $exception::class;
        }

        $callSite = $this->callSites->resolve();
        $scrubbed = $this->scrubContext($context);

        $this->bridge->recordEvent('log', [
            'level'   => $event->level,
            'message' => $event->message,
            'context' => $scrubbed,
        ], $callSite);

        // ExceptionHook already advises on exceptions — skip those here so we
        // don't double-emit and burn the AI budget.
        if (in_array($event->level, self::AI_LEVELS, true) && !($exception instanceof Throwable)) {
            $this->ai->advise(
                kind:     'error_log',
                title:    sprintf('[%s] %s', strtoupper($event->level), $event->message),
                body:     json_encode($scrubbed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '',
                callSite: $callSite,
            );
        }
    }

    /**
     * @param  array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function scrubContext(array $context): array
    {
        return array_map(
            static fn (mixed $v): mixed => match (true) {
                is_object($v)  => $v::class,
                is_resource($v) => '<resource>',
                default        => $v,
            },
            $context,
        );
    }
}
