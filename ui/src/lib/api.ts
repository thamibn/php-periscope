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

/**
 * Fetch JSON from the *Laravel-mounted* path, anchored at the mount prefix
 * the adapter injected via `<meta name="periscope-mount-prefix">`.
 * Used for endpoints the adapter serves alongside the UI
 * (e.g. `/periscope/api/settings`).
 *
 * Returns null when the meta tag isn't present (UI loaded from the daemon
 * directly) or the endpoint 404s.
 */
export async function getMountedJson<T>(relPath: string): Promise<T | null> {
  if (typeof window === "undefined") return null;
  const meta = document.querySelector('meta[name="periscope-mount-prefix"]');
  const prefix = meta?.getAttribute("content");
  if (prefix === null || prefix === undefined) return null;
  const url = (prefix.replace(/\/$/, "") || "") + "/" + relPath.replace(/^\//, "");
  try {
    const res = await fetch(url, { headers: { accept: "application/json" } });
    if (!res.ok) return null;
    return (await res.json()) as T;
  } catch {
    return null;
  }
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

  async getStorage(): Promise<StorageStats> {
    return getJson<StorageStats>("/api/storage");
  },

  async getClientMetrics(id: string): Promise<ClientMetrics | null> {
    if (isStaticMode()) return null;
    try {
      const res = await fetch(`${base}/api/traces/${id}/client-metrics`, {
        headers: { accept: "application/json" },
      });
      if (!res.ok) return null;
      const v = (await res.json()) as ClientMetrics | null;
      return v ?? null;
    } catch {
      return null;
    }
  },

  async revealStorage(): Promise<{ revealed: boolean; command: string }> {
    const res = await fetch(`${base}/api/storage/reveal`, { method: "POST" });
    if (!res.ok) throw new Error(`${res.status} ${res.statusText}`);
    return (await res.json()) as { revealed: boolean; command: string };
  },
};

/**
 * Daemon-truth storage stats — sum of every `.cptrace` on disk, not just
 * the paginated 50 the trace list returns. Used by the Sidebar Storage
 * section so the user sees the real disk footprint.
 */
export interface StorageStats {
  trace_dir: string;
  trace_count: number;
  total_bytes: number;
}

/** Client-side timing posted by the floating toolbar after the page hides. */
export interface ClientMetrics {
  pid?: number;
  started_at_unix_micros?: number;
  uri?: string;
  vitals?: {
    lcp_ms?: number | null;
    cls?: number | null;
    fcp_ms?: number | null;
    inp_ms?: number | null;
  };
  navigation?: {
    ttfb_ms?: number;
    dom_content_loaded_ms?: number;
    load_event_ms?: number;
    transfer_size_bytes?: number;
    decoded_body_size_bytes?: number;
    type?: string;
  };
}

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
  const url = wsUrl();
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

function wsUrl(): string {
  if (base) return base.replace(/^http/, "ws") + "/ws";
  const proto = window.location.protocol === "https:" ? "wss:" : "ws:";
  return `${proto}//${window.location.host}/ws`;
}

/**
 * Singleton WS used to publish UI messages (e.g. cursor_set) back to the
 * daemon. We keep one persistent connection per tab so cursor drags don't
 * pay the open/close handshake cost. Reconnects with backoff on drop.
 *
 * Inbound messages are still surfaced via `subscribeWs(onMessage)` — that
 * helper can connect over a separate WS, but that means each tab opens two
 * sockets. The daemon broadcasts to every subscriber, so whoever sends a
 * cursor_set also sees the echo come back; keeping the publisher and
 * listener decoupled is fine for now.
 */
let publishWs: WebSocket | null = null;
let publishClosed = false;
let publishRetry = 0;
let publishReady = false;
const publishQueue: string[] = [];

function ensurePublishWs(): void {
  if (publishWs || publishClosed || isStaticMode() || typeof window === "undefined") return;
  publishWs = new WebSocket(wsUrl());
  publishWs.onopen = () => {
    publishRetry = 0;
    publishReady = true;
    while (publishQueue.length > 0) {
      const msg = publishQueue.shift()!;
      try {
        publishWs?.send(msg);
      } catch {
        /* drop */
      }
    }
  };
  publishWs.onclose = () => {
    publishWs = null;
    publishReady = false;
    if (publishClosed) return;
    const delay = Math.min(5000, 250 * 2 ** publishRetry++);
    setTimeout(ensurePublishWs, delay);
  };
  publishWs.onerror = () => publishWs?.close();
}

/** Publish a cursor move to the daemon (and through it, every other tab). */
export function publishCursorSet(traceId: string, atMicros: number, frameId?: number): void {
  if (isStaticMode() || typeof window === "undefined") return;
  const payload = JSON.stringify({
    type: "cursor_set",
    trace_id: traceId,
    at_micros: Math.max(0, Math.round(atMicros)),
    ...(typeof frameId === "number" ? { frame_id: frameId } : {}),
  });
  ensurePublishWs();
  if (publishReady && publishWs) {
    try {
      publishWs.send(payload);
      return;
    } catch {
      /* fall through to queue */
    }
  }
  // Cap the queue so a long-disconnected tab doesn't grow unbounded.
  if (publishQueue.length < 32) publishQueue.push(payload);
}
