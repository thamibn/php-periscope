<?php

declare(strict_types=1);

namespace Periscope\Laravel\Detection;

use Periscope\Laravel\Bridge\ExtensionBridge;

/**
 * Naive in-process N+1 detector.
 *
 * Heuristic: if the same SQL pattern (with bindings normalised away) executes
 * threshold-or-more times against the same connection within a single request,
 * we emit one `n_plus_one_warning` event the first time the threshold flips.
 *
 * Per plan Phase 5 §differentiator #2: "we detect the pattern (same SQL ran N
 * times in one frame, bindings differ only by id) AND surface the exact code
 * change". The frame-scoping refinement is deferred to the daemon's
 * /api/traces/{id}/insights — where we can also rank with full timing data.
 *
 * Inspired by beyondcode/laravel-query-detector.
 */
final class NPlusOneDetector
{
    /** @var array<string, int> fingerprint => count */
    private array $counts = [];

    /** @var array<string, bool> fingerprint => warned-once */
    private array $warned = [];

    public function __construct(
        private readonly ExtensionBridge $bridge,
        private readonly ?AiAdvisor $ai = null,
        private readonly int $threshold = 4,
    ) {}

    /**
     * @param  array<string, mixed>|null $callSite
     */
    public function inspect(string $connection, string $sql, ?array $callSite): void
    {
        $fingerprint = $connection . '|' . $this->normalise($sql);
        $count = ($this->counts[$fingerprint] = ($this->counts[$fingerprint] ?? 0) + 1);

        if ($count < $this->threshold || isset($this->warned[$fingerprint])) {
            return;
        }

        $this->warned[$fingerprint] = true;

        $this->bridge->recordEvent('n_plus_one_warning', [
            'connection' => $connection,
            'sql'        => $sql,
            'occurrences'=> $count,
            'threshold'  => $this->threshold,
            'suggestion' => $this->suggest($sql),
        ], $callSite);

        $this->ai?->advise(
            kind:     'n_plus_one',
            title:    sprintf('N+1 detected (%d× same SQL on `%s`)', $count, $connection),
            body:     $sql,
            callSite: $callSite,
        );
    }

    /**
     * Collapse bindings into a stable shape so `WHERE id = ?` and
     * `WHERE id = ?` from different bindings group together.
     * Whitespace is also collapsed.
     */
    private function normalise(string $sql): string
    {
        $sql = preg_replace('/\s+/', ' ', $sql) ?? $sql;
        return trim(strtolower($sql));
    }

    private function suggest(string $sql): string
    {
        if (preg_match('/from\s+`?(\w+)`?\s+where\s+`?(\w+)`?\s*=\s*\?/i', $sql, $m)) {
            return "Possible N+1 on `{$m[1]}` filtered by `{$m[2]}` — eager-load the parent relation with ->with(...) at the call site, or batch the lookup with whereIn.";
        }
        return 'Same SQL fired ≥ threshold times — eager-load the relation or batch the lookup at the call site.';
    }
}
