# php-periscope MVP Implementation Plan

**Date:** 2026-05-08
**Author:** Thamsanca Ntuli (with Claude Code)
**Project:** php-periscope — live observability + time-travel debugger for PHP/Laravel
**Status:** Draft v1 — ready to start Phase 1
**Tagline:** *See into your PHP request.*

---

## Overview

php-periscope pauses a running PHP/Laravel request and shows the developer **everything** that happened up to the paused line — variables in scope, every SQL query, every log line, every dispatched job, every fired event, every cache hit, every outbound HTTP call, every Redis command — in one live browser UI with a timeline scrubber that lets you step backward through the request.

It is the first PHP debugger to combine three things that today live in three separate tools:

| Today's tool | What it does | Limitation |
|--------------|--------------|------------|
| Xdebug | Step debugging, variables, breakpoints | No observability; no time-travel; setup is painful |
| Telescope | Queries, logs, jobs, events, cache, mail, requests | Post-mortem only — request must finish first |
| DebugBar | Same data, live | Footer-only UI; no breakpoints; no time-travel |

php-periscope merges all three into one live, interactive UI built on a modern stack (Zend Observer API, Rust, DAP, browser-native UI) with no legacy DBGp, no IDE lock-in, and time-travel as a first-class feature.

---

## Current State Analysis

This is a **greenfield project**. The repo at `/Users/thamsancantuli/Documents/php-periscope` is empty (just `.git`, `docs/`, `thoughts/` directories created during this planning session).

### Reference projects studied

| Project | What we learn from it |
|---------|----------------------|
| **Xdebug** (xdebug/xdebug) | Engine integration patterns, DBGp anti-patterns to avoid, performance pitfalls (50× slowdown in profile mode) |
| **OpenTelemetry-PHP** (open-telemetry/opentelemetry-php-instrumentation) | Modern use of Zend Observer API in production |
| **Tideways** | Sampling-based profiler architecture |
| **Mozilla rr** | Deterministic record-and-replay for native code — the inspiration for time-travel |
| **Laravel Telescope** | Observability event hooks (DB::listen, Log channels, etc.) |
| **VSCode PHP Debug** (xdebug/vscode-php-debug) | DAP-to-DBGp bridge — we'll skip the bridge and speak DAP natively |
| **Spatie Ray** | "Live observability dump" UX — what users expect from a modern dev tool |

### Key constraints discovered

- **Zend Observer API** (PHP 8.0+) is the modern, supported hook mechanism for engine extensions. Older Zend Extension hook macros are still available but Observer API is what new tooling should use.
- **DAP (Debug Adapter Protocol)** is supported by VSCode, Neovim, Zed, Helix, Sublime, and JetBrains products via plugins. Using DAP gives us multi-IDE support from day one, with no per-IDE work.
- **Laravel exposes events for everything we need** — `DB::listen`, `Event::listen('*')`, `Queue::before/after`, mail events, cache events, Redis events, HTTP client middleware. No reflection or monkey-patching required.
- **PECL** is the standard PHP extension distribution channel. **brew tap** is the standard way to ship to macOS dev environments.
- The user's own dev setup has three PHP environments (Docker dev, Docker testing, Valet brew PHP 8.1/8.2/8.3) — and Xdebug is only installed in the testing container. This is the UX gap we want to close.

---

## Desired End State

After MVP ship (~3–4 months part-time / 6–8 weeks full-time AI-assisted):

**A developer can:**

1. `brew install thamibn/php-periscope/php-periscope` (or run a one-line install script).
2. Open VSCode in a Laravel project, install the `php-periscope` extension from the marketplace.
3. Set a breakpoint by clicking a gutter line.
4. Hit the route in their browser.
5. See VSCode pause at the breakpoint **and** automatically open `localhost:9999/periscope` in their browser, showing:
   - Source code with current line highlighted
   - Variables in scope
   - Call stack
   - Every SQL query run so far (with timings, bindings, stack trace, N+1 detection)
   - Every log line written
   - Every event dispatched
   - Every job dispatched (queued but not yet run)
   - Every cache hit/miss
   - Every Redis command
   - Every outbound HTTP call
   - Memory and elapsed time
6. Drag a timeline scrubber backward → see exactly what variables and observable state looked like at any earlier point in this request.
7. Step forward, step over, step out — and **step backward** (DAP `supportsStepBack`).
8. Continue the request and see the response in the browser.

**Verification:**

- End-to-end smoke test: a sample Laravel app in `examples/laravel-demo/` that, when run with periscope attached, hits a known route, pauses at a known breakpoint, shows expected query count and log output in the UI, and successfully scrubs backward.
- Real-world test: clone Laravel, Symfony, or WordPress; run their test suite with periscope loaded; no test-suite regressions, no segfaults under AddressSanitizer.

---

## What We're NOT Doing (v1)

Explicitly out of scope for the MVP. Each is a deliberate cut to keep the project shippable:

- **Production debugging** — sampling, snapshot breakpoints, non-blocking pause. (Deferred to v2; the killer enterprise feature.)
- **Opcode-level / line-level stepping** — only function-boundary stepping in v1. Saves 100× on trace size and replay complexity.
- **Variable mutation tracking** — capture vars only at function entry/exit, not on every assignment.
- **PHP < 8.3 or > 8.3** — single PHP version target for v1.
- **Windows** — macOS + Linux only.
- **Closures, references, circular references, generators, fibers, weak refs, enums-with-magic-methods** — best-effort capture; document edge cases as known gaps. v2 problem.
- **Async runtimes** — Fibers, Swoole, ReactPHP, Frankenphp, Octane. Single-request-thread model only.
- **PhpStorm-specific features** — PhpStorm supports DAP via plugin but its UX is rougher; VSCode is the priority.
- **OpenTelemetry export** — debug events as spans. Deferred.
- **Multi-language framework adapters** — Symfony, WordPress, raw PHP only get basic support; Laravel gets first-class.
- **Mobile / cloud UI** — local browser UI only.
- **Authentication / multi-user** — `localhost:9999` is single-user, no auth.
- **Custom non-DAP protocol** — DAP is the only IDE protocol in v1 (custom protocol deferred to v2 if/when needed for time-travel features DAP can't express well).

---

## Implementation Approach

**Phasing principle: smoke test at every phase boundary.** Each phase ends with a working artifact that can be demoed in isolation. If a phase falls behind, the previous phase still produces value.

**Parallelism principle: serialize until the trace format is frozen, then parallelize.** Phases 1–4 must be sequential (each builds on the previous). Once the trace format is locked at end of Phase 4, the C extension (Phases 1–5) and Rust daemon (Phase 6) can be developed in parallel by separate worktrees / contributors. UI mockup (Phase 9, mockup-only sub-step) can start at any time.

**Quality principle: AddressSanitizer from day one, not added later.** The C extension will be compiled with `-fsanitize=address` in CI from the very first hello-world phase. Memory bugs will be caught at test time with clear errors instead of in production with cryptic segfaults.

**Distribution principle: ship the extension and the daemon together as one install.** Users should never have to install pieces separately. `brew install php-periscope` installs the extension for all brew-managed PHP versions and drops the Rust daemon binary in `$PATH`. The VSCode extension auto-detects and connects.

---

## Phase 1: Hello-World C Extension

### Overview

Prove the build toolchain works. Produce a `.so` file that loads in PHP 8.3 and prints "periscope loaded" on startup. Nothing more.

### Changes Required

#### 1. Repo skeleton

**Files**:
- `extension/config.m4` — autoconf build script (`phpize` target)
- `extension/php_periscope.h` — extension header
- `extension/periscope.c` — extension entry point (MINIT/MSHUTDOWN/RINIT/RSHUTDOWN/MINFO)
- `extension/Makefile.frag` — extra build rules (none needed yet)
- `extension/.gitignore` — ignore `.libs/`, `*.lo`, `modules/`, autoconf output

#### 2. Top-level repo files

**Files**:
- `README.md` — project overview, install (placeholder), status badge
- `LICENSE` — Proprietary, all rights reserved. License model TBD; may change before public release.
- `.editorconfig` — UTF-8, LF, 4-space indent for C, 2 for everything else
- `.gitignore` — top-level
- `Makefile` — top-level orchestration: `make extension`, `make test`, `make clean`, `make asan`

#### 3. CI

**File**: `.github/workflows/ci.yml`

Matrix: macOS-latest + ubuntu-latest, PHP 8.3 only.

Jobs:
- Build extension with `phpize && ./configure && make`
- Build with `-fsanitize=address` (Linux only — macOS ASan + PHP has known issues)
- Load extension with `php -d extension=./modules/periscope.so -m | grep periscope`

### Success Criteria

#### Automated Verification:
- [ ] `make extension` completes with no warnings: `cd extension && phpize && ./configure && make`
- [ ] Extension loads cleanly: `php -d extension=$(pwd)/extension/modules/periscope.so -m | grep periscope` outputs `periscope`
- [ ] AddressSanitizer build is clean: `make asan` produces an ASan-instrumented `.so` that loads without errors
- [ ] CI passes on macOS-latest and ubuntu-latest

#### Manual Verification:
- [ ] On Thami's Macbook (brew PHP 8.3), running `php -d extension=...periscope.so -r 'echo "hi";'` prints `periscope loaded` once on startup, then `hi`
- [ ] No memory growth visible in Activity Monitor across 1000 invocations

**Implementation Note**: Pause after Phase 1 for Thami to confirm the build works on his actual Valet PHP 8.3 (`/opt/homebrew/opt/php@8.3/bin/php`) before proceeding.

---

## Phase 2: Zend Observer API Hooks

### Overview

Use the Zend Observer API to log every PHP function entry and exit, with arguments and return values, to stderr. No on-disk trace yet — just prove we can hook every call deterministically.

### Changes Required

#### 1. Observer registration

**File**: `extension/periscope.c`

Add `MINIT` registration of an Observer factory via `zend_observer_fcall_register()`. The factory returns begin/end handlers that log:
- Function name (including class::method for methods, closure scope for closures)
- Argument count
- Return type and stringified value (best-effort; objects show `Class#id`)
- Wall-clock time entry/exit
- Stack depth

#### 2. Filter list

**File**: `extension/periscope_filter.c` (new)
**File**: `extension/periscope_filter.h` (new)

A static allow/deny list to skip uninteresting functions (`strlen`, `count`, etc.). Configurable via `php.ini` setting `periscope.skip_internal=1`. v1 default: skip internal functions, observe userland only.

#### 3. Test app

**File**: `tests/integration/hello.php`

A short PHP script that calls a few user functions, including one with arguments and a return value. Used as a fixture by the smoke test.

### Success Criteria

#### Automated Verification:
- [ ] Running `php -d extension=...periscope.so tests/integration/hello.php 2>&1` emits structured stderr lines like `[periscope] enter foo(int 42, string "x")` and `[periscope] exit foo -> bool true (3.2ms)`
- [ ] Same script under ASan: `make test-asan` passes with zero memory errors
- [ ] Function-call log matches a golden file: `tests/integration/hello.expected.log`
- [ ] Recursion depth tested up to 10000 frames without stack overflow

#### Manual Verification:
- [ ] Run a real Laravel route (`/` on a fresh `laravel new` app) with the extension loaded — observe a sane volume of function calls (~5000-15000), no crashes, no warnings in `storage/logs/laravel.log`
- [ ] Time the same route with and without the extension; record overhead. Target: < 5× slowdown when logging to stderr (will be much less when logging to a binary trace in Phase 4)

---

## Phase 3: Variable Capture (the Cliff)

### Overview

The hardest C work in the entire project. Capture PHP variables (zvals) at function entry and exit and serialize them to a stable, language-agnostic representation. This is where most segfaults will live.

### Changes Required

#### 1. zval-to-snapshot serializer

**File**: `extension/periscope_capture.c` (new)
**File**: `extension/periscope_capture.h` (new)

Recursive serializer with a depth limit (default 5) and size limit (default 1MB per value). Handles:

| zval type | Strategy |
|-----------|----------|
| `IS_NULL`, `IS_TRUE`, `IS_FALSE` | Direct |
| `IS_LONG`, `IS_DOUBLE` | Direct |
| `IS_STRING` | Copy with length cap (default 4KB, configurable) |
| `IS_ARRAY` | Recurse with depth tracking |
| `IS_OBJECT` | Class name + property list (only public + protected, no `__get`/`__set` triggering) + object ID for identity |
| `IS_REFERENCE` | Resolve to underlying zval, mark as reference |
| `IS_RESOURCE` | Type name + resource ID, no contents |
| Closures, generators, fibers | Mark as opaque, capture class name only — known gap |

#### 2. Cycle detection

**File**: `extension/periscope_capture.c`

Track visited objects in an internal hash table during recursion. If we re-encounter an object, emit a back-reference token instead of recursing. Prevents infinite loops on circular references.

#### 3. Magic method safety

**File**: `extension/periscope_capture.c`

When serializing object properties, read the property table directly via `zend_std_get_properties()` rather than going through `Z_OBJ_HT(obj)->read_property` — this bypasses `__get` so observation doesn't trigger user code. Document this trade-off in `docs/ARCHITECTURE.md`.

#### 4. Wire into Phase 2 hooks

**File**: `extension/periscope.c`

Replace stderr logging with a structured snapshot of args (entry) and return value (exit), emitted as a (still-stderr-only) JSON line. Trace-format work follows in Phase 4.

### Success Criteria

#### Automated Verification:
- [ ] `tests/unit/capture/` Pest tests cover all zval types with snapshot fixtures
- [ ] Circular reference test does not OOM and emits a back-reference: `tests/unit/capture/circular.phpt`
- [ ] Deep array (nested 100 levels) is truncated to depth limit, not crashed: `tests/unit/capture/deep_array.phpt`
- [ ] Long string (10MB) is truncated to size limit, not stored fully: `tests/unit/capture/long_string.phpt`
- [ ] Magic method test: serializing an object with `__get` does not invoke `__get`: `tests/unit/capture/magic_get.phpt`
- [ ] AddressSanitizer clean across all capture tests
- [ ] Valgrind clean on Linux (`make valgrind` target)

#### Manual Verification:
- [ ] Capture a real Laravel `User` model with relationships loaded — verify nothing infinite-loops, all expected fields are present
- [ ] Capture a `Illuminate\Support\Collection` with 1000 items — verify it truncates sensibly
- [ ] Capture a `Closure` — verify it shows scope class name and parameter list, doesn't crash

**Implementation Note**: This is the highest-risk phase. Budget extra time. Pause after Phase 3 for a thorough manual test against a real Laravel codebase before moving on. If variable capture is rough, everything else compounds the problems.

---

## Phase 4: Trace Format & On-Disk Storage

### Overview

Replace stderr emission with a real binary trace format written to disk. This unblocks parallel work on the Rust replay engine in Phase 7.

### Decision: Protobuf vs Cap'n Proto

| Criterion | Protobuf | Cap'n Proto |
|-----------|----------|-------------|
| C library availability | nanopb, protobuf-c — mature | capnp-c — less mature, smaller community |
| Rust support | `prost` — excellent | `capnp` — good |
| Schema evolution | Excellent (well-known field rules) | Good |
| Encoded size | Smaller (varint) | Slightly larger |
| Decode cost | Parse step required | Zero-copy (mmap-friendly) |
| Replay scrubbing | Scan + index | Random access via offsets |

**Decision: Cap'n Proto.** Time-travel scrubbing benefits from zero-copy random access. We mmap the trace file in Rust and seek to any frame instantly. This is the right call for a replay-heavy workload, even though Protobuf is more familiar.

### Changes Required

#### 1. Schema

**File**: `proto/trace.capnp`

```capnp
@0xb87f8e23a9f4c2d1;

struct Trace {
  meta @0 :Meta;
  frames @1 :List(Frame);
  observabilityEvents @2 :List(ObservabilityEvent);
}

struct Meta {
  phpVersion @0 :Text;
  startedAtUnixMicros @1 :UInt64;
  workingDir @2 :Text;
  entryPoint @3 :Text;
}

struct Frame {
  id @0 :UInt32;
  parentId @1 :UInt32;     # 0 = root
  function @2 :Text;
  file @3 :Text;
  line @4 :UInt32;
  enterMicros @5 :UInt64;  # offset from Meta.startedAtUnixMicros
  exitMicros @6 :UInt64;
  args @7 :List(Value);
  returnValue @8 :Value;
  observabilityEventIds @9 :List(UInt32);  # events that fired during this frame
}

struct Value {
  union {
    nullVal @0 :Void;
    boolVal @1 :Bool;
    intVal @2 :Int64;
    floatVal @3 :Float64;
    stringVal @4 :Text;
    arrayVal @5 :List(KeyValue);
    objectVal @6 :ObjectSnapshot;
    backref @7 :UInt32;       # cycle break
    truncated @8 :Truncation; # depth/size limit hit
    opaque @9 :Text;          # closures, resources, etc.
  }
}

struct KeyValue { key @0 :Text; value @1 :Value; }
struct ObjectSnapshot { className @0 :Text; objectId @1 :UInt32; properties @2 :List(KeyValue); }
struct Truncation { reason @0 :Text; }

# ObservabilityEvent populated in Phase 5 (Laravel adapter)
struct ObservabilityEvent {
  id @0 :UInt32;
  unionType :union {
    sqlQuery @1 :SqlQueryEvent;
    logLine @2 :LogEvent;
    cacheOp @3 :CacheEvent;
    httpCall @4 :HttpEvent;
    redisOp @5 :RedisEvent;
    eventDispatched @6 :EventEvent;
    jobDispatched @7 :JobEvent;
    mailSent @8 :MailEvent;
  }
  atMicros @9 :UInt64;
}

# (event sub-structs defined in proto/events.capnp, see Phase 5)
```

#### 2. C-side writer

**Files**:
- `extension/periscope_trace.c` (new)
- `extension/periscope_trace.h` (new)
- Vendor `capnp-c` into `extension/third_party/` or use `pkg-config --cflags --libs capnp_c`

A streaming writer that opens a trace file on RINIT (one trace per request), appends frames as they close, and finalizes on RSHUTDOWN. Trace location: `${PERISCOPE_TRACE_DIR:-/tmp/periscope}/{request_id}.cptrace`.

#### 3. Rust-side reader (skeleton)

**File**: `daemon/Cargo.toml` (new)
**File**: `daemon/src/trace.rs` (new)

Use `capnp` crate. mmap the trace file. Provide a `Trace::open(path)` API that returns a struct exposing `frames()`, `events()`, and `frame_at(time_micros)` for replay.

### Success Criteria

#### Automated Verification:
- [ ] Running the Phase 2 hello.php emits a `.cptrace` file: `ls /tmp/periscope/*.cptrace`
- [ ] Rust reader can open and dump the trace: `cargo run --bin periscope-dump /tmp/periscope/*.cptrace` shows expected frames
- [ ] Round-trip test: every Value variant survives a write-then-read cycle: `cargo test --package periscope-daemon trace::roundtrip`
- [ ] Trace file size for a hello-world Laravel route is < 5MB
- [ ] AddressSanitizer clean

#### Manual Verification:
- [ ] Open a generated trace in a hex viewer and confirm Cap'n Proto header is well-formed
- [ ] Run a real Laravel route, observe trace size and write performance — write must not visibly stall the request

---

## Phase 5: Laravel Adapter (Composer Package)

### Overview

A Composer-installable Laravel package that registers framework hooks (DB::listen, Log channels, Event::listen('*'), Queue::before, etc.) and forwards events to the C extension via a small FFI surface. Without this, periscope is "Xdebug with extra steps." With this, it's the killer feature.

### Changes Required

#### 1. Composer package skeleton

**Path**: `laravel-adapter/`

**Files**:
- `laravel-adapter/composer.json`
- `laravel-adapter/src/PeriscopeServiceProvider.php`
- `laravel-adapter/src/Hooks/QueryHook.php`
- `laravel-adapter/src/Hooks/LogHook.php`
- `laravel-adapter/src/Hooks/CacheHook.php`
- `laravel-adapter/src/Hooks/EventHook.php`
- `laravel-adapter/src/Hooks/QueueHook.php`
- `laravel-adapter/src/Hooks/MailHook.php`
- `laravel-adapter/src/Hooks/RedisHook.php`
- `laravel-adapter/src/Hooks/HttpHook.php`
- `laravel-adapter/src/Bridge/ExtensionBridge.php` — calls `periscope_record_event()` (FFI to C extension)

Package name: `thamibn/periscope-laravel`. Auto-discovered service provider so it activates automatically when present.

#### 2. C extension FFI

**File**: `extension/periscope_userland_api.c` (new)

Expose `periscope_record_event(string $type, array $payload)` as a userland-callable function (registered via `PHP_FE` table). The Laravel adapter calls this from each hook. The function packs the payload into a Cap'n Proto `ObservabilityEvent` and appends it to the current trace's event list, with the current frame ID attached.

#### 3. Per-hook payload contracts

**File**: `laravel-adapter/docs/EVENT_PAYLOADS.md` (new)

Document the schema each hook sends to `periscope_record_event()`. Examples:

```php
// QueryHook — fired from DB::listen
periscope_record_event('sql', [
    'connection' => $event->connectionName,
    'sql' => $event->sql,
    'bindings' => $event->bindings,
    'time_ms' => $event->time,
]);

// LogHook — fired from Log channels
periscope_record_event('log', [
    'level' => $level,
    'message' => $message,
    'context' => $context,
]);
```

#### 4. N+1 detection

**File**: `laravel-adapter/src/Detection/NPlusOneDetector.php`

Naive in-process detector: if the same SQL pattern (with bindings normalized) runs ≥ 4 times within one frame's lifetime, attach an `n_plus_one_warning` to the trace. Inspired by `beyondcode/laravel-query-detector`.

### Success Criteria

#### Automated Verification:
- [ ] `composer require thamibn/periscope-laravel` works in a fresh `laravel new` project
- [ ] Service provider auto-discovery test: ` php artisan about | grep periscope` shows the package as registered
- [ ] Pest tests in `laravel-adapter/tests/` cover each hook firing and event being recorded (use a stub `ExtensionBridge` for unit tests)
- [ ] N+1 detector test: a known N+1 query pattern produces a warning event in the trace
- [ ] Integration test: install adapter into Laravel skeleton, run a route that hits DB + cache + dispatches a job, verify trace contains the expected events

#### Manual Verification:
- [ ] Install adapter in Thami's `property-core-backend` repo on a feature branch, run a real listing detail page, manually inspect the trace for sane query/log/cache events
- [ ] Confirm N+1 warning fires for a known N+1 case (the `agencies` query on listings index — based on the Phase 1 mockup we discussed)

---

## Phase 6: Rust DAP Server (Daemon)

### Overview

A standalone Rust binary that:
1. Speaks Debug Adapter Protocol over stdio (the standard DAP transport).
2. Communicates with the C extension via a Unix domain socket (`/tmp/periscope/daemon.sock`) for live debug commands (set breakpoint, step, continue).
3. Reads completed traces for reverse-step / time-travel.

This is the bridge that makes VSCode/Neovim/Zed talk to periscope.

### Changes Required

#### 1. Daemon scaffold

**Files**:
- `daemon/Cargo.toml` — deps: `tokio`, `serde`, `serde_json`, `capnp`, `tracing`, `clap`
- `daemon/src/main.rs`
- `daemon/src/dap.rs` — DAP protocol
- `daemon/src/socket.rs` — Unix socket to extension
- `daemon/src/state.rs` — current debug session state

#### 2. DAP message handling (subset)

Implement these DAP requests (minimum viable subset):

| DAP request | What it does |
|-------------|--------------|
| `initialize` | Handshake; advertise `supportsStepBack: true` |
| `launch` | Start (or attach to) a PHP request |
| `setBreakpoints` | Forward to C extension |
| `configurationDone` | Begin running |
| `threads` | Single thread |
| `stackTrace` | From current frame chain |
| `scopes` | Locals, arguments |
| `variables` | Resolve via Value snapshots from trace |
| `continue` | Resume |
| `next` (step over) | One frame forward |
| `stepIn` | Into next called frame |
| `stepOut` | To parent frame |
| `stepBack` | One frame backward (time-travel) |
| `reverseContinue` | Backward to last breakpoint |
| `evaluate` | Read a variable from current snapshot — read-only in v1, no expression eval |

#### 3. Extension ↔ daemon protocol

**File**: `daemon/src/protocol.rs`
**File**: `extension/periscope_daemon_link.c` (new)

A small length-prefixed JSON protocol over the Unix socket (we don't need Cap'n Proto here; messages are tiny and infrequent). Messages:

```
ext → daemon: {"type": "request_started", "request_id": "...", "trace_path": "..."}
ext → daemon: {"type": "frame_entered", "frame_id": ..., "function": "...", "file": "...", "line": ...}
ext → daemon: {"type": "breakpoint_hit", "frame_id": ..., "file": "...", "line": ...}
daemon → ext: {"type": "set_breakpoints", "file": "...", "lines": [42, 87]}
daemon → ext: {"type": "continue"} | {"type": "step_over"} | {"type": "step_in"} | {"type": "step_out"}
```

When the extension hits a breakpoint, it pauses the request thread (busy-loop on a flag with a microsleep — this is single-request, no async, OK for v1) until the daemon sends `continue`.

### Success Criteria

#### Automated Verification:
- [ ] `cargo build --release` produces a `periscope-daemon` binary
- [ ] DAP handshake test: pipe a recorded `initialize` request to stdin, get a valid `initialize` response
- [ ] Extension-daemon link test: spawn the daemon, run a PHP script with periscope, confirm `request_started` and `frame_entered` events arrive at the daemon socket
- [ ] DAP integration test: use the `dap-rs` test harness to drive a full launch → setBreakpoint → continue → stop-on-breakpoint → step → continue cycle against a fixture PHP script
- [ ] No `unsafe` Rust code in the daemon (enforce via `#![forbid(unsafe_code)]`)

#### Manual Verification:
- [ ] Configure VSCode `launch.json` to spawn `periscope-daemon`. Set a breakpoint in a PHP script. Hit run. Verify VSCode pauses at the breakpoint and shows the call stack and locals.
- [ ] Step backward — verify VSCode shows the previous frame and its variables

---

## Phase 7: Replay Engine

### Overview

The replay engine answers the question: "What was the world like at time T in this request?" Used by both the daemon (for `stepBack`, `reverseContinue`) and the UI (for the timeline scrubber).

### Changes Required

#### 1. Index-on-open

**File**: `daemon/src/replay/index.rs` (new)

When a trace is opened, build an in-memory index:
- Frame tree (parent → children)
- Time-ordered list of events (frames + observability events)
- Hash maps: frame_id → byte offset in trace, event_id → byte offset

This makes any seek O(1).

#### 2. State reconstruction

**File**: `daemon/src/replay/state.rs` (new)

Given a target time T, reconstruct:
- Current frame (the deepest frame whose enter ≤ T < exit)
- Call stack (chain of parents up to root)
- Variables in current frame (read directly from frame's args/locals snapshot)
- All observability events that occurred ≤ T

Variables are not interpolated between snapshots — we have function-boundary snapshots only, so "between calls" we show the caller's frame state. This is a deliberate v1 limitation.

#### 3. Reverse-step semantics

**File**: `daemon/src/replay/cursor.rs` (new)

Define a `ReplayCursor` that tracks the current "view time." Operations:
- `step_back()` → move cursor to previous frame's enter time
- `reverse_continue()` → move cursor backward until next breakpoint
- `step_forward()` → opposite

These map to DAP's `stepBack` / `reverseContinue` / `next`.

### Success Criteria

#### Automated Verification:
- [ ] Index build is < 100ms for a 50MB trace: `cargo bench --bench index_build`
- [ ] Seek test: given a trace with N=1000 frames, seek to frame 500 returns correct stack and locals: `cargo test replay::seek`
- [ ] Step-back test: from frame 500, `step_back()` lands on frame 499's enter: `cargo test replay::step_back`

#### Manual Verification:
- [ ] In VSCode, hit a breakpoint deep in a Laravel request. Press the "step back" button repeatedly. Variables and stack should update visibly with each step. No crashes.

---

## Phase 8: Time-Travel via DAP `supportsStepBack`

### Overview

Wire the Phase 7 replay engine into the Phase 6 DAP server. Mostly glue work, but it's where time-travel becomes user-visible.

### Changes Required

#### 1. Capability advertisement

**File**: `daemon/src/dap.rs`

In the `initialize` response, set `"supportsStepBack": true` and `"supportsRestartFrame": true`.

#### 2. Live → replay handoff

When a request is running live (extension paused on a breakpoint), `stepBack` switches the session into "replay mode" — the extension keeps the request frozen, and the daemon serves all subsequent stack/variable queries from the replay engine instead. `continue` (forward) returns to live mode.

This avoids having to actually rewind PHP execution (which is impossible) — we instead serve a *view* of past state from the trace.

**File**: `daemon/src/state.rs` — add `Mode::Live | Mode::Replay { cursor }`

#### 3. UI hint

When in replay mode, the daemon emits a DAP `output` event with `category: "console"` and a banner line: `[periscope] viewing past state at T+3.2s — step forward to return to live`.

### Success Criteria

#### Automated Verification:
- [ ] DAP integration test: `stepBack` after a breakpoint hit returns updated stackTrace + variables matching the trace's previous frame
- [ ] `continue` from replay mode resumes the live request

#### Manual Verification:
- [ ] In VSCode: set breakpoint in `ListingController::show`. Hit it. Step back into the middleware that called it. See the request before route resolution. Continue — request finishes normally, response renders in browser.

---

## Phase 9: Browser UI

### Overview

A local web app served by the daemon at `http://localhost:9999`. Shows source + variables + queries + logs + jobs + events + cache + Redis + HTTP, with a timeline scrubber.

Two sub-phases to de-risk:

### Phase 9a: Static clickable mockup (Day 1–3)

**Purpose**: nail the design before any backend wiring. Cheap to throw away.

**File**: `ui/mockup/index.html` (single-file static HTML + CSS + tiny JS, no framework)

A static page showing the layout from the conversation: source pane, scope pane, timeline scrubber, expandable panels for queries/logs/cache/etc. Sample data hard-coded. Clickable but not connected to anything.

**Output**: A standalone HTML file that can be opened in any browser. Used to get feedback on the UX from potential users without building anything real.

### Phase 9b: Real UI wired to the daemon

**Stack decision**: **SolidJS** over Svelte.
- Smaller bundle (~12KB vs Svelte's ~30KB+ runtime)
- React-like JSX so contributors find it familiar
- Excellent fine-grained reactivity for the timeline scrubber (state changes 60fps when dragging)
- Strong TypeScript story

Files:
- `ui/package.json` — Bun + Vite + SolidJS + Tailwind
- `ui/src/App.tsx`
- `ui/src/panels/Source.tsx`
- `ui/src/panels/Scope.tsx`
- `ui/src/panels/Queries.tsx`
- `ui/src/panels/Logs.tsx`
- `ui/src/panels/Jobs.tsx`
- `ui/src/panels/Events.tsx`
- `ui/src/panels/Cache.tsx`
- `ui/src/panels/Http.tsx`
- `ui/src/panels/Redis.tsx`
- `ui/src/components/TimelineScrubber.tsx`
- `ui/src/components/AiAssist.tsx` — Phase 9b stretch goal: send current frame + question to an LLM endpoint

The daemon serves the built `ui/dist/` over HTTP on `localhost:9999` and exposes a WebSocket at `/ws` that streams session events from Rust to the browser. Same protocol on the wire as DAP-internal events, but JSON for browser consumption.

### Success Criteria

#### Automated Verification (9a):
- [ ] HTML validates (`html-validate ui/mockup/index.html`)
- [ ] Lighthouse score > 90 for accessibility

#### Automated Verification (9b):
- [ ] `bun run build` produces an asset bundle < 200KB gzipped
- [ ] `bun test` passes for component tests
- [ ] WebSocket integration test: open a session, drag the scrubber, confirm the daemon receives `cursor_set` messages

#### Manual Verification:
- [ ] (9a) Show mockup to ≥ 3 PHP devs, gather feedback before building 9b
- [ ] (9b) Trigger a real breakpoint in a Laravel app, open `localhost:9999`, observe all expected panels populate, scrub the timeline backward, confirm queries/logs disappear/reappear in correct order

---

## Phase 10: Real-World Integration Tests

### Overview

Find what breaks before users do. Run periscope against three large open-source PHP apps and fix everything that crashes, hangs, or misreports.

### Changes Required

#### 1. Test harnesses

**File**: `tests/real-world/laravel/`
- Pull `laravel/laravel` skeleton into a git submodule
- Run its test suite with the extension loaded
- Run a sample request through the periscope UI

**File**: `tests/real-world/symfony/`
- Pull `symfony/demo` skeleton
- Same drill

**File**: `tests/real-world/wordpress/`
- Pull a fixed-version WordPress
- Run a homepage request

#### 2. Bug-fix cycle

For every crash, hang, or wrong-output bug found:
- Reproduce in a unit test
- Fix in the right component
- Add to the regression suite

This is open-ended work — budget 1–2 weeks of *just* this.

#### 3. Performance regression

**File**: `tests/perf/baseline.md`

Record baseline timings for each app's standard route (with and without extension). Set a CI gate: extension overhead must be < 3× for the Laravel skeleton homepage. (3× is honest given function-boundary recording; opcode-level would be much worse.)

### Success Criteria

#### Automated Verification:
- [ ] All three test harnesses run their full test suites with periscope loaded, with no regressions vs. unmodified runs
- [ ] CI perf gate: < 3× overhead on Laravel homepage
- [ ] Zero ASan errors across all three real-world test runs

#### Manual Verification:
- [ ] Use periscope to debug a real bug in `property-core-backend` (Thami's day job) — does it actually help, or is it slower than `dd()`?

---

## Phase 11: Distribution

### Overview

Make installation a single command on macOS and Linux.

### Changes Required

#### 1. PECL-style package

**File**: `package.xml` (PECL metadata)

Standard PECL extension package so users can `pecl install periscope` if they prefer the canonical PHP path.

#### 2. Homebrew tap

**Files**:
- A separate repo `thamibn/homebrew-php-periscope`
- `Formula/php-periscope.rb` — formula that builds the extension for each installed brew PHP, drops the daemon binary in `/opt/homebrew/bin/`
- `Formula/php-periscope.rb` runs `pecl install` per PHP version found via `brew list | grep '^php'`

#### 3. One-line install script

**File**: `scripts/install.sh`

```bash
curl -fsSL https://periscope.dev/install.sh | bash
```

(Domain is aspirational; for v1 we ship a script in the repo and tell users to run `bash <(curl -fsSL https://raw.githubusercontent.com/.../install.sh)`.)

The script detects:
- OS (macOS / Linux)
- PHP installation type (brew / apt / docker / valet)
- PHP version

…and installs accordingly. Verbose mode (`bash install.sh -v`) prints each step. Dry-run mode prints what *would* happen.

#### 4. VSCode extension

**Path**: `vscode-extension/`

Standard VSCode extension that:
- Bundles the `periscope-daemon` binary
- Provides a debug configuration (`periscope` debug type)
- Auto-spawns the daemon on debug start
- Gutter icons for breakpoints
- Status bar item showing connection state

Publish to the VSCode Marketplace as `thamibn.php-periscope`.

#### 5. Uninstall

**File**: `scripts/uninstall.sh`

Reverses all the install steps. Important — segfault-prone tools must be easy to remove.

### Success Criteria

#### Automated Verification:
- [ ] `brew install thamibn/php-periscope/php-periscope` works in a fresh CI VM
- [ ] `bash scripts/install.sh` works on a fresh Ubuntu 22.04 + PHP 8.3 image and a fresh macOS image
- [ ] `code --install-extension thamibn.php-periscope` succeeds on Linux + macOS
- [ ] `bash scripts/uninstall.sh` removes everything cleanly (verify with diff before/after)

#### Manual Verification:
- [ ] Thami runs the install script on his Macbook (Valet PHP 8.1/8.2/8.3 + Docker setup) and gets a working debugger
- [ ] Three external testers (recruited from Laravel Slack/Discord) install via the script and report friction

---

## Phase 12: Documentation, Beta Launch, Issues Workflow

### Overview

Ship to public beta. Set up the GitHub issues triage rituals so the project doesn't get drowned in the first week.

### Changes Required

#### 1. Documentation site

**Path**: `docs/site/`

- Built with `vitepress` or `astro` (cheap, fast)
- Hosted on GitHub Pages or Cloudflare Pages
- Pages: Home, Getting Started, Architecture, Known Limitations, Contributing, FAQ, Roadmap

#### 2. CONTRIBUTING.md

**File**: `CONTRIBUTING.md`

- How to set up dev env (build C extension + Rust daemon)
- Coding standards (clang-format for C, rustfmt for Rust, prettier for UI)
- How to run the test suite
- ASan / Valgrind workflow
- PR template

#### 3. Issue templates

**Files**:
- `.github/ISSUE_TEMPLATE/bug_report.md`
- `.github/ISSUE_TEMPLATE/feature_request.md`
- `.github/ISSUE_TEMPLATE/crash_report.md` — special template for segfaults: requires backtrace, OS, PHP version, extension list

#### 4. Code of Conduct

**File**: `CODE_OF_CONDUCT.md` — standard Contributor Covenant 2.1.

#### 5. Beta launch announcement

- Twitter/Mastodon post with the timeline scrubber demo gif
- Laravel News submission
- r/PHP post
- Hacker News submission (timed for Tuesday morning ET)
- Direct outreach to `php-fig`, `Laravel`, `Symfony` Discord/Slack channels

#### 6. Feedback funnel

- Discord server invite link in README and docs
- `feedback@periscope.dev` (placeholder; can be Gmail/forward)
- A dedicated `beta-feedback` GitHub Discussions board

### Success Criteria

#### Automated Verification:
- [ ] Documentation site deploys cleanly on every push to `main` via GitHub Actions
- [ ] All links in README and docs resolve (CI link-checker)

#### Manual Verification:
- [ ] At least 10 external users report successful install
- [ ] At least 3 unique segfault reports filed within first 30 days, all triaged and at least 2 fixed
- [ ] HN/r/PHP posts get organic upvotes (≥ 50 each as a soft signal)

---

## Parallel Execution Strategy

### Parallel Tracks

After **Phase 4** (trace format frozen), work splits into three independent tracks. Each can be a separate worktree / contributor.

| Track | Scope | Files affected | Dependencies |
|-------|-------|----------------|--------------|
| **A — Extension+Adapter** | Phases 5 (Laravel adapter) | `laravel-adapter/`, `extension/periscope_userland_api.c` | Phase 4 (trace format) |
| **B — Daemon+Replay** | Phases 6, 7, 8 | `daemon/` | Phase 4 (trace format) |
| **C — UI** | Phase 9a (mockup), then 9b (real) | `ui/` | Phase 9a → 9b dep on Phase 6 protocol |

### Sequential Steps (must be in order)

1. **Phase 1** — Hello-world extension. Nothing else can build until this works.
2. **Phase 2** — Observer hooks. Phase 3 builds on the same Observer registration.
3. **Phase 3** — Variable capture. Phase 4 trace format depends on knowing what we're serializing.
4. **Phase 4** — Trace format. The contract that unblocks Tracks A/B/C.
5. **Phases 10, 11, 12** — Integration test, distribution, beta. Sequential and gating release.

### Merge Order

After Tracks A, B, C merge:

1. Track A first (Laravel adapter — extends C extension; least likely to conflict)
2. Track B second (daemon — reads trace format produced by A's events, but doesn't share files with A)
3. Track C third (UI — depends on B's WebSocket protocol)
4. Run full integration suite (Phase 10) on the combined branch
5. Cut a release tag

---

## Testing Strategy

### Unit tests

**C extension:**
- `.phpt` tests in `extension/tests/` for every Observer hook, every zval type, every edge case (recursion, magic methods, large strings)
- Pest tests in `tests/unit/` for higher-level behaviors

**Rust daemon:**
- `cargo test` for trace round-trip, DAP message handling, replay seek, cursor operations

**Laravel adapter:**
- Pest tests in `laravel-adapter/tests/` for each hook firing the right event payload (using a stub `ExtensionBridge`)

**UI:**
- `bun test` with `@solidjs/testing-library` for component logic
- Visual regression for panels via Playwright snapshots

### Integration tests

- End-to-end: `tests/integration/e2e.sh` spawns the daemon, runs a fixture PHP script, drives DAP commands via a mock client, asserts on the resulting trace and DAP responses
- Real-world (Phase 10): full Laravel/Symfony/WordPress test suites with periscope loaded

### Memory safety tests

- **AddressSanitizer** on every CI run (Linux) — catches ~80% of memory bugs at test time
- **Valgrind** on a nightly CI cron — catches the rest, slowly
- **Fuzz testing** (Phase 10b, time-permitting): libFuzzer + a small PHP grammar to generate random programs

### Manual testing steps

1. Set a breakpoint in a Laravel controller. Hit the route. Verify variables, queries, logs all show in browser UI.
2. Drag the timeline scrubber backward. Confirm UI updates show earlier state.
3. Step backward in VSCode. Confirm stack and variables update.
4. Run a real production-grade Laravel app's homepage with periscope loaded; confirm no crash and < 3× slowdown.
5. Uninstall via `scripts/uninstall.sh`. Confirm clean removal.

---

## Performance Considerations

### Targets

- **Recording overhead**: < 3× slowdown on a typical Laravel route. (Honest given function-boundary recording. Xdebug profile mode is ~50× as a comparison.)
- **Memory overhead**: < 100MB additional resident memory per recorded request.
- **Trace size**: < 10MB for a typical Laravel page request.
- **Replay seek**: < 50ms to any frame in a 50MB trace.
- **Daemon startup**: < 200ms cold start (Rust binary + index empty trace dir).
- **UI scrubbing**: 60fps timeline drag (handled by SolidJS fine-grained reactivity).

### Optimization opportunities (note for v2)

- Trace compression (zstd) — likely 10× size reduction
- Lazy variable serialization — only capture values for the frames near a breakpoint
- Sampling mode for "production debugging" v2 — record 1% of requests fully

---

## Risk Register

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| **Memory bugs in C extension cause segfaults in user apps** | High | Catastrophic | ASan from day one, Valgrind nightly, fuzz testing, real-app integration tests, dedicated crash-report issue template |
| **Variable capture breaks on closures/refs/circular refs** | High | High | Document as known v1 gap; capture as `opaque`; fix in v2 |
| **Performance overhead too high for users to enable** | Medium | High | Function-boundary recording (not opcode); benchmark gate in CI; allow filtering by namespace |
| **Determinism fails for replay** (`time()`, `rand()` divergence) | Medium | Medium | v1 doesn't actually re-execute — we serve views of recorded state, not re-runs. Determinism becomes a v2 problem when we add re-execution. |
| **DAP `stepBack` UX is rough in VSCode** | Medium | Medium | Test early in Phase 8; consider custom UI as primary, IDE as fallback |
| **Bus factor of 1 (just Thami)** | High | Catastrophic over time | Document everything aggressively; structure code so AI-assisted contributors can pick up modules; recruit one early-stage co-maintainer before public beta |
| **Naming clash / trademark** | Low | Low | "Periscope" is a generic term but also a defunct Twitter product. We use `php-periscope` consistently to avoid conflict. |
| **Cap'n Proto C lib less mature than Protobuf** | Medium | Medium | Pin a vendored copy; have an "if this fails, switch to Protobuf" branch ready (24-hour migration if needed) |
| **PHP 8.4 ships during MVP and breaks the extension** | Medium | Low | Lock to 8.3 for v1; plan a 1-week 8.4-compat sprint after MVP |
| **Scope creep — "just one more panel"** | High | Medium | This document. Read it before adding anything. |

---

## Migration Notes

Not applicable — greenfield project, no existing data or systems to migrate.

---

## Week 1–2 Concrete Task List

What to actually do this week and next, ordered. If you do nothing else, do these.

### Week 1 (Phase 1 + Phase 2 setup)

**Day 1**
- [ ] Create top-level `Makefile`, `README.md`, `LICENSE` (MIT), `.editorconfig`, `.gitignore`
- [ ] Initialize `extension/` with `config.m4`, `php_periscope.h`, `periscope.c` (MINIT prints "periscope loaded")
- [ ] Local build: `cd extension && phpize && ./configure && make` — see a `.so` pop out
- [ ] Run extension against `php -d extension=...periscope.so -r 'echo "hi";'` — confirm "periscope loaded" + "hi"

**Day 2**
- [ ] Set up GitHub Actions: `.github/workflows/ci.yml` with macOS + Linux build matrix, PHP 8.3 only
- [ ] Add ASan job (Linux only)
- [ ] Add `make asan` and `make valgrind` Makefile targets

**Day 3**
- [ ] Read php-src `Zend/zend_observer.h` and `Zend/zend_observer.c` thoroughly — Claude can summarize but you need to know what `zend_observer_fcall_register` actually does
- [ ] Read OpenTelemetry-PHP-instrumentation source for production usage patterns
- [ ] Sketch the function signature for the observer factory

**Day 4–5**
- [ ] Implement Observer registration in `MINIT`
- [ ] Implement begin/end handlers that log to stderr
- [ ] Test against `tests/integration/hello.php`
- [ ] Verify ASan-clean

### Week 2 (Phase 2 finish + Phase 3 start)

**Day 6**
- [ ] Add `php.ini` setting `periscope.skip_internal=1` and respect it in the observer
- [ ] Create golden-output file `tests/integration/hello.expected.log`
- [ ] CI runs hello.php and diffs against golden

**Day 7**
- [ ] Run extension against a real `laravel new` app — count function calls on `/`, time the route, document baseline overhead
- [ ] **Pause point**: review with Thami before continuing

**Day 8–10**
- [ ] Begin Phase 3 (variable capture)
- [ ] Start with primitives only: `IS_NULL`, `IS_TRUE`, `IS_FALSE`, `IS_LONG`, `IS_DOUBLE`, `IS_STRING`
- [ ] Write `.phpt` tests for each
- [ ] Get ASan-clean

End of Week 2: extension loads, observes every userland function call, captures primitive arguments. ~10% of total MVP work, but the riskiest 10% is now de-risked.

---

## References

- This conversation: chat history with the user on 2026-05-08 about why Xdebug is hard to set up and what a modern alternative would look like
- Project repo: https://github.com/thamibn/php-periscope
- Project docs (in this repo):
  - `docs/VISION.md` — the elevator pitch
  - `docs/SCOPE.md` — what's in / what's out for v1
  - `docs/ROADMAP.md` — phase calendar
  - `docs/ARCHITECTURE.md` — system diagram and decisions
- External:
  - PHP source — `Zend/zend_observer.h`, `Zend/zend_observer.c`
  - DAP spec — https://microsoft.github.io/debug-adapter-protocol/specification
  - Cap'n Proto — https://capnproto.org/
  - OpenTelemetry PHP instrumentation — https://github.com/open-telemetry/opentelemetry-php-instrumentation
  - Mozilla rr (record-and-replay inspiration) — https://rr-project.org/
  - Laravel Telescope (observability hooks reference) — https://github.com/laravel/telescope
