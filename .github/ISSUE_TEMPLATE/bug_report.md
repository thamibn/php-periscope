---
name: Bug report
about: Something doesn't behave as documented or expected
title: "[bug] "
labels: ["bug", "needs-triage"]
assignees: []
---

## Summary

<!-- One sentence: what is wrong? -->

## Steps to reproduce

1.
2.
3.

## Expected behaviour

<!-- What you thought would happen. -->

## Actual behaviour

<!-- What actually happened. Paste error messages, stderr output, log lines. -->

## Environment

- OS:                  <!-- macOS 14.5 / Ubuntu 22.04 / etc -->
- PHP version:         <!-- output of `php -v` -->
- Periscope version:   <!-- output of `php -r 'echo phpversion("periscope");'` -->
- Laravel version:     <!-- composer show laravel/framework | grep versions -->
- Daemon version:      <!-- periscope-daemon --version -->
- Install method:      <!-- install.sh / homebrew / pecl / manual -->

## Trace artefact (optional but very helpful)

If the bug is reproducible against a recorded request, export the trace and attach it:

```bash
periscope-export <trace-id> --format json --out bug-trace.json
```

A redacted JSON export is much more useful for triage than a screenshot.

## Anything else?

<!-- Hunches, recent config changes, custom middleware that might matter. -->
