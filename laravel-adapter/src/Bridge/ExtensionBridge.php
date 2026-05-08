<?php

declare(strict_types=1);

namespace Periscope\Laravel\Bridge;

/**
 * Wraps the C extension's userland API.
 *
 * If the periscope extension isn't loaded (e.g. dev installed the package
 * but hasn't installed the .so yet), every method becomes a silent no-op.
 * This means the adapter is harmless to leave installed in environments
 * without the extension — the `composer require` is reversible.
 */
/**
 * Not `final` so test code can extend it via anonymous class for assertion
 * fakes (see tests/Feature/QueryHookTest.php). The single instance property
 * is `readonly` via constructor promotion — extension does not change that.
 */
class ExtensionBridge
{
    public function __construct(
        private readonly bool $enabled = true,
    ) {}

    public function isAvailable(): bool
    {
        return $this->enabled
            && function_exists('periscope_record_event');
    }

    /**
     * @param array<string, mixed>      $payload
     * @param array<string, mixed>|null $callSite
     */
    public function recordEvent(string $type, array $payload, ?array $callSite = null): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        return periscope_record_event($type, $payload, $callSite);
    }

    /**
     * Drop a labeled marker on the timeline.
     */
    public function checkpoint(string $label, mixed $context = null): bool
    {
        if (!$this->isAvailable() || !function_exists('periscope_checkpoint')) {
            return false;
        }

        return periscope_checkpoint($label, $context);
    }
}
