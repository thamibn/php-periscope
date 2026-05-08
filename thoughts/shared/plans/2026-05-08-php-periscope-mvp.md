# php-periscope MVP Implementation Plan

**Date:** 2026-05-08 (last updated 2026-05-08 with AI-native + retention additions)
**Author:** Thamsanca Ntuli (with Claude Code)
**Project:** php-periscope — live observability + time-travel debugger **for Laravel** (v1)
**Status:** v1 in progress — Phases 1–8 landed (live pause-on-breakpoint via Unix socket; DAP routes setBreakpoints/continue to live extensions; cleanup endpoints), Phase 9 (browser UI) next
**Tagline:** *See into your Laravel request — your AI co-pilot does too.*

**v1 audience scope:** Laravel only. The C extension is framework-agnostic by design (correct engineering for a Zend Observer hook), but we test, market, and support **only** Laravel in v1. Other frameworks ship as separate Composer packages after v1 (`periscopephp/symfony`, `periscopephp/wordpress`, `periscopephp/codeigniter`) once Laravel adoption proves the model. v1 narrowness is a deliberate scope cut.

## Cross-cutting requirements (added during implementation)

These are not phases on their own; each is folded into existing phases below with
the relevant bullets called out under that phase's "added requirement" headings.
Listed here so the full set is visible at a glance.

1. **AI-native trace access** — every trace must be queryable by AI dev tools (Claude Code, Cursor, Codex, Continue, aider). Three delivery channels: `--json` mode on the dump CLI (Phase 4), HTTP REST API (Phase 6), MCP server (Phase 11). Plus deterministic insights (N+1, DB-in-loop, slow-frame, memory hog) at `/api/traces/{id}/insights`. AI then *interprets* the structured insights and recommends fixes. Marketing line: *"Your AI co-pilot reads every request and tells you what's wrong — N+1 queries, slow frames, lost auth state, memory hogs — before you even know to look."*

2. **Request + response envelope in trace** — URL, method, headers, cookies, query, POST body, raw body, files, response status + body + headers. Framework-agnostic capture in C extension (Phase 4 schema, Phase 5 implementation). Laravel adapter enriches with route, auth user, session (Phase 5).

3. **Call-site for every observability event** — every SQL, log, cache, redis, http, job, mail event must carry `userCallSite { file, line, snippet, frameStack }`. UI shows "this query came from `ListingResource.php:42` in line `'agency' => $this->agency,`" with deep-link to IDE. (Phase 5.)

4. **Trace retention** — `periscope.max_traces` (default 100) + `periscope.max_trace_age_seconds` (default 86400) sweep at RINIT. Manual `make trace-clean`. Documented as ephemeral with privacy warning. (Phase 4 — done.)

5. **Adaptive UI** — only render panels that have non-zero events in the current trace. Out of the box on Laravel: Source / Variables / Stack / Timeline / Queries / Logs / Jobs / Events / Cache / Redis / HTTP / Mail panels all light up. (Phase 9.)

6. **Laravel-only in v1; other frameworks as separate packages later** — C extension is framework-agnostic internally (correct engineering, allows cheap v1.1+ growth), but v1 ships ONLY `periscopephp/laravel`. We do not test, market, or support Symfony/WordPress/CodeIgniter/plain PHP in v1. Future packages: `periscopephp/symfony`, `periscopephp/wordpress`, `periscopephp/codeigniter` — each follows the same pattern (Composer package, framework auto-discovery, forwards events to the same C extension).

7. **No end-user toolchain** — distribution ships precompiled bottles via brew/PECL. End users never need Rust, C++, capnp, or a compiler. Maintainers + CI handle the build. (Phase 11.)

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

1. `brew install periscopephp/php-periscope/php-periscope` (or run a one-line install script).
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
- Real-world test: clone the Laravel skeleton + a representative production-grade Laravel app (maintainer-supplied, gated submodule); run their test suites with periscope loaded; no regressions, no segfaults under AddressSanitizer.

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
- **Other PHP frameworks** — Symfony, WordPress, CodeIgniter, plain PHP. Out of v1 scope entirely. Each ships as its own Composer package after v1 (`periscopephp/symfony`, `periscopephp/wordpress`, `periscopephp/codeigniter`) once Laravel adoption proves the model.
- **Mobile / cloud UI** — local browser UI only.
- **Authentication / multi-user** — `localhost:9999` is single-user, no auth.
- **Custom non-DAP protocol** — DAP is the only IDE protocol in v1 (custom protocol deferred to v2 if/when needed for time-travel features DAP can't express well).

---

## Implementation Approach

**Phasing principle: smoke test at every phase boundary.** Each phase ends with a working artifact that can be demoed in isolation. If a phase falls behind, the previous phase still produces value.

**Parallelism principle: serialize until the trace format is frozen, then parallelize.** Phases 1–4 must be sequential (each builds on the previous). Once the trace format is locked at end of Phase 4, the C extension (Phases 1–5) and Rust daemon (Phase 6) can be developed in parallel by separate worktrees / contributors. UI mockup (Phase 9, mockup-only sub-step) can start at any time.

**Quality principle: AddressSanitizer from day one, not added later.** The C extension will be compiled with `-fsanitize=address` in CI from the very first hello-world phase. Memory bugs will be caught at test time with clear errors instead of in production with cryptic segfaults.

**Distribution principle: ship the extension and the daemon together as one install.** Users should never have to install pieces separately. `brew install php-periscope` installs the extension for all brew-managed PHP versions and drops the Rust daemon binary in `$PATH`. The VSCode extension auto-detects and connects.

**Self-sufficiency principle: zero Xdebug dependency, ever.** php-periscope is the tool people install *to escape Xdebug*. Anything Xdebug does that users still want — opcode-level stepping, profile mode, cachegrind output, function tracing — we either ship ourselves or explicitly defer to v2 with our *own* implementation. We never tell a user "install Xdebug for that piece." Leaning on Xdebug undermines the pitch.

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

## Phase 5 watcher coverage — full Telescope parity

v1 ships **all 18 Laravel Telescope watchers** in the `periscopephp/laravel` adapter. Source of truth: https://laravel.com/docs/13.x/telescope. Each watcher is one Hook class in `laravel-adapter/src/Hooks/`, all forward to the C extension via `periscope_record_event()`.

| # | Watcher | What it captures | Laravel event/hook |
|---|---------|------------------|---------------------|
| 1 | **BatchWatcher** | Queued batch info (job + connection details) | `Bus::batched`, batch lifecycle events |
| 2 | **CacheWatcher** | Cache hits, misses, updates, forgotten keys | `CacheHit`, `CacheMissed`, `KeyWritten`, `KeyForgotten` events |
| 3 | **CommandWatcher** | Artisan command execution: arguments, options, exit code, output | `Console\Events\CommandStarting/Finished` |
| 4 | **DumpWatcher** | `dump()` calls — variable dumps | Custom hook on `dump` global |
| 5 | **EventWatcher** | Dispatched events: payload, listeners, broadcast data (excludes framework internals) | `Event::listen('*')` |
| 6 | **ExceptionWatcher** ★ | Reportable exceptions: data + stack trace | Exception handler / `Log::error` |
| 7 | **GateWatcher** | Gate / policy authorization checks: data + result | `Gate::after`, `Gate::before` |
| 8 | **HttpClientWatcher** | Outgoing HTTP client requests | `Http::globalMiddleware`, `RequestSending`/`ResponseReceived` events |
| 9 | **JobWatcher** | Queued jobs: data + status | `Queue::before/after/failing` |
| 10 | **LogWatcher** | Application log lines (default: error+, configurable) | `Log::listen` |
| 11 | **MailWatcher** | Sent emails: preview + recipient + data | `MessageSending`, `MessageSent` events |
| 12 | **ModelWatcher** ★ | Eloquent model events (creating/created/updating/updated/deleting/deleted/retrieved) **plus per-class hydration counts** ("this request hydrated `Listing` × 5432, `Agency` × 1200, `User` × 45") — DebugBar-style aggregate that surfaces over-fetching at a glance | `eloquent.created:*`, `eloquent.updated:*`, `eloquent.deleted:*`, `eloquent.retrieved:*` |
| 13 | **NotificationWatcher** | Sent notifications | `NotificationSending`, `NotificationSent` |
| 14 | **QueryWatcher** | DB queries: raw SQL, bindings, time, slow-query flag (default 100ms) | `DB::listen` |
| 15 | **RedisWatcher** | Redis commands (includes cache commands) | `Redis::enableEvents` + `CommandExecuted` |
| 16 | **RequestWatcher** | HTTP request data, headers, session, response | RouteMatched + Response middleware |
| 17 | **ScheduleWatcher** | Scheduled task execution: command + output | `Schedule\Events\ScheduledTaskStarting/Finished` |
| 18 | **ViewWatcher** | View rendering: name, path, data, composers | `composing:*` event |

★ = highest-priority differentiators (Exception + Model are critical for the "AI tells you what's wrong" pitch; without them the AI is blind to the most common failure modes).

**Implementation note:** every event payload includes a **CallSite** (`file`, `line`, `snippet`, `frameStack`) per Appendix A.5 — clicking any event in the UI jumps to the user-code line that triggered it. This is what makes "10× queries from ListingResource.php:42" work.

### Phase 5 also includes DebugBar-style aggregate "summary" counts

For every observable event type, the UI also surfaces aggregate totals over the request — the at-a-glance numbers DebugBar puts in its footer:

- **Queries**: total count, total time, slow-query count, N+1 warning count, by connection
- **Models hydrated**: per-class counts (`Listing × 5432`, `Agency × 1200`)
- **Cache**: hit count / miss count / hit ratio, by store
- **Logs**: count by level (debug, info, warning, error)
- **Jobs dispatched**: count by class + queue
- **Events fired**: count by class
- **HTTP calls**: count, total bytes, total time, by host
- **Mail sent**: count by recipient domain
- **Notifications sent**: count by channel
- **Memory**: peak resident, peak per-frame
- **Time**: total request duration (DebugBar's headline number), top 10 slowest frames, time-to-first-byte
- **Request size**: incoming body bytes (`Request.totalBodyBytes`), upload count + sizes (`Request.files`)
- **Response size**: outgoing body bytes (`Response.totalBodyBytes`), final HTTP status (`Response.statusCode`)

These are derived in the daemon (Phase 6+) by summing/grouping the per-event data already in the trace. No new C-extension work — the watchers emit individual events; the daemon's reader aggregates on-demand for the UI and `/api/traces/{id}/summary`. Same data also feeds the AI insights endpoint.

### Phase 5 differentiators — spices on top of Telescope/DebugBar parity

Telescope-parity is the *floor*, not the pitch. Each of these is a concrete feature neither Telescope nor DebugBar nor Clockwork ships, designed so the answer to "what's different from Telescope?" is obvious at a glance:

1. **Time-travel scrubbing with per-frame variable state** ★
   The single biggest unique feature. Drag the timeline, see every variable at every frame redraw in real time. Telescope is post-mortem; DebugBar is live but flat; we are live AND scrubbable. (Phase 7 + 8 + 9.)

2. **N+1 with concrete fix suggestions, not just warnings** ★
   Telescope flags slow queries. We detect the pattern (same SQL ran N times in one frame, bindings differ only by id) AND surface the exact code change: *"add `->with('agency')` to the query at `ListingService.php:42`"*. Implemented as a deterministic heuristic in the daemon (Phase 6 `/api/traces/{id}/insights`).

3. **Per-frame memory delta** ★
   DebugBar shows peak memory globally. We attach `memory_delta_bytes` to every frame: *"`Foo::loadAll` added 47MB."* Already trivial to capture (read `memory_get_usage()` at frame entry/exit in the C extension; subtract). Surfaces memory hogs to the second.

4. **Authorization decision trail**
   Every `Gate::allows()` / `Policy::can()` check captured WITH the values it compared. Telescope shows "GateChecked: update — denied". We show: *"GateChecked: update Listing#128 — denied because ListingPolicy::update at line 14 returned false (compared `$user->id` (42) !== `$listing->user_id` (88))."* Comes "for free" because we already capture frame variables at every call (Phase 3) — the GateWatcher just links the check event to the frame's locals.

5. **Synthetic checkpoints** (`periscope_checkpoint(string $label, mixed $context = null): void`)
   Userland-callable from any PHP code. Drops a labeled marker on the timeline. *"Checkpoint: after auth resolved."* Useful for non-framework code paths where Laravel events don't fire. Trivial to implement (one userland function in the C extension; one event type in the trace).

6. **Trace tags + bookmarks**
   Annotate any trace ("the broken one") or specific frame ("the line where it goes wrong") with text. Stored in a sidecar `.tags.json` next to the `.cptrace`. Survives across sessions. Useful for "I'll come back to this tomorrow" workflows.

7. **Per-route history view**
   See all traces for `/listings/{id}` — rank by slowest, most queries, error rate, p95. Telescope lets you filter; we surface aggregate trends across requests. Powered by reading the request envelope (Phase 4 schema) across the trace dir.

8. **Performance budgets**
   `periscope.budget.queries=50`, `periscope.budget.duration_ms=500`, `periscope.budget.memory_mb=128`. Trace gets flagged if exceeded. Surfaces in UI + `/api/traces/{id}/insights`. CI-friendly (run integration tests with budgets, fail the build on regression).

9. **AI co-pilot integration** ★
   Per Appendix A.6 — full structured access via HTTP API + MCP server, plus deterministic insights. Your AI assistant becomes a debugging pair partner. No equivalent in Telescope/DebugBar/Clockwork.

10. **Live overlay**
    Browser UI open + you hit a route → traces stream in live, UI animates. Telescope has SPA tab navigation but no live frame-by-frame animation.

11. **Trace diff** (Phase 9b stretch)
    Compare two traces side-by-side. Useful for "what changed when I added the cache layer?" — see the delta in queries, memory, frames.

12. **Lazy/proxy detection in observation** ★
    Per Appendix A.5 / Phase 3 — when capturing variables, we explicitly do NOT trigger `__get`. Telescope/DebugBar's variable dumps fire lazy-load proxies (Doctrine + some Laravel relations), introducing observation-side-effects. We don't. Means inspecting an Eloquent model in periscope NEVER triggers a database round-trip.

★ = headline differentiators that get top billing in the launch blog and README.

**The pitch becomes:**

> *"Telescope + DebugBar + Clockwork + Xdebug — minus all four UIs, plus time-travel, N+1 fix suggestions, per-frame memory deltas, synthetic checkpoints, and an AI co-pilot. One install, one tab, no waiting for the request to finish."*

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

Package name: `periscopephp/laravel`. Auto-discovered service provider so it activates automatically when present.

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
- [ ] `composer require periscopephp/laravel` works in a fresh `laravel new` project
- [ ] Service provider auto-discovery test: ` php artisan about | grep periscope` shows the package as registered
- [ ] Pest tests in `laravel-adapter/tests/` cover each hook firing and event being recorded (use a stub `ExtensionBridge` for unit tests)
- [ ] N+1 detector test: a known N+1 query pattern produces a warning event in the trace
- [ ] Integration test: install adapter into Laravel skeleton, run a route that hits DB + cache + dispatches a job, verify trace contains the expected events

#### Manual Verification:
- [ ] Install adapter in a representative production-grade Laravel app (maintainer-supplied) on a feature branch, run a real listing detail page, manually inspect the trace for sane query/log/cache events
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
- [x] `cargo build --release` produces a `periscope-daemon` binary
- [x] DAP handshake test: pipe a recorded `initialize` request to stdin, get a valid `initialize` response (`dap::tests::handshake_advertises_step_back`)
- [x] Extension-daemon link socket accepts framed JSON and acks (`ext_link::tests::accepts_hello_and_acks`). Live stop-on-breakpoint coordination is reserved for Phase 8 — wire is in place, C-side pause primitive lands there.
- [x] HTTP API smoke against a real trace: `/api/health`, `/api/traces`, `/api/traces/{id}/{summary,insights,timeline}`, `/api/file` traversal guard (`scripts/smoke.sh` Phase 6 block)
- [x] No `unsafe` Rust code in the daemon (enforced via `#![forbid(unsafe_code)]` at the crate root)

#### Manual Verification:
- [ ] Configure VSCode `launch.json` to spawn `periscope-daemon --dap-stdio`. Open a recorded `.cptrace` via `launch.tracePath`. Verify VSCode shows the frame stack and stepBack moves the cursor backward.
- [ ] Live launch (run a request and stop on a real breakpoint) — landing in Phase 8 alongside the C-side pause primitive.

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
- [x] Index build O(F + E) on F frames + E events; runs unit-test fixtures instantly. Bench-on-50MB lands with the real-world phase (Phase 10) when we have a ≥50MB trace.
- [x] Seek test: `frame_at(t)` picks the deepest overlapping window (`replay::index::tests::frame_at_picks_deepest_overlapping`)
- [x] Step semantics: `step_in`, `step_over`, `step_out`, `step_back`, `forward_continue`, `reverse_continue` (`replay::cursor::tests::*`)
- [x] State reconstruction at time T returns the deepest frame, full stack, and prefix events (`replay::state::tests::at_time_returns_deepest_frame_and_prefix_events`)
- [x] HTTP `/api/traces/{id}/state?at=…|frame_id=…` end-to-end smoke (`scripts/smoke.sh` Phase 6 block)

#### Manual Verification:
- [ ] In an IDE with DAP support, open a recorded `.cptrace` and verify step-in / step-over / step-out / step-back move the cursor correctly across a Laravel request's call tree.

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
- [x] **8a:** end-of-request push from C extension reaches WebSocket clients (`daemon/tests/ws_fanout.rs`, `scripts/smoke.sh` Phase 8a block)
- [x] **8b:** full live-pause round trip — DAP `setBreakpoints` → `DaemonMessage::SetBreakpoints` → fake C ext → `ExtMessage::BreakpointHit` → DAP `stopped` event → DAP `continue` → `DaemonMessage::Continue` (`daemon/tests/dap_breakpoint.rs::live_breakpoint_round_trip`)
- [x] **8b:** C-extension pause primitive — `periscope_daemon_link_pause` blocks until daemon sends `Continue`; non-blocking drain at every userland frame boundary picks up new breakpoints mid-request without spinning
- [x] **8b:** trace storage cleanup endpoints — `DELETE /api/traces/{id}` and `DELETE /api/traces` (`scripts/smoke.sh` Phase 6 block)
- [x] No `unsafe` Rust still holds (`#![forbid(unsafe_code)]` at crate root)

#### Manual Verification:
- [ ] In VSCode: set breakpoint in `ListingController::show`. Hit the route. PHP request actually halts mid-execution. IDE shows paused state with stack + scope. Click continue — request resumes, response renders in browser.
- [ ] In PhpStorm via the JetBrains DAP plugin: same as above. (Confirms the per-IDE failure modes the user wants to test.)

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

### Phase 9b cross-cutting UI features (added during Phase 5c implementation review)

These features operate on the data the Phase 5 watchers already emit; no new C-extension or adapter work is required.

#### Event grouping / de-dup
- The daemon (Phase 6) computes a fingerprint per event = `(type, sha1(canonicalised_payload))`.
- `/api/traces/{id}/events?group=true` returns `{fingerprint, count, first_at, last_at, sample_event_ids: [...]}` rows.
- UI panels (logs, queries, model, exceptions, n+1, ai_suggestion) render as collapsed rows: `[12×] connection refused`, with an "expand all 12" disclosure that lists each occurrence + its call-site link.
- Critically: the raw trace is untouched — time-travel scrubbing still walks every event in order. Grouping is a *view*, not a *capture*.

#### Datadog-style payload filtering
- `GET /api/traces/{id}/events?type=log&filter=<expr>` accepts a small JSON-path query language: `payload.level:error AND payload.context.user_id:42 AND payload.message:"connection refused"`.
- Each panel has a free-text filter bar wired to the same endpoint. Saved filters per project (sidebar bookmarks).
- Works against any panel because every emitted payload is already a structured JSON tree.

#### Failed-jobs panel
- Reads Laravel's `failed_jobs` table via a daemon endpoint (`GET /api/failed-jobs`) — we don't shadow Laravel's storage.
- Per row: stack trace + AI suggestion (already emitted by ExceptionHook + AiAdvisor), originating-request deep link (the trace where JobHook recorded `phase=queued`), attempts, queue/connection, first/last failure timestamps.
- Actions: retry (single, bulk-by-class, bulk-by-exception), forget, retry-with-edited-payload.
- Differentiators: time-travel into the failed run's recorded trace, diff vs last successful run of the same class, pattern-grouped failures across last 24h, AI verdict "code at `Foo.php:42` still has the bug — retry anyway?", one-click Pest repro generator.

#### Static export + drag-and-drop load (v1, scoped into Phase 9b)

User-confirmed v1 feature (2026-05-08): ship a self-contained `.html` export of any trace that opens in any browser without the daemon running. Like Chrome's "Save performance profile as HTML," Sentry's issue export, the Firefox profiler's shareable links — but for periscope traces.

**Three export formats, one CLI:**

```
periscope-export <trace-id-or-path> [--format html|json|cptrace] [--out file.ext]
```

| Format | Default for | Size | What it is | Who reads it |
|---|---|---|---|---|
| `html` (default) | sharing with humans | ~1–2MB | UI bundle + trace JSON inlined into one self-contained file | any browser, double-click |
| `json` | AI agents + scripts | ~50–500KB | the same shape `GET /api/traces/{id}` returns today | Claude/Cursor/Codex/jq/anything |
| `cptrace` | archiving / re-hosting | ~5–100KB | a copy of the original binary trace | another `periscope-daemon` instance |

**Why three formats, not one:** AI agents read JSON natively — making them parse HTML to extract data is silly. Humans want a clickable UI — making them install a daemon to view a colleague's trace defeats the purpose. Long-term archives want the smallest format that round-trips through a daemon — that's the binary.

**Two halves of the implementation:**

1. **Export CLI** at `daemon/src/bin/export.rs`:
   - Reuses `Trace::open` + `trace_view::decode_trace` + `insights::compute` + `summary::compute` — no new logic, just packaging.
   - `html`: bundles the SolidJS `dist/` assets + the trace JSON inlined as `window.PERISCOPE_TRACE = {...}`.
   - `json`: pretty-prints the same struct; identical to `periscope-dump --json` but with insights + summary pre-baked.
   - `cptrace`: a `cp` of the source file. Trivial; included for symmetry.

2. **Load** — UI gracefully reads the trace from `window.PERISCOPE_TRACE` (set by the exporter inline) instead of `/api/*` when present:
   - At boot, the SolidJS `App.tsx` checks for `window.PERISCOPE_TRACE`; if present, uses it as the data source and disables daemon-only features (live WebSocket, IDE breakpoint sync).
   - Drag-and-drop: hosted UI also accepts dropping a `.cptrace` or a `.json` file onto the page → load directly. Dropping a previously-exported `.html` opens it in a new tab.
   - Disabled features in static mode: live mode, `setBreakpoints` (replay-only), AI ask-button (no daemon to proxy through — but the raw trace JSON is still readable for download + copy-paste into Claude).

**Use cases the user confirmed they need:**
- *"My colleague hit a weird bug. I email them `bug.html`. They double-click. Full debugger UI in their browser. They don't install anything."*
- Trace embedding in bug reports / GitHub issues / Slack threads.
- Privacy-preserving sharing (file lives where the user puts it; no SaaS).
- Demo / documentation: link a trace from a blog post → readers see the full UI.
- Time capsules: archive a trace before a refactor; re-open it 6 months later without needing the matching daemon version.

**Why this beats Clockwork's sharing service:**
- No SaaS dependency — periscope traces include cookies, request bodies, captured variables; we never default to "send to a third party."
- Works offline.
- Zero install for the recipient.
- Long-term archival — daemon versions can change, the exported HTML is frozen.

**Implementation cost:** ~1 day Rust (`periscope-export` reuses existing decoders), ~1 day SolidJS (`window.PERISCOPE_TRACE` data-source switch + drag-and-drop file reader). Lands alongside Phase 9b's main UI build.

**Not in this scope:** importing an exported `.html` *back* into the daemon for re-indexing — we accept the export as terminal/read-only. If a user wants to re-host the trace, they share the original `.cptrace` file instead.

#### Trace storage management UI (v1, Phase 9b)

User-confirmed (2026-05-08): the UI must *show* what's sitting in the trace dir and let the user clean it up. We are putting files in `/tmp/periscope/`; the user is right that we should make that visible and reversible from inside our own UI rather than punting to `make trace-clean` or `rm -rf`.

**Daemon endpoints (landed in Phase 8b):**
- `GET /api/traces` — already lists traces with size_bytes, started_at_unix_micros, duration, request URI, status code, has_exception. Phase 8b kept this.
- `DELETE /api/traces/{id}` — removes one `.cptrace` file. Returns `{deleted: 1}`.
- `DELETE /api/traces` — removes all `.cptrace` files in the configured `trace_dir`. Returns `{deleted: N}`.

**UI affordances (Phase 9b):**
- Sidebar "Storage" section: total trace count + total bytes on disk, current `trace_dir` path.
- Per-row "delete" icon on the trace list (confirm-on-click).
- "Clear all" button under the storage panel (confirm dialog with the count and total bytes; default-deny when ≥10 traces or ≥10MB so accidental clicks don't nuke history).
- "Open dir in Finder/Explorer" deep-link (macOS `open <dir>`, Linux `xdg-open <dir>`).

**Existing automatic retention (Phase 4 — already shipping):**
- `periscope.max_traces` (default 100) + `periscope.max_trace_age_seconds` (default 86400, 24h). Sweep at RINIT.
- Manual `make trace-clean` Makefile target.
- Now joined by daemon HTTP delete endpoints + future UI buttons.

**Why this matters:** users will accumulate trace files on disk during normal use. Without an in-UI cleanup path, they either `rm -rf /tmp/periscope` blind (loses history they wanted) or never clean up (disk fills). Surface the size + give them the buttons.

### Phase 9b Clockwork-parity polish (added 2026-05-08 after reviewing underground.works/clockwork)

**Guiding principle: zero Xdebug dependency.** php-periscope is the tool people install *to escape Xdebug*. Anything we'd otherwise solve by leaning on Xdebug (profiling, opcode-level zoom, a built-in cachegrind viewer) we ship ourselves. Lean on Xdebug → undermine the pitch. These five items close the visible gaps Clockwork still wins on today, all built on data we already have.

1. **In-page floating toolbar** (steal from Clockwork's "Toolbar" feature)
   - 2KB JS snippet the Laravel adapter injects (opt-in via config) into HTML responses — a tiny floating chip in the page corner showing duration, query count, memory peak, status.
   - Click → opens `localhost:9999/periscope` to the *current* request's trace.
   - Lives at `laravel-adapter/resources/js/toolbar.js` (~150 LoC) + `Periscope\Laravel\Middleware\InjectToolbar`.
   - Why it matters: lowest-friction entry point. No browser extension to install, no separate tab to remember. The user sees their request go red and clicks to dig in.

2. **Web Vitals + client-side timing** (steal from Clockwork "Client-metrics and Web Vitals")
   - The same toolbar JS also records `navigation.timing.*` + Web Vitals (LCP, CLS, INP, FCP, TTFB) via the standard `web-vitals` package and POSTs them to `POST /api/traces/{id}/client-metrics`.
   - Daemon merges client metrics into the trace's response panel; UI shows a "Client" tab on the timeline alongside server-side phases.
   - Why it matters: full request lifecycle, not just server-side. Backend dev sees that their 50ms response renders 1.8s after click because the JS bundle blocks paint.

3. **Self-contained profile mode + flame graph** (replaces "Xdebug profile viewer" — we do not depend on Xdebug)
   - `PERISCOPE_PROFILE=1` env var (or `?periscope_profile=1` query string with the rerun feature) flips the C extension into a sampling profiler for that request: every N microseconds (default 1ms, configurable via `periscope.profile_sample_us`), capture the current call stack to a separate `.profile` sidecar of the trace.
   - Daemon parses the sidecar and renders a **flame graph** in the UI's Performance panel — same data shape as Brendan Gregg's flamegraph format internally, but our own renderer in SolidJS.
   - Two granularities: **frame-level** (always on, free, derived from existing Phase 2 enter/exit timings) and **opcode-level** (opt-in via profile mode, sampling-based to keep overhead tolerable).
   - We are not a cachegrind viewer. We are not a "click to enable Xdebug profile" UI. The pitch: *"You don't need Xdebug for anything anymore."*
   - Phase: framework lands in v1 (always-on frame-level flame graph from existing data); opcode-sampling profiler ships in **v1.1** as a focused 1-week sprint.

4. **Self-hosted trace sharing** (steal from Clockwork "Sharing", improve the privacy posture)
   - `make trace-share TRACE=<id>` and a "Share" button in the UI:
     1. Run redaction pipeline (already exists for headers/body keys per A.4) — never share the unredacted trace.
     2. Create a deep-link bundle (`<id>.cptrace` + sidecars + redaction manifest) and POST it to `$PERISCOPE_SHARE_ENDPOINT`.
     3. Default `PERISCOPE_SHARE_ENDPOINT` is unset — sharing is no-op until the user configures their own self-hosted Rust binary (`periscope-share`, ships in same daemon repo) running on their own infra. We do **not** run a SaaS in v1.
     4. Returns a public URL: `https://traces.example.internal/abc123` that opens a read-only periscope UI viewing that trace.
   - The Rust `periscope-share` server is ~300 LoC: one POST endpoint that stores the bundle in S3/local-disk + a static UI bundle that reads via the same `/api/*` shape.
   - Why we don't piggyback on Clockwork's free SaaS: trace contents include cookies, request bodies, captured variables. We will never default to "send this to a third party we don't control."
   - Phase: v1.1 — landing it sooner is fine if a real support workflow demands it.

5. **UI density rework before Phase 9b build** (steal Clockwork's tighter query-panel layout)
   - Before any 9b code lands, re-do the 9a static mockup with target densities:
     - Query row: ≤ 32px tall, monospace SQL truncated to one line, duration + connection right-aligned, expand-on-click.
     - Log row: ≤ 24px, level chip (8px), 1-line message, timestamp right-aligned.
     - Timeline rows: 16px high, color by event type, hover shows full payload.
   - Mockup pass → 3-dev review (per existing 9a manual verification) → only then start 9b.
   - Why it matters: Clockwork's screenshot scans 4× faster than our current mockup. UX density is a trust signal — sparse layouts read as "toy."

These five are the *visible* surface. The deeper differentiators (time-travel scrubbing, AI-native API, no-Xdebug-dependency, deterministic insights with fix suggestions, lazy-safe variable inspection) remain the headline pitch.

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

**File**: `tests/real-world/laravel-skeleton/`
- Pull `laravel/laravel` skeleton into a git submodule
- Run its test suite with the extension loaded
- Run a sample request through the periscope UI

**File**: `tests/real-world/private-app/` (gated submodule, maintainer-only)
- The maintainer's real production-grade Laravel app — listings, agencies, jobs, queues, the full surface
- Run a representative set of routes; verify no regressions, all observability events surface as expected
- Used as the integration-truth signal before each release

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
- [ ] Use periscope to debug a real bug in `the maintainer-supplied private Laravel app) — does it actually help, or is it slower than `dd()`?

---

## Phase 11: Distribution

### Overview

Make installation a single command on macOS and Linux.

**MCP shipping decision (locked-in 2026-05-08 during 5c):** the MCP server ships as a `php artisan periscope:mcp` command inside the Laravel adapter, built on **`laravel/mcp`** (Laravel 13's first-party MCP SDK). NO separate Rust `periscope-mcp` binary. Users get `claude mcp add periscope -- php artisan periscope:mcp` and the tool registration works.

### Changes Required

#### 1. PECL-style package

**File**: `package.xml` (PECL metadata)

Standard PECL extension package so users can `pecl install periscope` if they prefer the canonical PHP path.

#### 2. Homebrew tap

**Files**:
- A separate repo `periscopephp/homebrew-php-periscope`
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

Publish to the VSCode Marketplace as `periscopephp.php-periscope`.

#### 5. Uninstall

**File**: `scripts/uninstall.sh`

Reverses all the install steps. Important — segfault-prone tools must be easy to remove.

### Success Criteria

#### Automated Verification:
- [ ] `brew install periscopephp/php-periscope/php-periscope` works in a fresh CI VM
- [ ] `bash scripts/install.sh` works on a fresh Ubuntu 22.04 + PHP 8.3 image and a fresh macOS image
- [ ] `code --install-extension periscopephp.php-periscope` succeeds on Linux + macOS
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
- Direct outreach to Laravel Discord, Laracasts community, Laravel News editor

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
- Real-world (Phase 10): Laravel skeleton + maintainer-supplied private test suites with periscope loaded

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

## Appendix A: Decisions & Conventions Captured During Implementation

This appendix consolidates everything from the project's persistent memory (`/.claude/projects/.../memory/*.md`) so the plan is the single source of truth. Each subsection explains the rule, the reason, and where in the phase plan it applies.

### A.1 — Laravel-only in v1, with the architecture set up for future framework packages

**Rule (v1):** ship `periscopephp/laravel` and nothing else. Test, market, and support **only** Laravel. Don't pursue Symfony / WordPress / CodeIgniter / plain PHP in v1.

**Architectural rule (timeless):** the C extension stays framework-agnostic — no Laravel-specific code at the engine layer (CLAUDE.md invariant #8). Framework-specific hooks live in Composer adapter packages. This is correct engineering regardless of how many frameworks we ultimately support.

**Why this combination:**
- Scope discipline — v1 ships something coherent and excellent, not three half-finished adapters.
- Architecture stays clean — when we eventually add `periscopephp/symfony` etc., zero rework on the extension or daemon.
- Laravel community is concentrated and targetable (Laravel News, Laracasts, Discord, Twitter); seeding v1 is tractable.

**Layers:**

- Layer 1 — **C extension** (`extension/`): framework-agnostic, observes every userland function call via Zend Observer API.
- Layer 2 — **Laravel adapter** (`laravel-adapter/`, the only adapter in v1): registers a `PeriscopeServiceProvider` that fires when Laravel boots its container. Hooks `DB::listen`, log channels, queue events, cache events, mail events, Redis events, HTTP client middleware, route resolution, auth.
- Layer 3 — **Daemon + UI**: render whatever events arrive in the trace; oblivious to which adapter produced them.

**Future packages (post-v1, separate repos / Composer packages, same architecture):**
- `periscopephp/symfony` — hooks Symfony Profiler events
- `periscopephp/wordpress` — hooks `pre_get_posts`, `$wpdb`, the HTTP API, REST API
- `periscopephp/codeigniter` — hooks CI4 events, Query Builder, validation, sessions

Timing depends on community demand; not committed for v1.

**Do not** add a `periscope.framework=laravel|symfony|...` INI knob to the C extension to gate behaviour. Detection is solved by Composer + service provider auto-discovery.

### A.2 — Adaptive UI: only render panels for event types in the trace

**Rule:** When designing/building the browser UI in Phase 9, only render panels for event types that actually exist in the current trace.

**Rules:**

- If the trace has zero `sql_query` events → no SQL panel.
- If the trace has zero `dispatched_job` events → no Jobs panel.
- If the trace has zero `mail_sent` events → no Mail panel.
- Same for cache, redis, http, queue, events.
- The minimum-always-shown panels: Source, Variables/Scope, Call Stack, Timeline, Logs (every PHP project has logs).
- Laravel session with the adapter → all panels light up automatically.

The trace is the source of truth. Don't gate panel visibility on a `framework` field — gate it on `events.some(e => e.type === 'sql_query')`. This also means future framework packages (post-v1) emit the same event types and get the same panels for free, no UI changes.

User's framing: *"smart and adaptive — not cluttered with useless features the project does not benefit from."*

### A.3 — Laravel Collection rendering

Phase 3 already captures them functionally — `Illuminate\Support\Collection`'s internal `$items` array is dumped as a normal private property: `object(Illuminate\Support\Collection)#7 {-items: array(N) [...]}`. The data is all there.

Phase 5 adds cosmetic polish:

- **Laravel adapter**: emit a hint marking instances of `Illuminate\Support\Collection`, `Illuminate\Database\Eloquent\Collection`, `LazyCollection`.
- **UI (Phase 9b)**: when a captured object's class is in this Laravel collection allowlist, render as `Collection(N) [items...]` instead of `object(Collection)#X {-items: [...]}`. Same data, cleaner presentation.

Eloquent models with relationships also benefit from the existing `<lazy>` path — relations not yet loaded show as `<lazy>` instead of triggering a database round-trip during inspection.

### A.4 — Trace MUST capture full HTTP request envelope (URL, headers, cookies, body, session, response)

**Rule:** Every recorded trace needs the full incoming request and response captured at the framework-agnostic level, with framework adapter enrichment on top.

**Why:** Without request context the timeline is half-blind. Looking at `User::find($id)` is meaningless without knowing what URL/route/headers triggered it. This is the first thing any developer expects in a debugger UI.

**Phase 4 — Trace schema additions** (`proto/trace.capnp`): `Request` struct (method, uri, headers, cookies, query, postParams, rawBody capped, files, remoteAddr, scheme), `Response` struct (statusCode, headers, body, durationMicros, peakMemoryBytes), both attached to `Meta`. (Schema landed in Phase 4.)

**Phase 4 — C extension capture** (`extension/periscope_request.c`): read at RINIT (request data) and RSHUTDOWN (response data), framework-agnostic — `$_SERVER`, `$_GET`, `$_POST`, `$_COOKIE`, `$_FILES`, `php://input` for raw body, `http_response_code()` and `headers_list()` at shutdown. Skip when SAPI is `cli`.

**Phase 5 — Laravel adapter enrichment** (`laravel-adapter/src/Hooks/RequestHook.php`): emit a `requestResolved` event with route name, controller@method, route parameters, authenticated user, session info, CSRF token presence, validation errors, locale.

**Phase 9 UI:**
- Always-on **Request** panel: method, URL, headers, cookies, body — present in every trace.
- Always-on **Response** panel: status, headers, body, duration, peak memory.
- When Laravel adapter present: enrich with resolved route + auth user above raw data.

**Privacy:**
- `periscope.redact_headers` INI (default: `Authorization,Cookie,Set-Cookie,X-Auth-Token`) — those keys stored as `<redacted>`.
- `periscope.redact_body_keys` for POST body / JSON keys (`password`, `password_confirmation`, `credit_card`, etc.).
- Document that traces sit on disk in `/tmp/periscope/` — users responsible for not committing them.

### A.5 — Every observability event must include precise call site (file:line + snippet)

**Rule:** When a Laravel hook (or any future framework adapter) records an observability event, it must include:

- **File**: the path of the topmost *user-code* frame on the call stack (skipping vendor/, Illuminate\*, Symfony\*).
- **Line**: line number within that file.
- **Snippet**: 3 lines of source code (line-2, line, line+2) verbatim, so devs see the Eloquent / Cache / Log call inline in the panel.
- **Stack to root**: list of frame_ids from the user-code frame back to the request entry.

**User framing:** *"For 10× queries, show the exact file/line where it was executed so devs immediately see where the N+1 is coming from."*

**Schema additions for Phase 5** (additive, no breaking change):

```capnp
struct CallSite {
  file        @0 :Text;
  line        @1 :UInt32;
  snippet     @2 :List(SnippetLine);
  frameStack  @3 :List(UInt32);   # frame ids root → leaf
}

struct SnippetLine {
  number      @0 :UInt32;
  source      @1 :Text;
}

# Add to ObservabilityEvent struct:
struct ObservabilityEvent {
  ...
  userCallSite @13 :CallSite;
}
```

**Laravel adapter implementation** — `debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 30)`, skip vendor/laravel/, vendor/illuminate/, vendor/symfony/, vendor/composer/, capture file+line+3-line snippet from first user frame. Apply to ALL event types: SQL, log, cache, redis, http, jobs, mail, events.

**UI rendering (Phase 9):** every event row has a 📍 icon that expands to file:line + snippet. Click a line number → IDE deep link (`vscode://file/path/to/foo.php:42`, `phpstorm://open?file=...&line=42`).

### A.6 — AI-native trace access (HTTP API + MCP server + analyst)

**Rule:** Claude Code, Cursor, Codex, Continue, aider, and any other AI dev tool must be able to query periscope traces programmatically, AND interpret them to recommend fixes.

**User framing:** *"AIs should have access to give devs the ability to tell what's happening where a naked eye cannot see — at the end, AI can make sense of what's happening when you log in and tell you what to improve, what's not right."*

**Two delivery channels — ship both:**

1. **HTTP REST API** — universal. The Rust daemon (Phase 6) serves browser UI at `localhost:9999`; same daemon exposes JSON API at `localhost:9999/api/*`. Endpoints:
   - `GET /api/traces` — list recent traces (id, timestamp, request URI, duration, exception)
   - `GET /api/traces/{id}` — full trace as JSON
   - `GET /api/traces/{id}/frames` — paginated frames
   - `GET /api/traces/{id}/frames/{frameId}` — one frame with locals + scope
   - `GET /api/traces/{id}/queries` — SQL events (Phase 5)
   - `GET /api/traces/{id}/timeline` — event-ordered list for scrubbing
   - `GET /api/traces/{id}/exceptions` — any exceptions thrown
   - `GET /api/traces/{id}/insights` — **deterministic** heuristics: N+1 detection, DB-in-loop, slow-frame ranking, memory hog detection. Independent of any AI.
   - `GET /api/file?path=<abs>&line=<n>&radius=<k>` — read lines `line-k` through `line+k` of a source file on disk. Lets the UI / AI agent zoom from the trace's stored ±6 snippet to any radius (or the whole file) without bloating the trace. Path must be inside the project (daemon enforces project root). Returns the lines plus the file's mtime so the UI can warn if the file was edited after the trace was captured.
   - `POST /api/traces/{id}/rerun?mode=off|full` — replays the trace's captured request envelope (method, URL, headers, body, cookies) against the live app, with `X-Periscope-Mode: <mode>` header injected. The C extension's RINIT honours the header and short-circuits capture for `off`. UI uses this for the "Re-run with periscope off" button — user gets a side-by-side comparison of "trace time (with periscope)" vs "real time (no periscope)" without touching php.ini or restarting FPM. The C-side header reader landed in 5d.3 so the daemon endpoint can be implemented in Phase 6 without further extension work.

2. **MCP server** — Anthropic's protocol for tool servers, supported by Claude Code natively. **Ship as a `php artisan periscope:mcp` command in the Laravel adapter using `laravel/mcp` (Laravel 13's first-party MCP SDK)** — NOT a separate Rust binary. This means:
   - No extra binary to distribute / install — already in the Composer package.
   - Laravel-native tool registration (PHP attributes on tool methods).
   - Works with Claude Code / Cursor / any MCP client over stdio out of the box.
   - The Rust daemon's scope stays narrow (DAP + replay + UI HTTP only).

   Tools to expose:
   - `list_traces(limit?)` — recent traces
   - `get_trace(id)` — full trace
   - `find_traces(uri_pattern, status_code?, has_exception?)` — search
   - `explain_slowness(trace_id)` — heuristic-based perf analysis
   - `get_frame(trace_id, frame_id)` — drill in
   - `get_variable(trace_id, frame_id, name)` — specific local at point in time
   - `diff_frames(trace_id, frame_a, frame_b)` — what changed between two frames

3. **In-trace AI advisories (Phase 5c — done)** — opt-in `AiAdvisor` in the Laravel adapter calls **`laravel/ai`** (Laravel 13's first-party SDK, suggests dependency) when enabled. Emits `ai_suggestion` events for slow queries, N+1 patterns, exceptions, and error logs — kind-specific system prompts, hard-capped per request, errors swallowed. Provider-agnostic via the SDK: OpenAI, Anthropic, Gemini, Ollama (free-local), OpenRouter (free-tier), DeepSeek, Groq, Mistral, xAI, Azure, Bedrock — same code, switch via `.env`. We do not roll our own HTTP client / provider abstraction.

4. **AI introspection of the host Laravel app (Phase 5d / 10)** — pair periscope traces with **`laravel/boost`** in real-world test apps so the AI agent reading our trace also has live access to the host app's routes / DB / models / config. Periscope = "what happened during this request"; boost = "what is this app's structure right now". Together = "complete Laravel context for the AI."

**Patterns the deterministic insights endpoint detects (Phase 6)**:

| Pattern | Recommendation |
|---|---|
| Same SQL pattern fired N times within one frame, bindings differ only by id | "N+1 detected — eager-load with `with('agency')` at `ListingsController.php:42`" |
| Function called X times in a loop, each fires a query | "DB-in-loop pattern — chunk or batch" |
| Memory peaked > Y MB during one frame | "Loaded N models eagerly — use lazy collection or chunk" |
| Cache::get always misses for the same key | "Cache miss storm — keys never written by any seen frame" |
| Sequential `Http::get(...)` calls each > 100ms | "HTTP serialization — use Http::pool" |
| Variable `$user` set to null at frame X but expected non-null downstream | "Auth state lost in middleware Y" |

**Architecturally important:** deterministic insights MUST exist independently of AI access. AI is a multiplier on top of structured signals; it isn't the primary detector. Otherwise the product depends on an AI vendor and gets worse if the AI is down or expensive.

**Phasing:**

- **Phase 4** (done): `--json` mode on `periscope-dump` — any agent can shell out to get trace data.
- **Phase 6 (DAP daemon)**: `/api/*` endpoints in same Rust binary serving browser UI.
- **Phase 9b (Browser UI)**: shares same `/api/*` — single source of truth. Adds an "Ask Claude" button that ships the trace summary + a question to the user's configured AI provider.
- **Phase 11 (Distribution)**: ship `periscope-mcp` as a separate sub-command or binary. Add `claude mcp add periscope` instructions in README. Add Cursor / Continue / aider integration docs.

**Privacy guardrails:**
- Default to `localhost`-only binding.
- Never expose externally without explicit `--listen 0.0.0.0` flag.
- Document that pointing an internet-connected AI agent at a trace dir means that AI vendor *sees* the trace contents (cookies, request bodies). Document, don't try to prevent — but flag loudly.
- Phase 5+ Laravel adapter redaction (auth tokens, password fields) MUST run before traces hit AI agents.

**Marketing line:** *"Your AI co-pilot reads every request and tells you what's wrong — N+1 queries, slow frames, lost auth state, memory hogs — before you even know to look."*

### A.7 — No Xdebug dependency, ever (we replace it, we don't lean on it)

**Rule:** php-periscope must never require, recommend, integrate, or piggyback on Xdebug. Anything users currently use Xdebug for that they still want, **we ship ourselves** — or we defer to v2 with our own implementation, never as "go install Xdebug for that part."

**User framing (2026-05-08):** *"I don't want anything to do with Xdebug. We will create our own profiling and graphs. We are moving users from Xdebug pain — please, our tool should cover everything for us. Let's not rely on other people."*

**Why:**
- The pitch is "you don't need Xdebug anymore." Bundling an Xdebug viewer or recommending Xdebug for profiling tells the user the pitch is a lie.
- Xdebug's setup pain (multiple PHP versions, broken FPM connections, port collisions, segfaults under macOS) is the entire reason this project exists. Inheriting that pain by reference defeats the project.
- Self-sufficiency = a single `brew install` covers every workflow Xdebug covered. That's the "easy to set up" pillar (memory: feedback_product_values).

**What this rules out (we will not do these):**
- Built-in cachegrind viewer — even though Clockwork ships one, we don't.
- "Click to enable Xdebug profile mode" buttons in our UI.
- Documentation that says "install Xdebug for opcode-level stepping."
- Any optional Xdebug bridge / adapter in `extension/` or `daemon/`.

**What we ship instead:**
- **Frame-level flame graph (v1)** — derived from the per-frame `enter_micros` / `exit_micros` we already capture in Phase 2. SolidJS renderer in Phase 9b. Free.
- **Sampling profile mode (v1.1)** — `PERISCOPE_PROFILE=1` flips the C extension into a sampling profiler (default 1ms sample interval, configurable via `periscope.profile_sample_us`). Writes to a `.profile` sidecar of the trace. Daemon parses + renders.
- **Opcode-level zoom (v2 candidate)** — eventually, if real-world data shows function-boundary granularity isn't enough. Our own Zend implementation, not Xdebug's.
- **Function trace export (v1.1)** — already have it via `make periscope-dump --json`, plus `/api/traces/{id}` HTTP endpoint. Anyone who wants Xdebug-style trace text can pipe through `jq`.

**Where this lives in the code:**
- C extension profile sampler: `extension/periscope_profile.c` (v1.1 add).
- Daemon sidecar parser + flame-graph endpoint: `daemon/src/profile.rs` + `GET /api/traces/{id}/profile` (v1.1 add).
- UI flame-graph component: `ui/src/panels/Flame.tsx` (v1 lands frame-level; v1.1 lands opcode-level when sampler exists).

**Allowed exception:** documenting *for migration purposes* how Xdebug's features map onto periscope's (`docs/MIGRATING_FROM_XDEBUG.md`) is fine — that's marketing, not dependency.

#### Why our profiler will beat Xdebug — concrete technical leverage

We are not "another PHP profiler." We are starting in 2026 with 15 years of profiling research that did not exist when Xdebug was designed. The advantages are real and we should bank every one of them. Xdebug's profile mode is **22.7× slowdown** on our bench (`scripts/bench-vs-xdebug.sh`); periscope's full-capture mode is already **48× faster than Xdebug profile mode at the same coverage**. Profile-specific work continues that lead:

| Lever | Xdebug | php-periscope |
|---|---|---|
| **Engine hook** | Legacy `zend_execute_ex` override (replaces the entire opcode dispatch loop — pays cost on every call whether we're sampling or not) | Zend Observer API (PHP 8.0+) — JIT-friendly, opt-in per-function, near-zero overhead when sampler is idle |
| **Timer source** | Wall-clock via `gettimeofday()` on every call, even for profile mode | Modern: `clock_gettime(CLOCK_MONOTONIC_RAW)` for sub-µs resolution; `mach_absolute_time` on macOS; `posix_timer_create` with `SIGEV_THREAD` driving an async-signal-safe sampler thread |
| **Sampling vs full trace** | Full cachegrind dump — every call recorded → 100MB+ profile files | Sampling profiler (default 1ms interval, configurable). Files stay <5MB because we only capture stacks at sample boundaries, not every call |
| **Stack capture** | Walks `EG(current_execute_data)` synchronously per call | Lock-free SPSC ring buffer between PHP thread and a dedicated sampler thread. Sampler walks the stack on a tick; PHP-side cost is one ring-buffer write |
| **Storage format** | Plain-text cachegrind — gigabyte-size files for long requests, slow to parse, no random access | Cap'n Proto sidecar — zero-copy reads, mmap-friendly, random seek to any sample. Optionally zstd-compressed (~10× smaller) |
| **Reader** | Cachegrind parsers are typically PHP or Java; reading large files is slow | Rust + Cap'n Proto in our daemon. 50MB profiles parse in <100ms (already validated for the trace format in Phase 4) |
| **Rendering** | Static cachegrind output → external viewer (kcachegrind, qcachegrind, webgrind) → opens in a separate process | SolidJS flame graph rendered in our existing UI tab. Fine-grained reactivity → 60fps zoom and pan even on 100k samples. WebGL/Canvas path for huge graphs |
| **Memory tracking** | Profile mode does not capture memory deltas | Per-frame `memory_get_usage()` delta is already captured in v1; profile mode joins this with sampled stacks → "show me which sampled stack added the most memory" |
| **Per-frame variables** | Profile mode strips variables — performance-only view | Our sampler can opt-in capture top-N locals at sample boundaries (cheap because we already serialise them in Phase 3); flame graph nodes click-through to variable state at that sample |
| **Time-travel** | Not possible — cachegrind is post-mortem static | Flame graph scrubs *with* the timeline. Drag the cursor backward → flame graph rewinds → click a flame → variable scope at that point. Nobody else has this |
| **Hardware counters** | Not exposed | Optional `perf_event_open` integration on Linux (cache misses, branch mispredicts, page faults) attached to samples; users who run on bare metal get hardware-level insight without leaving the UI |
| **Concurrency model** | Synchronous; profile mode pauses the request thread for I/O | Tokio async daemon, lock-free MPSC ring buffer to writer thread. PHP thread never blocks on profile I/O |
| **AI-readable output** | Cachegrind is human-only; no AI tooling speaks it | Profile sidecar lives at `/api/traces/{id}/profile` as JSON, plus an MCP `analyze_profile(trace_id)` tool. AI agents read flame data directly |
| **Build & ship** | Compile-time PHP extension, manual ini config per PHP version | `brew install` ships extension+daemon for every brew PHP; `PERISCOPE_PROFILE=1` flips it on for one request |
| **Live overhead** | Profile mode forces always-on full capture per request | Sampling means the user can leave profile mode on continuously in dev with single-digit % overhead, not 22×. Per-request opt-in via `?periscope_profile=1` query string (already half-built — see commit 7210bed for the per-request mode header) |

**Cumulative effect:** profile mode that's tolerable on every request, scrubs in real time, integrates with variables and the timeline, ships AI-readable output, and uses tooling Xdebug literally could not use because the tech didn't exist when Xdebug was written. We don't need Xdebug because the legacy approach was the reason Xdebug profile mode is unusable in practice.

**Implementation phasing:**
- **v1 (free)**: frame-level flame graph from existing per-frame timings + memory deltas. Already captured; just needs the SolidJS renderer in Phase 9b.
- **v1.1 (one focused sprint)**: sampling profile mode — `extension/periscope_profile.c` + `daemon/src/profile.rs` + `ui/src/panels/Flame.tsx` opcode-level zoom.
- **v2 candidate**: hardware perf counter integration; AI-driven flame regression detection between traces.

---

## References

- This conversation: chat history with the user on 2026-05-08 about why Xdebug is hard to set up and what a modern alternative would look like
- Project repo: https://github.com/periscopephp/php-periscope
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
