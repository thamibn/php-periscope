<?php

declare(strict_types=1);

use Periscope\Laravel\Bridge\ExtensionBridge;

uses(Periscope\Laravel\Tests\TestCase::class)->in('Feature', 'Unit');

/**
 * Recording ExtensionBridge fake — captures every recordEvent() call so tests
 * can inspect type/payload/callSite without depending on the C extension.
 */
function periscopeRecordingBridge(): ExtensionBridge
{
    return new class extends ExtensionBridge {
        /** @var list<array{type: string, payload: array<string, mixed>, callSite: ?array<string, mixed>}> */
        public array $events = [];

        public function __construct() { parent::__construct(enabled: true); }
        public function isAvailable(): bool { return true; }
        public function recordEvent(string $type, array $payload, ?array $callSite = null): bool
        {
            [$payload, $callSite] = $this->prepareEvent($payload, $callSite);
            $this->events[] = ['type' => $type, 'payload' => $payload, 'callSite' => $callSite];
            return true;
        }
    };
}
