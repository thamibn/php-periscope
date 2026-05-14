# FAQ

## Is this Xdebug?

No. periscope replaces Xdebug for most workflows. It uses the modern Zend Observer API (PHP 8.0+) rather than DBGp, and ships its own DAP server in Rust. Function-boundary capture is much cheaper than Xdebug's per-opcode tracing — typical overhead is < 5× active and < 1.05× when the extension is loaded but the request isn't being traced.

You **don't need both**. Uninstall Xdebug while you use periscope. (Two `extension=` lines that both hook every function call will create amusing failure modes.)

## How much overhead?

| Mode | Overhead vs no extension |
|---|---|
| Loaded but inactive | < 1.05× |
| Active, tracing to a file | ~1.3–2.0× on a typical Laravel route |
| Hot path (deeply recursive userland) | up to ~4× worst-case |

See [`docs/POSITIONING.md`](https://github.com/thamibn/php-periscope/blob/main/docs/POSITIONING.md) for the head-to-head benchmark numbers vs Xdebug.

## Does it work in production?

**Not yet.** v1 is dev / staging only. Three reasons:

1. Trace contents include cookies, headers, request bodies, captured variables — credential-leakage risk.
2. The UI in production is locked behind a 403 by default (`PERISCOPE_UI_ALLOW_IN_PROD=false`). You can open it with a token (`PERISCOPE_UI_TOKEN`) but doing so on the public internet is a security hole.
3. We don't ship sampling. Every request is recorded.

Production-ready sampling + snapshot-on-error mode is v2.

## Does periscope phone home?

No. Zero telemetry. Zero analytics. Zero remote endpoints. Traces stay on disk in `/tmp/periscope/`. The toolbar chip's Web Vitals POST stays on `localhost:9999`. The MCP server is local-only over stdio. No SaaS.

If you set `PERISCOPE_AI_ENABLED=true`, the configured provider (Ollama / OpenAI / Anthropic / etc.) will see redacted log lines + SQL fingerprints for AI suggestions — but that's your provider, not ours.

## How is it different from Telescope?

Telescope is a post-mortem dashboard you query after the fact. periscope is a **live** debugger you scrub through frame-by-frame, with full variable capture and function-stack context. You also get insights (N+1, slow-query analysis, AI suggestions) that Telescope doesn't compute.

You can run both side-by-side; periscope's UI doesn't conflict with Telescope's. We auto-skip Telescope's own self-polling routes (`/telescope/*`) so traces don't fill up with Telescope dashboard traffic.

## How is it different from DebugBar?

DebugBar is a footer chip in the page. periscope is a full debugger that opens in its own UI (or mounts at `/periscope` inside your app). DebugBar shows the current request; periscope keeps the last N requests and lets you scrub backward.

Like Telescope, you can run both — we auto-skip `/_debugbar/*` traffic.

## Does it work with Valet / Herd / Docker / Laravel Sail?

Valet ✓ (multi-PHP setups too — install script detects each brew PHP and writes its `99-periscope.ini`).
Herd ✓ if the bundled PHP is 8.3+.
Docker / Sail ✓, but you'll need to mount the extension into the container or build it inside.

## Does the daemon need to run all the time?

Only when you're using the UI or wiring an AI agent. The C extension records traces with or without the daemon — they accumulate in `/tmp/periscope/` and get cleaned up by retention.

You can `systemd-run --user` or `launchd`-control the daemon. v1.1 may ship a `periscope-daemon` launchd / systemd unit.

## How do I share a trace with a colleague?

```bash
periscope-export <trace-id> --format html --out bug.html
```

Email `bug.html`. Recipient double-clicks. Full debugger UI in their browser. No daemon, no install. The bundle inlines `window.PERISCOPE_TRACE` so the SolidJS app reads the data without `/api/*` calls.

Alternative formats: `--format json` (for AI agents / scripts), `--format cptrace` (binary, for re-hosting in another daemon).

## Can my AI read the traces?

Yes — that's the whole AI-native pillar.

```bash
claude mcp add periscope -- php artisan mcp:start periscope
```

Then Claude / Cursor / Codex / any MCP-speaking agent can call `list_traces`, `get_summary`, `get_insights`, `query_events` (with our JSON-path filter language), `get_state` (time-travel to a moment), and `read_file`.

## How does the time-travel work?

The C extension records function entry/exit events with timestamps and captured variables. The Rust daemon indexes these into a `TraceIndex` and exposes a `frame_at(t)` lookup — the deepest frame whose `[enter, exit]` window covers the cursor time `t`. State reconstruction returns the deepest frame, full call stack, scope variables, and prefix events.

The UI's timeline scrubber drives `at_micros` directly; cursor changes fan out over WebSocket so two browser tabs on the same trace stay in sync.

DAP's `stepBack` request is wired to the same machinery — VSCode's reverse-step button works.

## Why function-boundary, not opcode-level?

Per-opcode tracing is what makes Xdebug slow. We chose function-boundary as the v1 sweet spot:

- Enough information to reconstruct call stacks, see what was passed in / returned, scrub through frames.
- Cheap enough that real Laravel apps stay debuggable.

Opcode-level zoom (sampling-based, for hot-path analysis) is v2.

## Why SolidJS, not React / Svelte?

The timeline scrubber state updates at 60fps when dragging. SolidJS's fine-grained reactivity means only the affected DOM nodes re-render — no virtual-DOM diff per frame. Bundle is ~27KB gzipped vs React's ~140KB or Svelte's ~30KB runtime.

## Why Cap'n Proto, not Protobuf?

Cap'n Proto is zero-copy on read. Our daemon scans traces at every cursor move; zero-copy decoding means the latency stays imperceptible even on multi-MB traces. Protobuf needs a full parse per read.

## Why isn't Windows native supported?

A combination of engineering cost and audience math.

**Engineering:**

- PHP extensions on Windows use a different build script (`config.w32`) and need MSVC built against the same Visual Studio version PHP itself uses. The C extension would need a parallel build path.
- Cap'n Proto C++ on Windows needs vcpkg/MSVC; the daemon's IPC layer needs Windows named-pipe variants of the Unix domain sockets we use today.
- `scripts/install.sh` is bash — Windows would need a PowerShell twin.
- CI runners × PHP versions × ASan would double or triple the cost of every run.

**Audience math:**

- Most Laravel devs on Windows already use **WSL2** (or Docker, which is built on WSL2 on Windows anyway). Laravel Sail explicitly targets WSL2.
- WSL2 runs our Linux install path with zero changes. We get Windows users covered at near-zero cost to us; native support would double our CI matrix and test surface for a small additional audience.

Setup is one PowerShell command: `wsl --install -d Ubuntu-22.04`. See the [Windows section in Getting Started](/guide/getting-started#windows-wsl2).

## I have a different question.

[Open a discussion](https://github.com/thamibn/php-periscope/discussions) — we'll add it here if it's broadly useful.
