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
- `extension/periscope_trace.cc` — Cap'n Proto trace file writer (C++ for the capnp runtime)
- `extension/periscope_daemon_link.c` — UDS protocol to daemon
- `extension/periscope_filter.c` — header / cookie / param redaction
- `extension/periscope_userland.c` — `periscope_record_event()` and the userland API surface

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

A Composer package (`thamibn/php-periscope-laravel`) auto-discovered by Laravel 12.x / 13.x.

**Responsibilities:**
- Register eighteen event-listener hooks (`laravel-adapter/src/Hooks/`): queries, logs, cache, jobs, batches, events, mail, notifications, redis, HTTP client, exceptions, model writes, view renders, gates, console commands, schedule events, request lifecycle, and `dd()` / `dump()` captures
- Forward each captured event to `periscope_record_event()` (the C extension's userland-callable function)
- Detect N+1 queries and attach warnings
- Run a slow-query analyser and an opt-in **AI advisor** (`Periscope\Laravel\Insights\AiAdvisor`) that uses `laravel/ai` to suggest fixes
- Inject the floating toolbar chip into HTML responses
- Mount the SolidJS UI inside the host app at a configurable prefix (`/periscope` default)
- Auto-register the periscope **MCP server** via `laravel/mcp` (`php artisan mcp:start periscope`) — see [§4 below](#4-ai-native-surface)

**Why a Composer package and not just framework hooks in C:**
- Laravel APIs change between major versions; PHP-side adapter shields the C extension from framework churn
- Zero ceremony for users: `composer require` and it works
- Auto-discovery surfaces both the service provider and the MCP server

### 4. AI-native surface

The `laravel/mcp` integration ships eight tools that proxy to the daemon's HTTP API:

| Tool | Returns |
|---|---|
| `list_traces` | Recent requests, most-recent first |
| `get_trace` | Full trace document for one request |
| `get_summary` | Totals + top hot frames + slow queries |
| `get_insights` | N+1, slow queries, exceptions, error logs, AI suggestions |
| `get_timeline` | Time-ordered frame + event timeline |
| `get_state` | Reconstructed state at a microsecond — call stack, scope vars, prefix events |
| `query_events` | Events by type / JSON-path filter / grouping |
| `read_file` | A slice of project source so the AI can reason about code |

Same data source as the UI. No second source of truth.

### 5. VSCode extension (`vscode-extension/`)

Registers a `periscope` debug type with VSCode's DAP client. On `F5` it spawns `periscope-daemon` and pipes DAP messages over stdio. Status-bar liveness chip shows when the daemon is up. Marketplace listing is pending v0.2.

### 6. Trace format (`proto/`)

Cap'n Proto schema describing recorded function frames and observability events.

**Why Cap'n Proto over Protobuf:**
- Zero-copy reads via mmap — replay scrubbing benefits hugely
- Random access via fixed-offset structs
- Protobuf would require a parse step on every seek, which adds up at 60fps timeline scrubbing

**Trade-off:** Cap'n Proto's C library (`capnp-c`) is less mature than Protobuf's. We accept this and pin a vendored copy. Fallback plan: 24-hour migration to Protobuf if `capnp-c` causes blocking issues.

### 7. Browser UI (`ui/`)

A SolidJS + Tailwind web app, built with Vite and Bun, served by the daemon at `localhost:9999` and (when `PERISCOPE_UI_ENABLED=true`) by the Laravel adapter at `/periscope`.

**Why SolidJS over Svelte:**
- Smaller runtime (~12KB vs ~30KB)
- Fine-grained reactivity → 60fps timeline scrubbing without virtual-DOM overhead
- React-like JSX → contributors find it familiar

**Eighteen panels** render based on event presence: Overview, Source + Scope, Queries, Models, Logs, Cache, Jobs, Events, HTTP, Redis, Mail, Notifications, Exceptions, Dumps, Insights, Performance (flame graph), Request, Response. Empty panels are hidden.

**Communication:** WebSocket + HTTP to the daemon. Same wire when the UI is mounted in-app — the bundle reads from `/periscope/api/*` instead of `:9999/api/*`. Standalone `.html` export reads from inlined `window.PERISCOPE_TRACE` and the daemon-only features (live mode, breakpoints) gracefully degrade.

### 8. Distribution

- `scripts/install.sh` — one-line install for macOS + Linux. Detects every brew PHP, builds the `.so` against each, writes `99-periscope.ini`, builds the Rust daemon, drops binaries into `/opt/homebrew/bin` or `/usr/local/bin`
- `homebrew/Formula/php-periscope.rb` — Homebrew formula (tap-based until the public tap repo lands in v0.3)
- `extension/package.xml` — PECL packaging (public release pending v0.3)
- `vscode-extension/` — packaged as `.vsix` (marketplace listing pending v0.2)

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

### PHP version target — 8.3 and 8.4 for v1
**Date:** 2026-05-08 (initial), widened 2026-05-14
**Reason:** Each additional PHP version multiplies the test matrix and reveals new edge cases in zval handling. v1 supports 8.3 + 8.4 (matches `scripts/install.sh`). 8.1 + 8.2 are deferred to v1.1.
**Reversibility:** High. Adding versions is purely additive work.

### Laravel version target — 12.x + 13.x for v1
**Date:** 2026-05-14
**Reason:** `laravel/mcp` 0.7 requires `illuminate/json-schema` 12.41+, which excludes Laravel 11. Rather than ship a partial Laravel-11 experience (no MCP), the v1 floor is Laravel 12. Older Laravel versions are out — they're EOL or in security-only support.
**Reversibility:** Medium. Adding Laravel 11 needs either MCP to widen or a vendored `json-schema` polyfill.

## Why we don't do certain things

### Why no production debugging in v1
Production safety is its own engineering project — sampling, non-blocking pause, snapshot breakpoints, secure auth, multi-user. Bundling it with v1 would 3× the scope. v2 priority.

### Why no async (Fibers/Swoole/Frankenphp/Octane) in v1
Async PHP runtimes break the "one trace per request, one thread" model. Each one (Fibers, Swoole, Frankenphp) needs its own integration work. Single-thread is the 80% case for Laravel apps; ship that first.

### Why no DBGp compatibility shim
Some users will ask for "Xdebug-compatible mode" so PhpStorm Just Works. We don't ship a DBGp bridge because:
1. **There's a cleaner reuse path** — [LSP4IJ](https://plugins.jetbrains.com/plugin/23257-lsp4ij) (Red Hat's free DAP client plugin for IntelliJ-platform IDEs, PhpStorm 2024.2+) connects to `periscope-daemon --dap-stdio` today. Setup is a docs page, not protocol code. See [PhpStorm guide](https://github.com/thamibn/php-periscope/blob/main/docs/site/guide/phpstorm.md) and [`v1.2` on the roadmap](ROADMAP.md).
2. Maintaining two protocol implementations doubles the maintenance burden — every new debug feature we add would need DAP *and* DBGp implementations.
3. DBGp is XML-over-TCP from 2003. It's also opcode-step-oriented; we're frame-level. The semantic mismatch means every DBGp `step_into` would need translation logic for what doesn't map.
4. A custom JetBrains plugin (Kotlin, deferred to v2) would give native UX without the protocol detour. v1.2's LSP4IJ path is the no-code-required interim.
