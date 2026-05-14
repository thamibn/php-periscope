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
            '/vendor/thamibn/php-periscope-laravel/',
            '/laravel-adapter/src/',
        ],
        private int $snippetLines = 6,
        private int $maxBacktrace = 30,
    ) {}

    /**
     * Resolve the topmost user-code call site.
     *
     * @param  bool $statementSnippet When true, snippet captures the entire
     *         PHP statement that contains the call site (e.g. a full Eloquent
     *         chain spanning multiple lines), rather than ±N lines of context.
     *         For query / N+1 events this is what the user actually wants to
     *         see — the offending Builder chain, not the surrounding `for`
     *         loop or comments.
     *
     * @return array{
     *   file: string,
     *   line: int,
     *   snippet: list<array{number: int, source: string}>,
     *   frame_stack: list<int>,
     *   stack: list<array{file: string, line: int, function: string}>
     * }|null
     */
    public function resolve(bool $statementSnippet = false): ?array
    {
        $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $this->maxBacktrace);

        $top = null;
        $stack = [];

        foreach ($bt as $i => $frame) {
            $file = $frame['file'] ?? null;
            $line = $frame['line'] ?? null;

            if (!is_string($file) || !is_int($line) || $line <= 0) {
                continue;
            }

            if ($this->isVendorPath($file)) {
                continue;
            }

            $entry = [
                'file'     => $file,
                'line'     => $line,
                // The function called from this frame is on the *next* backtrace entry —
                // PHP records "called by" not "currently executing".
                'function' => $this->describeCallee($bt[$i + 1] ?? null),
            ];

            if ($top === null) {
                $top = $entry;
            }

            $stack[] = $entry;
        }

        if ($top === null) {
            return null;
        }

        return [
            'file'        => $top['file'],
            'line'        => $top['line'],
            'snippet'     => $statementSnippet
                ? $this->statementSnippet($top['file'], $top['line'])
                : $this->snippet($top['file'], $top['line']),
            'frame_stack' => [],
            'stack'       => $stack,
        ];
    }

    /**
     * @param  array<string, mixed>|null $frame
     */
    private function describeCallee(?array $frame): string
    {
        if ($frame === null) {
            return '';
        }
        $class    = $frame['class']    ?? '';
        $type     = $frame['type']     ?? '';
        $function = $frame['function'] ?? '';
        return $class !== ''
            ? sprintf('%s%s%s', $class, $type, $function)
            : (string) $function;
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
     * Statement-aware snippet — captures the full PHP statement that the
     * offending line is part of, so multi-line Eloquent / Builder chains
     * land in the panel as one cohesive block.
     *
     * Heuristic: walk backwards from $line until we hit a line whose trimmed
     * tail is `;`, `{`, `}`, or PHP open tag — that's the previous statement
     * boundary. Walk forward from $line until we hit a line ending with `;` —
     * that's the end. Capture everything in between.
     *
     * For chains like:
     *   $users = User::query()
     *       ->where('status', 'active')
     *       ->orderBy('id')
     *       ->get();
     * we end up with the four-line statement instead of three lines of
     * surrounding context.
     *
     * @return list<array{number: int, source: string}>
     */
    private function statementSnippet(string $file, int $line): array
    {
        if (!is_readable($file)) {
            return [];
        }
        $lines = @file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false || $line <= 0 || $line > count($lines)) {
            return [];
        }

        $idx       = $line - 1; // zero-based
        $startIdx  = $idx;
        $endIdx    = $idx;
        $maxSpan   = 30; // safety cap so a malformed file can't blow up the panel

        // Walk backward to find the start of the statement.
        for ($i = $idx - 1; $i >= 0 && ($idx - $i) <= $maxSpan; $i--) {
            $trim = rtrim($lines[$i]);
            $tail = substr($trim, -1);
            if ($trim === '' || $trim === '<?php' || $tail === ';' || $tail === '{' || $tail === '}') {
                break;
            }
            $startIdx = $i;
        }

        // Walk forward to the line that closes the statement.
        $countLines = count($lines);
        for ($i = $idx; $i < $countLines && ($i - $idx) <= $maxSpan; $i++) {
            $endIdx = $i;
            $trim   = rtrim($lines[$i]);
            if (str_ends_with($trim, ';')) {
                break;
            }
        }

        $out = [];
        for ($i = $startIdx; $i <= $endIdx; $i++) {
            $out[] = ['number' => $i + 1, 'source' => $lines[$i]];
        }
        return $out;
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
