# Getting started

In about three minutes you'll have the extension loaded, the daemon running, and your first Laravel request showing up in the UI.

## Prerequisites

- **macOS 13+ or Linux** (Ubuntu 22.04+ tested). Windows: see [the Windows section below](#windows-wsl2).
- **PHP 8.3 or 8.4.** v1 doesn't support older PHPs (8.1 / 8.2 are a v1.1 sprint).
- **A C++17 toolchain.** Clang on macOS (Xcode CLT), gcc on Linux.
- **Rust** (rustup) — needed to build the daemon.
- **Cap'n Proto C++ library**: `brew install capnp` / `apt-get install libcapnp-dev capnproto`.
- **A Laravel app.** Adapter targets Laravel 12 / 13.

### Windows (WSL2)

Windows native is **not supported and never will be** — the C extension hooks into the Zend engine in ways that don't have a clean Windows-native equivalent in scope for v1. The supported path is **WSL2 with Ubuntu**, which is the same setup Laravel Sail and most modern PHP-on-Windows tooling uses.

One-time setup (open PowerShell as administrator):

```powershell
wsl --install -d Ubuntu-22.04
wsl --set-default-version 2
```

Reboot, finish Ubuntu's first-run user setup, then open the Ubuntu terminal. From there everything in this guide works exactly as on a native Linux box — `apt-get install libcapnp-dev capnproto php8.3-dev`, run the install script, `composer require periscopephp/laravel`, etc.

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
composer require periscopephp/laravel
```

That's the whole adapter setup. Service-provider auto-discovery picks it up; defaults are sensible. Visit any route in your app — the first request will write a `.cptrace` file to `/tmp/periscope/`.

### Toolbar chip (optional)

To inject a Clockwork-style request chip into HTML responses:

```bash
# .env
PERISCOPE_TOOLBAR_ENABLED=true
```

The chip shows duration / query count / status. Click → opens the trace.

### In-app UI mount (optional)

To serve the periscope UI from inside your Laravel app at `/periscope` (no separate port):

```bash
# .env
PERISCOPE_UI_ENABLED=true
```

`app.test/periscope` now serves the same UI the daemon hosts at `localhost:9999`.

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

## VSCode integration

Install the VSCode extension once it ships on the marketplace (`periscopephp.php-periscope`). Until then, build locally from `vscode-extension/`:

```bash
cd vscode-extension
npm install && npm run package
code --install-extension php-periscope-0.1.0.vsix
```

Hit **F5** in your Laravel project — periscope synthesises a launch config that opens the most recent trace. Step-in / step-over / step-out / **step-back** all work via the daemon's DAP transport.

## What's next?

- [Architecture](/guide/architecture) — what's inside the extension, the daemon, and the adapter.
- [Known limitations](/guide/known-limitations) — what doesn't work in v1.
- [FAQ](/guide/faq) — common questions about overhead, data privacy, and compatibility.
