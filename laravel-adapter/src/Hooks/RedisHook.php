<?php

declare(strict_types=1);

namespace Periscope\Laravel\Hooks;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Events\CommandExecuted;
use Periscope\Laravel\Bridge\ExtensionBridge;
use Periscope\Laravel\Support\CallSiteResolver;
use Throwable;

final readonly class RedisHook implements Hook
{
    public function __construct(
        private ExtensionBridge $bridge,
        private CallSiteResolver $callSites,
        private Dispatcher $events,
        private RedisFactory $redis,
    ) {}

    public function register(): void
    {
        if (!$this->bridge->isAvailable()) {
            return;
        }

        try {
            // No-op if no redis backend configured; swallow to keep boot resilient.
            if (method_exists($this->redis, 'enableEvents')) {
                $this->redis->enableEvents();
            }
        } catch (Throwable) {
            return;
        }

        $this->events->listen(CommandExecuted::class, $this->onCommand(...));
    }

    private function onCommand(CommandExecuted $event): void
    {
        $this->bridge->recordEvent('redis', [
            'connection' => $event->connectionName,
            'command'    => $event->command,
            'parameters' => $this->normaliseParams($event->parameters),
            'time_ms'    => (float) $event->time,
        ], $this->callSites->resolve());
    }

    /**
     * @param  array<int, mixed> $params
     * @return array<int, mixed>
     */
    private function normaliseParams(array $params): array
    {
        return array_map(
            static fn (mixed $v): mixed => match (true) {
                is_scalar($v) || $v === null => $v,
                is_array($v)                 => '<array(' . count($v) . ')>',
                is_object($v)                => $v::class,
                default                      => get_debug_type($v),
            },
            $params,
        );
    }
}
