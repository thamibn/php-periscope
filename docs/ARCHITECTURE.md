# Architecture

## High-level diagram

```
┌─────────────────────────────────────────────────────────────────────────┐
│  Developer's machine                                                     │
│                                                                          │
│  ┌─────────────────────────┐     ┌─────────────────────────────────┐   │
│  │  PHP process            │     │  VSCode (or Neovim, Zed, etc.)  │   │
│  │  (running Laravel app)  │     │                                  │   │
│  │                         │     │  ┌────────────────────────────┐ │   │
│  │  ┌───────────────────┐  │     │  │  php-periscope debug       │ │   │
│  │  │  php-periscope.so │  │     │  │  configuration             │ │   │
│  │  │  (C extension)    │  │     │  └────────────┬───────────────┘ │   │
│  │  │                   │  │     │               │ DAP (stdio)     │   │
│  │  │  • Observer hooks │  │     └───────────────┼─────────────────┘   │
│  │  │  • zval capture   │  │                     │                     │
│  │  │  • Trace writer   │  │                     ▼                     │
│  │  │  • Daemon link    │◄─┼────UDS───────┐  ┌──────────────────────┐ │
│  │  └───────────────────┘  │              │  │  periscope-daemon    │ │
│  │           │             │              │  │  (Rust binary)       │ │
│  │           │ Cap'n Proto │              └──┤                      │ │
│  │           ▼             │                 │  • DAP server        │ │
│  │  ┌───────────────────┐  │                 │  • Replay engine     │ │
│  │  │  /tmp/periscope/  │  │                 │  • Trace reader      │ │
│  │  │   *.cptrace       │◄─┼─────mmap────────┤  • WebSocket server  │ │
│  │  └───────────────────┘  │                 │                      │ │
│  └─────────────────────────┘                 └──┬───────────────────┘ │
│                                                  │                     │
│  ┌─────────────────────────────┐  ws (port 9999)│                     │
│  │  Browser at localhost:9999  │◄───────────────┘                     │
│  │  (SolidJS UI)               │                                       │
│  │                             │                                       │
│  │  • Source + breakpoints     │                                       │
│  │  • Variables / scope        │                                       │
│  │  • Timeline scrubber        │                                       │
│  │  • Queries / Logs / Jobs /  │                                       │
│  │    Cache / Redis / HTTP     │                                       │
│  └─────────────────────────────┘                                       │
└─────────────────────────────────────────────────────────────────────────┘
```

## Components

### 1. C extension (`extension/`)

A `php-periscope.so` loaded via `php.ini`'s `extension=` directive. Built on Zend Observer API (PHP 8.0+).

**Responsibilities:**
- Register a function-call observer in `MINIT`
- On every userland function entry/exit, capture name, args, return value
- Pause execution when a breakpoint is hit (busy-wait flag with microsleep)
- Communicate live with the Rust daemon over a Unix domain socket
- Stream completed frames to a Cap'n Proto trace file
- Expose `periscope_record_event()` to userland (for the Laravel adapter)

**Key files:**
- `extension/periscope.c` — extension entry point + Observer registration
- `extension/periscope_capture.c` — zval-to-Value serializer
- `extension/periscope_trace.c` — trace file writer
- `extension/periscope_daemon_link.c` — UDS protocol to daemon
- `extension/periscope_userland_api.c` — `periscope_record_event()` and friends

**Why C and not Rust:** PHP extensions interact with the Zend engine through C-level pointer manipulation (zvals, refcounts, hash tables). Rust FFI would help but would not eliminate the C surface area; staying in C keeps the boundary clean.

### 2. Rust daemon (`daemon/`)

A single static binary (`periscope-daemon`) speaking DAP over stdio and serving a WebSocket on `localhost:9999`.

**Responsibilities:**
- DAP protocol implementation (subset — see plan)
- Listen on `/tmp/periscope/daemon.sock` for events from the C extension
- Read completed traces (mmap Cap'n Proto)
- Build replay indexes
- Serve the browser UI (HTTP + WS)

**Why Rust:**
- Memory safe — no segfaults in the daemon, even under malicious input
- Single static binary — no runtime to install
- Excellent async story (`tokio`) for the WebSocket + UDS + DAP triple-multiplexing
- `capnp` Rust crate is mature

**Key crates:**
- `tokio` — async runtime
- `capnp` — trace reading
- `serde` + `serde_json` — DAP messages
- `tracing` — observability of the debugger itself
- `axum` or `warp` — HTTP/WS server

`#![forbid(unsafe_code)]` on the entire daemon crate.

### 3. Laravel adapter (`laravel-adapter/`)

A Composer package (`thamibn/periscope-laravel`) auto-discovered by Laravel.

**Responsibilities:**
- Register hooks for: `DB::listen`, `Log::*`, `Cache::*` events, `Event::listen('*')`, `Queue::before`, `Mail::send`, Redis events, HTTP client middleware
- Forward each captured event to `periscope_record_event()` (the C extension's userland-callable function)
- Detect N+1 queries and attach warnings

**Why a Composer package and not just framework hooks in C:**
- Laravel APIs change between major versions; PHP-side adapter shields the C extension from framework churn
- Zero ceremony for users: `composer require` and it works

### 4. Trace format (`proto/`)

Cap'n Proto schema describing recorded function frames and observability events.

**Why Cap'n Proto over Protobuf:**
- Zero-copy reads via mmap — replay scrubbing benefits hugely
- Random access via fixed-offset structs
- Protobuf would require a parse step on every seek, which adds up at 60fps timeline scrubbing

**Trade-off:** Cap'n Proto's C library (`capnp-c`) is less mature than Protobuf's. We accept this and pin a vendored copy. Fallback plan: 24-hour migration to Protobuf if `capnp-c` causes blocking issues.

### 5. Browser UI (`ui/`)

A SolidJS + Tailwind web app, built with Vite and Bun, served by the daemon at `localhost:9999`.

**Why SolidJS over Svelte:**
- Smaller runtime (~12KB vs ~30KB)
- Fine-grained reactivity → 60fps timeline scrubbing without virtual-DOM overhead
- React-like JSX → contributors find it familiar

**Communication:** WebSocket to the daemon. The daemon emits events; the UI renders them. No state in the UI beyond presentation.

## Decisions registered

### Cap'n Proto over Protobuf for trace format
**Date:** 2026-05-08
**Reason:** Zero-copy random access for replay scrubbing.
**Reversibility:** Medium. Schema definitions are simple; switch would be ~24 hours of migration work.

### DAP over DBGp for IDE protocol
**Date:** 2026-05-08
**Reason:** DAP is the modern multi-IDE standard. DBGp is an XML-over-TCP relic from 2003 used by Xdebug. Speaking DAP natively means we skip the DBGp-to-DAP bridge and gain VSCode/Neovim/Zed/JetBrains support directly.
**Reversibility:** Low. DAP is structurally different from DBGp; switching would be a rewrite of `daemon/src/dap.rs`.

### Zend Observer API over older Zend Extension hook macros
**Date:** 2026-05-08
**Reason:** Observer API (PHP 8.0+) is the modern, supported hook mechanism. Used in production by OpenTelemetry-PHP. Cleaner API surface.
**Reversibility:** High. Mostly a question of which `zend_observer_*` vs `ZEND_DECLARE_MODULE_GLOBALS` style we use; could swap if needed.

### Function-boundary recording, not opcode-level
**Date:** 2026-05-08
**Reason:** 100× smaller traces, simpler replay, lower performance overhead. Trade-off: lose mid-function stepping. Acceptable for v1; revisit in v2 if users demand it.
**Reversibility:** Medium. Opcode-level capture would require additional Observer hooks and a much larger trace format. Big lift but contained.

### SolidJS over Svelte for UI
**Date:** 2026-05-08
**Reason:** Smaller bundle, better fine-grained reactivity for scrubbing, JSX feels familiar.
**Reversibility:** High. UI is a thin layer over the daemon's WebSocket protocol; could rewrite in any framework in days.

### Single-PHP-version target (8.3) for v1
**Date:** 2026-05-08
**Reason:** Each additional PHP version multiplies the test matrix and reveals new edge cases in zval handling. Lock to 8.3 to ship; expand to 8.1/8.2/8.4 in v1.1.
**Reversibility:** High. Adding versions is purely additive work.

## Why we don't do certain things

### Why no production debugging in v1
Production safety is its own engineering project — sampling, non-blocking pause, snapshot breakpoints, secure auth, multi-user. Bundling it with v1 would 3× the scope. v2 priority.

### Why no async (Fibers/Swoole/Frankenphp/Octane) in v1
Async PHP runtimes break the "one trace per request, one thread" model. Each one (Fibers, Swoole, Frankenphp) needs its own integration work. Single-thread is the 80% case for Laravel apps; ship that first.

### Why no DBGp compatibility shim
Some users will ask for "Xdebug-compatible mode" so PhpStorm Just Works. We don't ship this because:
1. PhpStorm does support DAP (via plugin)
2. Maintaining two protocol implementations doubles the maintenance burden
3. DBGp's XML-over-TCP is awful and we don't want to reward it
