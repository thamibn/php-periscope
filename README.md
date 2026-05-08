# php-periscope

> See into your PHP request.

A live observability + time-travel debugger for PHP and Laravel. Pause any request, see every variable, query, log, job, event, cache hit, Redis command, and HTTP call that led to that line — and scrub backward in time.

**Status:** 🚧 Planning complete — implementation Phase 1 starts soon. See [`thoughts/shared/plans/2026-05-08-php-periscope-mvp.md`](thoughts/shared/plans/2026-05-08-php-periscope-mvp.md) for the full plan.

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

- PHP 8.3 only • macOS + Linux only • Local development only
- Function-boundary recording (not opcode-level) for performance
- VSCode + browser UI as primary interfaces
- Laravel first-class, Symfony / WordPress best-effort

See [`docs/SCOPE.md`](docs/SCOPE.md) for the full in/out list.

## Documentation

- [Vision](docs/VISION.md) — the elevator pitch
- [Scope](docs/SCOPE.md) — what's in / out for v1
- [Roadmap](docs/ROADMAP.md) — phase calendar
- [Architecture](docs/ARCHITECTURE.md) — system diagram & decisions
- [Implementation plan](thoughts/shared/plans/2026-05-08-php-periscope-mvp.md) — the plan of record

## License

Proprietary — all rights reserved. The licensing model is undecided and may change once the project reaches a public release. See [`LICENSE`](LICENSE).
