# Known limitations

What v1 deliberately does *not* do. Each item maps to a future-version commitment or a permanent scope decision in [docs/SCOPE.md](https://github.com/thamibn/php-periscope/blob/main/docs/SCOPE.md).

## Platform

- **Windows native is out of scope, permanently.** Use WSL2.
- **macOS + Linux only.** Tested on macOS 13+ and Ubuntu 22.04+. Other distros likely work but aren't on CI.
- **PHP 8.3 / 8.4 only.** No support for 8.1 / 8.2 in v1. (v1.1 sprint will add them.)

## Frameworks

- **Laravel only in v1.** The C extension is framework-agnostic, but the only adapter we ship + test + market is Laravel. Symfony / WordPress / CodeIgniter / plain PHP support is v1.1+ as separate Composer packages.
- **Laravel versions: 12.x / 13.x.** Laravel 11 may work with hand-edited composer constraints but isn't supported — `laravel/mcp` requires `illuminate/json-schema` 12.41+, so the MCP server in particular needs Laravel 12+. Older versions are out.

## Capture model

- **Function-boundary recording, not opcode-level.** Variables are captured at function entry and exit, not on every assignment. So you see "what `$user` looked like when this function was called" and "what got returned" — not "what `$user` looked like after line 42". Opcode-level zoom ships in v2.
- **Closures, references, and circular references are captured *safely* but not perfectly.** A circular array shows a `[circular]` marker rather than the full reference graph. Closures show the scope class + parameter list, not the captured `use ($var)` state. v2 candidates.
- **Variable mutation is not tracked between captures.** If you mutate `$user->name` between entry and return, periscope sees both values but not the intermediate transitions. v2.
- **No tracking of static-property mutations** between frames. Same reason as above.

## Concurrency

- **No async runtime support.** Fibers, Swoole, FrankenPHP, RoadRunner, Octane — none of those are supported in v1. The Zend Observer API doesn't slice cleanly across Fiber boundaries; we'd need per-Fiber storage. v2.
- **Multi-process safety is best-effort.** Two FPM workers writing traces under the same PID range will produce distinct trace files (the PID is part of the filename), but if you've configured `extension_dir`-shared logs you may see interleaving. Not a real-world problem under default config.

## Production

- **No production-mode in v1.** No sampling, no snapshot-on-error, no remote control plane. Treat periscope as a dev / staging tool. The data captured (cookies, headers, request bodies, captured variables) leaks credentials freely — see the UI's production lockdown (a token-gated 403 by default).
- **No telemetry, no SaaS, no remote shipping.** Traces stay on disk. The toolbar chip's Web Vitals POST stays on `localhost:9999`. The MCP server is local-only over stdio. The export `.html` lives wherever you put it.

## Daemon / UI

- **Single-machine.** The daemon serves one port (`localhost:9999`). There's no clustering, no multi-tenant mode, no auth at the HTTP API in v1 (the daemon assumes it's behind localhost). Production deployments must use the Laravel adapter's UI mount with the `UiGate` token.
- **No PhpStorm UX polish.** DAP works in PhpStorm via the standard DAP plugin, but the JetBrains-native experience (Run/Debug configurations, gutter affordances) is v2 work.
- **No OpenTelemetry export.** Cap'n Proto on disk; periscope-internal protocol over HTTP/WS. v2.

## AI features

- **AI suggestions are opt-in and off by default.** Requires `laravel/ai` + a configured provider (Ollama / OpenAI / Anthropic / Gemini / Groq / OpenRouter / DeepSeek). When disabled, the `ai_suggestion` events simply never fire.
- **The MCP server is local-only.** No `Mcp::web()` registration; no OAuth flow. AI agents must run on the same machine as the daemon.

## Distribution

- **No PECL public release yet.** `pecl install periscope` will work after we publish to pecl.php.net; until then, install via the script, brew, or `pecl install ./extension/package.xml` against a local clone.
- **No public Homebrew tap yet.** The formula lives in this repo (`homebrew/Formula/php-periscope.rb`) and works via `brew install --HEAD` against the `head` branch.
- **No VSCode Marketplace listing yet.** Build locally from `vscode-extension/`.

## What we'll never add

- Windows native code paths (use WSL2).
- A SaaS for shared trace hosting (we don't run your data; export `.html` files and share them yourself).
- Per-opcode timing in v1 (overhead would defeat the pitch). Opcode sampling — yes, in v2.

See [Roadmap](/guide/roadmap) for the timeline on the v1.1 / v2 items above.
