#!/usr/bin/env bash
# Head-to-head bench: periscope vs xdebug on fib(25) (~242k recursive calls).
# Requires:
#   - make extension          (builds periscope.so)
#   - pecl install xdebug     (xdebug 3.x for PHP 8.3)
set -euo pipefail

cd "$(dirname "$0")/.."

PERI_SO="$(pwd)/extension/modules/periscope.so"
XDEBUG_SO="${XDEBUG_SO:-/opt/homebrew/Cellar/php@8.3/8.3.22/pecl/20230831/xdebug.so}"
PHP="${PHP:-php}"
TRACE_OUT="${TRACE_OUT:-/tmp/xdebug-trace}"

[ -f "$PERI_SO" ]   || { echo "build periscope first: make extension" >&2; exit 1; }
[ -f "$XDEBUG_SO" ] || { echo "xdebug.so not at $XDEBUG_SO — set XDEBUG_SO env" >&2; exit 1; }
mkdir -p "$TRACE_OUT"

bench_php="$(mktemp /tmp/periscope-bench-XXXXX.php)"
trap "rm -f $bench_php" EXIT
cat > "$bench_php" <<'EOF'
<?php
declare(strict_types=1);
function fib(int $n): int { return $n < 2 ? $n : fib($n-1) + fib($n-2); }
fib(15);                        // warmup
$start = microtime(true);
$result = fib(25);
$end = microtime(true);
fprintf(STDERR, "fib(25) = %d  in %.3fms\n", $result, ($end - $start) * 1000);
EOF

run() {
    local label="$1"; shift
    printf "  %-55s " "$label"
    "$@" "$bench_php" 2>&1 1>/dev/null | grep "fib(25)" | head -1 || true
}

echo "Head-to-head: periscope vs xdebug on fib(25)"
echo "============================================="
echo ""
echo "Periscope:"
run "no extension (baseline)" \
    $PHP -n
run "kill switch (loaded, disabled)" \
    $PHP -n -d extension="$PERI_SO" -d periscope.disabled=1
run "namespace_filter='Foo\\\\' (no match)" \
    $PHP -n -d extension="$PERI_SO" -d periscope.namespace_filter='Foo\\'
run "full capture (every call, vars+types+timings)" \
    $PHP -n -d extension="$PERI_SO"

echo ""
echo "Xdebug 3.x:"
run "mode=off" \
    $PHP -n -d zend_extension="$XDEBUG_SO" -d xdebug.mode=off
run "mode=develop" \
    $PHP -n -d zend_extension="$XDEBUG_SO" -d xdebug.mode=develop
run "mode=trace (call records, no vars)" \
    $PHP -n -d zend_extension="$XDEBUG_SO" -d xdebug.mode=trace -d xdebug.output_dir="$TRACE_OUT" -d xdebug.start_with_request=yes
run "mode=profile (callgrind, no vars)" \
    $PHP -n -d zend_extension="$XDEBUG_SO" -d xdebug.mode=profile -d xdebug.output_dir="$TRACE_OUT" -d xdebug.start_with_request=yes

# ---------------------------------------------------------------------------
# Phase 5 scenario — 600 mixed observable events through the Laravel adapter.
# Measures the *marginal* cost of QueryHook/CacheHook/LogHook/EventHook firing
# real events — the dimension xdebug can't observe at all (it traces functions,
# not Laravel events). Bench is otherwise apples-to-apples on the same fixture.
# ---------------------------------------------------------------------------

LARAVEL_FIXTURE="tests/perf/laravel-load.php"
[ -f "$LARAVEL_FIXTURE" ] || { echo "missing $LARAVEL_FIXTURE" >&2; exit 1; }

# The fixture needs Laravel's autoloader (loads Testbench), so we don't pass
# `-n` (no-INI) like the fib bench — composer-installed extensions that the
# adapter relies on (json, openssl, etc.) need to load. We pass only the
# extensions we're benching as explicit `-d extension=…`.
run_full() {
    local label="$1"; shift
    printf "  %-55s " "$label"
    # Discard stderr (full-capture periscope is *very* verbose); fixture
    # prints its timing line to stdout so we only need to filter that.
    PHP_INI_SCAN_DIR=/dev/null "$@" "$LARAVEL_FIXTURE" 2>/dev/null \
        | grep "laravel-load" | head -1 || true
}

echo ""
echo "Phase 5 scenario: 600 events through Laravel hooks"
echo "==================================================="
echo ""
echo "Periscope:"
run_full "no extension (Laravel adapter idle)" \
    "$PHP"
run_full "kill switch (loaded, disabled)" \
    "$PHP" -d extension="$PERI_SO" -d periscope.disabled=1
run_full "full capture (every call + every event)" \
    "$PHP" -d extension="$PERI_SO" -d periscope.skip_internal=1 -d periscope.trace_dir="$TRACE_OUT"

echo ""
echo "Xdebug 3.x (function-level only — no Laravel-event observability):"
run_full "mode=off" \
    "$PHP" -d zend_extension="$XDEBUG_SO" -d xdebug.mode=off
run_full "mode=develop" \
    "$PHP" -d zend_extension="$XDEBUG_SO" -d xdebug.mode=develop
run_full "mode=trace (call records, no vars)" \
    "$PHP" -d zend_extension="$XDEBUG_SO" -d xdebug.mode=trace \
    -d xdebug.output_dir="$TRACE_OUT" -d xdebug.start_with_request=yes
