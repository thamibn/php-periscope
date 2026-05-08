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
