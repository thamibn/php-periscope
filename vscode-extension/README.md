# PHP Periscope — VSCode extension

Live observability + time-travel debugger for PHP/Laravel apps. Scrub through any recorded HTTP request, see every SQL query, log line, cache op, dispatched job, fired event, sent mail, outbound HTTP, and exception — with full DAP step-in/step-over/step-out/**step-back** support inside VSCode.

## What this extension does

- Registers a custom debug type `periscope`. Hit **F5** in a Laravel project after recording a trace and step through it like a paused process.
- Auto-spawns the `periscope-daemon` binary (DAP server) on activation. Status bar chip shows daemon liveness; click it to open the browser UI at `http://localhost:9999`.
- Step-back works out of the box (`supportsStepBack`) — the daemon's replay engine reconstructs state at any moment in the recorded trace.

## Prerequisites

1. **The C extension is installed** (`extension=periscope.so` in your `php.ini`).
2. **The `periscope-daemon` binary is on `PATH`**.
3. **The Laravel adapter is installed** in the project being debugged: `composer require thamibn/php-periscope-laravel`.

All three drop in with one command:

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/thamibn/php-periscope/main/scripts/install.sh)
```

## Quick start

1. Trigger an HTTP request in your Laravel app while the C extension is loaded — the daemon will record a `.cptrace` in `/tmp/periscope/`.
2. In VSCode: **F5** → pick **"Periscope: open latest trace"** → step through the recorded execution. Use **Step Back** (`Shift+F11` or the reverse-step toolbar button) to scrub backward in time.
3. Status-bar chip → opens the browser UI at `localhost:9999` for the panel view of queries / logs / cache / jobs / events / etc.

## Settings

| Setting | Default | What it does |
|---|---|---|
| `periscope.daemonPath` | `periscope-daemon` | Path to the daemon binary. Set to an absolute path if it's not on PATH. |
| `periscope.daemonUrl` | `http://127.0.0.1:9999` | Where the daemon's HTTP / WebSocket API lives. |
| `periscope.autoStartDaemon` | `true` | Spawn the daemon when the extension activates; kill it on shutdown. |

## Commands

| Command | What it does |
|---|---|
| `Periscope: Open Browser UI` | Opens `${periscope.daemonUrl}` in your default browser. |
| `Periscope: Start Daemon` | Spawns the daemon (no-op if already running). |
| `Periscope: Stop Daemon` | Sends SIGTERM to the running daemon process. |

## Status bar

A `$(eye) periscope` chip on the right edge of the status bar polls `/api/health` every 3 seconds. Green-eye = daemon up; closed-eye + `offline` text = daemon not reachable.

## Wiring AI agents

The MCP server lives inside the Laravel adapter. Once you've installed the adapter:

```bash
claude mcp add periscope -- php artisan mcp:start periscope
```

Claude / Cursor / Codex can then call `list_traces`, `get_insights`, `query_events`, `get_state` (time-travel), and friends — same data the UI shows.

## Licence

See the [project licence](https://github.com/thamibn/php-periscope/blob/main/LICENSE) — proprietary, v1 is Laravel-only.
