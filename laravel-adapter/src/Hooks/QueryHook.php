<?php

declare(strict_types=1);

namespace Periscope\Laravel\Hooks;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\DatabaseManager;
use Periscope\Laravel\Bridge\ExtensionBridge;
use Periscope\Laravel\Detection\AiAdvisor;
use Periscope\Laravel\Detection\NPlusOneDetector;
use Periscope\Laravel\Detection\SlowQueryAnalyzer;
use Periscope\Laravel\Support\CallSiteResolver;

/**
 * Telescope-parity QueryWatcher.
 *
 * Forwards every executed DB query to the trace as an `sql` event with:
 *   - connection name
 *   - raw SQL + bindings
 *   - execution time (ms)
 *   - slow flag (if > slow_query_ms)
 *   - resolved CallSite (file, line, source snippet)
 *
 * Goes beyond Telescope by always attaching a CallSite — Telescope shows
 * the query and a generic stack trace; we point straight at the user-code
 * line that triggered it, with surrounding source visible in the panel.
 */
final readonly class QueryHook implements Hook
{
    public function __construct(
        private ExtensionBridge $bridge,
        private CallSiteResolver $callSites,
        private DatabaseManager $db,
        private NPlusOneDetector $nPlusOne,
        private SlowQueryAnalyzer $slowAnalyzer,
        private AiAdvisor $aiAdvisor,
        private int $slowQueryMs = 100,
    ) {}

    public function register(): void
    {
        if (!$this->bridge->isAvailable()) {
            return;
        }

        $this->db->listen($this->onQuery(...));
    }

    private function onQuery(QueryExecuted $event): void
    {
        $callSite = $this->callSites->resolve();
        $isSlow   = $event->time >= $this->slowQueryMs;

        $payload = [
            'connection' => $event->connectionName,
            'sql'        => $event->sql,
            'bindings'   => $this->normaliseBindings($event->bindings),
            'time_ms'    => (float) $event->time,
            'slow'       => $isSlow,
        ];

        $this->bridge->recordEvent('sql', $payload, $callSite);
        $this->nPlusOne->inspect($event->connectionName, $event->sql, $callSite);

        if ($isSlow) {
            $this->slowAnalyzer->analyse(
                $event->connectionName, $event->sql, (float) $event->time, $callSite,
            );
            $this->aiAdvisor->advise(
                kind:     'slow_query',
                title:    sprintf('Slow query (%.1fms)', (float) $event->time),
                body:     $event->sql,
                callSite: $callSite,
            );
        }
    }

    /**
     * @param  array<int|string, mixed> $bindings
     * @return array<int|string, mixed>
     */
    private function normaliseBindings(array $bindings): array
    {
        return array_map(
            static fn (mixed $v): mixed => match (true) {
                $v instanceof \DateTimeInterface => $v->format('c'),
                is_object($v) && method_exists($v, '__toString') => (string) $v,
                is_resource($v) => '<resource>',
                default => $v,
            },
            $bindings,
        );
    }
}
