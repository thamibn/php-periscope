<?php

declare(strict_types=1);

namespace Periscope\Laravel\Hooks;

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Contracts\Events\Dispatcher;
use Periscope\Laravel\Bridge\ExtensionBridge;
use Periscope\Laravel\Support\CallSiteResolver;

final readonly class CacheHook implements Hook
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

        $this->events->listen(CacheHit::class,      $this->onHit(...));
        $this->events->listen(CacheMissed::class,   $this->onMiss(...));
        $this->events->listen(KeyWritten::class,    $this->onWrite(...));
        $this->events->listen(KeyForgotten::class,  $this->onForget(...));
    }

    private function onHit(CacheHit $e): void
    {
        $this->emit('hit', ['key' => $e->key, 'tags' => $e->tags, 'store' => $e->storeName ?? null]);
    }

    private function onMiss(CacheMissed $e): void
    {
        $this->emit('miss', ['key' => $e->key, 'tags' => $e->tags, 'store' => $e->storeName ?? null]);
    }

    private function onWrite(KeyWritten $e): void
    {
        $this->emit('write', [
            'key'     => $e->key,
            'tags'    => $e->tags,
            'store'   => $e->storeName ?? null,
            'seconds' => $e->seconds,
        ]);
    }

    private function onForget(KeyForgotten $e): void
    {
        $this->emit('forget', ['key' => $e->key, 'tags' => $e->tags, 'store' => $e->storeName ?? null]);
    }

    /** @param array<string, mixed> $payload */
    private function emit(string $action, array $payload): void
    {
        $this->bridge->recordEvent('cache', ['action' => $action, ...$payload], $this->callSites->resolve());
    }
}
