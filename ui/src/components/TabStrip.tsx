import { For, Show } from "solid-js";
import { TABS, activeTab, eventsByType, setActiveTab, summary } from "../lib/store";
import type { TabId } from "../lib/store";

export function TabStrip() {
  const counts = () => {
    const grouped = eventsByType();
    const s = summary();
    const c: Partial<Record<TabId, number>> = {
      queries: s?.queries.count ?? grouped.get("sql")?.length ?? 0,
      models: s?.models.hydrated_count ?? grouped.get("model")?.length ?? 0,
      logs: s?.logs.count ?? grouped.get("log")?.length ?? 0,
      cache: (s?.cache.hits ?? 0) + (s?.cache.misses ?? 0) + (s?.cache.writes ?? 0)
        || grouped.get("cache")?.length || 0,
      jobs: s?.jobs.count ?? grouped.get("job")?.length ?? 0,
      events: s?.events.count ?? grouped.get("event")?.length ?? 0,
      http: s?.http.count ?? grouped.get("http")?.length ?? 0,
      redis: grouped.get("redis")?.length ?? 0,
      mail: s?.mail.count ?? grouped.get("mail")?.length ?? 0,
      notifications: s?.notifications.count ?? grouped.get("notification")?.length ?? 0,
      exceptions: s?.exceptions.count ?? grouped.get("exception")?.length ?? 0,
    };
    return c;
  };

  return (
    <nav class="sticky top-[57px] z-20 flex items-center gap-1 px-3 py-2 border-b border-ink-700/60 bg-ink-950/80 backdrop-blur overflow-x-auto scroll-thin">
      <For each={TABS}>
        {(t) => {
          const count = () => counts()[t.id];
          const tone = () =>
            t.id === "exceptions" && (count() ?? 0) > 0
              ? "text-danger"
              : t.id === "queries" && (summary()?.queries.slow_count ?? 0) > 0
                ? "text-warn"
                : "text-ink-400";
          return (
            <button
              type="button"
              class={`tab ${activeTab() === t.id ? "tab-active" : ""}`}
              onClick={() => setActiveTab(t.id)}
            >
              {t.label}
              <Show when={count() !== undefined && count()! > 0}>
                <span class={`ml-1 text-[10px] mono ${tone()}`}>{count()}</span>
              </Show>
            </button>
          );
        }}
      </For>
    </nav>
  );
}
