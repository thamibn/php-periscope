//! DebugBar-style aggregate summary for a trace.
//!
//! Per plan §"Phase 5 also includes DebugBar-style aggregate summary counts" —
//! the at-a-glance numbers DebugBar puts in its footer, derived in the daemon
//! by summing/grouping the per-event data already in the trace. No new
//! C-extension work; the watchers emit individual events; we aggregate here.

use std::collections::BTreeMap;

use serde::Serialize;

use crate::trace_view::{EventJson, TraceJson};

#[derive(Serialize, Clone)]
pub struct Summary {
    pub duration_micros: u64,
    pub frame_count: usize,
    pub event_count: usize,
    pub queries: QuerySummary,
    pub models: ModelSummary,
    pub cache: CacheSummary,
    pub logs: LogSummary,
    pub jobs: JobSummary,
    pub events: EventSummary,
    pub http: HttpSummary,
    pub mail: MailSummary,
    pub notifications: NotificationSummary,
    pub exceptions: ExceptionSummary,
    pub request: RequestSummary,
    pub response: ResponseSummary,
}

#[derive(Serialize, Clone, Default)]
pub struct QuerySummary {
    pub count: usize,
    pub total_ms: f64,
    pub slow_count: usize,
    pub n_plus_one_count: usize,
    pub by_connection: BTreeMap<String, usize>,
}

#[derive(Serialize, Clone, Default)]
pub struct ModelSummary {
    pub hydrated_count: usize,
    pub by_class: BTreeMap<String, usize>,
}

#[derive(Serialize, Clone, Default)]
pub struct CacheSummary {
    pub hits: usize,
    pub misses: usize,
    pub writes: usize,
    pub forgets: usize,
    pub by_store: BTreeMap<String, usize>,
}

#[derive(Serialize, Clone, Default)]
pub struct LogSummary {
    pub count: usize,
    pub by_level: BTreeMap<String, usize>,
}

#[derive(Serialize, Clone, Default)]
pub struct JobSummary {
    pub count: usize,
    pub by_class: BTreeMap<String, usize>,
}

#[derive(Serialize, Clone, Default)]
pub struct EventSummary {
    pub count: usize,
    pub by_class: BTreeMap<String, usize>,
}

#[derive(Serialize, Clone, Default)]
pub struct HttpSummary {
    pub count: usize,
    pub total_ms: f64,
    pub total_bytes: u64,
    pub by_host: BTreeMap<String, usize>,
}

#[derive(Serialize, Clone, Default)]
pub struct MailSummary {
    pub count: usize,
    pub by_recipient_domain: BTreeMap<String, usize>,
}

#[derive(Serialize, Clone, Default)]
pub struct NotificationSummary {
    pub count: usize,
    pub by_channel: BTreeMap<String, usize>,
}

#[derive(Serialize, Clone, Default)]
pub struct ExceptionSummary {
    pub count: usize,
    pub by_class: BTreeMap<String, usize>,
}

#[derive(Serialize, Clone, Default)]
pub struct RequestSummary {
    pub method: String,
    pub uri: String,
    pub total_body_bytes: u32,
    pub upload_count: usize,
}

#[derive(Serialize, Clone, Default)]
pub struct ResponseSummary {
    pub status_code: u16,
    pub total_body_bytes: u32,
    pub duration_micros: u64,
    pub peak_memory_bytes: u64,
}

pub fn compute(trace: &TraceJson) -> Summary {
    let mut s = Summary {
        duration_micros: trace.meta.duration_micros,
        frame_count: trace.frames.len(),
        event_count: trace.observability_events.len(),
        queries: QuerySummary::default(),
        models: ModelSummary::default(),
        cache: CacheSummary::default(),
        logs: LogSummary::default(),
        jobs: JobSummary::default(),
        events: EventSummary::default(),
        http: HttpSummary::default(),
        mail: MailSummary::default(),
        notifications: NotificationSummary::default(),
        exceptions: ExceptionSummary::default(),
        request: RequestSummary::default(),
        response: ResponseSummary::default(),
    };

    if let Some(req) = &trace.meta.request {
        s.request = RequestSummary {
            method: req.method.clone(),
            uri: req.uri.clone(),
            total_body_bytes: req.total_body_bytes,
            upload_count: 0, // files list not exposed in MetaJson today
        };
    }
    if let Some(resp) = &trace.meta.response {
        s.response = ResponseSummary {
            status_code: resp.status_code,
            total_body_bytes: resp.total_body_bytes,
            duration_micros: resp.duration_micros,
            peak_memory_bytes: resp.peak_memory_bytes,
        };
    }

    for e in &trace.observability_events {
        match e.type_tag.as_str() {
            "sql" => fold_sql(&mut s.queries, e),
            "model" | "model_hydrated" | "model_summary" => fold_model(&mut s.models, e),
            "cache" => fold_cache(&mut s.cache, e),
            "log" => fold_log(&mut s.logs, e),
            "job" => fold_job(&mut s.jobs, e),
            "event" => fold_event(&mut s.events, e),
            "http" => fold_http(&mut s.http, e),
            "mail" => fold_mail(&mut s.mail, e),
            "notification" => fold_notification(&mut s.notifications, e),
            "exception" => fold_exception(&mut s.exceptions, e),
            "n_plus_one" => s.queries.n_plus_one_count += 1,
            _ => {}
        }
    }

    s
}

fn fold_sql(q: &mut QuerySummary, e: &EventJson) {
    q.count += 1;
    if let Some(ms) = e.payload.get("time_ms").and_then(|v| v.as_f64()) {
        q.total_ms += ms;
        if ms >= 100.0 {
            q.slow_count += 1;
        }
    }
    if let Some(conn) = e.payload.get("connection").and_then(|v| v.as_str()) {
        *q.by_connection.entry(conn.to_string()).or_default() += 1;
    }
}

fn fold_model(m: &mut ModelSummary, e: &EventJson) {
    if let Some(class) = e.payload.get("class").and_then(|v| v.as_str()) {
        let n = e
            .payload
            .get("count")
            .and_then(|v| v.as_u64())
            .unwrap_or(1) as usize;
        m.hydrated_count += n;
        *m.by_class.entry(class.to_string()).or_default() += n;
    } else if let Some(by) = e.payload.get("by_class").and_then(|v| v.as_object()) {
        // model_summary aggregate event
        for (k, v) in by {
            let n = v.as_u64().unwrap_or(0) as usize;
            m.hydrated_count += n;
            *m.by_class.entry(k.clone()).or_default() += n;
        }
    }
}

fn fold_cache(c: &mut CacheSummary, e: &EventJson) {
    let op = e
        .payload
        .get("operation")
        .and_then(|v| v.as_str())
        .unwrap_or("");
    match op {
        "hit" => c.hits += 1,
        "miss" => c.misses += 1,
        "write" => c.writes += 1,
        "forget" => c.forgets += 1,
        _ => {}
    }
    if let Some(store) = e.payload.get("store").and_then(|v| v.as_str()) {
        *c.by_store.entry(store.to_string()).or_default() += 1;
    }
}

fn fold_log(l: &mut LogSummary, e: &EventJson) {
    l.count += 1;
    let level = e
        .payload
        .get("level")
        .and_then(|v| v.as_str())
        .unwrap_or("info");
    *l.by_level.entry(level.to_string()).or_default() += 1;
}

fn fold_job(j: &mut JobSummary, e: &EventJson) {
    j.count += 1;
    if let Some(class) = e.payload.get("job").and_then(|v| v.as_str()) {
        *j.by_class.entry(class.to_string()).or_default() += 1;
    } else if let Some(class) = e.payload.get("class").and_then(|v| v.as_str()) {
        *j.by_class.entry(class.to_string()).or_default() += 1;
    }
}

fn fold_event(ev: &mut EventSummary, e: &EventJson) {
    ev.count += 1;
    if let Some(class) = e.payload.get("event").and_then(|v| v.as_str()) {
        *ev.by_class.entry(class.to_string()).or_default() += 1;
    }
}

fn fold_http(h: &mut HttpSummary, e: &EventJson) {
    h.count += 1;
    if let Some(ms) = e.payload.get("duration_ms").and_then(|v| v.as_f64()) {
        h.total_ms += ms;
    }
    let bytes = e
        .payload
        .get("response_bytes")
        .and_then(|v| v.as_u64())
        .unwrap_or(0)
        + e.payload
            .get("request_bytes")
            .and_then(|v| v.as_u64())
            .unwrap_or(0);
    h.total_bytes += bytes;
    if let Some(url) = e.payload.get("url").and_then(|v| v.as_str()) {
        if let Some(host) = host_of(url) {
            *h.by_host.entry(host).or_default() += 1;
        }
    }
}

fn fold_mail(m: &mut MailSummary, e: &EventJson) {
    m.count += 1;
    if let Some(arr) = e.payload.get("to").and_then(|v| v.as_array()) {
        for v in arr {
            if let Some(addr) = v.as_str() {
                if let Some(at) = addr.rfind('@') {
                    let domain = addr[at + 1..].to_string();
                    *m.by_recipient_domain.entry(domain).or_default() += 1;
                }
            }
        }
    }
}

fn fold_notification(n: &mut NotificationSummary, e: &EventJson) {
    n.count += 1;
    if let Some(channel) = e.payload.get("channel").and_then(|v| v.as_str()) {
        *n.by_channel.entry(channel.to_string()).or_default() += 1;
    }
}

fn fold_exception(x: &mut ExceptionSummary, e: &EventJson) {
    x.count += 1;
    if let Some(class) = e.payload.get("class").and_then(|v| v.as_str()) {
        *x.by_class.entry(class.to_string()).or_default() += 1;
    }
}

fn host_of(url: &str) -> Option<String> {
    // Cheap host extractor — avoids pulling in a URL parser.
    let after_scheme = url.split_once("://").map(|(_, r)| r).unwrap_or(url);
    let host = after_scheme
        .split('/')
        .next()
        .unwrap_or("")
        .split('?')
        .next()
        .unwrap_or("");
    if host.is_empty() {
        None
    } else {
        Some(host.to_string())
    }
}
