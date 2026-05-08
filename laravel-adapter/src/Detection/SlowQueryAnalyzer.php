<?php

declare(strict_types=1);

namespace Periscope\Laravel\Detection;

use Periscope\Laravel\Bridge\ExtensionBridge;

/**
 * Static SQL-text analyzer for slow queries.
 *
 * Runs against any query whose execution time crosses the configured slow
 * threshold. Detects index-killer patterns from the SQL string alone — no
 * EXPLAIN, no DB round-trip, no extra request latency. The recommendations
 * are deterministic and source-of-truth-checkable so the AI co-pilot
 * (Appendix A.6) can build on top without depending on its own SQL knowledge.
 *
 * v1.1 candidate: opt-in EXPLAIN FORMAT=JSON path that augments these
 * heuristics with `possible_keys: NULL`, `rows_examined`, `using filesort`,
 * etc. — the daemon's `/api/traces/{id}/insights` ranks both signals together.
 */
final class SlowQueryAnalyzer
{
    public function __construct(
        private readonly ExtensionBridge $bridge,
    ) {}

    /**
     * @param  array<string, mixed>|null $callSite
     */
    public function analyse(string $connection, string $sql, float $timeMs, ?array $callSite): void
    {
        $issues = $this->detect($sql);
        if ($issues === []) {
            return;
        }

        $this->bridge->recordEvent('slow_query_warning', [
            'connection' => $connection,
            'sql'        => $sql,
            'time_ms'    => $timeMs,
            'issues'     => $issues,
        ], $callSite);
    }

    /** @return list<array{code: string, severity: string, message: string, suggestion: string}> */
    public function detect(string $sql): array
    {
        $normalised = preg_replace('/\s+/', ' ', trim($sql)) ?? $sql;
        $lower      = strtolower($normalised);
        $issues     = [];

        // 1. SELECT * — over-fetching.
        if (preg_match('/\bselect\s+\*/i', $lower)) {
            $issues[] = [
                'code'       => 'select_star',
                'severity'   => 'low',
                'message'    => 'Query uses SELECT * — fetches every column.',
                'suggestion' => 'List only the columns you actually use; reduces row size and lets MySQL use covering indexes.',
            ];
        }

        // 2. Leading-wildcard LIKE — cannot use a B-tree index.
        if (preg_match("/\\blike\\s+['\"]%/i", $lower)) {
            $issues[] = [
                'code'       => 'leading_wildcard_like',
                'severity'   => 'high',
                'message'    => 'LIKE with a leading wildcard (%foo) cannot use a standard index.',
                'suggestion' => 'Use a FULLTEXT index, or store a denormalised search column, or switch to a search engine (Meilisearch / Algolia / Typesense).',
            ];
        }

        // 3. Function on indexed column — kills the index.
        if (preg_match('/\bwhere\b[^()]*\b(lower|upper|date|year|month|day|substring|left|right)\s*\(/i', $lower)) {
            $issues[] = [
                'code'       => 'function_on_column',
                'severity'   => 'high',
                'message'    => 'Function applied to a column in the WHERE clause prevents index usage.',
                'suggestion' => 'Store the pre-computed value in a column (DATE column for date-truncation, generated column for case-folding) and index that.',
            ];
        }

        // 4. ORDER BY without LIMIT — sort the world.
        if (preg_match('/\border\s+by\b/i', $lower) && !preg_match('/\blimit\s+\d+/i', $lower)) {
            $issues[] = [
                'code'       => 'order_by_no_limit',
                'severity'   => 'medium',
                'message'    => 'ORDER BY without LIMIT — server sorts the entire result set.',
                'suggestion' => 'Add a LIMIT, paginate, or chunk(). For large tables this often matters more than the WHERE clause.',
            ];
        }

        // 5. OR across different columns — typically unindexable as one plan.
        if (preg_match('/\bwhere\b.+?\bor\b/i', $lower) && substr_count($lower, ' or ') >= 1) {
            // Crude column-diversity check: if WHERE has 2+ distinct identifiers around OR, flag.
            if (preg_match_all('/[a-z_][a-z_0-9]*\s*=\s*\?/i', $lower, $m) && count(array_unique($m[0])) >= 2) {
                $issues[] = [
                    'code'       => 'or_across_columns',
                    'severity'   => 'medium',
                    'message'    => 'WHERE … OR … across different columns is often not coverable by one composite index.',
                    'suggestion' => 'Rewrite as UNION ALL of two indexed lookups, or add a composite/expression index covering the OR branches.',
                ];
            }
        }

        // 6. Correlated / IN subquery — sometimes a perf cliff.
        if (preg_match('/\bin\s*\(\s*select\b/i', $lower)) {
            $issues[] = [
                'code'       => 'in_subquery',
                'severity'   => 'medium',
                'message'    => 'IN (SELECT …) sub-query can be evaluated per outer row on older MySQL versions.',
                'suggestion' => 'Rewrite as a JOIN or EXISTS, or pre-fetch the inner ids into PHP and bind a flat IN list.',
            ];
        }

        // 7. No WHERE clause on a SELECT — full table scan.
        if (preg_match('/^\s*select\b/i', $lower)
            && !preg_match('/\bwhere\b/i', $lower)
            && !preg_match('/\blimit\s+\d+/i', $lower)
        ) {
            $issues[] = [
                'code'       => 'unbounded_select',
                'severity'   => 'high',
                'message'    => 'SELECT without WHERE or LIMIT — full table scan.',
                'suggestion' => 'Add a WHERE clause, LIMIT, or use chunkById() for batch iteration.',
            ];
        }

        return $issues;
    }
}
