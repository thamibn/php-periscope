# CLAUDE.md

Guidance for Claude Code working in the php-periscope repository.

## Project Overview

**php-periscope** is a live observability + time-travel debugger **built for Laravel** (v1 ships Laravel-only — see plan §A.1). It pauses any Laravel request, shows the developer every variable, SQL query, log line, dispatched job, fired event, cache hit, Redis command, and outbound HTTP call that occurred up to the paused line — and lets them scrub backward in time. The C extension is framework-agnostic by design so future packages (`periscopephp/symfony`, `periscopephp/wordpress`, `periscopephp/codeigniter`) can ship post-v1, but in v1 we test, market, and support **only** Laravel.

This is a greenfield project. The full plan lives at `thoughts/shared/plans/2026-05-08-php-periscope-mvp.md`. Always read that plan before starting any implementation work.

## Tech Stack

| Component | Language | Why |
|-----------|----------|-----|
| `extension/` | C (Zend Observer API, PHP 8.0+) | Engine-level hooks require C. Observer API is the modern path. |
| `daemon/` | Rust (tokio + capnp + serde) | Memory-safe DAP server, replay engine, WebSocket UI bridge |
| `proto/` | Cap'n Proto schema | Zero-copy trace format for fast scrubbing |
| `laravel-adapter/` | PHP 8.3 (Composer package) | Laravel-specific observability hooks |
| `ui/` | SolidJS + TypeScript + Tailwind, Vite + Bun | Fine-grained reactivity for 60fps timeline scrubbing |
| `vscode-extension/` | TypeScript | DAP debug-type registration, daemon spawning |

## Project Layout

```
php-periscope/
├── extension/              # C extension (Zend Observer API)
├── daemon/                 # Rust DAP server + replay engine
├── ui/                     # SolidJS browser UI
├── laravel-adapter/        # Composer package for Laravel hooks
├── vscode-extension/       # VSCode marketplace extension
├── proto/                  # Cap'n Proto trace schema
├── tests/
│   ├── integration/
│   ├── perf/
│   └── real-world/         # Laravel, Symfony, WordPress submodules
├── docs/                   # VISION, SCOPE, ROADMAP, ARCHITECTURE
├── thoughts/shared/plans/  # Implementation plans (the source of truth)
├── scripts/                # install.sh, uninstall.sh
└── .github/workflows/      # CI: build + ASan + Valgrind nightly
```

## Build & Test Commands

```bash
# Build C extension
make extension              # phpize && configure && make
make asan                   # build with AddressSanitizer
make valgrind               # run tests under Valgrind (Linux)

# Build Rust daemon
cd daemon && cargo build --release

# Build UI
cd ui && bun install && bun run build

# Run tests
make test                   # full test suite
make test-unit              # C .phpt + Pest unit tests
make test-integration       # end-to-end DAP smoke test
make test-real-world        # Laravel/Symfony/WordPress integration

# CI parity (run before pushing)
make ci
```

## Critical Invariants

1. **AddressSanitizer must stay green.** Every CI run builds the C extension with `-fsanitize=address` on Linux. A red ASan job blocks merge.
2. **No `unsafe` in the Rust daemon, with one documented exception.** Enforced by `#![forbid(unsafe_code)]` at crate root. The single allowed exception is the trace mmap reader (Phase 7) — it requires `unsafe` because the OS can change a mapped file out-of-band; we make a written `# Safety` promise that trace files are append-only-at-write / read-only-thereafter and back it with tests. The relaxation lives in *one* function, gated by `#[allow(unsafe_code)]` with a doc comment, not a global lift.
3. **Observer API only, no DBGp.** We use Zend Observer API (PHP 8.0+) and DAP. Do not add DBGp protocol code.
4. **Cap'n Proto, not Protobuf**, for the trace format. Decision recorded in `docs/ARCHITECTURE.md`.
5. **Function-boundary recording, not opcode-level.** v1 captures variables only at function entry/exit. Do not add per-opcode hooks.
6. **PHP 8.3 only for v1.** Do not add 8.1/8.2/8.4 compat in v1; that's a v1.1 sprint.
7. **macOS + Linux only.** Skip Windows code paths.
8. **No Laravel-version-specific code in `extension/`.** Framework details belong in `laravel-adapter/` (Composer package), not the C layer. (The extension stays framework-agnostic; only the Composer adapter is Laravel-specific.)
9. **Laravel-only in v1.** Don't add Symfony/WordPress/CodeIgniter code paths, tests, or doc claims. Other frameworks ship as separate Composer packages post-v1.

## Git Commit Rules

- **Do not include Claude/AI attribution** in commit messages, PR titles, or PR bodies. No `Co-Authored-By: Claude`, no `🤖 Generated with Claude Code`.
- **Skip GPG signing**: pass `--no-gpg-sign` (or use `-c commit.gpgsign=false`).
- This overrides the Claude Code default commit template.

## How to Approach Work in This Repo

- Read the plan at `thoughts/shared/plans/2026-05-08-php-periscope-mvp.md` before any implementation.
- The plan is phased — work the phases in order. Phase 1 must produce a working `.so` before Phase 2 starts.
- After each phase that has a "pause point" (Phase 1, 3, 9a, 10), stop and confirm with the user before moving on.
- Use Pest tests for the Laravel adapter. Use `.phpt` tests for the C extension. Use `cargo test` for the daemon. Use `bun test` for the UI.

## What's Out of Scope (v1)

See `docs/SCOPE.md`. Highlights of what NOT to build:

- **Symfony / WordPress / CodeIgniter / plain PHP support** — v1.1+, separate Composer packages
- Production debugging (sampling, snapshot breakpoints) — v2
- Async runtime support (Fibers, Swoole, Frankenphp, Octane) — v2+
- PhpStorm-specific UX polish — v2
- OpenTelemetry export — v2
- Variable mutation tracking — v2
- Closures/references/circular refs perfect handling — v2
- Windows native — out of scope permanently (use WSL)

## References

- Plan: `thoughts/shared/plans/2026-05-08-php-periscope-mvp.md`
- Vision: `docs/VISION.md`
- Scope: `docs/SCOPE.md`
- Roadmap: `docs/ROADMAP.md`
- Architecture: `docs/ARCHITECTURE.md`
