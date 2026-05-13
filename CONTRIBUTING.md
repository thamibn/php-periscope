# Contributing to php-periscope

Thanks for considering a contribution. periscope is greenfield and the bar is high — read this once before you start coding so your patch lands cleanly.

## Quick links

- **Plan of record:** [`thoughts/shared/plans/2026-05-08-php-periscope-mvp.md`](thoughts/shared/plans/2026-05-08-php-periscope-mvp.md). Always read the relevant phase before opening a PR.
- **Vision / scope / roadmap:** [`docs/VISION.md`](docs/VISION.md), [`docs/SCOPE.md`](docs/SCOPE.md), [`docs/ROADMAP.md`](docs/ROADMAP.md).
- **Architecture:** [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md).
- **Issues & ideas:** [`.github/ISSUE_TEMPLATE/`](.github/ISSUE_TEMPLATE) (please pick the right template — the crash-report one in particular has fields we *need*).

## Code of Conduct

This project follows the [Contributor Covenant 2.1](CODE_OF_CONDUCT.md). By participating you agree to abide by it. Report issues to the maintainer privately.

## Project layout

```
extension/        C extension (Zend Observer API, PHP 8.3 only in v1)
daemon/           Rust DAP server + replay engine + WebSocket bridge
ui/               SolidJS + Vite + Tailwind browser UI
laravel-adapter/  Laravel 13 Composer package (hooks + MCP server + toolbar + UI mount)
proto/            Cap'n Proto trace schema
vscode-extension/ Marketplace VSCode extension (`periscope` debug type)
homebrew/         Tap formula
scripts/          install.sh / uninstall.sh / smoke tests
tests/            integration + perf harnesses
docs/             vision / scope / roadmap / architecture
```

## Dev environment

You need each toolchain only for the part you touch.

### Prerequisites (everyone)

- macOS 13+ or Linux (Ubuntu 22.04+). Windows is out of scope; use WSL2.
- Git, Make.

### C extension (`extension/`)

- PHP 8.3 (brew on macOS, `php@8.3` apt PPA on Linux) plus its dev headers (brew bundles them; apt: `php-dev`).
- A C++17 toolchain (clang on macOS, gcc on Linux).
- `pkg-config`.
- Cap'n Proto C++: `brew install capnp` or `apt-get install libcapnp-dev capnproto`.

```bash
make extension       # phpize + ./configure + make → extension/modules/periscope.so
make test            # full suite incl. .phpt + smoke
make asan            # rebuild with -fsanitize=address (Linux only)
make valgrind        # run tests under Valgrind (Linux only)
```

**Invariants:**
- AddressSanitizer **must** stay green. CI fails the merge otherwise.
- No engine-private API (`zend_internal_*`) without sign-off.
- Function-boundary recording only — no per-opcode hooks in v1.
- Framework-specific code does **not** belong here. The extension stays framework-agnostic; Laravel-specific logic lives in `laravel-adapter/`.

### Rust daemon (`daemon/`)

- Rust stable (rustup recommended).
- `cargo build --release` from `daemon/`.

```bash
cd daemon
cargo test               # unit + integration
cargo clippy -- -D warnings
cargo fmt --check
```

**Invariants:**
- `#![forbid(unsafe_code)]` at crate root. The one documented exception is the trace mmap reader (Phase 7), gated by `#[allow(unsafe_code)]` with a `# Safety` comment. Don't relax this globally.
- Cap'n Proto, not Protobuf. Decision lives in `docs/ARCHITECTURE.md`.

### UI (`ui/`)

- Bun (recommended) or Node 20+.

```bash
cd ui
bun install
bun run dev          # localhost:5173, hot-reload
bun run build        # → ui/dist/
bun test             # vitest
```

The daemon serves the built bundle at `localhost:9999`; the Laravel adapter can also mount it at `app.test/periscope` (opt-in).

### Laravel adapter (`laravel-adapter/`)

- PHP 8.3, Composer.

```bash
cd laravel-adapter
composer install
./vendor/bin/pest    # Pest + Orchestra Testbench
```

The adapter targets Laravel 11 / 12 / 13. v1 ships Laravel-only — do **not** add Symfony / WordPress / CodeIgniter code paths.

### VSCode extension (`vscode-extension/`)

```bash
cd vscode-extension
npm install
npm run compile      # → out/extension.js
npm run package      # → php-periscope-x.y.z.vsix
```

## Coding standards

| Layer | Tooling | Notes |
|---|---|---|
| C | `clang-format` (style file at repo root coming with Phase 12c follow-up) | 4-space indent, no tabs. K&R braces. |
| Rust | `cargo fmt` + `cargo clippy -- -D warnings` | All public items documented. |
| PHP | PSR-12 + Laravel Pint where applicable. `declare(strict_types=1);` on every file. | Use Laravel helpers (`Arr::*`, `Str::*`, `collect()`, `Http::baseUrl`, `tap`, `rescue`) over raw PHP. |
| TS / TSX | `tsc --strict`, Prettier defaults | No `any` without comment. |

We do **not** ship code with linter warnings. CI runs the relevant linter for the layer you touched.

## Tests

| Layer | Where | How |
|---|---|---|
| C | `extension/tests/*.phpt` | `make test-phpt` |
| Rust | `daemon/src/**/tests/`, `daemon/tests/*.rs` | `cargo test` |
| UI | `ui/tests/*.test.ts` | `bun test` |
| Adapter | `laravel-adapter/tests/{Unit,Feature}` | `./vendor/bin/pest` |
| End-to-end smoke | `scripts/smoke.sh` | `make smoke` |

The full suite runs with `make ci` — that's the gate every PR must clear.

### AddressSanitizer + Valgrind

ASan is the primary memory-safety net for the C extension. The CI job is **required** — if it goes red, the merge is blocked.

```bash
make asan                # build periscope.so with -fsanitize=address
make test-asan           # run .phpt suite against the asan build
make valgrind            # Linux only; nightly CI also runs this
```

If your patch triggers a leak, fix the leak in the same PR. We do not merge "leaks but doesn't crash."

## Commit + PR rules

- **No AI attribution** in commit messages or PR bodies. No `Co-Authored-By: Claude`, no `🤖 Generated with …`.
- **Skip GPG signing** unless your local config requires it.
- One logical change per PR. If your PR diff has two unrelated subsystems touched, split it.
- The first commit on a feature branch should explain *why* in the body — the *what* is in the diff.
- Run `make ci` locally before pushing.

We follow a phased plan. If your PR doesn't map to a phase or fits cleanly into an open phase's success criteria, open an issue first so we can scope it together.

## Reporting bugs

Use the [bug report template](.github/ISSUE_TEMPLATE/bug_report.md) for general bugs and the dedicated [crash report template](.github/ISSUE_TEMPLATE/crash_report.md) for segfaults / SIGABRT / SIGBUS / SIGSEGV. The crash template asks for a backtrace, the loaded extension list, and the OS / PHP version because we genuinely cannot triage segfaults without those.

## Security

Don't open a public issue for a security report. Email the maintainer directly. We'll respond within 7 days.

## Licence

By contributing you agree your contribution is licenced under the project's [LICENSE](LICENSE). This is currently proprietary; see the licence file for the full terms.
