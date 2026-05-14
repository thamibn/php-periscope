# php-periscope

> See into your Laravel request.

[![CI](https://github.com/thamibn/php-periscope/actions/workflows/ci.yml/badge.svg)](https://github.com/thamibn/php-periscope/actions/workflows/ci.yml)
[![Docs](https://github.com/thamibn/php-periscope/actions/workflows/docs.yml/badge.svg)](https://github.com/thamibn/php-periscope/actions/workflows/docs.yml)
[![Links](https://github.com/thamibn/php-periscope/actions/workflows/link-check.yml/badge.svg)](https://github.com/thamibn/php-periscope/actions/workflows/link-check.yml)

A live observability + time-travel debugger built **for Laravel**. Pause any request, see every variable, query, log, job, event, cache hit, Redis command, and HTTP call that led to that line — and scrub backward in time. **Xdebug-tier debugging plus Telescope-tier observability, in one live UI, with an AI co-pilot.**

**Status:** v0.1.0-alpha. All twelve plan phases shipped — C extension, Rust daemon, SolidJS UI, Laravel adapter, MCP server (AI-native), install/uninstall scripts, PECL package, VSCode extension scaffold, Homebrew formula, docs site, CI workflows. Real-world test harness (Phase 10) is next.

## Quickstart

**Install** — pick one:

```bash
# macOS (recommended) — Homebrew tap, single command:
brew tap periscopephp/php-periscope https://github.com/thamibn/php-periscope.git
brew install --HEAD periscopephp/php-periscope/php-periscope

# Linux + any Unix shell — one-liner, no Homebrew required:
bash <(curl -fsSL https://raw.githubusercontent.com/thamibn/php-periscope/main/scripts/install.sh)
```

Both build the C extension against your PHP, drop the daemon binaries into your prefix, and write `99-periscope.ini` so the extension auto-loads. Pick whichever you'd run for any other CLI tool.

**Windows:** use **WSL2** with Ubuntu, then run the Linux command above inside Ubuntu. Native Windows is not supported in v1 — see [the FAQ](https://periscope.thamibn.com/guide/faq#why-isnt-windows-native-supported) for why and the [Windows setup section](https://periscope.thamibn.com/guide/getting-started#windows-wsl2) for the one-time `wsl --install` step.

**Then in your Laravel app:**

```bash
# add to any Laravel 11 / 12 / 13 app
composer require periscopephp/laravel

# start the daemon, open the UI
periscope-daemon &
open http://127.0.0.1:9999

# wire AI agents (Claude / Cursor / Codex / anything MCP)
claude mcp add periscope -- php artisan mcp:start periscope
```

Full setup walk-through, including the Homebrew tap and the VSCode extension: **[Getting Started →](https://periscope.thamibn.com/guide/getting-started)**

## What it does

| Today's tools | What they do | Limitation |
|---|---|---|
| Xdebug | Breakpoints + variables | No observability; no time-travel; setup pain |
| Telescope | Queries / logs / jobs / events | Post-mortem only |
| DebugBar | Live observability | Tiny footer; no breakpoints; no time-travel |

**php-periscope merges all three** into one live UI with time-travel as a first-class feature.

Benchmark vs Xdebug (from [`docs/POSITIONING.md`](docs/POSITIONING.md)): 3.3× faster inactive, 4.1× faster in trace mode.

## Architecture

- **C extension** using Zend Observer API (PHP 8.3+) — engine hooks, variable capture, Cap'n Proto trace recording.
- **Rust daemon** speaking Debug Adapter Protocol — works in VSCode, Neovim, Zed, Helix, JetBrains.
- **Browser UI** (SolidJS) at `localhost:9999` — source, variables, queries, logs, jobs, timeline scrubber, flame graph, insights, AI suggestions.
- **Laravel adapter** (Composer package `periscopephp/laravel`) — auto-discovers Laravel events; ships the floating toolbar chip, the in-app UI mount, and the [first-party MCP server](https://laravel.com/docs/mcp) for AI agents.
- **VSCode extension** — registers a `periscope` debug type, auto-spawns the daemon, status-bar liveness chip.
- **Cap'n Proto trace format** — zero-copy on read so the timeline scrubber stays imperceptible.

See [docs/site/guide/architecture.md](docs/site/guide/architecture.md) for the deep dive.

## v1 scope

- **Laravel only** (11.x / 12.x / 13.x). The C extension is framework-agnostic; Symfony / WordPress / CodeIgniter / plain-PHP adapters are post-v1.
- **PHP 8.3 / 8.4** • macOS + Linux • **Local development only** (no production sampling in v1).
- **Function-boundary recording**, not opcode-level — overhead < 5× even on hot paths.
- **No telemetry, no SaaS** — traces stay on disk; the MCP server is local-only over stdio.

See [`docs/SCOPE.md`](docs/SCOPE.md) for the full in / out list.

## Documentation

The full docs site (VitePress) is under [`docs/site/`](docs/site/). It auto-deploys to Cloudflare Pages on every push to `main` once the deploy secrets are configured.

Quick links:

- [Getting started](docs/site/guide/getting-started.md)
- [Architecture](docs/site/guide/architecture.md)
- [Known limitations](docs/site/guide/known-limitations.md)
- [FAQ](docs/site/guide/faq.md)
- [Roadmap](docs/site/guide/roadmap.md)

Source-of-truth docs in the repo:

- [Vision](docs/VISION.md) • [Scope](docs/SCOPE.md) • [Roadmap](docs/ROADMAP.md) • [Architecture](docs/ARCHITECTURE.md) • [Positioning vs Xdebug](docs/POSITIONING.md)
- [Implementation plan](thoughts/shared/plans/2026-05-08-php-periscope-mvp.md) — the phased plan of record.

## AI-native

```bash
claude mcp add periscope -- php artisan mcp:start periscope
```

Eight MCP tools expose every trace to AI agents: `list_traces`, `get_trace`, `get_summary`, `get_insights`, `get_timeline`, `get_state` (time-travel), `query_events` (with JSON-path filter language), `read_file`. No other PHP debugger lets Claude / Cursor / Codex query traces this directly.

Built on Laravel 13's first-party [`laravel/mcp`](https://laravel.com/docs/mcp) SDK.

## Trace storage and privacy

Each request writes one `.cptrace` file to `periscope.trace_dir` (default `/tmp/periscope/`, ~5KB–500KB per request depending on call volume).

**Automatic retention** runs at the start of every request:

| INI knob | Default | Behaviour |
|---|---|---|
| `periscope.max_traces` | `100` | Keep newest N traces; delete the rest. Set to `0` to disable. |
| `periscope.max_trace_age_seconds` | `86400` (24h) | Delete traces older than this. Set to `0` to disable. |

**Manual cleanup** from the CLI or the UI's Storage panel:

```bash
make trace-clean
make trace-clean PERISCOPE_TRACE_DIR=/some/path
```

**Privacy:** traces capture request bodies, cookies, headers, and captured variables. They may contain credentials. Don't commit them. Don't post them in public bug reports without `periscope-export <id> --format json` redaction. The C extension already strips a default set of header names (`Authorization`, `Cookie`, `Set-Cookie`) — see [`docs/SCOPE.md`](docs/SCOPE.md) for the full redaction policy.

## Contributing

We welcome contributions. Read [**CONTRIBUTING.md**](CONTRIBUTING.md) first — it covers per-subsystem dev setup, coding standards, the ASan / Valgrind workflow, and the commit + PR rules (no AI co-author attribution, no GPG signing required, `make ci` clean before pushing).

- **Report a bug:** [bug report template](.github/ISSUE_TEMPLATE/bug_report.md).
- **Report a process exit / crash:** [crash report template](.github/ISSUE_TEMPLATE/crash_report.md) — please fill every field; we can't triage process exits without the stack trace + environment + extension list.
- **Suggest a feature:** [feature request template](.github/ISSUE_TEMPLATE/feature_request.md) with a v1 / v1.1 / v2+ scope checkbox.
- **Ask a question:** [GitHub Discussions](https://github.com/thamibn/php-periscope/discussions).
- **Conduct:** [Contributor Covenant 2.1](CODE_OF_CONDUCT.md).

## Feedback funnel (beta)

- **GitHub Discussions** — open-ended questions, design ideas, "is this a bug or am I holding it wrong?" → <https://github.com/thamibn/php-periscope/discussions>.
- **GitHub Issues** — concrete, reproducible bugs and crashes. Use the templates.
- **Security disclosures** — privately email the maintainer (see `composer.json` `lead` block). Response within 7 days.
- **Discord** (planned for v0.3 public beta) — invite link will land here.
- **`feedback@periscope.dev`** (planned) — for one-off prose feedback that doesn't fit an issue. Until the alias goes live, route prose through GitHub Discussions.

## Licence

Proprietary — all rights reserved. The licensing model may change before public release. See [`LICENSE`](LICENSE).
