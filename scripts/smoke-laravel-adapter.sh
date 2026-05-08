#!/usr/bin/env bash
# Phase 5d integration smoke.
#
# Boots a real Laravel kernel via Orchestra Testbench, registers the
# PeriscopeServiceProvider (no fakes — the actual ExtensionBridge), exercises
# every Phase 5 watcher with one or more events, then dumps the resulting
# `.cptrace` and asserts every expected event type is present with a call
# site that resolves to the scenario script (not to adapter source).
#
# Run from the repo root:  bash scripts/smoke-laravel-adapter.sh
set -euo pipefail

cd "$(dirname "$0")/.."
ROOT="$(pwd)"
SO="$ROOT/extension/modules/periscope.so"
DUMP="$ROOT/daemon/target/debug/periscope-dump"
SCENARIO="$ROOT/tests/integration/laravel/scenario.php"
PHP="${PHP:-/opt/homebrew/opt/php@8.3/bin/php}"

[ -f "$SO" ]       || { echo "FAIL: $SO missing — run 'make extension'"; exit 1; }
[ -f "$DUMP" ]     || { echo "FAIL: $DUMP missing — run 'cd daemon && cargo build'"; exit 1; }
[ -f "$SCENARIO" ] || { echo "FAIL: $SCENARIO missing"; exit 1; }

pass() { printf "  \033[32mPASS\033[0m %s\n" "$1"; }
fail() { printf "  \033[31mFAIL\033[0m %s\n" "$1"; exit 1; }

TRACE_DIR="$(mktemp -d -t periscope-smoke-XXXXXX)"
trap 'rm -rf "$TRACE_DIR"' EXIT

echo "Phase 5d Laravel adapter integration smoke"
echo "==========================================="
echo "trace dir: $TRACE_DIR"

# Run the scenario with the C extension loaded.
PHP_INI_SCAN_DIR=/dev/null "$PHP" \
    -d extension="$SO" \
    -d periscope.skip_internal=1 \
    -d periscope.trace_dir="$TRACE_DIR" \
    "$SCENARIO" >/dev/null 2>"$TRACE_DIR/scenario.err" \
    || fail "scenario script crashed (see $TRACE_DIR/scenario.err)"

TRACE="$(ls -t "$TRACE_DIR"/*.cptrace 2>/dev/null | head -1)"
[ -n "$TRACE" ] || fail "no trace file produced in $TRACE_DIR"
pass "scenario produced trace ($TRACE)"

JSON="$("$DUMP" --json "$TRACE")"
[ -n "$JSON" ] || fail "periscope-dump --json produced no output"

# Required event types — each one must appear at least once in the trace.
for required in sql cache log event exception n_plus_one_warning; do
    count="$(printf '%s' "$JSON" | python3 -c "
import json, sys
data = json.loads(sys.stdin.read())
print(sum(1 for e in data.get('observability_events', []) if e.get('type') == '$required'))
")"
    [ "$count" -gt 0 ] && pass "$required: $count event(s)" \
                       || fail "$required: 0 events (expected ≥ 1)"
done

# Every event must carry a call site that resolves to the scenario file
# (not to adapter source — that would mean vendor_skip is broken).
mismatch="$(printf '%s' "$JSON" | python3 -c "
import json, sys
data = json.loads(sys.stdin.read())
events = data.get('observability_events', [])
bad = []
for e in events:
    cs = e.get('user_call_site') or {}
    f = cs.get('file', '')
    if not f.endswith('scenario.php'):
        bad.append(f'{e[\"type\"]}@{f}')
print(len(bad))
print('\\n'.join(bad[:5]))
")"
bad_count="$(printf '%s' "$mismatch" | head -1)"
[ "$bad_count" = "0" ] && pass "every event resolves to scenario.php (vendor_skip works)" \
                       || fail "$bad_count event(s) resolved to adapter/vendor source: $(printf '%s' "$mismatch" | tail -n +2 | tr '\n' ' ')"

echo ""
echo "All assertions passed."
