# Scope (v1 / MVP)

## In scope

### Platforms
- PHP 8.3 + 8.4
- macOS (Apple Silicon + Intel)
- Linux (x86_64 + arm64)

### Features
- Step debugging: breakpoints, step over, step into, step out
- **Step backward** (time-travel via DAP `supportsStepBack`)
- Variable inspection at function boundaries (entry + exit)
- Call stack inspection at any point in a recorded request
- Timeline scrubber to view past state
- **Live observability merged with breakpoints**:
  - SQL queries (with bindings, timings, stack trace, N+1 detection)
  - Log lines (all PSR-3 levels)
  - Dispatched events
  - Dispatched (queued) jobs + batches
  - Cache reads / writes / hits / misses
  - Redis commands
  - Outbound HTTP calls (Guzzle, Laravel HTTP client)
  - Mail / notifications
  - Model writes
  - View renders
  - Gate / policy checks
  - Console commands + schedule events
  - Request / response envelope
  - `dd()` / `dump()` captures
- Browser UI at `localhost:9999`, plus in-app mount at `/periscope`
- VSCode debugger integration via DAP (`periscopephp.php-periscope`)
- Composer-installable Laravel adapter
- Brew + PECL + install-script distribution
- **AI-native MCP server** — `php artisan mcp:start periscope` exposes eight tools (`list_traces`, `get_trace`, `get_summary`, `get_insights`, `get_timeline`, `get_state`, `query_events`, `read_file`)
- **Insights panel** — N+1 detection, slow-query analyser, AI advisor (opt-in via `laravel/ai`)
- Static `.html` trace export — share a debugger UI without a daemon
- Trace retention (`periscope.max_traces`, `periscope.max_trace_age_seconds`) + UI storage management

### Framework support
- **Laravel** — only framework in v1. Adapter targets Laravel 12.x / 13.x. All hooks above are Laravel-driven.
- The C extension is **framework-agnostic** by design. Symfony / WordPress / CodeIgniter / plain-PHP support ships post-v1 as separate Composer adapter packages.

## Out of scope (v1)

### Deferred to v1.1
- PHP 8.1 / 8.2 support
- Laravel 11.x support (`laravel/mcp` 0.7 requires `illuminate/json-schema` 12.41+)
- Self-hosted trace sharing (`periscope-share` binary + `Mcp::web()` mode)
- Sampling profiler (opcode-level zoom, opt-in)
- Safe-mode dry-run (`PERISCOPE_DRYRUN=true` — DB-transaction-wrapped requests rolled back at RSHUTDOWN)
- VSCode extension marketplace listing
- Public PECL release (`pecl install periscope`)
- Public Homebrew tap repo

### Deferred to v2
- Production debugging (sampling, snapshot breakpoints, non-blocking pause)
- Variable mutation tracking (assignment-level, not just function-boundary)
- OpenTelemetry export
- PhpStorm-specific UX polish (run/debug configurations, gutter affordances). DAP is third-party-plugin only on PhpStorm today.
- Custom non-DAP protocol (designed for if/when DAP becomes a ceiling)
- Symfony / WordPress / CodeIgniter / plain-PHP adapter packages
- Async runtime support: Fibers, Swoole, ReactPHP, FrankenPHP, Octane

### Permanently out of scope
- Windows native (WSL2 works because it's Linux)
- PHP < 8.1 (no readonly properties → adapter wouldn't compile)
- Closures with captured-by-reference vars (best-effort capture, no perfect fidelity)
- Generators mid-iteration (snapshot opaque)
- Distributed tracing across multiple PHP processes
- Mobile app or hosted-cloud UI
- Authentication / multi-user (`localhost:9999` is single-user)
- Xdebug integration (we replace it, not lean on it)

### Anti-goals
- Replacing Telescope as a long-term observability tool (we are a *debugger*)
- Becoming a profiler (overlap with Tideways / Blackfire — out of scope)
- Cross-language debugging (no JavaScript, no Python, PHP only)

## Acceptance for "MVP done"

- [x] All 12 phases in `thoughts/shared/plans/2026-05-08-php-periscope-mvp.md` complete
- [ ] Laravel skeleton app runs the framework's own test suite with periscope loaded with no regressions (Phase 10 / v0.2)
- [ ] AddressSanitizer clean across the full test suite
- [x] One-command install on macOS + Linux
- [ ] VSCode extension published to marketplace (v0.2)
- [ ] At least 10 external beta users have installed and reported back (v0.3)

See [`docs/ROADMAP.md`](ROADMAP.md) for the v0.2 / v0.3 / v1.0 milestones.
