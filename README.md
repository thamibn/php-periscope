# php-periscope

> See into your Laravel request.

A live observability + time-travel debugger built **for Laravel**. Pause any request, see every variable, query, log, job, event, cache hit, Redis command, and HTTP call that led to that line — and scrub backward in time. **Xdebug-tier debugging plus Telescope-tier observability, in one live UI, with an AI co-pilot.**

**Status:** 🚧 Phases 1–4 landed. C extension observes every PHP function call with full variable capture; Cap'n Proto traces written to disk; Rust daemon reads them. See [`thoughts/shared/plans/2026-05-08-php-periscope-mvp.md`](thoughts/shared/plans/2026-05-08-php-periscope-mvp.md) for the full plan and [`docs/POSITIONING.md`](docs/POSITIONING.md) for the head-to-head benchmark vs Xdebug (we're 3.3× faster inactive, 4.1× faster in trace mode).

## What it does

| Today's tools | What they do | Limitation |
|---------------|--------------|------------|
| Xdebug | Breakpoints + variables | No observability; no time-travel; setup pain |
| Telescope | Queries / logs / jobs / events | Post-mortem only |
| DebugBar | Live observability | Tiny footer; no breakpoints; no time-travel |

**php-periscope merges all three** into one live UI with time-travel as a first-class feature.

## Architecture

- **C extension** using Zend Observer API (PHP 8.0+) — engine hooks, variable capture, trace recording
- **Rust daemon** speaking Debug Adapter Protocol — works in VSCode, Neovim, Zed, Helix, Sublime, JetBrains
- **Browser UI** (SolidJS) at `localhost:9999` — source, variables, queries, logs, jobs, timeline scrubber
- **Laravel adapter** (Composer package) — auto-discovers Laravel events for full request observability

See [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) for the full picture.

## v1 scope (MVP)

- **Laravel only** (latest 2 LTS — 11.x and 12.x at launch)
- PHP 8.3 only • macOS + Linux only • Local development only
- Function-boundary recording (not opcode-level) for performance
- VSCode + PhpStorm + browser UI as primary interfaces
- The underlying C extension is framework-agnostic, but in v1 we test, market, and support **only** Laravel. Symfony / CodeIgniter / WordPress / plain PHP are post-v1 if there's demand.

See [`docs/SCOPE.md`](docs/SCOPE.md) for the full in/out list.

## Documentation

- [Vision](docs/VISION.md) — the elevator pitch
- [Scope](docs/SCOPE.md) — what's in / out for v1
- [Roadmap](docs/ROADMAP.md) — phase calendar
- [Architecture](docs/ARCHITECTURE.md) — system diagram & decisions
- [Implementation plan](thoughts/shared/plans/2026-05-08-php-periscope-mvp.md) — the plan of record

## Trace storage and cleanup

When `periscope.trace_dir` is set (it's empty by default, meaning no on-disk traces are written), each request writes one `.cptrace` file (~5KB–500KB depending on call volume).

**Automatic retention** — runs at the start of every request:

| INI knob | Default | Behaviour |
|---|---|---|
| `periscope.max_traces` | `100` | Keep newest N traces; delete the rest. Set to `0` to disable. |
| `periscope.max_trace_age_seconds` | `86400` (24h) | Delete traces older than this. Set to `0` to disable. |

**Manual cleanup**:

```bash
make trace-clean                                  # default: /tmp/periscope
make trace-clean PERISCOPE_TRACE_DIR=/some/path   # custom dir
```

**Privacy note** — traces capture request bodies, cookies, headers, and variable contents. They may contain secrets. Don't commit them. Don't ship them to support without redaction. The C extension already redacts a default set of headers (`Authorization`, `Cookie`, `Set-Cookie`); see [`docs/SCOPE.md`](docs/SCOPE.md) for the full redaction policy.

## Installing for end users (eventually)

End users will install precompiled binaries — **no Rust, C++, or C toolchain required**:

```bash
# macOS (planned, Phase 11)
brew install thamibn/php-periscope/php-periscope

# Linux (planned, Phase 11)
curl -fsSL https://periscope.dev/install.sh | bash

# Per-project
composer require thamibn/periscope-laravel
```

The build chain (`make extension`, `cargo build`, `capnp compile`) only runs for maintainers building releases. Users get bottles.

## License

Proprietary — all rights reserved. The licensing model is undecided and may change once the project reaches a public release. See [`LICENSE`](LICENSE).
