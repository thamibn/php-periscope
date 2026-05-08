<?php

declare(strict_types=1);

namespace Periscope\Laravel\Hooks;

use Illuminate\Contracts\Events\Dispatcher;
use Periscope\Laravel\Bridge\ExtensionBridge;
use Periscope\Laravel\Support\CallSiteResolver;

/**
 * Captures every dispatched event to the trace.
 *
 * Skips framework-internal events (Illuminate\, Symfony\, eloquent.* — those
 * are covered by ModelHook) so user-defined event traffic stays the focus.
 */
final readonly class EventHook implements Hook
{
    /** @var list<string> */
    private const FRAMEWORK_PREFIXES = [
        'Illuminate\\',
        'Symfony\\',
        'eloquent.',
        'cache.',
        'creating: ',
        'composing: ',
        'auth.',
        'kernel.',
        'bootstrapping:',
        'bootstrapped:',
        'locale.',
    ];

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

        $this->events->listen('*', function (string $eventName, array $payload): void {
            if ($this->isInternal($eventName)) {
                return;
            }

            $this->bridge->recordEvent('event', [
                'event'     => $eventName,
                'listeners' => count($this->events->getListeners($eventName)),
            ], $this->callSites->resolve());
        });
    }

    private function isInternal(string $name): bool
    {
        foreach (self::FRAMEWORK_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }
        return false;
    }
}
