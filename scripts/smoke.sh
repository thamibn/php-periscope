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

# 15. Trace retention sweep — old files get pruned at RINIT
RTD=$(mktemp -d /tmp/periscope-smoke-retention-XXXXX)
for i in 1 2 3 4 5; do printf "fake" > "$RTD/old-$i.cptrace"; sleep 0.01; done
$PHP -d extension="$SO" -d periscope.trace_dir="$RTD" -d periscope.max_traces=2 -d periscope.max_trace_age_seconds=0 -r 'echo "";' >/dev/null 2>&1
remaining="$(ls "$RTD"/*.cptrace 2>/dev/null | wc -l | tr -d ' ')"
[ "$remaining" -le "3" ] && \
  pass "retention sweep enforced max_traces=2 (5 old + 1 new -> $remaining files)" || \
  fail "retention left $remaining files (expected <= 3)"
rm -rf "$RTD"

echo ""
echo "Phase 6 smoke tests"
echo "==================="

DAEMON_BIN="$(pwd)/daemon/target/debug/periscope-daemon"
if [ -x "$DAEMON_BIN" ]; then
    # 16. Generate a trace then point the daemon at the dir; hit a few /api/*
    SMOKE_TRACE_DIR=$(mktemp -d /tmp/periscope-smoke-api-XXXXX)
    SMOKE_PORT=29998
    $PHP -d extension="$SO" -d periscope.trace_dir="$SMOKE_TRACE_DIR" \
        -r 'function greet($n){ return "hi $n"; } echo greet("world");' >/dev/null 2>&1
    TRACE_FILE=$(ls "$SMOKE_TRACE_DIR"/*.cptrace 2>/dev/null | head -1)
    TRACE_ID=$(basename "$TRACE_FILE" .cptrace)
    [ -n "$TRACE_ID" ] || fail "Phase 6: failed to seed a trace for daemon"

    PERISCOPE_LOG=warn "$DAEMON_BIN" \
        --trace-dir "$SMOKE_TRACE_DIR" \
        --no-socket \
        --listen "127.0.0.1:$SMOKE_PORT" \
        --project-root "$(pwd)" >/dev/null 2>&1 &
    DAEMON_PID=$!
    # Tiny startup wait — daemon is sub-100ms in practice.
    sleep 0.4

    HEALTH=$(curl -fsS "http://127.0.0.1:$SMOKE_PORT/api/health" 2>/dev/null || true)
    [ -n "$HEALTH" ] && echo "$HEALTH" | grep -q '"status":"ok"' && \
      pass "/api/health responds {status:ok}" || \
      fail "/api/health failed (got: $HEALTH)"

    LIST=$(curl -fsS "http://127.0.0.1:$SMOKE_PORT/api/traces" 2>/dev/null || true)
    echo "$LIST" | grep -q "$TRACE_ID" && \
      pass "/api/traces lists the seeded trace" || \
      fail "/api/traces did not list trace $TRACE_ID"

    SUM=$(curl -fsS "http://127.0.0.1:$SMOKE_PORT/api/traces/$TRACE_ID/summary" 2>/dev/null || true)
    echo "$SUM" | grep -q '"queries"' && \
      pass "/api/traces/{id}/summary returns aggregate panels" || \
      fail "/api/traces/{id}/summary missing queries panel"

    INS=$(curl -fsS "http://127.0.0.1:$SMOKE_PORT/api/traces/$TRACE_ID/insights" 2>/dev/null || true)
    echo "$INS" | grep -q '"slow_frames"' && \
      pass "/api/traces/{id}/insights returns deterministic insights" || \
      fail "/api/traces/{id}/insights missing slow_frames"

    TL=$(curl -fsS "http://127.0.0.1:$SMOKE_PORT/api/traces/$TRACE_ID/timeline" 2>/dev/null || true)
    echo "$TL" | grep -q '"frame_enter"' && \
      pass "/api/traces/{id}/timeline returns frame_enter entries" || \
      fail "/api/traces/{id}/timeline missing frame_enter"

    # Phase 7: replay state at time T
    ST=$(curl -fsS "http://127.0.0.1:$SMOKE_PORT/api/traces/$TRACE_ID/state?at=0" 2>/dev/null || true)
    echo "$ST" | grep -q '"current_frame"' && \
      pass "/api/traces/{id}/state?at= reconstructs frame at time" || \
      fail "/api/traces/{id}/state?at= missing current_frame"
    echo "$ST" | grep -q '"stack"' && \
      pass "/api/traces/{id}/state returns call stack" || \
      fail "/api/traces/{id}/state missing stack"

    # /api/file path traversal is blocked.
    FORBIDDEN_CODE=$(curl -s -o /dev/null -w '%{http_code}' \
        "http://127.0.0.1:$SMOKE_PORT/api/file?path=/etc/passwd")
    [ "$FORBIDDEN_CODE" = "403" ] && \
      pass "/api/file refuses paths outside the project root (403)" || \
      fail "/api/file traversal returned $FORBIDDEN_CODE (expected 403)"

    # Clean-up endpoints (Phase 8b add)
    DEL=$(curl -fsS -X DELETE "http://127.0.0.1:$SMOKE_PORT/api/traces/$TRACE_ID" 2>/dev/null || true)
    echo "$DEL" | grep -q '"deleted":1' && \
      pass "DELETE /api/traces/{id} removes one trace" || \
      fail "DELETE /api/traces/{id} did not return deleted:1 (got: $DEL)"
    [ ! -f "$SMOKE_TRACE_DIR/$TRACE_ID.cptrace" ] && \
      pass "DELETE /api/traces/{id} actually removed the file" || \
      fail "trace file still on disk after DELETE"

    kill "$DAEMON_PID" >/dev/null 2>&1 || true
    wait "$DAEMON_PID" >/dev/null 2>&1 || true
    rm -rf "$SMOKE_TRACE_DIR"
else
    echo "  SKIP  periscope-daemon not built (run 'cargo build' in daemon/)"
fi

echo ""
echo "Phase 8a smoke tests"
echo "===================="

if [ -x "$DAEMON_BIN" ]; then
    # 17. End-to-end: extension pushes request_finished over the unix socket
    # and the daemon fans it out as text to anyone listening on the LinkBus.
    # We tap the bus via the ws_fanout integration test (already passing under
    # cargo). Here we just exercise the C-side push: spawn the daemon with
    # the socket enabled, run a PHP script with PERISCOPE_DAEMON_SOCKET set,
    # and confirm the daemon log shows a hello + request_finished.
    SMOKE_DIR=$(mktemp -d /tmp/periscope-smoke-8a-XXXXX)
    SOCK="$SMOKE_DIR/daemon.sock"
    DAEMON_LOG="$SMOKE_DIR/daemon.log"

    # Run daemon with debug logging so we can grep it.
    PERISCOPE_LOG=debug "$DAEMON_BIN" \
        --trace-dir "$SMOKE_DIR" \
        --listen "127.0.0.1:29996" \
        --socket "$SOCK" \
        --project-root "$(pwd)" >"$DAEMON_LOG" 2>&1 &
    DAEMON_PID=$!
    # Wait for the unix socket to appear.
    for i in 1 2 3 4 5 6 7 8 9 10; do
        [ -S "$SOCK" ] && break
        sleep 0.1
    done
    [ -S "$SOCK" ] || { kill "$DAEMON_PID" 2>/dev/null; fail "8a: daemon socket never appeared"; }

    # Run PHP with the daemon link enabled.
    PERISCOPE_DAEMON_SOCKET="$SOCK" \
      $PHP -d extension="$SO" -d periscope.trace_dir="$SMOKE_DIR" \
        -r 'echo "ok\n";' >/dev/null 2>&1

    # Daemon needs a moment to flush its tracing.
    sleep 0.3
    kill "$DAEMON_PID" >/dev/null 2>&1 || true
    wait "$DAEMON_PID" >/dev/null 2>&1 || true

    grep -q 'Hello' "$DAEMON_LOG" && \
      pass "ext-link: daemon received Hello from C extension" || \
      fail "ext-link: daemon never logged Hello (log: $DAEMON_LOG)"
    grep -q 'RequestFinished' "$DAEMON_LOG" && \
      pass "ext-link: daemon received RequestFinished after RSHUTDOWN" || \
      fail "ext-link: daemon never logged RequestFinished (log: $DAEMON_LOG)"

    rm -rf "$SMOKE_DIR"
else
    echo "  SKIP  periscope-daemon not built"
fi

echo ""
echo "All smoke tests passed."
