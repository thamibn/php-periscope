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
echo "All smoke tests passed."
