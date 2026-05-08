<?php

declare(strict_types=1);

namespace Periscope\Laravel\Support;

/**
 * Walks debug_backtrace() to find the topmost user-code frame and returns
 * a CallSite payload (file, line, snippet, frame_stack) for the trace.
 *
 * Skips vendor/laravel, vendor/illuminate, vendor/symfony etc. so the
 * resolved frame is always YOUR code, not framework internals.
 *
 * One of the v1 differentiators — "10× queries from ListingResource.php:42"
 * is what makes the Queries panel actionable instead of just informative.
 */
final readonly class CallSiteResolver
{
    /**
     * @param list<string> $vendorSkip   substrings that disqualify a frame
     * @param int          $snippetLines number of context lines on each side (0 = no snippet)
     * @param int          $maxBacktrace cap on debug_backtrace depth
     */
    public function __construct(
        private array $vendorSkip = [
            '/vendor/laravel/',
            '/vendor/illuminate/',
            '/vendor/symfony/',
            '/vendor/composer/',
            // The adapter's own frames must be skipped so call sites land on
            // user code, not on QueryHook.php / CacheHook.php / etc.
            '/vendor/periscopephp/laravel/',
            '/laravel-adapter/src/',
        ],
        private int $snippetLines = 2,
        private int $maxBacktrace = 30,
    ) {}

    /**
     * @return array{file: string, line: int, snippet: list<array{number: int, source: string}>, frame_stack: list<int>}|null
     */
    public function resolve(): ?array
    {
        $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $this->maxBacktrace);

        foreach ($bt as $frame) {
            $file = $frame['file'] ?? null;
            $line = $frame['line'] ?? null;

            if (!is_string($file) || !is_int($line) || $line <= 0) {
                continue;
            }

            if ($this->isVendorPath($file)) {
                continue;
            }

            return [
                'file'        => $file,
                'line'        => $line,
                'snippet'     => $this->snippet($file, $line),
                'frame_stack' => [],
            ];
        }

        return null;
    }

    private function isVendorPath(string $path): bool
    {
        foreach ($this->vendorSkip as $needle) {
            if ($needle !== '' && str_contains($path, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return list<array{number: int, source: string}>
     */
    private function snippet(string $file, int $line): array
    {
        if ($this->snippetLines <= 0 || !is_readable($file)) {
            return [];
        }

        $lines = @file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return [];
        }

        $start = max(0, $line - $this->snippetLines - 1);
        $end   = min(count($lines) - 1, $line + $this->snippetLines - 1);

        $out = [];
        for ($i = $start; $i <= $end; $i++) {
            $out[] = [
                'number' => $i + 1,
                'source' => $lines[$i],
            ];
        }
        return $out;
    }
}
