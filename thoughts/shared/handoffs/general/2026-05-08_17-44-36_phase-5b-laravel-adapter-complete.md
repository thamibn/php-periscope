---
date: 2026-05-08T17:44:36+02:00
researcher: Thamsanca Ntuli
git_commit: 1f764f6390714f4e0bc83db815b3c8e626df8b98
branch: main
repository: php-periscope
topic: "php-periscope Phase 5 — Laravel Adapter (Telescope-parity watchers)"
tags: [implementation, strategy, laravel-adapter, c-extension, capnp, observability, telescope-parity]
status: complete
last_updated: 2026-05-08
last_updated_by: Thamsanca Ntuli
type: implementation_strategy
---

# Handoff: php-periscope — Phase 5b complete, Phase 5c next (17 remaining Telescope watchers)

## Task(s)

Implementing the v1 MVP of php-periscope per `thoughts/shared/plans/2026-05-08-php-periscope-mvp.md`. Phase-by-phase, committing + pushing at every phase boundary. **Laravel-only in v1** (deliberate scope cut — other framework adapters ship later as separate Composer packages).

| Phase | Status | Commit |
|---|---|---|
| 1 — Hello-world C extension | ✅ | `adf570c` |
| 2 — Zend Observer API hooks (function entry/exit + types + timing) | ✅ | `850fcfe` |
| 3 — Variable capture (the cliff: enums, lazy/`__get` detection, cycle back-refs, depth/string/items caps, namespace_filter perf knob, kill switch) | ✅ | `2e87dbc` |
| Bench vs Xdebug 3.5.1 head-to-head (we're 3.3× faster inactive, 4.1× faster trace mode) | ✅ | `055373e` |
| 4 — Cap'n Proto trace + Rust daemon reader (incl. `--json` for AI access) | ✅ | `fd79a1a` |
| 4.1 — trace retention (max_traces, max_age) + AI-native `--json` mode + plan additions for cross-cutting requirements | ✅ | `6da3feb` |
| Scope tightening: Laravel-only positioning across README/POSITIONING.md/plan/CLAUDE.md | ✅ | `2081f46` |
| 5a — C extension userland API (`periscope_record_event`, `periscope_checkpoint`) + schema additions (`CallSite`, `SnippetLine`, `userCallSite`, `genericJson` event payload, request/response writer methods) | ✅ | `f6aab06` |
| **5b — Laravel adapter Composer package + first hook (QueryHook)** | ✅ | `1f764f6` |
| 5c — Remaining 17 Telescope-parity watchers + DebugBar-style aggregate counts | ⏳ pending |
| 5d — Real Laravel integration test (`imo.test` Valet site) + commit + push | ⏳ pending |
| 6–12 (DAP daemon, replay engine, time-travel `stepBack` wiring, browser UI, real-world tests, distribution, beta launch) | ⏳ pending |

User explicitly authorized proceeding through phases linearly. Has confirmed Laravel-only scope, AI-native access strategy (HTTP API in Phase 6, MCP server in Phase 11), and has explicitly asked for Telescope-parity *plus* unique differentiators — see plan §"Phase 5 differentiators".

## Critical References

- **Source-of-truth plan**: `thoughts/shared/plans/2026-05-08-php-periscope-mvp.md` — Phase 5 watcher table at the §"Phase 5 watcher coverage" header (18 watchers from Telescope 13 docs); differentiators table near the §"Phase 5 differentiators" header.
- `CLAUDE.md` — invariant #8 (no framework code in C extension) and invariant #9 (Laravel-only in v1).
- `docs/POSITIONING.md` — canonical pitch with measured benchmark numbers vs Xdebug 3.5.1.
- `proto/trace.capnp` — full schema, single source of truth shared between C++ writer and Rust reader. Recently added `genericJson` payload + `CallSite` struct.

## Recent changes

**Phase 5a — `f6aab06`:**
- `proto/trace.capnp:108-138` — added `GenericJsonEvent`, `CallSite`, `SnippetLine`, `userCallSite` field on `ObservabilityEvent`.
- `extension/periscope_userland.c` (new) — `periscope_record_event(string $type, array $payload, ?array $callSite = null): bool` + `periscope_checkpoint(string $label, mixed $context = null): bool`. Both JSON-encode the payload internally and forward to the C++ trace writer.
- `extension/periscope_trace.{h,cc}` — added `periscope_trace_event()`, `periscope_trace_set_request()`, `periscope_trace_set_response()`. Events stored as `EventRow` rows, written into the trace at `close` time as `genericJson` variant entries.
- `extension/periscope.c` — added `periscope_userland_functions` to `zend_module_entry`, included `SAPI.h`.
- `extension/tests/004-userland-functions.phpt`, `040-userland-api.phpt`, `041-userland-event-recorded.phpt` (new).

**Phase 5b — `1f764f6`:**
- `laravel-adapter/composer.json` — package `thamibn/periscope-laravel`, Laravel 11/12, PSR-4 `Periscope\Laravel\`, auto-discovers ServiceProvider, dev deps `orchestra/testbench` + Pest 3.
- `laravel-adapter/config/periscope.php` — 18 hook toggles (one per Telescope watcher) + `slow_query_ms` + `snippet_lines` + `vendor_skip` allowlist. All values resolve from `.env`.
- `laravel-adapter/src/PeriscopeServiceProvider.php` — merges config, binds `ExtensionBridge` + `CallSiteResolver` as singletons, iterates configured hooks at boot.
- `laravel-adapter/src/Bridge/ExtensionBridge.php` — wraps `periscope_record_event()` and `periscope_checkpoint()`. Class is `readonly` but **not** `final` (anonymous-class fakes need to extend it for tests). Gracefully no-ops when extension isn't loaded.
- `laravel-adapter/src/Support/CallSiteResolver.php` — walks `debug_backtrace(IGNORE_ARGS, 30)`, skips vendor/laravel|illuminate|symfony|composer|nesbot|psr|spatie|livewire, returns `{file, line, snippet, frame_stack}`.
- `laravel-adapter/src/Hooks/Hook.php` — interface contract.
- `laravel-adapter/src/Hooks/QueryHook.php` — `DB::listen` → `sql` event with normalized bindings (DateTime → ISO-8601, Stringable → string, resource → placeholder), slow flag, full call site.
- `laravel-adapter/tests/{Pest.php, TestCase.php, Unit/CallSiteResolverTest.php, Unit/ExtensionBridgeTest.php, Feature/QueryHookTest.php}` — 9/9 passing.

**Plan/docs additions during this session:**
- Plan §"Cross-cutting requirements" — 7 product additions surfaced during phases 1–4 (AI-native, request/response envelope, call site for events, trace retention, adaptive UI, Laravel-only scope, no end-user toolchain).
- Plan §"Phase 5 watcher coverage" — full Telescope 13 watcher table, fetched via WebFetch from Laravel docs.
- Plan §"Phase 5 differentiators" — 12 features above Telescope/DebugBar parity (time-travel scrubbing, N+1 with concrete fixes, per-frame memory delta, gate decision trail, synthetic checkpoints, trace tags, per-route history, performance budgets, AI co-pilot, live overlay, trace diff, lazy detection).
- Plan Appendix A — consolidated all project memory entries into the plan itself per user request.
- New memory: `project_safe_mode_debugging.md` — `PERISCOPE_DRYRUN=true` (v1.1+ candidate) wraps requests in a transaction that rolls back at RSHUTDOWN, stubs Mail/Queue/HTTP. Distinct from time-travel rewind.

## Learnings

- **PHP Observer API doesn't fire for engine-specialized opcodes** (`strlen`, `count`, `func_num_args`, etc.). Other internal functions (`array_sum`, `strtoupper`) are observed when `periscope.skip_internal=0`. Documented in `extension/tests/012-observer-include-internal.phpt`. Internal-function observation isn't a v1 requirement (`skip_internal=1` is default).
- **Cap'n Proto file naming**: `capnp compile -oc++:.` emits `.c++` extension which trips libtool. Top-level `Makefile` proto-gen step renames to `.cpp` after generation. Both are gitignored — regenerated from `proto/trace.capnp` at build time.
- **Mixing C and C++ in a PHP extension**: `config.m4` uses `PHP_REQUIRE_CXX()` + `pkg-config` for capnp + `CXXFLAGS="$CXXFLAGS -std=c++17"`. Files with `.cc`/`.cpp` extension auto-compile as C++; everything else as C. The trace writer is the only C++ in the project; everything else stays plain C.
- **Rust daemon must avoid `unsafe`** per CLAUDE.md invariant. Memory-mapped reads via `memmap2::Mmap::map` require `unsafe`, so we read the file into a `Vec<u8>` instead — fine for v1, revisit in Phase 7 if benchmarks demand.
- **Anonymous-class test fakes**: `final readonly class` cannot be extended by anonymous fakes in tests. ExtensionBridge keeps `readonly` on its constructor-promoted property only (not on the class itself), no `final` — extending works.
- **Capnp generated files schema bindings live in `crate::trace_capnp`** at the Rust crate root, not inside a `schema::` submodule (capnpc-generated code expects flat module path).
- **Performance bench numbers vs Xdebug 3.5.1 (PHP 8.3.22, fib(25) ≈ 242k recursive calls)**: baseline 6.7ms · periscope kill switch 8.5ms (1.27×) · periscope full capture 323ms (48×) · xdebug develop 27.8ms (4.15×) · xdebug trace 1326ms (198×) · xdebug profile 152ms (22.7×). Captured in `scripts/bench-vs-xdebug.sh`. We're 3.3× faster inactive and 4.1× faster in full trace, and we capture variables which xdebug trace mode does not.

## Artifacts

- **Plan (source of truth)**: `thoughts/shared/plans/2026-05-08-php-periscope-mvp.md`
- **Project memory (project-level decisions)**:
  - `~/.claude/projects/-Users-thamsancantuli-Documents-php-periscope/memory/project_framework_detection.md`
  - `~/.claude/projects/-Users-thamsancantuli-Documents-php-periscope/memory/project_request_capture.md`
  - `~/.claude/projects/-Users-thamsancantuli-Documents-php-periscope/memory/project_event_call_site.md`
  - `~/.claude/projects/-Users-thamsancantuli-Documents-php-periscope/memory/project_ai_native_access.md`
  - `~/.claude/projects/-Users-thamsancantuli-Documents-php-periscope/memory/project_safe_mode_debugging.md`
- **Docs**: `docs/POSITIONING.md` (canonical pitch + bench numbers), `docs/SCOPE.md`, `docs/ARCHITECTURE.md`, `docs/VISION.md`, `docs/ROADMAP.md`
- **Schema**: `proto/trace.capnp` — DO NOT renumber existing field tags
- **C extension**: `extension/{periscope.c, periscope_filter.{c,h}, periscope_capture.{c,h}, periscope_userland.{c,h}, periscope_trace.{h,cc}, php_periscope.h, config.m4}` + `extension/tests/*.phpt` (24 tests)
- **Rust daemon**: `daemon/{Cargo.toml, build.rs, src/lib.rs, src/trace.rs, src/bin/dump.rs, tests/roundtrip.rs}`
- **Laravel adapter**: `laravel-adapter/{composer.json, config/periscope.php, src/PeriscopeServiceProvider.php, src/Bridge/ExtensionBridge.php, src/Support/CallSiteResolver.php, src/Hooks/{Hook.php, QueryHook.php}, tests/**}`
- **Scripts**: `scripts/{smoke.sh, bench-vs-xdebug.sh}`
- **Build**: top-level `Makefile` (targets: `extension`, `proto-gen`, `test`, `trace-clean`, `extension-clean`, `clean`, `help`)

## Action Items & Next Steps

**Phase 5c — implement the remaining 17 Telescope-parity watchers.** All follow the `QueryHook` template (~30–50 lines each). Files to create under `laravel-adapter/src/Hooks/`:

| # | Hook class | Listens to | Event type tag |
|---|---|---|---|
| 1 | `BatchHook` | `Bus::batched`, batch lifecycle | `batch` |
| 2 | `CacheHook` | `CacheHit/Missed/KeyWritten/KeyForgotten` | `cache` |
| 3 | `CommandHook` | `CommandStarting/Finished` | `command` |
| 4 | `DumpHook` | global `dump()` (off by default per config) | `dump` |
| 5 | `EventHook` | `Event::listen('*')` (skip framework internals — see Telescope's blacklist for reference) | `event` |
| 6 | `ExceptionHook` ★ | exception handler / `report()` | `exception` |
| 7 | `GateHook` | `Gate::after()` | `gate` |
| 8 | `HttpClientHook` | `Http` global middleware + `RequestSending`/`ResponseReceived` | `http` |
| 9 | `JobHook` | `Queue::before/after/failing` | `job` |
| 10 | `LogHook` | `Log::listen` | `log` |
| 11 | `MailHook` | `MessageSending/Sent` | `mail` |
| 12 | `ModelHook` ★ | `eloquent.{created,updated,deleted,retrieved}:*` — also accumulate per-class hydration counts and emit one summary `model_summary` event at request end (DebugBar-style aggregate) | `model` |
| 13 | `NotificationHook` | `NotificationSending/Sent` | `notification` |
| 14 | `RedisHook` | `Redis::enableEvents()` + `CommandExecuted` | `redis` |
| 15 | `RequestHook` | `RouteMatched` + Response middleware → calls `periscope_trace_set_request/set_response` (or a userland equivalent we'll need to add) — also captures auth user + session info → emits `request_resolved` event | `request_resolved` |
| 16 | `ScheduleHook` | `ScheduledTaskStarting/Finished` | `schedule` |
| 17 | `ViewHook` | `composing:*` | `view` |

★ = headline differentiators (Exception + Model are critical for the AI co-pilot pitch).

**For each hook:**
1. Constructor takes `ExtensionBridge` + `CallSiteResolver` + framework-specific dependencies via DI.
2. `register()` wires the listener, calls `$bridge->recordEvent($type, $payload, $callSites->resolve())` per event.
3. Update `PeriscopeServiceProvider::resolveHooks()` to yield it when its config toggle is true.
4. Add a Pest test under `tests/Feature/{HookName}Test.php` exercising at least: install, payload shape, slow/threshold flag (where applicable), the unique behaviors (e.g. ModelHook hydration counts, ExceptionHook stack capture).

**For RequestHook specifically** (item 15): may need a new userland C function `periscope_set_request_envelope($method, $uri, $headers, $cookies, $query, $post, $rawBody, $remoteAddr, $scheme)` that calls `periscope_trace_set_request()`. Same for `periscope_set_response_envelope()`. Or — alternatively — capture in C at RINIT/RSHUTDOWN reading `EG(symbol_table)` for `_SERVER`/`_GET`/etc. The C-side approach is cleaner (framework-agnostic) but more work; the userland-bridge approach reuses the existing pattern.

**Then Phase 5d:** install adapter into a fresh `laravel new` skeleton (or the user's `imo.test` Valet site), hit a route exercising queries+logs+cache+jobs+exceptions, dump the trace via `daemon/target/debug/periscope-dump --json`, verify all expected events with call sites are present. Update `scripts/smoke.sh`. Commit + push.

## Other Notes

- **Build sequence**: `make extension` (regenerates capnp → phpize → configure → make), `make test` (runs `.phpt` + `scripts/smoke.sh`), `cargo test` in `daemon/` (Rust round-trip), `cd laravel-adapter && composer install && ./vendor/bin/pest` (adapter tests).
- **Trace files**: `/tmp/periscope/*.cptrace`. Auto-cleaned by retention (`periscope.max_traces=100`, `max_trace_age_seconds=86400`). `make trace-clean` for manual purge. Documented in README.
- **Git rules** (per memory): no Claude attribution, `--no-gpg-sign`, one commit per phase, push after every commit.
- **PHP version**: PHP 8.3.22 only for v1. Modern syntax everywhere — `declare(strict_types=1)`, constructor property promotion, readonly, typed everything, match instead of switch, first-class callable syntax.
- **The user's Laravel project for integration testing** is `imo.test` (Valet). Phase 5d should test against this. Maintainer also has `property-core-backend` as the more thorough integration target (mentioned in plan Phase 10).
- **Xdebug head-to-head bench** is repeatable via `bash scripts/bench-vs-xdebug.sh`. Requires `pecl install xdebug` first.
- **GitHub remote**: `git@github.com:thamibn/php-periscope.git`. CI is `.github/workflows/ci.yml` — macOS + ubuntu matrix on PHP 8.3, plus a Linux ASan job. Note CI hasn't been validated end-to-end since the capnp dependency was added; first push that lands on a fresh CI runner may need the workflow updated to install capnp + Rust before `make extension`.
- **Open question for Phase 5c**: how do we handle the request/response envelope capture cleanly? Either (a) C extension reads `$_SERVER` etc. at RINIT (framework-agnostic, no Laravel coupling) or (b) Laravel adapter's RequestHook explicitly forwards via new userland functions. Option (a) is more correct architecturally; option (b) is faster to ship. Pick during 5c implementation.
