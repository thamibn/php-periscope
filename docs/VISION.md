# Vision

**See into your PHP request.**

php-periscope is a live observability + time-travel debugger for PHP and Laravel. Pause any request. See every variable, every SQL query, every log line, every dispatched job, every fired event, every cache hit, every Redis command, every outbound HTTP call — in one browser UI. Scrub backward through time to see what the world looked like at any earlier point in the request.

## Why this exists

The PHP debugging world today is split across three tools that don't talk to each other:

- **Xdebug** gives you breakpoints and step-through, but no observability. You see `$user` is null, but not why.
- **Telescope** shows you queries and logs, but only after the request finishes. Post-mortem only.
- **DebugBar** shows live data, but in a tiny footer with no breakpoints and no time-travel.

If you want all three, you're juggling three tools — and none of them lets you scrub backward in time.

php-periscope merges all three. Set a breakpoint. Hit it. See *everything* that happened up to that line, in one UI.

## What's different

| | Xdebug | Telescope | DebugBar | **php-periscope** |
|---|---|---|---|---|
| Breakpoints | ✅ | ❌ | ❌ | ✅ |
| Variables / call stack | ✅ | ❌ | ❌ | ✅ |
| Live observability (queries, logs, jobs, events, cache, Redis, HTTP) | ❌ | ✅ post-mortem | ✅ live | ✅ live, paused |
| Time-travel (step backward) | ❌ | ❌ | ❌ | ✅ |
| Browser-native UI (no IDE required) | ❌ | ✅ | ❌ | ✅ |
| Multi-IDE (VSCode, Neovim, Zed, JetBrains) | partial | n/a | n/a | ✅ via DAP |
| Setup complexity | high | medium | low | one command |

## Non-goals (v1)

- Production debugging (deferred to v2)
- Async / Swoole / Frankenphp / Octane
- Windows
- PHP < 8.3 or > 8.3

See `SCOPE.md` for the full list of what is and isn't in v1.

## North-star demo

A developer:

1. `brew install thamibn/php-periscope/php-periscope`
2. Opens VSCode in their Laravel project
3. Sets a breakpoint
4. Hits the route in their browser
5. Sees VSCode pause **and** their browser open `localhost:9999` showing source, variables, queries (with N+1 warning), logs, jobs, events, cache — all live, all from this single request, all stitched together
6. Drags a timeline scrubber backward — the whole UI rewinds to show earlier state
7. Continues — request finishes normally

That's the whole pitch.
