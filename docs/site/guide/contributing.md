# Contributing

The full contributor guide lives at the repo root: [**CONTRIBUTING.md**](https://github.com/thamibn/php-periscope/blob/main/CONTRIBUTING.md).

It covers:

- **Repo layout** — what each folder does.
- **Dev environment** for each subsystem (extension, daemon, ui, laravel-adapter, vscode-extension), plus the prerequisite toolchains.
- **Build + test commands** — `make extension`, `cargo test`, `bun test`, `./vendor/bin/pest`.
- **Memory-safety workflow** — AddressSanitizer + Valgrind. ASan must stay green.
- **Coding standards** per language — clang-format / rustfmt / Pint / Prettier.
- **Commit + PR rules** — no AI co-author attribution, no GPG signing required, one logical change per PR, `make ci` clean before pushing.
- **How to file bugs** — the dedicated [crash report template](https://github.com/thamibn/php-periscope/blob/main/.github/ISSUE_TEMPLATE/crash_report.md) needs a stack trace + `php -m` output, no exceptions.

Quick links:

- [`thoughts/shared/plans/2026-05-08-php-periscope-mvp.md`](https://github.com/thamibn/php-periscope/blob/main/thoughts/shared/plans/2026-05-08-php-periscope-mvp.md) — the phased plan of record.
- [`docs/SCOPE.md`](https://github.com/thamibn/php-periscope/blob/main/docs/SCOPE.md) — what's in / out of scope for v1.
- [`docs/ARCHITECTURE.md`](https://github.com/thamibn/php-periscope/blob/main/docs/ARCHITECTURE.md) — the deep-dive on how the four components fit.
- [`CODE_OF_CONDUCT.md`](https://github.com/thamibn/php-periscope/blob/main/CODE_OF_CONDUCT.md) — Contributor Covenant 2.1.

## Reporting issues

- **Bug report** (something doesn't behave as documented): [bug_report template](https://github.com/thamibn/php-periscope/issues/new?template=bug_report.md).
- **Crash report** (PHP process exited unexpectedly): [crash_report template](https://github.com/thamibn/php-periscope/issues/new?template=crash_report.md). Please fill every field — we can't triage process exits without the stack trace + environment + extension list.
- **Feature request / discussion**: [GitHub Discussions](https://github.com/thamibn/php-periscope/discussions).

## Security disclosure

Privately email the maintainer rather than opening a public issue. We respond within 7 days.
