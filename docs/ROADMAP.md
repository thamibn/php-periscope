# Roadmap

Calendar-style view of the 12-phase plan in `thoughts/shared/plans/2026-05-08-php-periscope-mvp.md`. Calendar weeks assume part-time AI-assisted development (~10–15 hrs/week). Compress by 3× for full-time work.

## Milestone calendar

| Milestone | Phase | Weeks (part-time) | Calendar (start 2026-05-11) |
|-----------|-------|-------------------|------------------------------|
| **M1 — Hello extension** | 1 | 1 | 2026-05-11 → 2026-05-17 |
| **M2 — Function hooks** | 2 | 1 | 2026-05-18 → 2026-05-24 |
| **M3 — Variable capture** | 3 | 3 | 2026-05-25 → 2026-06-14 |
| **M4 — Trace format** | 4 | 1 | 2026-06-15 → 2026-06-21 |
| **M5 — Laravel adapter** | 5 (Track A) | 2 | 2026-06-22 → 2026-07-05 |
| **M6 — DAP daemon** | 6 (Track B) | 2 | 2026-06-22 → 2026-07-05 *(parallel)* |
| **M7 — Replay engine** | 7 | 2 | 2026-07-06 → 2026-07-19 |
| **M8 — Time-travel wired** | 8 | 1 | 2026-07-20 → 2026-07-26 |
| **M9a — UI mockup** | 9a | 0.5 | 2026-05-25 → 2026-05-28 *(in parallel with M3)* |
| **M9b — UI real** | 9b (Track C) | 3 | 2026-07-27 → 2026-08-16 |
| **M10 — Real-world tests** | 10 | 2 | 2026-08-17 → 2026-08-30 |
| **M11 — Distribution** | 11 | 2 | 2026-08-31 → 2026-09-13 |
| **M12 — Beta launch** | 12 | 1 | 2026-09-14 → 2026-09-20 |

**MVP target ship date: 2026-09-20** (~19 weeks part-time from 2026-05-11). Slip budget: +4 weeks for the Phase 3 cliff and Phase 10 bug-fix cycle. Realistic ship window: **late September to late October 2026**.

## Risk-adjusted view

If Phase 3 (variable capture) goes well: ship on time.
If Phase 3 takes 2× expected: ship late October.
If Phase 3 reveals a fundamental Zend issue: re-evaluate. May pivot to "function-call-only debugger with no variable inspection" as a stripped-down v0.5 ship.

## Post-MVP (v2 priorities, in rough order)

1. **Production-safe debugging** — sampling, snapshot breakpoints. The killer enterprise feature.
2. **OpenTelemetry export** — debug events as spans across services.
3. **AI assist panel** — "explain this frame", "why is `$x` null?" with full request context as a prompt.
4. **PhpStorm polish** — first-class JetBrains plugin.
5. **Async runtime support** — Fibers, Swoole, Frankenphp, Octane.
6. **Variable mutation tracking** — assignment-level snapshots.
7. **Symfony N+1 detection** parity with Laravel.
8. **Cross-process tracing** — follow a request from web → queue worker → web again.

## Cadence

- Weekly: Thami's working session (3–6 hrs, evenings/weekend)
- Bi-weekly: progress review + scope re-check against this roadmap
- Monthly: external sanity check (show progress to a PHP friend, gather feedback)

## Pause points

After the following phases, **stop and confirm with Thami before continuing**:

- End of Phase 1 (build toolchain works)
- End of Phase 3 (variable capture is the highest-risk phase)
- End of Phase 9a (UI mockup — get external feedback before building real UI)
- End of Phase 10 (real-world bugs surface — decide if we have enough quality to launch)
