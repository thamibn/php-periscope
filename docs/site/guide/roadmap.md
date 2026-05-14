# Roadmap

The canonical roadmap doc lives at [`docs/ROADMAP.md`](https://github.com/thamibn/php-periscope/blob/main/docs/ROADMAP.md). This page is a digested, user-facing view.

## v0.1.0 — alpha (here)

What's shipping today:

- ✅ C extension with Zend Observer API integration, full variable capture, Cap'n Proto trace writing.
- ✅ Rust daemon: DAP server, replay engine, HTTP API, WebSocket fanout.
- ✅ SolidJS UI with timeline scrubber, 18 panels, dark theme, Tailwind styling.
- ✅ Laravel adapter: 18 hooks — queries, logs, cache, jobs, batches, events, mail, notifications, redis, HTTP client, exceptions, models, views, gates, commands, schedules, request lifecycle, `dd()`/`dump()` captures.
- ✅ Insights panel: N+1 detection, slow-query analyser, AI advisor (opt-in via `laravel/ai`).
- ✅ AI-native MCP server via `laravel/mcp` (8 tools: list / trace / summary / insights / timeline / state / events / file).
- ✅ Floating toolbar chip + Web Vitals capture.
- ✅ Trace storage management UI + per-trace delete + bulk clear.
- ✅ Static `.html` export for sharing traces without a daemon.
- ✅ Event grouping + Datadog-style JSON-path payload filtering.
- ✅ One-line install script for macOS + Linux.
- ✅ Homebrew tap formula.
- ✅ PECL package.xml.
- ✅ VSCode extension scaffold (`periscope` debug type, daemon launcher, status bar).

## v0.2.0 — Real-world hardening

- Laravel skeleton CI harness running the framework's own test suite with periscope loaded.
- Performance regression gate (< 3× overhead on Laravel homepage).
- Bug-fix cycle from beta-tester reports.
- VSCode Marketplace publish (`periscopephp.php-periscope`).
- Cloudflare Pages deploy for this docs site.

## v0.3.0 — Public beta

- PECL public release (`pecl install periscope`).
- Homebrew tap repo (`periscopephp/homebrew-php-periscope`) split out from this repo.
- Discord server + GitHub Discussions board for beta feedback.
- Demo GIF + walkthrough video.

## v1.0.0 — Stable

- All open beta-tester crash reports triaged and fixed.
- Performance: ≥ 3 unique segfault reports filed within first 30 days, ≥ 2 fixed.
- Docs: link checker green in CI on every push.

## v1.1.0 — Quick wins

- PHP 8.1 + 8.2 support.
- Self-hosted trace sharing (`periscope-share` Rust binary + `Mcp::web()` mode).
- Sampling profiler — opcode-level zoom, opt-in, 1-week sprint.
- Safe-mode dry-run: opt-in `PERISCOPE_DRYRUN=true` that wraps the request in a DB transaction rolled back at RSHUTDOWN, stubs Mail/Queue/HTTP.
- VSCode extension polish (JetBrains-native UX).
- The dropped failed-jobs panel, if user demand surfaces.

## v2.0.0 — Bigger surface

- Symfony adapter package.
- WordPress adapter package.
- Production mode: sampling, snapshot-on-error, remote control plane.
- Async runtime support: Fibers, Swoole, FrankenPHP, RoadRunner, Octane.
- Per-opcode timing (sampling-based, opt-in).
- Variable mutation tracking between captures.
- OpenTelemetry export.
- PhpStorm-specific UX polish (gutter affordances, run configurations).
- Closures / references / circular references — perfect-fidelity capture.

## Out of scope, permanently

- Windows native code paths — use WSL2.
- A SaaS for trace hosting — share `.html` exports instead.

## How decisions get made

Items move between buckets based on:

1. Beta-tester demand. Real reports beat "wouldn't it be cool if".
2. Code budget — the maintainer's weekend hours.
3. Whether the work serves the [four product pillars](https://github.com/thamibn/php-periscope/blob/main/docs/POSITIONING.md): fast, useful, easy to set up, easy to use.

[Feature requests](https://github.com/thamibn/php-periscope/issues/new?template=feature_request.md) influence ordering. Self-classify into v1 / v1.1 / v2 in the template — we'll triage.
