import { For, Show, createResource } from "solid-js";
import {
  TABS,
  activeTab,
  eventsByType,
  setActiveTab,
  setSelectedTraceId,
  setTracesRefreshKey,
  summary,
  traces,
  tracesRefreshKey,
} from "../lib/store";
import type { TabId } from "../lib/store";
import { fmtBytes } from "../lib/format";
import { api, isStaticMode } from "../lib/api";
import { health } from "../lib/health";

/**
 * Vertical navigation, three sections, then storage controls:
 *
 *   ─────────────
 *    DASHBOARD       ← section heading (uppercase, tracked, dim)
 *      Overview
 *      Insights
 *      Performance
 *
 *    ACTIVITY
 *      Queries  ............... 12
 *      Models  ................. 6
 *      ...
 *
 *    INSPECT
 *      Request
 *      Response
 *      Source / Scope
 *   ─────────────
 *    STORAGE         ← refresh / clear-all actions
 *
 * The trace list lives in the Overview panel, not here — switching trace
 * is part of the dashboard, not the navigation chrome.
 */
const NAV_SECTIONS: { title: string; items: { id: TabId; label: string }[] }[] = [
  {
    title: "Dashboard",
    items: [
      { id: "overview",    label: "Overview" },
      { id: "insights",    label: "Insights" },
      { id: "performance", label: "Performance" },
    ],
  },
  {
    title: "Activity",
    items: [
      { id: "queries",       label: "Queries" },
      { id: "models",        label: "Models" },
      { id: "cache",         label: "Cache" },
      { id: "logs",          label: "Logs" },
      { id: "jobs",          label: "Jobs" },
      { id: "events",        label: "Events" },
      { id: "http",          label: "HTTP" },
      { id: "redis",         label: "Redis" },
      { id: "mail",          label: "Mail" },
      { id: "notifications", label: "Notifications" },
      { id: "exceptions",    label: "Exceptions" },
      { id: "dumps",         label: "Dumps" },
    ],
  },
  {
    title: "Inspect",
    items: [
      { id: "request",  label: "Request" },
      { id: "response", label: "Response" },
      { id: "source",   label: "Source / Scope" },
    ],
  },
];

// Keep this in sync with `TABS` so a dropped section doesn't silently disappear.
void TABS;

export function Sidebar() {
  const counts = () => {
    const grouped = eventsByType();
    const s = summary();
    const c: Partial<Record<TabId, number>> = {
      queries:        s?.queries.count               ?? grouped.get("sql")?.length          ?? 0,
      models:         s?.models.hydrated_count       ?? grouped.get("model")?.length        ?? 0,
      logs:           s?.logs.count                  ?? grouped.get("log")?.length          ?? 0,
      cache:          ((s?.cache.hits ?? 0) + (s?.cache.misses ?? 0) + (s?.cache.writes ?? 0))
                      || grouped.get("cache")?.length || 0,
      jobs:           s?.jobs.count                  ?? grouped.get("job")?.length          ?? 0,
      events:         s?.events.count                ?? grouped.get("event")?.length        ?? 0,
      http:           s?.http.count                  ?? grouped.get("http")?.length         ?? 0,
      redis:          grouped.get("redis")?.length   ?? 0,
      mail:           s?.mail.count                  ?? grouped.get("mail")?.length         ?? 0,
      notifications:  s?.notifications.count         ?? grouped.get("notification")?.length ?? 0,
      exceptions:     s?.exceptions.count            ?? grouped.get("exception")?.length    ?? 0,
      dumps:          grouped.get("dump")?.length    ?? 0,
    };
    return c;
  };

  // Daemon-truth storage stats. Refetches whenever the trace list changes
  // (delete / clear-all / new request_finished bump the refresh key).
  const [storage, { refetch: refetchStorage }] = createResource(
    () => (isStaticMode() ? null : tracesRefreshKey()),
    async () => {
      if (isStaticMode()) return null;
      try {
        return await api.getStorage();
      } catch {
        return null;
      }
    },
  );

  // Prefer daemon-reported totals; fall back to the in-memory list when the
  // daemon hasn't responded yet (first render).
  const totalCount = () => storage()?.trace_count ?? traces().length;
  const totalSize = () =>
    storage()?.total_bytes ?? traces().reduce((acc, t) => acc + t.size_bytes, 0);
  const traceDir = () => storage()?.trace_dir ?? health()?.trace_dir;

  const onReveal = async () => {
    try {
      await api.revealStorage();
    } catch {
      /* swallow — the OS will silently no-op if `open` isn't available */
    }
  };

  const onClearAll = async () => {
    const list = traces();
    if (
      list.length >= 10
      || list.reduce((s, t) => s + t.size_bytes, 0) >= 10 * 1024 * 1024
    ) {
      if (!confirm(`Delete all ${list.length} traces (${fmtBytes(totalSize())})? This cannot be undone.`)) return;
    } else if (!confirm(`Delete all ${list.length} traces?`)) {
      return;
    }
    await api.clearTraces();
    setSelectedTraceId(null);
    setTracesRefreshKey((k) => k + 1);
    void refetchStorage();
  };

  return (
    <aside class="flex flex-col gap-4 sticky top-[57px] self-start max-h-[calc(100vh-57px-5rem)] overflow-y-auto scroll-thin pb-2">
      <nav class="space-y-4">
        <For each={NAV_SECTIONS}>
          {(section) => (
            <section>
              <h3 class="px-2 text-[10px] tracking-[0.18em] text-ink-500 uppercase mono mb-1.5">
                {section.title}
              </h3>
              <ul class="space-y-0.5">
                <For each={section.items}>
                  {(item) => {
                    const c = () => counts()[item.id];
                    const tone = () =>
                      item.id === "exceptions" && (c() ?? 0) > 0
                        ? "text-rose-300"
                        : item.id === "queries" && (summary()?.queries.slow_count ?? 0) > 0
                          ? "text-warn"
                          : "text-ink-400";
                    return (
                      <li>
                        <button
                          type="button"
                          onClick={() => setActiveTab(item.id)}
                          class={`w-full flex items-baseline gap-2 px-2 py-1 rounded text-[12.5px] transition-colors ${
                            activeTab() === item.id
                              ? "bg-accent/10 text-accent"
                              : "text-ink-200 hover:text-ink-100 hover:bg-ink-800/60"
                          }`}
                        >
                          <span class="truncate">{item.label}</span>
                          <Show when={c() !== undefined && c()! > 0}>
                            <span class="flex-1 border-b border-dotted border-ink-700/50" aria-hidden="true" />
                            <span class={`mono text-[11px] ${tone()}`}>{c()}</span>
                          </Show>
                        </button>
                      </li>
                    );
                  }}
                </For>
              </ul>
            </section>
          )}
        </For>
      </nav>

      <Show when={!isStaticMode()}>
        <section>
          <h3 class="px-2 text-[10px] tracking-[0.18em] text-ink-500 uppercase mono mb-1.5">
            Storage
          </h3>
          <div class="rounded border border-ink-700/60 px-3 py-2.5 space-y-2 text-[12px] text-ink-200">
            <div class="flex items-baseline gap-2">
              <span class="text-ink-400 normal-case">files</span>
              <span class="flex-1 border-b border-dotted border-ink-700/50" aria-hidden="true" />
              <span class="mono">{totalCount()}</span>
            </div>
            <div class="flex items-baseline gap-2">
              <span class="text-ink-400 normal-case">size</span>
              <span class="flex-1 border-b border-dotted border-ink-700/50" aria-hidden="true" />
              <span class="mono">{fmtBytes(totalSize())}</span>
            </div>
            <Show when={traceDir()}>
              <div class="flex items-baseline gap-2">
                <span class="text-ink-400 normal-case">dir</span>
                <span class="flex-1 border-b border-dotted border-ink-700/50" aria-hidden="true" />
                <button
                  type="button"
                  class="mono truncate max-w-[8rem] text-ink-200 hover:text-accent transition-colors"
                  title={`${traceDir()} — click to reveal`}
                  onClick={onReveal}
                >
                  {traceDir()}
                </button>
              </div>
            </Show>
            <div class="flex gap-2 pt-1">
              <button
                class="chip flex-1 justify-center"
                onClick={() => {
                  setTracesRefreshKey((k) => k + 1);
                  void refetchStorage();
                }}
              >
                Refresh
              </button>
              <button
                class="chip flex-1 justify-center hover:!border-danger hover:!text-danger"
                onClick={onClearAll}
              >
                Clear all
              </button>
            </div>
          </div>
        </section>
      </Show>
    </aside>
  );
}
