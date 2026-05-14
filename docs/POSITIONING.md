# Positioning — Why periscope, and how it differs from Xdebug, Telescope, DebugBar (Laravel)

This is the messaging foundation for the README, the docs site, the launch blog post, and any conference/podcast talking points. Treat it as the canonical pitch.

**v1 audience: Laravel developers.** We don't market, test, or support Symfony / WordPress / CodeIgniter / plain PHP in v1. That's a deliberate scope cut, not an oversight.

The underlying C extension happens to be framework-agnostic (that's correct engineering for a Zend Observer hook). **Other frameworks ship later, as separate Composer packages**: `periscopephp/symfony`, `periscopephp/wordpress`, `periscopephp/codeigniter`, etc. Each will follow the same pattern as the Laravel adapter — auto-discover on its host framework, hook the right events, forward to the same C extension. v1 ships only `thamibn/php-periscope-laravel`; the rest land in v1.1, v1.2, … as the community signals demand.

---

## One-liner

> **Xdebug + Telescope + DebugBar + Clockwork merged into one live UI, plus time-travel, plus an AI co-pilot — built for Laravel.**
> Set a breakpoint, see your variables *and* every Eloquent query / log / job / cache hit / HTTP call so far in the same screen, and scrub backward through time to see what state looked like 200ms ago. One install, one tab, no waiting for the request to finish.

---

## The four-tool problem (Laravel today)

Today, a Laravel developer who wants to understand a request uses **four separate tools** — each with a different UI, different lifecycle, and a gap the others fill:

| Tool | What it does | Limitation |
|---|---|---|
| **Xdebug** | Step debugging, variables, breakpoints | No observability; no time-travel; setup is painful |
| **Laravel Telescope** | Queries, logs, jobs, events, cache, mail, requests | Post-mortem only — request must finish first |
| **Laravel DebugBar** | Same data, live | Footer-only UI; no breakpoints; no time-travel |
| **Clockwork** | Same data, devtools panel | Browser-extension only; no breakpoints; no time-travel |

php-periscope merges all four into one live, interactive UI built on a modern stack (Zend Observer API, Rust, DAP, browser-native UI) with no legacy DBGp, no IDE lock-in, time-travel as a first-class feature, and an AI co-pilot that reads the trace and recommends fixes.

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

## Head-to-head benchmark (PHP 8.3.22, macOS arm64)

Same machine, same script, same warmup. Run the bench yourself with `bash scripts/bench-vs-xdebug.sh` (after `make extension`, `cargo build --bin periscope-dump`, and `pecl install xdebug`).

### Bench A — fib(25) (~242k recursive function calls, no observability events)

This is the pure function-call cost. Most representative of "what does the C extension cost on a tight CPU loop?"

| Tool | Mode | fib(25) time | × baseline |
|---|---|---|---|
| (none) | baseline | 6.48ms | 1.00× |
| **periscope** | kill switch (loaded, disabled) | **7.91ms** | **1.22×** |
| **periscope** | namespace filter (no match) | **8.08ms** | **1.25×** |
| **periscope** | full capture (vars + types + timings every call) | **257ms** | **39.7×** |
| xdebug 3.5.1 | `mode=off` | 6.65ms | 1.03× |
| xdebug 3.5.1 | `mode=develop` (loaded, inactive) | 27.7ms | 4.27× |
| xdebug 3.5.1 | `mode=trace` (call records, no vars) | 1406ms | 217× |
| xdebug 3.5.1 | `mode=profile` (callgrind, no vars) | 158ms | 24.4× |

**Headlines:**
- **Inactive overhead**: periscope is **3.5× faster than Xdebug** when both are loaded but not actively recording. (1.22× vs 4.27×.)
- **Full capture overhead**: periscope is **5.5× faster than Xdebug trace mode** — *and* periscope captures full typed variable snapshots that xdebug trace mode does not.
- xdebug `profile` is the only mode where xdebug is faster, because it does no variable capture and no call records — it emits a callgrind file for KCacheGrind. Different feature; not comparable.

### Bench B — Laravel hooks scenario (600 mixed observable events)

This is the bench that matters for v1's pitch. Boots Laravel, registers the periscope adapter, then fires **100 SQL queries + 300 cache events + 100 log lines + 100 user events** through the hook chain in a tight loop. Captures the marginal cost of `QueryHook` / `CacheHook` / `LogHook` / `EventHook` doing their job.

Xdebug has no Laravel-event observability — it only sees function calls. Numbers below are "same fixture, same loop", but xdebug only shows the function-level cost, not anything equivalent to what periscope captures.

| Tool | Mode | 600-event loop | What it captured |
|---|---|---|---|
| (none) | no extension | 5.0ms | nothing |
| **periscope** | kill switch (loaded, disabled) | **45.6ms** | nothing — bridge no-ops |
| **periscope** | full capture (every call + every event with call sites) | **172.2ms** | every SQL + bindings + timing, every cache op, every log line, every event — all with `file:line` + ±6 source snippet |
| xdebug 3.5.1 | `mode=off` | 1.2ms | nothing |
| xdebug 3.5.1 | `mode=develop` | 4.3ms | nothing observable to user |
| xdebug 3.5.1 | `mode=trace` | 436ms | function calls only — no Laravel events captured |

**Headlines:**
- **Periscope under full capture (172ms) is 2.5× faster than xdebug trace mode (436ms)** — and periscope captures the entire Laravel observability stream (SQL, cache, log, events, exceptions, n+1) which xdebug trace simply cannot see.
- This is the dimension xdebug doesn't compete on. If you want "see every query, log line, cache op, and event with the exact `file:line` of your code that triggered each" you need either Telescope (post-mortem only), DebugBar (footer-only, no breakpoints), or periscope (live, while paused).

### Phase 4+ optimizations already applied

The first POSITIONING numbers measured stderr-text emission. Phase 4 swapped that for binary Cap'n Proto, which is what the table above reflects. Further wins on the roadmap: mmap reader (Phase 7) for sub-millisecond trace open, and structured (typed) event variants for ~30% smaller traces.

## Where we *don't* differentiate (honesty section)

If we're going to ship this, we shouldn't oversell. We are NOT meaningfully better when:

- You just want to set a breakpoint and inspect a local variable. Xdebug is fine. We're a tiny bit smoother but not transformative.
- You just want to see queries after the fact. Telescope is fine. We're more or less equivalent in post-mortem mode.
- You're working on a non-PHP project. (Obviously.)
- You're in production and need sampling-based tracing — that's a v2 feature.

The differentiation is the **combination**: paused + observability + timeline. That's where the three-tool workflow collapses to one.

---

## What v1 ships (Laravel-only)

The Laravel adapter (`thamibn/php-periscope-laravel`) hooks every Laravel observability event and forwards it to the trace:

- Every Eloquent / DB query with bindings, connection, and timing
- Every `Log::info/warn/error` line with channel and context
- Every dispatched job (queued or sync) with payload
- Every fired event with payload
- Every cache hit / miss / write / forget with key + store
- Every Redis command
- Every outbound `Http::*` call with method, URL, status, duration
- Every sent `Mail::*` with recipient and mailable
- N+1 detection on Eloquent (when same SQL pattern fires N times in one frame)
- Resolved route name + controller@method + route params
- Authenticated user (`Auth::user()`)
- Session contents (with redaction)

Plus everything from the C extension (which works on any PHP code, but in v1 we only test against Laravel):

- Every function / method / closure call entered + exited
- All argument values + declared types + parameter names
- Return values + declared return types
- Wall-clock timing per call + stack depth
- Full variable snapshots (with depth/size caps), scrubbable through time

## Other frameworks (later)

Symfony, WordPress, CodeIgniter, plain PHP — each gets its own Composer package after v1. Same pattern as the Laravel adapter, same C extension underneath, same UI:

- `periscopephp/symfony` — hooks the Symfony Profiler events (Doctrine queries, mailer, security, messenger).
- `periscopephp/wordpress` — hooks `pre_get_posts`, `wp_loaded`, `$wpdb` queries, the HTTP API, REST API endpoints.
- `periscopephp/codeigniter` — hooks CI4's events, Query Builder, validation, sessions.

Timing on those depends on community demand. v1 stays focused on Laravel so we ship something coherent rather than spreading thin.

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
