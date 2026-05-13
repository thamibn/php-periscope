<!--
Thanks for the contribution! A few quick checks before we merge.
Delete sections that don't apply.
-->

## Summary

<!-- What does this PR change, and why? One short paragraph. -->

## Phase

<!-- Which plan phase does this map to? Link the section in
     thoughts/shared/plans/2026-05-08-php-periscope-mvp.md if relevant. -->

## Type of change

- [ ] Feature
- [ ] Bug fix
- [ ] Refactor (no behavioural change)
- [ ] Performance
- [ ] Docs
- [ ] Build / CI / tooling
- [ ] Test-only

## Checklist

- [ ] I ran `make ci` locally and it passed.
- [ ] I read [CONTRIBUTING.md](../CONTRIBUTING.md).
- [ ] If I touched C, the ASan build is still clean.
- [ ] If I touched Rust, `cargo clippy -- -D warnings` and `cargo fmt --check` pass.
- [ ] If I touched the adapter, `./vendor/bin/pest` passes (excluding pre-existing failures).
- [ ] If I touched the UI, `bun run build` succeeds and `bun test` passes.
- [ ] I did **not** add Symfony / WordPress / async / production-mode code paths (v1 is Laravel-only — see [docs/SCOPE.md](../docs/SCOPE.md)).
- [ ] My commits do **not** carry AI co-author attribution.
- [ ] My commits are not signed (per project policy).

## How was this tested?

<!-- New tests added, manual steps run, screenshots if UI work. -->

## Related issues

<!-- Closes #123, refs #456, etc. -->
