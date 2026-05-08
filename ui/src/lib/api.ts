import type {
  FileSlice,
  InsightsJson,
  ReconstructedState,
  SummaryJson,
  TimelineEntry,
  TraceJson,
  TraceSummaryRow,
} from "./types";

export const isStaticMode = (): boolean => typeof window !== "undefined" && !!window.PERISCOPE_TRACE;

// Where to find the daemon's HTTP API. Three sources, last wins:
//   1. same origin (when the daemon serves the UI directly at :9999)
//   2. <meta name="periscope-daemon-base" content="http://127.0.0.1:9999">
//      injected by the laravel-adapter when the UI is mounted at e.g.
//      `app.test/periscope` so XHRs cross over to the daemon.
//   3. window.PERISCOPE_DAEMON_BASE (set by the export inliner or by tests).
function detectBase(): string {
  if (typeof window === "undefined") return "";
  const explicit = (window as { PERISCOPE_DAEMON_BASE?: string }).PERISCOPE_DAEMON_BASE;
  if (explicit) return explicit.replace(/\/$/, "");
  const meta = document.querySelector('meta[name="periscope-daemon-base"]');
  const content = meta?.getAttribute("content")?.replace(/\/$/, "");
  if (content) return content;
  return "";
}
const base = detectBase();

/** Where the UI is currently sourcing trace data from. Empty string = same origin. */
export const daemonBase = (): string => base;

/** Pretty label for the header chip — shows the configured base, never a hardcoded port. */
export const daemonLabel = (): string => {
  if (typeof window === "undefined") return "";
  if (base) return base.replace(/^https?:\/\//, "");
  return window.location.host;
};

async function getJson<T>(path: string): Promise<T> {
  const res = await fetch(`${base}${path}`, { headers: { accept: "application/json" } });
  if (!res.ok) {
    const text = await res.text().catch(() => "");
    throw new Error(`${res.status} ${res.statusText}: ${text || path}`);
  }
  return (await res.json()) as T;
}

async function deleteJson<T>(path: string): Promise<T> {
  const res = await fetch(`${base}${path}`, { method: "DELETE" });
  if (!res.ok) throw new Error(`${res.status} ${res.statusText}`);
  return (await res.json()) as T;
}

export const api = {
  async listTraces(): Promise<TraceSummaryRow[]> {
    if (isStaticMode()) {
      const t = window.PERISCOPE_TRACE!.trace;
      return [
        {
          id: t.id,
          path: t.meta.entry_point,
          started_at_unix_micros: t.meta.started_at_unix_micros,
          duration_micros: t.meta.duration_micros,
          method: t.meta.request?.method ?? "",
          uri: t.meta.request?.uri ?? "",
          status_code: t.meta.response?.status_code ?? 0,
          frame_count: t.frames.length,
          event_count: t.observability_events.length,
          has_exception: t.observability_events.some((e) => e.type === "exception"),
          size_bytes: 0,
        },
      ];
    }
    return getJson<TraceSummaryRow[]>("/api/traces");
  },

  async getTrace(id: string): Promise<TraceJson> {
    if (isStaticMode()) return window.PERISCOPE_TRACE!.trace;
    return getJson<TraceJson>(`/api/traces/${id}`);
  },

  async getTimeline(id: string): Promise<TimelineEntry[]> {
    if (isStaticMode() && window.PERISCOPE_TRACE!.timeline) return window.PERISCOPE_TRACE!.timeline;
    return getJson<TimelineEntry[]>(`/api/traces/${id}/timeline`);
  },

  async getInsights(id: string): Promise<InsightsJson> {
    if (isStaticMode() && window.PERISCOPE_TRACE!.insights) return window.PERISCOPE_TRACE!.insights;
    return getJson<InsightsJson>(`/api/traces/${id}/insights`);
  },

  async getSummary(id: string): Promise<SummaryJson> {
    if (isStaticMode() && window.PERISCOPE_TRACE!.summary) return window.PERISCOPE_TRACE!.summary;
    return getJson<SummaryJson>(`/api/traces/${id}/summary`);
  },

  async getState(id: string, atMicros: number): Promise<ReconstructedState> {
    if (isStaticMode()) return reconstructStatic(atMicros);
    return getJson<ReconstructedState>(`/api/traces/${id}/state?at=${atMicros}`);
  },

  async readFile(path: string, line?: number, radius = 24): Promise<FileSlice> {
    if (isStaticMode()) {
      throw new Error("file reads are unavailable in static (exported) mode");
    }
    const qs = new URLSearchParams({ path });
    if (line) qs.set("line", String(line));
    qs.set("radius", String(radius));
    return getJson<FileSlice>(`/api/file?${qs.toString()}`);
  },

  async deleteTrace(id: string): Promise<{ deleted: number }> {
    return deleteJson(`/api/traces/${id}`);
  },

  async clearTraces(): Promise<{ deleted: number }> {
    return deleteJson(`/api/traces`);
  },
};

function reconstructStatic(atMicros: number): ReconstructedState {
  const t = window.PERISCOPE_TRACE!.trace;
  // Pick the deepest frame whose [enter, exit] window covers atMicros.
  let current = t.frames.find(
    (f) => atMicros >= f.enter_micros && atMicros <= f.exit_micros,
  );
  if (current && t.frames.length > 0) {
    let best = current;
    for (const f of t.frames) {
      if (atMicros >= f.enter_micros && atMicros <= f.exit_micros && f.depth > best.depth) {
        best = f;
      }
    }
    current = best;
  }
  const byId = new Map(t.frames.map((f) => [f.id, f]));
  const stack: typeof t.frames = [];
  let cur = current;
  while (cur) {
    stack.unshift(cur);
    cur = cur.parent_id ? byId.get(cur.parent_id) : undefined;
  }
  const events = t.observability_events.filter((e) => e.at_micros <= atMicros);
  const scope = current
    ? [
        ...(current.args_summary
          ? [{ name: "$args", value: current.args_summary, kind: "args" as const }]
          : []),
        ...(current.return_value_summary
          ? [{ name: "$return", value: current.return_value_summary, kind: "return" as const }]
          : []),
      ]
    : [];
  return {
    at_micros: atMicros,
    current_frame: current ?? null,
    stack,
    scope_variables: scope,
    events_so_far: events,
  };
}

// WebSocket subscription. Returns a disposer.
export function subscribeWs(onMessage: (msg: unknown) => void): () => void {
  if (isStaticMode()) return () => {};
  let url: string;
  if (base) {
    url = base.replace(/^http/, "ws") + "/ws";
  } else {
    const proto = window.location.protocol === "https:" ? "wss:" : "ws:";
    url = `${proto}//${window.location.host}/ws`;
  }
  let ws: WebSocket | null = null;
  let closed = false;
  let retry = 0;

  const open = () => {
    if (closed) return;
    ws = new WebSocket(url);
    ws.onmessage = (ev) => {
      try {
        onMessage(JSON.parse(ev.data));
      } catch {
        /* ignore */
      }
    };
    ws.onclose = () => {
      if (closed) return;
      const delay = Math.min(5000, 250 * 2 ** retry++);
      setTimeout(open, delay);
    };
    ws.onerror = () => ws?.close();
    ws.onopen = () => {
      retry = 0;
    };
  };
  open();

  return () => {
    closed = true;
    ws?.close();
  };
}
