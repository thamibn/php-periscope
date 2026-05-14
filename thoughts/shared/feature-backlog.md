# Feature backlog — what the DAP-foundation unlocks

Captured 2026-05-14 after shipping our own DAP-client plugins for both VSCode and PhpStorm (`vscode-extension/` + `jetbrains-plugin/`). With both clients in our control, the feature surface opens up substantially. This is a brainstorm to come back to — **not** a commitment to ship any of it.

Roadmap currently lives at [`docs/ROADMAP.md`](../../docs/ROADMAP.md). When any item below moves from "idea" to "committed", it lands in the v0.X / v1.X / v2 bucket there.

## 1. Quick wins — v0.2 candidates

Things our existing observer + daemon could do today with small surface-area changes.

### Conditional breakpoints
The daemon advertises `supportsConditionalBreakpoints: false`. Both plugins already send the `condition` field. Implementation: evaluate the condition expression at the breakpoint's frame, only emit the `stopped` event if true.

### Logpoints
Printf-style "log this, don't pause" breakpoints. Drop a log line at the breakpoint's spot without context-switching out of editing. Both DAP clients already understand `logMessage` in `SourceBreakpoint`.

### Hit-count breakpoints
"Only pause on the 5th hit of this line." Daemon needs a counter per breakpoint; DAP already has `hitCondition`.

### Exception breakpoints
Toggle in the Breakpoints view: pause on caught / uncaught PHP exceptions. We already capture exceptions via `ExceptionHook`; just need to map them to DAP `exception` stopped-reasons.

### Watch expressions auto-evaluated at every step
JetBrains' XDebugger does this automatically once we wire `evaluate` into watch refresh.

## 2. Periscope-specific killer features — v0.3 / v1.1

These don't exist in Xdebug or any other PHP debugger. Each leverages our observability layer in a way no other tool can.

### "Pause on slow query"
Set a threshold (e.g. 200ms). Debugger auto-pauses at the next SQL row exceeding it. Uses our existing slow-query analyser; just needs a DAP "auto-set-breakpoint-at-event" mechanism.

### "Pause on N+1 detection"
Same shape, leveraging the N+1 detector. Daemon triggers a synthetic `stopped` event the moment the analyser flags an N+1 group.

### "Pause on exception with AI insight pre-loaded"
On any caught/uncaught exception, debugger pauses AND the AI advisor pre-runs in the background — the "Variables" panel shows the suggested fix alongside the stack frame.

### Run/Debug-from-trace-event
Right-click an N+1 warning in the SolidJS Insights panel → "Debug the request that caused this" → IDE opens the `.cptrace`, jumps to the offending frame, pauses. One-click problem-to-debugger.

### Branch-aware time travel
For requests that took a feature-flag path: show "would-have-happened" branches the request could have taken if the flag flipped. Requires speculative replay — exploratory.

## 3. IDE-integration polish — v1.2 / v1.3

### Inline variable values in editor
The PhpStorm "Inline Debugger" feature shows `$user = ['id' => 42]` next to each line. We have richer data — *every* variable at *every* frame the trace touched. Way more annotations than Xdebug can offer.

### Gutter actions on .php files (PhpStorm + VSCode)
- "Open trace at this line"
- "Find all traces that hit this line"
- "Profile this method's average duration across the last N traces"
- "Set Periscope breakpoint here"

### JCEF tool window in PhpStorm
Embed the SolidJS UI inside PhpStorm via Chromium-embedded. No browser tab — full periscope UI lives in the IDE.

### Code lens / inlay hints
"Avg execution: 14ms across 1,247 traces" above each method. Static analysis + trace stats fused into the editor.

### Compare-to-baseline
Right-click in the gutter → "Compare this trace to the latest passing trace of this route" → diff view highlights what changed.

## 4. AI-augmented — v1.3 / v2

### "Why is `$x` null?"
Right-click any variable in the Variables panel → AI walks backward through the trace finding the assignment, explains why the value is what it is.

### AI-driven breakpoint suggestion
Paste a bug report into a prompt → AI sets breakpoints in places likely to catch the bug, opens the most recent trace, points the user at the right frame.

### Replay-to-failing-test
Turn a `.cptrace` into a failing Pest test case automatically. AI generates the fixtures + assertions from the recorded inputs/outputs.

### Auto-bisect slow regressions
Feed N traces to AI: "this route went from 80ms to 280ms last week. Which middleware/job/migration caused it?" — AI narrows it down using trace timestamps and call-graph diffs.

## 5. Production debugging — v2 flagship

The big enterprise feature. Distinct project size; needs its own design doc.

### Sampling mode
Capture 1-in-N production requests (or 1-in-N for a specific route). Store the same `.cptrace` format. Devs debug them locally as if they were dev traces.

### Snapshot-on-error
In production, periscope is dormant — until any exception fires. Then it dumps a `.cptrace` of the last N seconds of state. Bug reports come with a full debugger replay attached.

### Cross-process tracing
Follow a request from web → queue worker → web again. Each process writes its own `.cptrace`; daemon stitches them on read.

### OpenTelemetry export
Events as OTel spans, integrate with Datadog/Grafana/Honeycomb. We're not trying to *be* an APM — but emitting OTel makes us composable with one.

## 6. Headline features that could justify a premium tier — much later

### Live collaborative debugging
Share a paused session URL with a colleague. They see the same scrubbed state. Pair-debug a tricky bug across geography. (Conflicts with "no SaaS" v1 stance — needs design call.)

### Trace galleries
QA captures a `.cptrace` while reproducing a bug, attaches it to the GitHub issue. Devs click the link → debugger boots with the bug already paused at the broken line.

### "Periscope Replay" — hosted trace sharing
Self-host or SaaS-hosted gallery of shared traces. Would be the equivalent of Sentry's session replay, but for backend debugging. (Explicitly out of v1 scope; revisit if community asks.)

## 7. Smaller polish items collected along the way

- **Run-to-Position** — currently falls back to Resume; should set a temporary breakpoint and continue.
- **Set variable** value during a paused session (DAP `setVariable`). Daemon doesn't support mutation in v1, but the read-only equivalent ("Edit Value" disabled UI) is honest.
- **Source-map style remapping** for Laravel apps inside Sail/Docker (path translations).
- **Smart step into** for `array_map`-style callbacks where you'd want to skip the framework wrapper.

## 8. Competitive analysis — features NO competitor has

Field survey 2026-05-14 of the four major Laravel observability/debug tools, prioritising features that only periscope's architecture can deliver. Sources: each tool's GitHub README + docs.

### What each competitor offers today

| Feature | Telescope | Clockwork | Nightwatch | DebugBar | **periscope** |
|---|---|---|---|---|---|
| SQL queries | ✓ post-mortem | ✓ timeline | ✓ slow only | ✓ inline | ✓ live + paused |
| Logs / cache / jobs / events | ✓ | ✓ | partial | partial | ✓ |
| Mail / notifications / redis | ✓ | ✓ | — | — | ✓ |
| Request / response envelope | ✓ | ✓ | ✓ | ✓ | ✓ |
| Performance timeline | — | ✓ | ✓ | — | ✓ (flame graph) |
| Production sampling | — | — | ✓ (paid) | — | v2 |
| Self-hosted (no SaaS) | ✓ | ✓ | ✗ (hosted) | ✓ | ✓ |
| **Breakpoints + step debug** | ✗ | ✗ | ✗ | ✗ | **✓** |
| **Time-travel scrubbing** | ✗ | ✗ | ✗ | ✗ | **✓** |
| **AI advisor (in-trace)** | ✗ | ✗ | partial alerts | ✗ | **✓** |
| **MCP server (AI-native)** | ✗ | ✗ | ✗ | ✗ | **✓** |
| **JSON-path event filter** | ✗ | ✗ | ✗ | ✗ | **✓** |
| **Standalone HTML export** | ✗ | ✗ | ✗ | ✗ | **✓** |
| **Frame-level overhead** | n/a (post-mortem) | < 1.05× | n/a (sampled) | < 1.1× | < 1.05× / < 5× active |

Periscope today is the only PHP tool that **merges** Xdebug's debugging surface with Telescope's observability surface.

### What competitors *don't* have that we should add

These are the headline features that would put periscope decisively ahead in the "one roof" pitch.

#### A. Debugger-driven observability (zero competition)

1. **Set a breakpoint by SQL pattern, not just by line**
   "Pause when any query touches `users` with `WHERE id = ?`." Driven by our observer, not by code-line breakpoints. *No competitor has this — they don't have breakpoints at all.*

2. **Pause on N+1 detection in real time**
   The N+1 analyser fires → periscope auto-pauses the request. The dev steps from the offending query backward to the model loop. Telescope shows N+1 after the fact; we'd be the only one to pause as it happens.

3. **Pause on slow-query threshold**
   `periscope.pause_if_query_ms > 200` — debugger auto-pauses at the next slow row. Combines APM-style thresholds with breakpoint debugging.

4. **Pause when a specific user / session / IP / header matches**
   "Filter to logged-in users in `agencies` tenant, then pause." Production-friendly later (with sampling).

5. **Pause on exception, with the AI fix-suggestion preloaded**
   Debugger pauses, Variables panel shows the AI advisor's suggested fix in a sidecar — saves the "copy-stack-trace-into-Claude" round trip.

#### B. Time-travel features no APM has

6. **Click a SQL row → jump the debugger to the moment it executed**
   Telescope shows queries in a list, periscope's would be a hyperlink that opens the debugger at that microsecond. The same for jobs / events / log lines.

7. **Right-click a variable → "Why is this value?"**
   AI walks backward through the trace and pinpoints the assignment, with the calling line as a clickable annotation.

8. **Inline editor annotations** (PhpStorm + VSCode)
   Every variable in the editor shows its captured value `$user = ['id'=>42, 'name'=>'Thami']` from the most recent trace. Datadog has a limited "inline values" feature for Python — nobody has it for PHP.

9. **Diff two traces side-by-side**
   "What changed between the passing route and the failing route?" Visual diff of queries / events / variables. Telescope can't compare; Clockwork can't either.

#### C. AI-augmented features (genuinely new ground)

10. **Bisect a slow regression with AI**
    Drop the two `.cptrace` files (fast + slow) on the daemon → AI returns "the difference is the new `App\Listeners\AuditTrail::handle` that runs 8 extra queries." Reduces a multi-hour search to a one-shot prompt.

11. **Replay-to-failing-Pest-test**
    `php artisan periscope:trace-to-test <id>` → emits a Pest test file with HTTP fixtures + assertions matching the recorded behaviour. Bug reports become reproducible test cases automatically.

12. **AI-suggested breakpoint placement from a bug report**
    Paste a bug description into a prompt → AI sets breakpoints in likely places, opens the most recent trace, points to the suspect frame.

13. **AI panel: "Suggest a refactor for this trace"**
    AI sees the full request + queries + N+1 + slow-query analysis and proposes architectural fixes (split this controller, cache this query, debounce this listener).

14. **MCP tools that are operations, not just reads**
    Currently MCP exposes 8 read-only tools. Add `propose_fix(trace_id)`, `bisect(trace_a, trace_b)`, `simulate_with_query(query, trace_id)` — agentic, not just observational. *Cursor / Claude / Codex with these tools can debug a Laravel app without a human.*

#### D. Collaboration / shipping features

15. **`.cptrace` attachment as a first-class bug-report artefact**
    GitHub Action: comment `/periscope-trace bug.cptrace` on an issue, the bot uploads it to the team's storage and posts a viewer link. QA → eng → fix without a single "can you reproduce" round.

16. **Live collaborative paused session**
    Two devs on the same `.cptrace`, scrubbing in sync. WebSocket fanout already exists for browser-tab sync (Phase 8); extend to authenticated remote teammates.

17. **PR-bound trace galleries**
    On each PR, CI runs the test suite under periscope, attaches the failing-test traces to the PR comment. Reviewers click → see the request that failed without leaving GitHub.

#### E. Production features (v2 — flagship, no Laravel competitor has all three)

Nightwatch ships hosted prod sampling. Telescope/Clockwork don't ship prod. None offer:

18. **Self-hosted sampled production capture** — same `.cptrace` format, 1-in-N sampling, debug locally
19. **Snapshot-on-error in prod** — last N seconds of state, dumped to `.cptrace` only on exception
20. **Cross-process trace stitching** — request → queue worker → request, one trace

#### F. Operations / DX wins

21. **`periscope diff <trace-a> <trace-b>`** CLI — same as the UI diff, scriptable
22. **`periscope grep <pattern> <trace>`** — JSON-path query against the trace bytes
23. **`periscope record --filter "App\\Modules\\Billing\\*"` CLI** — capture only specific paths
24. **VS Code / PhpStorm gutter inlay "Run with periscope"** — single click, no Run-config setup
25. **Periscope-as-a-language-server** — emit diagnostics like "this query was slow in 3 of the last 10 traces" via LSP

### What we'd NOT chase

These belong to other categories — leaving on the table is intentional:

- **APM-grade alerting and SLO dashboards** (Datadog, Nightwatch territory)
- **Error tracking with grouping + assignment** (Sentry, Bugsnag)
- **Hosted log aggregation** (Loki, CloudWatch)
- **Frontend session replay** (PostHog, FullStory)
- **CSS / JS debugger** (Chrome DevTools owns this; we're PHP-only)

### Headline positioning that emerges from this list

> *"Telescope shows you what happened. Clockwork shows you when. Nightwatch warns you it's broken. periscope **pauses time and lets you walk through it backward — with AI on your shoulder.**"*

That's the elevator pitch the feature gaps above enable.

## Sequencing principles

When deciding what to pull from this list:

1. **Does it serve the four product pillars** — fast, useful, easy-to-set-up, easy-to-use?
2. **Does it leverage what only periscope can do** (the observer-driven richer data)? Anything an Xdebug plugin could do should rank lower.
3. **Does shipping it require new daemon code, new plugin code, or both?** Single-side-only items are cheaper to ship.
4. **Does it unlock a premium-tier story?** Some of these (collab debugging, prod sampling) are differentiators worth pricing separately, eventually.
