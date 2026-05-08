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
echo "All Phase 1 smoke tests passed."
