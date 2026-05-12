import { createMemo, createResource, createSignal } from "solid-js";
import type { ReconstructedState, TraceSummaryRow } from "./types";
import { api } from "./api";

export const [tracesRefreshKey, setTracesRefreshKey] = createSignal(0);
export const [selectedTraceId, setSelectedTraceId] = createSignal<string | null>(null);
export const [activeTab, setActiveTab] = createSignal<TabId>("overview");
export const [cursorMicros, setCursorMicros] = createSignal(0);
export const [filter, setFilter] = createSignal("");

export type TabId =
  | "overview"
  | "source"
  | "queries"
  | "models"
  | "logs"
  | "cache"
  | "jobs"
  | "events"
  | "http"
  | "redis"
  | "mail"
  | "notifications"
  | "exceptions"
  | "dumps"
  | "insights"
  | "performance"
  | "request"
  | "response";

export const TABS: { id: TabId; label: string }[] = [
  { id: "overview", label: "Overview" },
  { id: "source", label: "Source / Scope" },
  { id: "queries", label: "Queries" },
  { id: "models", label: "Models" },
  { id: "logs", label: "Logs" },
  { id: "cache", label: "Cache" },
  { id: "jobs", label: "Jobs" },
  { id: "events", label: "Events" },
  { id: "http", label: "HTTP" },
  { id: "redis", label: "Redis" },
  { id: "mail", label: "Mail" },
  { id: "notifications", label: "Notifications" },
  { id: "exceptions", label: "Exceptions" },
  { id: "dumps", label: "Dumps" },
  { id: "insights", label: "Insights" },
  { id: "performance", label: "Performance" },
  { id: "request", label: "Request" },
  { id: "response", label: "Response" },
];

export const [traces] = createResource<TraceSummaryRow[], number>(
  tracesRefreshKey,
  async () => {
    try {
      return await api.listTraces();
    } catch {
      return [];
    }
  },
  { initialValue: [] },
);

export const [trace] = createResource(selectedTraceId, async (id) => {
  if (!id) return null;
  return api.getTrace(id);
});

export const [timeline] = createResource(selectedTraceId, async (id) => {
  if (!id) return [];
  return api.getTimeline(id);
});

export const [insights] = createResource(selectedTraceId, async (id) => {
  if (!id) return null;
  return api.getInsights(id);
});

export const [summary] = createResource(selectedTraceId, async (id) => {
  if (!id) return null;
  return api.getSummary(id);
});

interface StateKey {
  id: string;
  at: number;
}
export const [state] = createResource<ReconstructedState | null, StateKey>(
  () => {
    const id = selectedTraceId();
    if (!id) return undefined;
    return { id, at: cursorMicros() };
  },
  async (key) => {
    if (!key) return null;
    return api.getState(key.id, key.at);
  },
);

export const eventsAtCursor = createMemo(() => {
  const t = trace();
  const at = cursorMicros();
  if (!t) return [];
  return t.observability_events.filter((e) => e.at_micros <= at);
});

export const eventsByType = createMemo(() => {
  const events = eventsAtCursor();
  const grouped = new Map<string, typeof events>();
  for (const e of events) {
    const cur = grouped.get(e.type) ?? [];
    cur.push(e);
    grouped.set(e.type, cur);
  }
  return grouped;
});

// No-op: previously auto-selected the newest trace. Now the UI starts on
// the Traces landing page and waits for the user to click a trace. Kept
// as a function for callers that may still reference it.
export function bootstrapSelection(): void {
  /* intentionally empty — landing page handles selection */
}

// Park the cursor at the end of the current trace whenever a different trace
// loads. Without this, switching traces leaves the cursor at the previous
// trace's position — so a trace whose events live past that timestamp shows
// up empty in every panel.
let lastBootstrappedTrace: string | null = null;
export function bootstrapCursor(): void {
  const t = trace();
  if (!t) return;
  if (lastBootstrappedTrace !== t.id) {
    lastBootstrappedTrace = t.id;
    setCursorMicros(t.meta.duration_micros);
  }
}
