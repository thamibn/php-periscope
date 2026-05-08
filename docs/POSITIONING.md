# Positioning — Why periscope, and how it differs from Xdebug, Telescope, DebugBar

This is the messaging foundation for the README, the docs site, the launch blog post, and any conference/podcast talking points. Treat it as the canonical pitch.

---

## One-liner

> **Xdebug + Telescope + DebugBar merged into one live UI, plus time-travel.**
> Set a breakpoint, see your variables *and* every query / log / job / cache hit / HTTP call so far in the same screen, and scrub backward through time to see what state looked like 200ms ago. One install, one tab, no waiting for the request to finish.

---

## The three-tool problem

Today, a Laravel/PHP developer who wants to understand a request uses **three separate tools** — each with a different UI, different lifecycle, and a gap the others fill:

| Tool | What it does | Limitation |
|---|---|---|
| **Xdebug** | Step debugging, variables, breakpoints | No observability; no time-travel; setup is painful |
| **Telescope** | Queries, logs, jobs, events, cache, mail, requests | Post-mortem only — request must finish first |
| **DebugBar** | Same data, live | Footer-only UI; no breakpoints; no time-travel |

php-periscope merges all three into one live, interactive UI built on a modern stack (Zend Observer API, Rust, DAP, browser-native UI) with no legacy DBGp, no IDE lock-in, and time-travel as a first-class feature.

---

## Three things Xdebug literally cannot do

### 1. Time travel (step backward)

Xdebug only steps forward. Step over a function and realise "wait, I needed to inspect that"? Your only option is to restart the request — re-create state, re-click through the app, re-run expensive queries.

Periscope records every frame. `stepBack` in your IDE rewinds the *view* of the trace. The trace is on disk; we replay it.

### 2. Live observability while paused

The single highest-value differentiator. Concrete pain point: *"This listings page is slow, why?"*

**Today, with Xdebug + Telescope:**
1. Set breakpoint on `ListingController@index`.
2. Hit page → IDE pauses.
3. Inspect `$listings` — looks fine.
4. Continue → request finishes.
5. Open Telescope in another tab.
6. Find your request, click into queries.
7. See 47 queries.
8. Switch back to IDE. Re-run with new breakpoints to find the loop.
9. Step through, eyeball the bindings, guess.

**With periscope:**
1. Set breakpoint on `ListingController@index`.
2. Hit page → IDE pauses, browser tab pops open at `localhost:9999`.
3. See `$listings` in the IDE *and* see all 47 queries in the browser, with an N+1 warning pointing at `ListingResource.php:42`.
4. Drag the timeline scrubber back to query #3 — see the variables at that exact point.
5. Done. ~30 seconds.

### 3. Multi-IDE support without bridge plugins

Xdebug speaks DBGp — a 20-year-old protocol. To use Xdebug in VSCode, Neovim, Zed, Helix, or Sublime you need a per-IDE bridge plugin. Some IDEs don't support it at all.

Periscope speaks **DAP** — the protocol VSCode, Neovim, Zed, Sublime, Helix, and PhpStorm (via DAP plugin) already speak natively. Zero bridges.

---

## Pain points we're solving (verbatim, from real Xdebug users)

| Pain | Today | With periscope |
|---|---|---|
| "Setup is hours of fiddling with `xdebug.client_host`, port forwarding in Docker, IDE config" | Real | One `brew install` + Composer require |
| "I have to choose between debugging and profiling — can't run both at once" | Real (modes are mutually exclusive) | One mode does both |
| "Xdebug slows production-like envs to a crawl when loaded" | 4.15× even in inactive `develop` mode (measured); 200× in trace mode | **3.3× faster** when loaded inactive (measured 1.27× vs Xdebug 4.15×); **4.1× faster** in full trace mode |
| "Telescope shows me queries but only after the request finishes" | Real | Live during pause |
| "DebugBar is fine but I can't set breakpoints in it" | Real | Same UI handles both |
| "I missed the variable I needed; have to re-run the whole flow" | Real | Step back, never re-run |
| "Xdebug works on my Macbook but breaks in CI / Docker / WSL" | Real (port mapping nightmares) | Unix socket + DAP, no port wiring |
| "Three separate tools, three different UIs, three mental models" | Real | One unified UI |

---

## Head-to-head benchmark (PHP 8.3.22, macOS arm64, fib(25) ≈ 242k recursive calls)

Same machine, same script, same warmup. Run the bench yourself with `bash scripts/bench-vs-xdebug.sh` (after `make extension` and `pecl install xdebug`).

| Tool | Mode | fib(25) time | × baseline |
|---|---|---|---|
| (none) | baseline | 6.70ms | 1.00× |
| **periscope** | kill switch (loaded, disabled) | **8.50ms** | **1.27×** |
| **periscope** | namespace filter (no match) | **8.21ms** | **1.23×** |
| **periscope** | full capture (vars + types + timings every call) | **323ms** | **48.3×** |
| xdebug 3.5.1 | `mode=off` | 6.64ms | 0.99× |
| xdebug 3.5.1 | `mode=develop` (loaded, inactive) | 27.8ms | 4.15× |
| xdebug 3.5.1 | `mode=trace` (call records, no vars) | 1326ms | 198× |
| xdebug 3.5.1 | `mode=profile` (callgrind, no vars) | 152ms | 22.7× |

**Read the table this way:**

- **Inactive overhead**: periscope is **3.3× faster than Xdebug** when both are loaded but not actively recording. (1.27× vs 4.15×.)
- **Full capture overhead**: periscope is **4.1× faster than Xdebug trace mode** *and* periscope captures full typed variable snapshots — Xdebug trace mode only writes call records with no variable data.
- The only category where Xdebug is faster (`mode=profile`, 152ms) does no variable capture at all — it's a different feature (callgrind output for KCacheGrind), not directly comparable.

Phase 4 (binary Cap'n Proto trace replacing stderr text formatting) is expected to cut full-capture overhead another 5–10×.

## Where we *don't* differentiate (honesty section)

If we're going to ship this, we shouldn't oversell. We are NOT meaningfully better when:

- You just want to set a breakpoint and inspect a local variable. Xdebug is fine. We're a tiny bit smoother but not transformative.
- You just want to see queries after the fact. Telescope is fine. We're more or less equivalent in post-mortem mode.
- You're working on a non-PHP project. (Obviously.)
- You're in production and need sampling-based tracing — that's a v2 feature.

The differentiation is the **combination**: paused + observability + timeline. That's where the three-tool workflow collapses to one.

---

## What works on every PHP project (framework-agnostic)

The C extension hooks at the Zend engine level — the engine doesn't care what framework you're running. Same `.so`, same observation, on:

- Laravel ✓
- Symfony ✓
- CodeIgniter (3 + 4) ✓
- WordPress ✓
- Drupal ✓
- Magento ✓
- Plain PHP / no framework ✓

What you get on every PHP project from Phase 2 onwards:

- Every function / method / closure call entered + exited
- All argument values + declared types + parameter names
- Return values + declared return types
- Wall-clock timing per call
- Stack depth
- (Phase 3+) Full variable snapshots, scrubbable through time

## Where the framework adapter adds value

The Laravel-specific Composer adapter (`thamibn/periscope-laravel`) layers richer events on top:

- "this was a `DB::select` query" with bindings
- "this was a `Log::info` line" with channel + context
- "this was a queued job" with payload
- N+1 detection on Eloquent
- Cache hit/miss with key
- Outbound HTTP via Laravel's HTTP client

Symfony / WordPress / CodeIgniter equivalents follow the same opt-in adapter pattern (post-v1 work).

## Adaptive UI

The browser UI shows panels only for event types present in the current trace. Plain PHP project? You get Source / Variables / Stack / Timeline / Logs — that's it. No empty Eloquent panel cluttering the screen. Laravel adapter installed? Queries, jobs, events, cache, Redis, HTTP, Mail panels light up automatically.

The trace is the source of truth. Panels are derived. Same UI, every project, never cluttered.

---

## When the pitch becomes real

This document is a forward-looking promise. It is concretely true at each phase:

| Phase | Pitch element delivered |
|---|---|
| 1 — Hello extension | None (build chain only) |
| 2 — Observer hooks | "Every function call observed, framework-agnostic" |
| 3 — Variable capture | "Variable snapshots at every frame" |
| 4 — Trace format | "On-disk trace, scrub-able later" |
| 5 — Laravel adapter | "Live SQL/log/cache/jobs while paused" |
| 6 — DAP daemon | "VSCode/Neovim/Zed/PhpStorm breakpoints" |
| 7 — Replay engine | "Step backward through time" (CLI / API) |
| 8 — DAP `stepBack` wired | **Time-travel in your IDE** |
| 9 — Browser UI | **The unified screen the pitch promises** |
| 10 — Real-world tests | "Works on big real codebases without crashing" |
| 11 — Distribution | "One brew install" |

After Phase 9, all the differentiated claims in this doc are concretely demoable. Before Phase 9, we're an Xdebug clone with extra steps. Set expectations accordingly when talking to early users.
