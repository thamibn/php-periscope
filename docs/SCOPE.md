# Scope (v1 / MVP)

## In scope

### Platforms
- PHP 8.3 (single version)
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
  - Dispatched (queued) jobs
  - Cache reads / writes / hits / misses
  - Redis commands
  - Outbound HTTP calls (Guzzle, Laravel HTTP client)
  - Mail / notifications
- Browser UI at `localhost:9999`
- VSCode debugger integration via DAP
- Composer-installable Laravel adapter
- Brew + PECL + install-script distribution

### Frameworks (level of polish)
- **Laravel** — first-class. All observability hooks. N+1 detection. Auto-discovered service provider.
- **Symfony** — best-effort. Core profiler integration. No N+1 detection in v1.
- **WordPress** — best-effort. `wpdb` query observation. No event-system integration.
- **Plain PHP** — debugger works, no observability beyond function calls.

## Out of scope (v1)

### Deferred to v2
- Production debugging (sampling, snapshot breakpoints, non-blocking pause)
- Variable mutation tracking (assignment-level, not just function-boundary)
- OpenTelemetry export
- AI-assisted debugging panel ("explain this frame", "why is `$x` null?")
- PhpStorm-specific UX polish (basic DAP works; deep PhpStorm integration later)
- Custom non-DAP protocol (designed for if/when DAP becomes a ceiling)

### Permanently out of scope (or v3+)
- Windows native (WSL works because it's Linux)
- PHP < 8.0 (Observer API not available)
- Async runtimes: Fibers, Swoole, ReactPHP, Frankenphp, Octane
- Closures with captured-by-reference vars (best-effort capture, no perfect fidelity)
- Generators mid-iteration (snapshot opaque)
- Distributed tracing across multiple PHP processes
- Mobile app or hosted-cloud UI
- Authentication / multi-user (`localhost:9999` is single-user)

### Anti-goals
- Replacing Telescope as a long-term observability tool (we are a *debugger*)
- Becoming a profiler (overlap with Tideways / Blackfire — out of scope)
- Cross-language debugging (no JavaScript, no Python, PHP only)

## Acceptance for "MVP done"

- [ ] All 12 phases in `thoughts/shared/plans/2026-05-08-php-periscope-mvp.md` complete
- [ ] Three real-world apps (Laravel, Symfony, WordPress) run their test suites with the extension loaded with no regressions
- [ ] AddressSanitizer clean across the full test suite
- [ ] One-command install on macOS + Linux
- [ ] VSCode extension published to marketplace
- [ ] At least 10 external beta users have installed and reported back
