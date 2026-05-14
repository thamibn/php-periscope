# Getting started

::: warning Alpha · Under active development
php-periscope is pre-release. Things will move, break, and change shape — including this install flow. If you're trying it out, please file issues on [GitHub](https://github.com/thamibn/php-periscope/issues). **Don't use this in production yet.**
:::

In about three minutes you'll have the extension loaded, the daemon running, and your first Laravel request showing up in the UI.

## Prerequisites

- **macOS 13+ or Linux** (Ubuntu 22.04+ tested). Windows: see [the Windows section below](#windows-wsl2).
- **PHP 8.3 or 8.4.** Install via [Herd](https://herd.laravel.com) or `brew install php@8.3`. v1 doesn't support 8.1 / 8.2 (v1.1 sprint).
- **A C++17 toolchain.** Clang on macOS (Xcode CLT), gcc on Linux.
- **A Laravel app.** Adapter targets Laravel 12 / 13.

The install script checks for everything else (Rust, Cap'n Proto, PhpStorm, VSCode/Cursor) and offers to install what's missing — you'll see a green ✓ for each requirement that's already present, and a Y/n prompt before anything is installed. No surprise downloads.

### Windows (WSL2)

Windows native is **not supported and never will be** — the C extension hooks into the Zend engine in ways that don't have a clean Windows-native equivalent in scope for v1. The supported path is **WSL2 with Ubuntu**, which is the same setup Laravel Sail and most modern PHP-on-Windows tooling uses.

One-time setup (open PowerShell as administrator):

```powershell
wsl --install -d Ubuntu-22.04
wsl --set-default-version 2
```

Reboot, finish Ubuntu's first-run user setup, then open the Ubuntu terminal. From there everything in this guide works exactly as on a native Linux box — `apt-get install libcapnp-dev capnproto php8.3-dev`, run the install script, `composer require thamibn/laravel-periscope`, etc.

Your Windows IDE (VSCode, PhpStorm) connects to WSL the standard way: VSCode's **Remote - WSL** extension or PhpStorm's **WSL Interpreter** setting. The periscope UI at `http://localhost:9999` is reachable from Windows because WSL2 forwards localhost transparently.

## Install in one line

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/thamibn/php-periscope/main/scripts/install.sh)
```

The script:

1. Builds the C extension (`phpize` → `./configure` → `make`).
2. Drops `periscope.so` into your PHP's `extension_dir`.
3. Writes `99-periscope.ini` into your PHP's `conf.d` so the extension auto-loads.
4. Builds `periscope-daemon` via `cargo build --release`.
5. Installs the daemon + helper binaries into `/opt/homebrew/bin` or `/usr/local/bin`.

Add `--dry-run` to see exactly what *would* happen, or `-v` for verbose output. See `bash scripts/install.sh --help` for all flags.

### Homebrew alternative

If you have brew and prefer formula-style installs:

```bash
brew tap periscopephp/php-periscope https://github.com/thamibn/php-periscope.git
brew install periscopephp/php-periscope/php-periscope
```

The formula builds the extension once per detected brew PHP (`php`, `php@8.3`, `php@8.4`).

## Verify

```bash
php -m | grep periscope        # should print "periscope"
periscope-daemon --version     # should print a version string
```

If either fails, re-read the script output — it tells you exactly which step missed.

## Wire into your Laravel app

```bash
cd your-laravel-app
composer require thamibn/laravel-periscope
```

That's the whole adapter setup. Service-provider auto-discovery picks it up; defaults are sensible.

On first install the adapter appends two lines to your `.env`:

```bash
# php-periscope — set to false to disable on this environment
PERISCOPE_TOOLBAR_ENABLED=true
PERISCOPE_UI_ENABLED=true
```

This is **idempotent**: if either key is already present (set to anything, including `false`), the adapter leaves your `.env` alone. It only runs in console context (e.g. during `composer require`) — web requests never touch the filesystem.

Visit any route in your app — the first request writes a `.cptrace` file to `/tmp/periscope/`.

::: tip Trace recording is independent
These two flags only control **how you access** traces. Trace recording itself is always on (controlled by the C extension's `periscope.enabled` ini knob, default `1`) — turning the toolbar or in-app UI off doesn't disable observability. You can still open `http://localhost:9999`, use the IDE plugins, or query traces via the MCP server with both flags set to false.
:::

### `PERISCOPE_TOOLBAR_ENABLED`

**When `true`** (auto-installed default): a `InjectToolbar` middleware is pushed onto Laravel's `web` middleware group. After your controller renders an HTML response, the middleware injects a small floating chip just before `</body>`. The chip shows: request duration, SQL query count, peak memory, HTTP status. Clicking it opens the trace in the in-app UI (`/periscope`) or — if `PERISCOPE_UI_ENABLED=false` — at `http://localhost:9999`.

What this affects:
- **HTML responses only.** The middleware checks `Content-Type` and skips JSON, XML, plain text, downloads, and anything without a `</body>` tag (so partial responses, htmx fragments, and Inertia JSON aren't touched).
- **Response body size**: ~1 KB extra HTML/CSS/JS per page.
- **Per-request cost**: a single regex on the response body. Sub-millisecond on a typical page; profile yourself if your responses are very large.
- **Production note**: don't leave on in prod for end users — set it `false` in production envs, leave it `true` in `local` / `staging`.

**When `false`**: middleware never registers, zero per-request work, no chip in the browser. Trace recording continues unchanged. Use this in prod or when the chip clashes with your own page footer.

```bash
# .env — production override
PERISCOPE_TOOLBAR_ENABLED=false
```

### `PERISCOPE_UI_ENABLED`

**When `true`** (auto-installed default): a route group is registered at `/periscope` (path is configurable via `PERISCOPE_UI_PATH`). Hits to `app.test/periscope` serve the same SolidJS UI the standalone `periscope-daemon` hosts at `http://localhost:9999` — same trace list, same time-travel scrubber, same panels. Convenient when you'd rather not juggle two browser tabs.

What this affects:
- **One route group** added to your Laravel router (`/periscope`, `/periscope/{trace}`, `/periscope/assets/*`).
- The route group uses the `web` middleware by default — i.e. anyone with web-session access can view it. Override via `PERISCOPE_UI_MIDDLEWARE=web,auth` (or stricter) for shared dev environments.
- Talks to the daemon over WebSocket — `periscope-daemon` still needs to be running on the host (`http://127.0.0.1:9999` by default; override with `PERISCOPE_UI_DAEMON_BASE`).
- Static assets are served by Laravel, not the daemon.

**When `false`**: no `/periscope` route added. Open the daemon's UI directly at `http://localhost:9999` instead. Use this when:
- You don't want a debugger UI accessible inside your Laravel app's domain (defense in depth on shared dev / staging boxes).
- The `/periscope` path conflicts with one of your own app routes.
- You only ever use the IDE plugins or AI agents (MCP) to read traces.

```bash
# .env — disable in-app mount, use the daemon's port instead
PERISCOPE_UI_ENABLED=false
```

### What about production?

Recommended in `.env.production`:

```bash
PERISCOPE_TOOLBAR_ENABLED=false   # don't ship a debugger chip to end users
PERISCOPE_UI_ENABLED=false        # don't expose the trace UI on your public domain
PERISCOPE_ENABLED=false           # if you want zero overhead, fully disable the adapter
```

v1 ships local-dev only; production sampling lands in v2.

## Open the UI

```bash
periscope-daemon
```

Then open <http://localhost:9999> and trigger any route in your Laravel app. The most recent trace lands at the top of the sidebar — click it, drag the timeline scrubber, expand panels.

## Wire AI agents

The Laravel adapter auto-registers an MCP server. To expose it to Claude Code, Cursor, Codex, or any MCP-speaking agent:

```bash
claude mcp add periscope -- php artisan mcp:start periscope
```

From there your AI can call `list_traces`, `get_summary`, `get_insights`, `query_events`, `get_state` (time-travel to a specific microsecond), and `read_file`. See [Architecture → AI-native](/guide/architecture#ai-native) for the tool set.

## IDE integration

::: tabs

== VSCode

The install script you ran above **already installed the extension into VSCode (or Cursor, or VSCodium) if it found a `code` CLI on your machine** — no separate step. Restart your editor, then hit **F5** in your Laravel project — periscope synthesises a launch config that opens the most recent trace. Step Over / Step Into / Step Out / **Step Back** all work via the daemon's DAP transport.

Didn't have an editor at install time? Install VSCode/Cursor and re-run the install script — it's idempotent and will pick up the new install.

== PhpStorm

The install script you ran above **already dropped the JetBrains plugin into every PhpStorm install on your machine** — no separate step. Restart any open PhpStorm window, then:

1. **Run → Edit Configurations → + → Periscope**
2. Pick your trace file (default: `$ProjectFileDir$/tmp/periscope/latest.cptrace`)
3. Hit **Shift+F9**

You get PhpStorm's native debug toolbar (Step Over, Step Into, Step Out, Resume) — plus the **Step Back** button Xdebug never has. Same Variables / Watches / Call Stack panels you already know.

Full walkthrough: **[PhpStorm setup →](/guide/phpstorm)**.

:::

## What's next?

- [PhpStorm setup](/guide/phpstorm) — register the plugin's Run/Debug config + first breakpoint.
- [Architecture](/guide/architecture) — what's inside the extension, the daemon, and the adapter.
- [Known limitations](/guide/known-limitations) — what doesn't work in v1.
- [FAQ](/guide/faq) — common questions about overhead, data privacy, and compatibility.
