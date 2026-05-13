---
name: Crash report
about: PHP process exited unexpectedly with the extension loaded
title: "[crash] "
labels: ["crash", "needs-triage", "priority:high"]
assignees: []
---

> **Please fill every section.** We genuinely cannot triage a process exit without the stack trace, environment, and loaded-extension list.

## What were you doing?

<!-- e.g. "running `php artisan test`", "loading /listings/123 in the browser", "running phpunit". -->

## How often does it reproduce?

- [ ] Every time on the same input
- [ ] Sometimes (~__ of __ attempts)
- [ ] Once so far

## Stack trace

<details>
<summary>Trace</summary>

```
<paste here — see "How to capture a trace" below>
```
</details>

## Environment

- OS + version:        <!-- e.g. macOS 14.5 (Sonoma) arm64 / Ubuntu 22.04 x86_64 -->
- PHP version:         <!-- `php -v` -->
- Periscope version:   <!-- `php -r 'echo phpversion("periscope");'` -->
- Daemon version:      <!-- `periscope-daemon --version` -->
- Build flavour:       <!-- release / asan / valgrind -->
- Install method:      <!-- install.sh / homebrew / pecl / manual -->

## Loaded extensions

<details>
<summary>php -m</summary>

```
<paste full output of `php -m`>
```
</details>

## periscope-specific config

```
<paste lines from your php.ini / 99-periscope.ini that mention periscope.*>
```

## Trace artefact (if any)

If a `.cptrace` was being written when the process exited, attach it (zip it first — they can be large). It often contains the last function frames before the exit.

```bash
ls /tmp/periscope/*.cptrace | tail -5
```

## How to capture a stack trace

<details>
<summary>macOS</summary>

```bash
# After the process exits, recent reports live under:
ls ~/Library/Logs/DiagnosticReports/ | grep -i php
# Open the newest one and paste the relevant frames into the "Stack trace" section.
```
</details>

<details>
<summary>Linux</summary>

```bash
# Re-run under gdb to capture a fresh trace:
gdb --batch -ex run -ex bt --args php <your-args>

# Or, if a core file was generated:
ls /var/lib/systemd/coredump/ /var/crash/ 2>/dev/null
coredumpctl gdb $(coredumpctl list | tail -1 | awk '{print $5}')
```
</details>

## Anything else?

<!-- Custom middleware, unusual PHP modules, recent OS updates, anything that might be relevant. -->
