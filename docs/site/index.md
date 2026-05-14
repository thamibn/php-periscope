---
layout: home

hero:
  name: "php-periscope"
  text: "See into your Laravel request."
  tagline: "Xdebug-tier debugging plus Telescope-tier observability, in one live UI — with an AI co-pilot."
  actions:
    - theme: brand
      text: Get started
      link: /guide/getting-started
    - theme: alt
      text: View on GitHub
      link: https://github.com/thamibn/php-periscope

features:
  - icon: "⏪"
    title: Time-travel debugging
    details: Pause any request and scrub backward in time. Every function frame, every captured variable, every SQL query, every log line — preserved and replayable, frame by frame.
  - icon: "📡"
    title: Telescope-tier observability
    details: SQL, logs, cache, Redis, jobs, events, mail, HTTP, exceptions, model writes — captured per request, grouped, filtered, time-correlated. All in one panel.
  - icon: "🤖"
    title: AI-native API
    details: Built-in MCP server exposes every trace to Claude, Cursor, Codex, and any MCP-speaking agent. `claude mcp add periscope -- php artisan mcp:start periscope` and your AI can query traces directly.
  - icon: "⚡"
    title: Fast inactive, fast active
    details: 3.3× faster than Xdebug when inactive, 4.1× faster in trace mode. Function-boundary recording, not opcode-level — overhead stays under 5× even on hot paths.
  - icon: "🎯"
    title: Built for Laravel 13
    details: v1 supports Laravel 12 / 13. Auto-discovers your queries, jobs, events, exceptions, cache + Redis ops. Toolbar chip injects into HTML responses. UI mounts at /periscope.
  - icon: "🧰"
    title: Works where you work
    details: VSCode + DAP debugger, browser UI on localhost:9999, exportable .html traces for sharing with colleagues — no SaaS, no telemetry, your data never leaves the box.
---

## Quickstart

```bash
# one-line install (macOS + Linux)
bash <(curl -fsSL https://raw.githubusercontent.com/thamibn/php-periscope/main/scripts/install.sh)

# add to any Laravel app
composer require thamibn/laravel-periscope

# start the daemon, open the UI
periscope-daemon &
open http://127.0.0.1:9999

# wire AI agents
claude mcp add periscope -- php artisan mcp:start periscope
```

## What you get on day one

The first request you trigger after install shows up in the timeline. Click any frame, see every variable bound at that line. Click any SQL row, see the call site. Drag the timeline scrubber backward, watch the queries / logs / cache hits disappear in reverse.

If something's wrong, the **Insights** panel tells you:

- *"This query ran 12 times with the same shape — looks like an N+1 in `ListingController:show:43`."*
- *"`SELECT *` on `agencies` is over-fetching. Slowest column read at 87ms — try `select(['id','name'])`."*
- *"Exception caught at `Foo.php:128`. AI advisor: `try/catch around the empty-collection branch on line 124 silently swallows the cause. Re-throw or log.`"*

## How is it different?

| | Xdebug | Telescope | DebugBar | **periscope** |
|---|---|---|---|---|
| Breakpoints + variables | ✓ | — | — | ✓ |
| SQL / logs / cache / jobs observability | — | ✓ | partial | ✓ |
| **Time-travel scrubbing** | — | — | — | ✓ |
| **AI-native (MCP)** | — | — | — | ✓ |
| Engine-level via Observer API | — | — | — | ✓ |
| Per-request UI | — | post-mortem | live footer | live, full-screen |
| Frame-level overhead | 15–40× | — | — | < 5× |

Telescope is read-only after the fact. Xdebug needs a re-run with breakpoints. DebugBar is a footer chip. periscope is the **debugger you would have written if Xdebug, Telescope, and DebugBar were the same project.**

## Status

**v0.1.0.** Built for PHP 8.3 + 8.4 on macOS + Linux. Laravel-only on the framework side (Laravel 12 / 13); the C extension is framework-agnostic and Symfony / WordPress adapters are post-v1.

See [docs/ROADMAP.md](/guide/roadmap) for what's next.
