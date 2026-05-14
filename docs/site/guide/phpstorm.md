# PhpStorm (v1.2 preview)

PhpStorm doesn't ship a first-party Debug Adapter Protocol client, but **[LSP4IJ](https://plugins.jetbrains.com/plugin/23257-lsp4ij)** (Red Hat's open-source DAP plugin for IntelliJ-platform IDEs) does — and periscope's Rust daemon speaks DAP natively. So PhpStorm can talk to periscope today, via LSP4IJ.

::: tip Status
v1.2 preview. Setup works on PhpStorm 2024.2+. Some features (notably the IDE-side step-back button) are gated on LSP4IJ adding the corresponding DAP requests — see [Known limitations](#known-limitations) below. The **browser UI scrubber at `localhost:9999`** is the canonical time-travel surface and works regardless of which IDE you use.
:::

## Prerequisites

- PhpStorm **2024.2** or newer (LSP4IJ's minimum).
- A working periscope install — `periscope-daemon` on your `$PATH`, the C extension loaded, the Laravel adapter `composer require`d. See [Getting started](/guide/getting-started) if you haven't done this yet.
- A `.cptrace` file already on disk (trigger any route in your Laravel app once — the trace lands in `/tmp/periscope/`).

## Step 1 — install LSP4IJ

In PhpStorm: **Settings → Plugins → Marketplace → search "LSP4IJ" → Install**, then restart.

Or from the command line:

```bash
# From PhpStorm's CLI tools (Tools → Create Command-line Launcher first)
phpstorm installPlugin com.redhat.devtools.lsp4ij
```

## Step 2 — configure periscope as a DAP server

Open **Settings → Languages & Frameworks → Debug Adapter Protocol** (added by LSP4IJ). Click **+** to register a new DAP server:

| Field | Value |
|---|---|
| Name | `periscope` |
| Command | `periscope-daemon` (or absolute path, e.g. `/opt/homebrew/bin/periscope-daemon`) |
| Args | `--dap-stdio` |
| Wait for trace before sending | unchecked |

Save.

## Step 3 — create a Run/Debug configuration

**Run → Edit Configurations → + → Debug Adapter Protocol**.

| Field | Value |
|---|---|
| Name | `Periscope: open latest trace` |
| Server | `periscope` (the one you just created) |
| Working directory | `$ProjectFileDir$` |
| Launch attributes (JSON) | See below |

Launch JSON:

```json
{
  "type": "periscope",
  "request": "launch",
  "name": "Periscope: open latest trace",
  "tracePath": "${workspaceFolder}/tmp/periscope/latest.cptrace",
  "stopOnEntry": false
}
```

The `tracePath` follows VSCode's `${workspaceFolder}` convention — LSP4IJ resolves it to your PhpStorm project root. Point it at a specific `.cptrace` file, or use the symlink `latest.cptrace` that the daemon maintains.

## Step 4 — debug

Hit **Shift + F9** (or the green bug icon). PhpStorm spawns `periscope-daemon --dap-stdio`, the daemon opens your trace, and you get:

- ✅ **Breakpoints** — standard, conditional, exception
- ✅ **Step over / step into / step out** — moves the cursor forward through the recorded trace
- ✅ **Variables** + **watches** + **evaluate expression** — full scope at the current frame
- ✅ **Call stack** — every frame from the request entry down to the cursor

## Then: open the browser UI for everything else

The IDE gives you Xdebug-parity breakpoint debugging. The richer periscope features — timeline scrubber, SQL panel with N+1 detection, Insights, AI advisor, request/response envelope, jobs, events, mail, exceptions — live at <http://localhost:9999> (or `app.test/periscope` if you set `PERISCOPE_UI_ENABLED=true`).

The browser UI and PhpStorm both connect to the same daemon and the same `.cptrace` file. Scrub backward in the browser, watch the IDE re-sync the moment you set a new breakpoint.

## Known limitations

- ❌ **The IDE-side step-back button is non-functional.** LSP4IJ's current DAP implementation does not handle the `stepBack` / `reverseContinue` requests (per its [`DAPSupport.md`](https://github.com/redhat-developer/lsp4ij/blob/main/docs/dap/DAPSupport.md)). Scrub backward in the **browser UI** at `localhost:9999` instead — it's the canonical time-travel surface anyway. We may upstream a PR to LSP4IJ to fix this.
- ❌ **No PhpStorm gutter affordances** ("set periscope breakpoint" from the gutter context menu, run-config templates, status-bar liveness chip). Those are JetBrains-plugin features, deferred to v2.
- ⚠️ **PhpStorm 2024.1 and older** are unsupported because LSP4IJ requires 2024.2+. Upgrade PhpStorm or stay on VSCode.
- ⚠️ **`stopOnEntry: true` works**, but the cursor lands at the first observed userland frame — not necessarily where you'd expect "request entry" in PhpStorm's Xdebug mental model. The trace doesn't include framework-bootstrap frames before the first userland call.

## Where this lands on the roadmap

This is v1.2 — a docs-only release that documents reusing existing tooling rather than shipping a custom JetBrains plugin. We're not building `periscopephp/phpstorm-plugin` for v1; LSP4IJ already covers ~80% of the IDE-side debugger UX, and the browser UI is where periscope's real differentiation lives.

A first-class JetBrains plugin with gutter affordances, native Run/Debug templates, and an embedded tool window for the trace UI remains on the **v2 roadmap** — but only if beta-tester demand justifies the maintenance cost. See [Roadmap](/guide/roadmap).
