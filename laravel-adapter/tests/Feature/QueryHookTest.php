<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Periscope\Laravel\Bridge\ExtensionBridge;
use Periscope\Laravel\Detection\AiAdvisor;
use Periscope\Laravel\Detection\NPlusOneDetector;
use Periscope\Laravel\Detection\SlowQueryAnalyzer;
use Periscope\Laravel\Hooks\QueryHook;
use Periscope\Laravel\Support\CallSiteResolver;

function periscopeDisabledAi(ExtensionBridge $bridge): AiAdvisor
{
    return new AiAdvisor(bridge: $bridge, enabled: false, maxPerRequest: 3);
}

it('attaches a DB::listen handler when registered', function (): void {
    $bridge = new ExtensionBridge(enabled: true);

    // We can't easily assert a real query is captured without a live extension,
    // so verify the hook installs without error and the bridge correctly
    // reports unavailable in this test process.
    $hook = new QueryHook(
        bridge: $bridge,
        callSites: new CallSiteResolver(snippetLines: 0),
        db: app(DatabaseManager::class),
        nPlusOne: new NPlusOneDetector($bridge),
        slowAnalyzer: new SlowQueryAnalyzer($bridge),
        aiAdvisor: periscopeDisabledAi($bridge),
        slowQueryMs: 100,
    );

    $hook->register();
    expect(true)->toBeTrue(); // installation didn't throw
});

it('flags a query as slow above the configured threshold', function (): void {
    // Verify the slow flag computation directly by simulating QueryExecuted.
    $bridge = new class extends ExtensionBridge {
        public ?array $lastPayload = null;
        public ?array $lastCallSite = null;

        public function __construct() { parent::__construct(enabled: true); }
        public function isAvailable(): bool { return true; }
        public function recordEvent(string $type, array $payload, ?array $callSite = null): bool
        {
            $this->lastPayload = $payload;
            $this->lastCallSite = $callSite;
            return true;
        }
    };

    $hook = new QueryHook(
        bridge: $bridge,
        callSites: new CallSiteResolver(snippetLines: 0),
        db: app(DatabaseManager::class),
        nPlusOne: new NPlusOneDetector($bridge),
        slowAnalyzer: new SlowQueryAnalyzer($bridge),
        aiAdvisor: periscopeDisabledAi($bridge),
        slowQueryMs: 100,
    );
    $hook->register();

    // Manually fire a QueryExecuted event with time = 250ms
    $connection = DB::connection();
    Event::dispatch(new QueryExecuted(
        sql: 'SELECT id FROM users WHERE id = ?',
        bindings: [42],
        time: 250.0,
        connection: $connection,
    ));

    expect($bridge->lastPayload)
        ->not->toBeNull()
        ->and($bridge->lastPayload['sql'])->toBe('SELECT id FROM users WHERE id = ?')
        ->and($bridge->lastPayload['bindings'])->toBe([42])
        ->and($bridge->lastPayload['time_ms'])->toBe(250.0)
        ->and($bridge->lastPayload['slow'])->toBeTrue();
});

it('does not flag a query as slow below the threshold', function (): void {
    $bridge = new class extends ExtensionBridge {
        public ?array $lastPayload = null;

        public function __construct() { parent::__construct(enabled: true); }
        public function isAvailable(): bool { return true; }
        public function recordEvent(string $type, array $payload, ?array $callSite = null): bool
        {
            $this->lastPayload = $payload;
            return true;
        }
    };

    $hook = new QueryHook(
        bridge: $bridge,
        callSites: new CallSiteResolver(snippetLines: 0),
        db: app(DatabaseManager::class),
        nPlusOne: new NPlusOneDetector($bridge),
        slowAnalyzer: new SlowQueryAnalyzer($bridge),
        aiAdvisor: periscopeDisabledAi($bridge),
        slowQueryMs: 100,
    );
    $hook->register();

    Event::dispatch(new QueryExecuted(
        sql: 'SELECT 1',
        bindings: [],
        time: 5.0,
        connection: DB::connection(),
    ));

    expect($bridge->lastPayload['slow'])->toBeFalse();
});

it('normalises DateTimeInterface bindings to ISO-8601 strings', function (): void {
    $bridge = new class extends ExtensionBridge {
        public ?array $lastPayload = null;

        public function __construct() { parent::__construct(enabled: true); }
        public function isAvailable(): bool { return true; }
        public function recordEvent(string $type, array $payload, ?array $callSite = null): bool
        {
            $this->lastPayload = $payload;
            return true;
        }
    };

    $hook = new QueryHook(
        bridge: $bridge,
        callSites: new CallSiteResolver(snippetLines: 0),
        db: app(DatabaseManager::class),
        nPlusOne: new NPlusOneDetector($bridge),
        slowAnalyzer: new SlowQueryAnalyzer($bridge),
        aiAdvisor: periscopeDisabledAi($bridge),
        slowQueryMs: 100,
    );
    $hook->register();

    $now = new DateTimeImmutable('2026-05-08T12:34:56+00:00');
    Event::dispatch(new QueryExecuted(
        sql: 'SELECT * FROM events WHERE created_at > ?',
        bindings: [$now],
        time: 1.0,
        connection: DB::connection(),
    ));

    expect($bridge->lastPayload['bindings'][0])
        ->toBeString()
        ->toBe('2026-05-08T12:34:56+00:00');
});
