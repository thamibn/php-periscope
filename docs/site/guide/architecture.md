# Architecture

periscope is four components glued together by a single binary trace format.

```
┌──────────────────┐        function entry/exit         ┌─────────────────────┐
│  PHP request     │  ─────────────────────────────────▶│ C extension         │
│  (FPM / CLI)     │       captured args + return       │ (Zend Observer API) │
└──────────────────┘                                    └────────┬────────────┘
        ▲                                                        │
        │ resume / pause                                         │ Cap'n Proto frames
        │                                                        ▼
┌──────────────────┐  ◀── DAP stdio ─── ┌────────────────────────────────────┐
│ IDE (VSCode /    │                    │   Rust daemon                      │
│ PhpStorm / JB)   │                    │  - DAP server + replay engine      │
│                  │  ─── breakpoints ─▶│  - HTTP /api/* + /ws WebSocket     │
└──────────────────┘                    │  - serves built UI bundle          │
                                        └──┬─────────────────────────────┬───┘
                                           │                             │
                          .cptrace files   │                             │ HTTP + WS
                          on disk         ◀┘                             ▼
                                                             ┌──────────────────────┐
                                                             │ SolidJS browser UI   │
                                                             │ localhost:9999       │
                                                             └──────────────────────┘
```

## The four components

### 1. C extension (`extension/`)

A PHP 8.0+ Zend Observer API extension. On every userland function entry it captures arguments; on every exit it captures the return value. Captures land in a per-request `.cptrace` file using Cap'n Proto for zero-copy serialisation.

Key invariants:

- **Function-boundary recording**, not opcode-level. v1 captures variables only at entry/exit. (Per-opcode hooks would mean Xdebug-tier overhead; we ship lower.)
- **Framework-agnostic.** No Laravel knowledge here — that lives in the adapter.
- **AddressSanitizer-clean** on every CI run. A red ASan job blocks merge.
- **PHP 8.3 + 8.4 in v1.** 8.1 / 8.2 are a v1.1 sprint.

Captured per frame: function name, declaring class, file:line, depth, enter/exit timestamps, arguments, return value, scope reference. Variable capture handles nulls, primitives, strings (truncated at `periscope.max_string`), arrays (size-capped at `max_array_items`), objects (property-capped at `max_object_props`), enums, closures, circular references, and lazy proxies. All under the depth cap (`max_depth = 5` by default).

### 2. Rust daemon (`daemon/`)

A `tokio`-based async server that does five things:

- **DAP server** over stdio. Speaks Debug Adapter Protocol so any DAP client (VSCode, Neovim, Helix, Zed, JetBrains) sees periscope as a debuggee. Supports `stepBack` — that's the time-travel.
- **Replay engine.** Reads a `.cptrace` file, builds an indexed `TraceIndex`, and reconstructs the full state (deepest frame, call stack, scope, prefix events) at any microsecond.
- **HTTP API** at `:9999/api/*`. Endpoints for traces / frames / events / queries / timeline / insights / summary / storage / client metrics. The same API the UI consumes.
- **WebSocket** at `:9999/ws`. The C extension pings the daemon at `RSHUTDOWN`; the daemon fans out `request_finished` notifications to every browser tab so they auto-jump to new traces. UI tabs also publish `cursor_set` to keep multiple browsers in sync when scrubbing.
- **Static UI bundle** served at `:9999/`. The SolidJS app, hashed assets, SPA fallback.

`#![forbid(unsafe_code)]` at the crate root. The single documented exception is the trace mmap reader (Phase 7), gated by `#[allow(unsafe_code)]` with a `# Safety` comment.

### 3. SolidJS UI (`ui/`)

The browser UI. SolidJS over Svelte for the fine-grained reactivity that makes the timeline scrubber feel like a video editor — state updates at 60fps when dragging.

Built with Vite + Bun. Tailwind for styles, dark-default theme. The whole bundle is ~27KB gzipped.

Eighteen panels: Overview, Source + Scope, Queries, Models, Logs, Cache, Jobs, Events, HTTP, Redis, Mail, Notifications, Exceptions, Dumps, Insights, **Performance (flame graph)**, Request, Response. Each panel only renders if there's data for it — empty panels are hidden.

### 4. Laravel adapter (`laravel-adapter/`)

A Composer package (`thamibn/laravel-periscope`) that:

- Registers eighteen event-listener hooks: queries, logs, cache, jobs, batches, events, mail, notifications, redis, HTTP client, exceptions, model writes, view renders, gates, console commands, schedule events, request lifecycle, and `dd()`/`dump()` captures.
- Records every observed event into the C extension via `periscope_record_event()`.
- Injects an optional toolbar chip into HTML responses.
- Mounts the SolidJS UI inside the host Laravel app at a configurable prefix (`/periscope` default).
- Auto-registers the periscope MCP server with `laravel/mcp` so `php artisan mcp:start periscope` works.

## AI-native

The MCP server (Phase 11a, `php artisan mcp:start periscope`) exposes eight tools:

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

Same data as the UI — the MCP server proxies to the daemon's `/api/*`. No second source of truth.

## On-disk format

Traces are append-only Cap'n Proto files. One trace per request. Naming: `<id>.cptrace` where `<id>` encodes the started-at timestamp and PID.

Storage is automatically capped by `periscope.max_traces` (default 100) and `periscope.max_trace_age_seconds` (default 86400 = 24h). The sweep runs at RINIT — no daemon required.

Each trace also has an optional sidecar `<id>.client-metrics.json` (Web Vitals from the toolbar chip).

## Three ways to reach the UI

1. **`localhost:9999`** — the daemon serves the SolidJS bundle directly.
2. **`app.test/periscope`** — the Laravel adapter mounts the same bundle inside your app (set `PERISCOPE_UI_ENABLED=true`).
3. **A standalone `.html` export** — `periscope-export <id> --format html` inlines the bundle + trace JSON into one self-contained file. Email it, attach it to a bug report, post it in a GitHub issue. Recipient double-clicks it and gets the full debugger UI in their browser. No daemon needed on the recipient's machine.

## Three identical wires

The UI ↔ daemon protocol is the same in all three deployment modes:

- HTTP `GET /api/*` — JSON, same shape regardless of who's hosting.
- WebSocket `/ws` — `request_finished` (from C ext → daemon → tabs) + `cursor_set` (tab ↔ tab fanout).
- Cap'n Proto frames — the bytes the daemon decodes on read.

This is why static-html export works: the UI degrades gracefully when daemon-only features (live mode, breakpoints) are missing, and reads from `window.PERISCOPE_TRACE` instead of `/api/*`.

## See also

- [`docs/ARCHITECTURE.md`](https://github.com/thamibn/php-periscope/blob/main/docs/ARCHITECTURE.md) — the deep-dive doc, including the trace-format schema and the Observer API call-graph reasoning.
- [`docs/POSITIONING.md`](https://github.com/thamibn/php-periscope/blob/main/docs/POSITIONING.md) — head-to-head vs Xdebug benchmark data.
