#!/usr/bin/env bash
# Phase 1 smoke tests. Things that .phpt can't easily express.
set -euo pipefail

cd "$(dirname "$0")/.."
SO="$(pwd)/extension/modules/periscope.so"
PHP="${PHP:-php}"

if [ ! -f "$SO" ]; then
  echo "FAIL: $SO not built. Run 'make extension' first." >&2
  exit 1
fi

pass() { printf "  \033[32mPASS\033[0m %s\n" "$1"; }
fail() { printf "  \033[31mFAIL\033[0m %s\n" "$1"; exit 1; }

echo "Phase 1 smoke tests"
echo "==================="

# 1. -m lists the extension
out="$($PHP -d extension="$SO" -m 2>/dev/null | grep -c '^periscope$' || true)"
[ "$out" = "1" ] && pass "php -m lists 'periscope'" || fail "php -m did not list periscope (got: $out)"

# 2. stderr banner appears once on startup
banner_count="$($PHP -d extension="$SO" -r 'echo "x";' 2>&1 1>/dev/null | grep -c 'periscope loaded' || true)"
[ "$banner_count" = "1" ] && pass "stderr banner emitted exactly once" || fail "expected 1 banner, got $banner_count"

# 3. Banner does NOT appear on stdout (would break SAPI consumers)
stdout_only="$($PHP -d extension="$SO" -r 'echo "x";' 2>/dev/null)"
[ "$stdout_only" = "x" ] && pass "stdout is uncontaminated" || fail "stdout was: $stdout_only"

# 4. Exit code is 0
$PHP -d extension="$SO" -r 'exit(0);' >/dev/null 2>&1 && pass "exit code 0 on clean run" || fail "non-zero exit"

# 5. 100 invocations don't crash (rough RSHUTDOWN sanity)
for i in $(seq 1 100); do
  $PHP -d extension="$SO" -r 'echo "";' >/dev/null 2>&1 || fail "crashed on invocation $i"
done
pass "100 sequential invocations without crash"

echo ""
echo "Phase 2 smoke tests"
echo "==================="

# 6. Observer fires on the integration fixture — enter/exit balance
fixture="tests/integration/hello.php"
[ -f "$fixture" ] || fail "fixture $fixture missing"
log="$($PHP -d extension="$SO" "$fixture" 2>&1 1>/dev/null)"
enters="$(printf '%s\n' "$log" | grep -c '\[periscope\] enter ' || true)"
exits="$(printf '%s\n' "$log" | grep -c '\[periscope\] exit  ' || true)"
[ "$enters" -gt 0 ] && [ "$enters" = "$exits" ] && \
  pass "fixture: $enters enters / $exits exits balanced" || \
  fail "fixture: $enters enters vs $exits exits — unbalanced"

# 7. Fixture observed expected method
printf '%s\n' "$log" | grep -q 'Greeter::greet' && \
  pass "fixture: observed Greeter::greet method call" || \
  fail "fixture: did not observe Greeter::greet"

# 8. Recursion depth tracking — fib(5) hits depth >= 6
printf '%s\n' "$log" | grep -q '@depth=6' && \
  pass "fixture: recursion @depth=6 reached (fib)" || \
  fail "fixture: did not see @depth=6"

# 9. Declared types appear in argument dump
printf '%s\n' "$log" | grep -q 'int $n = int(' && \
  pass "fixture: declared parameter types + names dumped" || \
  fail "fixture: missing typed parameter dump"

echo ""
echo "Phase 3 smoke tests"
echo "==================="

# 10. Capture fixture exercises objects, enums, cycles, lazy proxies
capture_log="$($PHP -d extension="$SO" tests/integration/capture.php 2>&1 1>/dev/null)"
printf '%s\n' "$capture_log" | grep -q 'enum(Status::Active' && \
  pass "capture: backed enum shown with case + value" || \
  fail "capture: backed enum not captured properly"
printf '%s\n' "$capture_log" | grep -q 'enum(Tier::Pro)' && \
  pass "capture: pure enum shown without trailing '='" || \
  fail "capture: pure enum format wrong"
printf '%s\n' "$capture_log" | grep -q 'recursion ↻' && \
  pass "capture: cycle detection emits back-ref" || \
  fail "capture: no cycle marker"
printf '%s\n' "$capture_log" | grep -q '<lazy>' && \
  pass "capture: __get-having object tagged <lazy>" || \
  fail "capture: lazy proxy not detected"
printf '%s\n' "$capture_log" | grep -q '+ro:id' && \
  pass "capture: readonly property visibility marker present" || \
  fail "capture: readonly visibility marker missing"

# 11. Namespace filter cuts noise dramatically
fixture="tests/integration/hello.php"
unfiltered="$($PHP -d extension="$SO" "$fixture" 2>&1 1>/dev/null | grep -c '\[periscope\] enter ' || true)"
filtered="$($PHP -d extension="$SO" -d periscope.namespace_filter='Greeter' "$fixture" 2>&1 1>/dev/null | grep -c '\[periscope\] enter ' || true)"
[ "$filtered" -lt "$unfiltered" ] && \
  pass "namespace_filter cuts observed calls ($unfiltered -> $filtered)" || \
  fail "namespace_filter did not reduce calls (still $filtered)"

# 12. Kill switch silences everything
killed="$(PERISCOPE_DISABLE=1 $PHP -d extension="$SO" "$fixture" 2>&1 1>/dev/null | grep -c '\[periscope\] enter ' || true)"
[ "$killed" = "0" ] && \
  pass "PERISCOPE_DISABLE=1 produces zero observation lines" || \
  fail "kill switch leaked $killed observations"

echo ""
echo "Phase 4 smoke tests"
echo "==================="

# 13. trace_dir writes a .cptrace file
TRACE_DIR=$(mktemp -d /tmp/periscope-smoke-XXXXX)
$PHP -d extension="$SO" -d periscope.trace_dir="$TRACE_DIR" "$fixture" >/dev/null 2>&1
TRACE_FILE=$(ls "$TRACE_DIR"/*.cptrace 2>/dev/null | head -1)
[ -n "$TRACE_FILE" ] && [ -s "$TRACE_FILE" ] && \
  pass "trace_dir produces non-empty .cptrace ($(wc -c < "$TRACE_FILE") bytes)" || \
  fail "trace_dir did not produce a .cptrace file"

# 14. Generated trace is valid Cap'n Proto (parseable by daemon reader)
DUMP_BIN="$(pwd)/daemon/target/debug/periscope-dump"
if [ -x "$DUMP_BIN" ]; then
    DUMP_OUT="$($DUMP_BIN "$TRACE_FILE" 2>&1)"
    echo "$DUMP_OUT" | grep -q "php           8.3" && \
      pass "Rust reader parses trace and shows PHP 8.3 meta" || \
      fail "Rust reader failed to parse trace"
    echo "$DUMP_OUT" | grep -q "frames " && \
      pass "Rust reader enumerates frames" || \
      fail "Rust reader saw no frames"
else
    echo "  SKIP  Rust dump binary not built (run 'cargo build' in daemon/)"
fi
rm -rf "$TRACE_DIR"

echo ""
echo "All smoke tests passed."
