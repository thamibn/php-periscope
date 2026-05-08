<?php

declare(strict_types=1);

namespace Periscope\Laravel\Hooks;

use Illuminate\Contracts\Auth\Access\Gate;
use Periscope\Laravel\Bridge\ExtensionBridge;
use Periscope\Laravel\Support\CallSiteResolver;

/**
 * Records every Gate / policy decision (allow/deny + reason).
 *
 * Differentiator: the decision trail is captured even when allow() succeeds —
 * Telescope only logs failures. The full trail is what answers "why was this
 * user allowed to do X?" weeks later in an audit.
 */
final readonly class GateHook implements Hook
{
    public function __construct(
        private ExtensionBridge $bridge,
        private CallSiteResolver $callSites,
        private Gate $gate,
    ) {}

    public function register(): void
    {
        if (!$this->bridge->isAvailable()) {
            return;
        }

        $this->gate->after(function (
            mixed $user,
            string $ability,
            mixed $result,
            ?array $arguments = []
        ): void {
            $this->bridge->recordEvent('gate', [
                'ability'    => $ability,
                'result'     => $result === true ? 'allowed' : 'denied',
                'user_id'    => is_object($user) && method_exists($user, 'getAuthIdentifier')
                    ? $user->getAuthIdentifier()
                    : null,
                'user_class' => is_object($user) ? $user::class : null,
                'arguments'  => $this->describeArguments($arguments ?? []),
            ], $this->callSites->resolve());
        });
    }

    /**
     * @param  array<int, mixed> $arguments
     * @return list<array{type: string, value: mixed}>
     */
    private function describeArguments(array $arguments): array
    {
        $out = [];
        foreach ($arguments as $arg) {
            $out[] = match (true) {
                is_object($arg) => ['type' => $arg::class, 'value' => method_exists($arg, 'getKey') ? $arg->getKey() : null],
                is_scalar($arg) => ['type' => get_debug_type($arg), 'value' => $arg],
                default         => ['type' => get_debug_type($arg), 'value' => null],
            };
        }
        return $out;
    }
}
