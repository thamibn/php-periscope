// Mirror of daemon/src/trace_view.rs + insights.rs + summary.rs JSON shapes.
// Keep field names identical to the Rust serde output (snake_case).

export interface TraceSummaryRow {
  id: string;
  path: string;
  started_at_unix_micros: number;
  duration_micros: number;
  method: string;
  uri: string;
  status_code: number;
  frame_count: number;
  event_count: number;
  has_exception: boolean;
  size_bytes: number;
}

export interface RequestJson {
  method: string;
  uri: string;
  remote_addr: string;
  scheme: string;
  headers: HeaderJson[];
  cookies: HeaderJson[];
  query: HeaderJson[];
  post_params: HeaderJson[];
  total_body_bytes: number;
  body_truncated: boolean;
}

export interface ResponseJson {
  status_code: number;
  headers: HeaderJson[];
  total_body_bytes: number;
  body_truncated: boolean;
  duration_micros: number;
  peak_memory_bytes: number;
}

export interface HeaderJson {
  name: string;
  value: string;
  redacted: boolean;
}

export interface MetaJson {
  php_version: string;
  periscope_version: string;
  sapi: string;
  entry_point: string;
  working_dir: string;
  hostname: string;
  pid: number;
  started_at_unix_micros: number;
  duration_micros: number;
  request?: RequestJson;
  response?: ResponseJson;
}

export interface FrameJson {
  id: number;
  parent_id: number;
  function: string;
  file: string;
  line: number;
  enter_micros: number;
  exit_micros: number;
  duration_micros: number;
  depth: number;
  flags: number;
  args_summary?: string;
  return_value_summary?: string;
  observability_event_ids: number[];
}

export interface CallSiteJson {
  file: string;
  line: number;
  snippet: { number: number; source: string }[];
  frame_stack: number[];
  stack?: { file: string; line: number; function: string }[];
}

export interface EventJson {
  id: number;
  at_micros: number;
  in_frame_id: number;
  type: string;
  payload: unknown;
  user_call_site?: CallSiteJson;
}

export interface TraceJson {
  id: string;
  meta: MetaJson;
  frames: FrameJson[];
  observability_events: EventJson[];
}

export interface TimelineEntry {
  at_micros: number;
  kind: "frame_enter" | "frame_exit" | "event";
  id: number;
  label: string;
}

export interface ScopeVariable {
  name: string;
  value: string;
  kind: "args" | "return";
}

export interface ReconstructedState {
  at_micros: number;
  current_frame: FrameJson | null;
  stack: FrameJson[];
  scope_variables: ScopeVariable[];
  events_so_far: EventJson[];
}

export interface InsightsJson {
  n_plus_one: NPlusOne[];
  slow_frames: SlowFrame[];
  memory_hogs: MemoryHog[];
  db_in_loop: DbInLoop[];
  serial_http: SerialHttp[];
  cache_miss_storm: CacheMiss[];
  slow_queries: SlowQuery[];
}
export interface NPlusOne {
  pattern: string;
  count: number;
  first_event_id: number;
  frame_id: number;
  call_site_file?: string;
  call_site_line?: number;
  recommendation: string;
}
export interface SlowFrame {
  frame_id: number;
  function: string;
  file: string;
  line: number;
  duration_micros: number;
  recommendation: string;
}
export interface MemoryHog {
  function: string;
  frame_id: number;
  recommendation: string;
}
export interface DbInLoop {
  frame_id: number;
  function: string;
  query_count: number;
  recommendation: string;
}
export interface SerialHttp {
  frame_id: number;
  function: string;
  call_count: number;
  total_ms: number;
  recommendation: string;
}
export interface CacheMiss {
  key: string;
  miss_count: number;
  recommendation: string;
}
export interface SlowQuery {
  event_id: number;
  sql: string;
  time_ms: number;
  recommendation: string;
}

export interface SummaryJson {
  duration_micros: number;
  frame_count: number;
  event_count: number;
  queries: {
    count: number;
    total_ms: number;
    slow_count: number;
    n_plus_one_count: number;
    by_connection: Record<string, number>;
  };
  models: { hydrated_count: number; by_class: Record<string, number> };
  cache: {
    hits: number;
    misses: number;
    writes: number;
    forgets: number;
    by_store: Record<string, number>;
  };
  logs: { count: number; by_level: Record<string, number> };
  jobs: { count: number; by_class: Record<string, number> };
  events: { count: number; by_class: Record<string, number> };
  http: { count: number; total_ms: number; total_bytes: number; by_host: Record<string, number> };
  mail: { count: number; by_recipient_domain: Record<string, number> };
  notifications: { count: number; by_channel: Record<string, number> };
  exceptions: { count: number; by_class: Record<string, number> };
  request: { method: string; uri: string; total_body_bytes: number; upload_count: number };
  response: {
    status_code: number;
    total_body_bytes: number;
    duration_micros: number;
    peak_memory_bytes: number;
  };
}

export interface FileSlice {
  path: string;
  start_line: number;
  end_line: number;
  total_lines: number;
  mtime_unix: number;
  lines: { number: number; source: string }[];
}

declare global {
  interface Window {
    PERISCOPE_TRACE?: {
      trace: TraceJson;
      summary?: SummaryJson;
      insights?: InsightsJson;
      timeline?: TimelineEntry[];
    };
  }
}
