# PhpStorm

Periscope ships a **first-party PhpStorm plugin** — `dev.periscope.phpstorm` — built on JetBrains' DAP-client API. Step Over, Step Into, Step Out, breakpoints, variables, watches, evaluate-expression, and **Step Back** (the button Xdebug never has) all work in PhpStorm's native debug toolbar.

::: tip Status
v0.1.0-alpha. Compatible with PhpStorm **2024.2+** (build `242.*` through `251.*`). The install is a single command — no Gradle, no Java, no source clone. The pre-built `.zip` is dropped into every PhpStorm install on your machine.
:::

## The whole install, end to end

If you haven't installed periscope yet, run the one-liner. **It installs the C extension, the daemon, AND the JetBrains plugin in one step** — every PhpStorm install on your machine gets the plugin dropped into its `plugins/` directory automatically.

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/thamibn/php-periscope/main/scripts/install.sh)
```

Then restart any open PhpStorm window. That's it.

If you already had periscope installed (just the extension + daemon), re-run the same line — it will detect PhpStorm and add the plugin without re-doing the C-extension build.

## Verify the plugin is there

PhpStorm → **Settings → Plugins → Installed** → search for `php-periscope`. Should be enabled, version `0.1.0-alpha`.

## Try it on an existing Laravel app

Assuming you have a Laravel 12 / 13 project at `~/code/my-app`:

```bash
cd ~/code/my-app

# 1. Add the adapter. Service-provider auto-discovery picks it up.
composer require thamibn/php-periscope-laravel

# 2. Start the daemon (in a separate terminal, leave it running)
periscope-daemon

# 3. Trigger any route once — produces the first .cptrace file.
curl http://localhost:8000/  # or whatever your APP_URL is
```

A `.cptrace` file now sits at `tmp/periscope/latest.cptrace` inside your project. PhpStorm can open it.

## Set a breakpoint, debug

1. Open the project in PhpStorm.
2. Open any `.php` file you want to inspect — say `app/Http/Controllers/HomeController.php`.
3. Click the gutter next to a line — a red breakpoint dot appears, same as Xdebug.
4. **Run → Edit Configurations → + → Periscope**.
   - **Name**: anything — e.g. `Periscope: latest trace`
   - **Trace file**: `$ProjectFileDir$/tmp/periscope/latest.cptrace` (or use the file picker)
   - **Daemon binary**: leave as `periscope-daemon` (it's on your `$PATH` after `install.sh`)
   - **Stop on entry**: off (unless you want to pause at the very first userland frame)
5. Click **OK**.
6. Hit **Shift+F9** (or the green bug icon).

PhpStorm pauses at your breakpoint with the full debugger UI:

- **Variables panel** — every captured variable at this frame, with type chips
- **Watches panel** — add expressions
- **Call Stack panel** — full request stack, click any frame to jump there
- **Threads panel** — single-thread (PHP requests are single-threaded in v1)
- **Console** — daemon stderr + any captured output
- **Debug toolbar** — Step Over (F8), Step Into (F7), Step Out (Shift+F8), **Step Back (Ctrl+Shift+F7)**, Resume (F9), Stop, Mute Breakpoints

The **Step Back** button is the periscope-vs-Xdebug differentiator. Hit it and the cursor moves backward through the recorded trace — `$user` reverts to its prior value, queries that ran later disappear, the call stack rewinds.

For richer time-travel UX (visual timeline scrubber, SQL panel with N+1 detection, Insights, AI advisor), open the browser at <http://localhost:9999> — same daemon, same trace, richer panels.

## Updates

Two paths — pick whichever fits your team's habits.

### Manual update — re-run the install script (recommended for solo / small teams)

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/thamibn/php-periscope/main/scripts/install.sh)
```

Idempotent: same command as the original install. It picks up whatever's in the **latest** GitHub Release, drops the new plugin `.zip` into every PhpStorm install on your machine, restarts nothing — you choose when to restart PhpStorm to pick up the new plugin. **No background polling. No surprise updates.** Run it when you want the new version.

If you want to know whether there's a new version *before* running the command, check the [releases page](https://github.com/thamibn/php-periscope/releases).

### Automatic update via PhpStorm — self-hosted plugin repository

If you'd rather have PhpStorm notify you when a new version drops (same UX as marketplace plugins, just without going through marketplace), add this URL once:

1. PhpStorm → **Settings → Plugins → ⚙ → Manage Plugin Repositories → +**
2. Paste: `https://periscope.thamibn.com/jetbrains/updatePlugins.xml`
3. Click **OK**.

PhpStorm now polls that URL alongside `plugins.jetbrains.com` on its normal update cycle. When we tag a new release, PhpStorm shows the standard "Updates available" notification and installs the new periscope version on next restart — same flow as any marketplace plugin.

This is opt-in. If you skip it, periscope **never** phones home — you stay on whatever version `install.sh` last installed until you re-run it.

### Updating just the C extension or daemon (without touching the plugin)

The install script is one-shot — it updates everything. If you want finer control, build from a fresh `git pull` and copy the artefacts yourself:

```bash
cd ~/code/php-periscope
git pull
make extension              # rebuilds the .so
cd daemon && cargo build --release  # rebuilds periscope-daemon
# then copy them in place as install.sh does
```

Most users won't need this — the one-liner is the path.

## What's not (yet) shipped

- ❌ **JetBrains Marketplace listing.** v0.3 public-beta milestone. Until then, install via the script or the custom repository URL above.
- ❌ **Gutter actions** ("Open trace at this line", "Scrub to this frame"). v0.2.
- ❌ **Embedded tool window with the SolidJS UI** (`JCEF`-based). v0.2. For now the browser UI at `:9999` covers it.
- ❌ **Run-to-Position** (`Ctrl+Alt+F9`). Falls back to Resume in v0.1.0-alpha.

## If something doesn't work

- **Plugin doesn't show up in Settings → Plugins.** Check `~/Library/Application\ Support/JetBrains/PhpStorm<version>/plugins/periscope-jetbrains/` exists. If not, re-run `install.sh -v` and look for `plugin installed: PhpStorm…` lines.
- **`./gradlew buildPlugin` failed with `Unable to locate a Java Runtime`** — that's the *contributor* flow; end users don't build from source. Use the curl-bash install line above instead.
- **`periscope: open latest trace` Run config errors with `expected a .cptrace file`** — you haven't triggered a request yet. Run `periscope-daemon &` and hit any route in your Laravel app once.
- **PhpStorm 2024.1 or older** — unsupported. Upgrade PhpStorm; LSP4IJ similarly required 2024.2+.

## Why we built our own plugin (vs reusing LSP4IJ)

LSP4IJ is a fine general-purpose LSP+DAP client, but:

- 80% of its codebase is the LSP half — we don't need language-server features (PhpStorm's bundled PHP plugin already provides them).
- Its DAP implementation [doesn't support `stepBack` or `reverseContinue`](https://github.com/redhat-developer/lsp4ij/blob/main/docs/dap/DAPSupport.md), which kills our time-travel pitch in the IDE.
- EPL-2.0 license — derivative work would have to adopt EPL-2.0, inconsistent with our proprietary `LICENSE`.

Our plugin is ~800 LOC of Kotlin, focused on exactly the DAP requests periscope uses, with Step Back wired up from day one. Live in [`jetbrains-plugin/`](https://github.com/thamibn/php-periscope/tree/main/jetbrains-plugin).
