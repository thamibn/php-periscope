# Roadmap

Source-of-truth roadmap. The user-facing version lives at [`docs/site/guide/roadmap.md`](site/guide/roadmap.md).

## Where we are: v0.1.0-alpha

All twelve plan phases in [`thoughts/shared/plans/2026-05-08-php-periscope-mvp.md`](../thoughts/shared/plans/2026-05-08-php-periscope-mvp.md) have shipped. Git log is authoritative; the shipped surface includes:

| Phase | What shipped |
|-------|--------------|
| 1     | C extension scaffold, `phpize` build |
| 2     | Zend Observer API hooks (function entry/exit) |
| 3     | Variable capture — primitives, strings, arrays, objects, enums, closures, circular refs, depth caps |
| 4     | Cap'n Proto trace format + Rust reader, `--json` mode for AI ingestion, trace retention |
| 5a–5d | C userland API + Laravel adapter — 18 hooks (queries, logs, cache, jobs, batches, events, mail, notifications, redis, HTTP client, exceptions, models, views, gates, commands, schedules, request lifecycle, `dd()`/`dump()`), N+1 detector, slow-query analyser, AI advisor, code-snippet capture per event, per-request mode header |
| 6     | Rust daemon — DAP stdio server, HTTP API, ext-link UDS, WebSocket fanout |
| 7     | Replay engine — `TraceIndex` + `ReplayCursor` + state reconstruction at any microsecond |
| 8     | End-of-request ping over UDS; live pause-on-breakpoint; bidirectional ext-link |
| 9     | SolidJS browser UI — 18 panels, dark theme, code-snippet source view, configurable mount, Clockwork-parity toolbar + storage UI |
| 9b    | Event grouping + Datadog-style JSON-path payload filtering |
| 11a–e | MCP server (`laravel/mcp`), install/uninstall scripts, PECL package.xml, VSCode extension scaffold, Homebrew formula |
| 12a–e | CONTRIBUTING + CoC, GitHub issue / PR templates, VitePress docs site, CI deploy + link checker, README rewrite |

## What's next

### v0.2.0 — Real-world hardening

- **Phase 10** — Laravel skeleton CI harness running the framework's own test suite with the extension loaded; performance regression gate (< 3× overhead on a typical Laravel home route)
- Bug-fix cycle from internal dogfooding + early beta reports
- VSCode Marketplace publish (`periscopephp.php-periscope`)
- Cloudflare Pages deploy live on every push to `main`

### v0.3.0 — Public beta

- PECL public release (`pecl install periscope`)
- Homebrew tap split out into `periscopephp/homebrew-php-periscope`
- Discord + GitHub Discussions board for beta feedback
- Demo GIF + walkthrough video

### v1.0.0 — Stable

- All open beta-tester crash reports triaged + fixed
- Performance: documented overhead claims hold under `make test-real-world`
- Docs: link checker green on every push

## v1.1 backlog (quick wins after v1.0)

1. **PHP 8.1 + 8.2 support** — additive zval-handling work
2. **Laravel 11.x support** — pending `laravel/mcp` widening the `illuminate/json-schema` dep or shipping a vendored fallback
3. **Self-hosted trace sharing** — `periscope-share` binary + `Mcp::web()` registration
4. **Sampling profiler** — opcode-level zoom, opt-in (1-week sprint)
5. **Safe-mode dry-run** — `PERISCOPE_DRYRUN=true` wraps requests in a DB transaction rolled back at RSHUTDOWN; stubs Mail / Queue / HTTP

## v1.2 — PhpStorm via LSP4IJ (docs-only)

PhpStorm 2024.2+ can talk to `periscope-daemon` today via [LSP4IJ](https://plugins.jetbrains.com/plugin/23257-lsp4ij) (Red Hat's open-source DAP client for IntelliJ-platform IDEs). v1.2 is a single docs page (`docs/site/guide/phpstorm.md`) and zero new daemon code — no DBGp bridge (would violate ARCHITECTURE.md decision §3), no custom JetBrains plugin (deferred to v2 if beta demand surfaces).

What works in PhpStorm today via LSP4IJ:
- Breakpoints (standard, conditional, exception)
- Step over / step into / step out
- Variables, watches, expression evaluate

What doesn't (yet) — LSP4IJ upstream gaps:
- IDE-side `stepBack` / `reverseContinue` — browser UI scrubber covers it
- Native PhpStorm UX (gutter, run-config templates) — that's a custom plugin, v2 territory

Optional follow-up: PR to `redhat-developer/lsp4ij` adding `stepBack` request handling. Unlocks the IDE-side back-arrow for every periscope user and any other reverse-debug-capable DAP server.

## v2 priorities

1. **Production-safe debugging** — sampling, snapshot breakpoints. The killer enterprise feature.
2. **OpenTelemetry export** — debug events as spans across services
3. **Symfony adapter package** — separate Composer package
4. **WordPress adapter package**
5. **Async runtime support** — Fibers, Swoole, FrankenPHP, RoadRunner, Octane
6. **First-class JetBrains plugin** — gutter affordances, run-config templates, embedded tool window. Only if v1.2's LSP4IJ-reuse path leaves a real UX gap that beta demand validates.
7. **Variable mutation tracking** — assignment-level snapshots
8. **Cross-process tracing** — follow a request from web → queue worker → web again

## Permanently out of scope

- Windows native (use WSL2)
- SaaS for trace hosting (export `.html` files instead)
- Xdebug integration / DBGp shim

## Decision cadence

- Items move between buckets based on beta-tester demand, code budget, and the [four product pillars](POSITIONING.md): fast, useful, easy to set up, easy to use.
- [Feature requests](https://github.com/thamibn/php-periscope/issues/new?template=feature_request.md) self-classify into v1 / v1.1 / v2; we triage.

## Pause points

Historically — phases that required a confirm-before-continue:

- End of Phase 1 (build toolchain works) — passed
- End of Phase 3 (variable capture, highest-risk phase) — passed
- End of Phase 9a (UI mockup) — passed
- End of Phase 10 (real-world bugs surface — decide if quality is launch-grade) — **upcoming, gates v0.2 → v0.3**
