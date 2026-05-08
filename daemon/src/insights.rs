//! Deterministic insight detectors.
//!
//! Per Appendix A.6: insights MUST exist independently of any AI vendor.
//! This module ranks slow frames, detects N+1 patterns, flags memory hogs,
//! spots DB-in-loop / serial HTTP / cache miss storms — all by walking the
//! decoded `TraceJson` produced by `trace_view`.

use serde::Serialize;

use crate::trace_view::{EventJson, FrameJson, TraceJson};

#[derive(Serialize, Clone)]
pub struct Insights {
    pub n_plus_one: Vec<NPlusOneInsight>,
    pub slow_frames: Vec<SlowFrameInsight>,
    pub memory_hogs: Vec<MemoryHogInsight>,
    pub db_in_loop: Vec<DbInLoopInsight>,
    pub serial_http: Vec<SerialHttpInsight>,
    pub cache_miss_storm: Vec<CacheMissInsight>,
    pub slow_queries: Vec<SlowQueryInsight>,
}

#[derive(Serialize, Clone)]
pub struct NPlusOneInsight {
    pub pattern: String,
    pub count: usize,
    pub first_event_id: u32,
    pub frame_id: u32,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub call_site_file: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub call_site_line: Option<u32>,
    pub recommendation: String,
}

#[derive(Serialize, Clone)]
pub struct SlowFrameInsight {
    pub frame_id: u32,
    pub function: String,
    pub file: String,
    pub line: u32,
    pub duration_micros: u64,
    pub recommendation: String,
}

#[derive(Serialize, Clone)]
pub struct MemoryHogInsight {
    pub function: String,
    pub frame_id: u32,
    pub recommendation: String,
}

#[derive(Serialize, Clone)]
pub struct DbInLoopInsight {
    pub frame_id: u32,
    pub function: String,
    pub query_count: usize,
    pub recommendation: String,
}

#[derive(Serialize, Clone)]
pub struct SerialHttpInsight {
    pub frame_id: u32,
    pub function: String,
    pub call_count: usize,
    pub total_ms: f64,
    pub recommendation: String,
}

#[derive(Serialize, Clone)]
pub struct CacheMissInsight {
    pub key: String,
    pub miss_count: usize,
    pub recommendation: String,
}

#[derive(Serialize, Clone)]
pub struct SlowQueryInsight {
    pub event_id: u32,
    pub sql: String,
    pub time_ms: f64,
    pub recommendation: String,
}

const SLOW_QUERY_THRESHOLD_MS: f64 = 100.0;
const SLOW_FRAME_TOP_N: usize = 10;
const N_PLUS_ONE_THRESHOLD: usize = 4;
const DB_IN_LOOP_THRESHOLD: usize = 5;
const SERIAL_HTTP_THRESHOLD: usize = 3;
const SERIAL_HTTP_MS_THRESHOLD: f64 = 100.0;
const CACHE_MISS_STORM_THRESHOLD: usize = 5;

/// Drop literal values from a SQL query so two queries that differ only by
/// bound literals collapse to the same fingerprint. Cheap heuristic — we are
/// not parsing SQL, just normalising the parts that obviously vary by row.
fn normalise_sql(sql: &str) -> String {
    let mut out = String::with_capacity(sql.len());
    let mut chars = sql.chars().peekable();
    while let Some(c) = chars.next() {
        match c {
            '0'..='9' => {
                while matches!(chars.peek(), Some(c2) if c2.is_ascii_digit() || *c2 == '.') {
                    chars.next();
                }
                out.push('?');
            }
            '\'' => {
                while let Some(c2) = chars.next() {
                    if c2 == '\'' {
                        break;
                    }
                }
                out.push('?');
            }
            '"' => {
                while let Some(c2) = chars.next() {
                    if c2 == '"' {
                        break;
                    }
                }
                out.push('?');
            }
            _ => out.push(c),
        }
    }
    // Also collapse runs of whitespace.
    out.split_whitespace().collect::<Vec<_>>().join(" ")
}

pub fn compute(trace: &TraceJson) -> Insights {
    let frames = &trace.frames;
    let events = &trace.observability_events;

    Insights {
        n_plus_one: detect_n_plus_one(events),
        slow_frames: rank_slow_frames(frames),
        memory_hogs: vec![], // peak memory deltas not yet captured per-frame in the trace
        db_in_loop: detect_db_in_loop(frames, events),
        serial_http: detect_serial_http(frames, events),
        cache_miss_storm: detect_cache_miss_storm(events),
        slow_queries: detect_slow_queries(events),
    }
}

fn detect_n_plus_one(events: &[EventJson]) -> Vec<NPlusOneInsight> {
    use std::collections::HashMap;

    // Group SQL events by (frame_id, normalised_sql). When a single frame
    // fires the same shape of query >= threshold times, that's an N+1.
    let mut buckets: HashMap<(u32, String), Vec<&EventJson>> = HashMap::new();
    for e in events {
        if e.type_tag != "sql" {
            continue;
        }
        let sql = e
            .payload
            .get("sql")
            .and_then(|v| v.as_str())
            .unwrap_or("");
        if sql.is_empty() {
            continue;
        }
        // Use the *parent* user-code frame from the call site if available,
        // else fall back to in_frame_id.
        let frame = e.in_frame_id;
        let pattern = normalise_sql(sql);
        buckets.entry((frame, pattern)).or_default().push(e);
    }

    let mut out = vec![];
    for ((frame_id, pattern), evs) in buckets {
        if evs.len() < N_PLUS_ONE_THRESHOLD {
            continue;
        }
        let first = evs[0];
        let (file, line) = first
            .user_call_site
            .as_ref()
            .map(|cs| (Some(cs.file.clone()), Some(cs.line)))
            .unwrap_or((None, None));
        let where_str = match (&file, line) {
            (Some(f), Some(l)) => format!(" at {}:{}", f, l),
            _ => String::new(),
        };
        out.push(NPlusOneInsight {
            pattern,
            count: evs.len(),
            first_event_id: first.id,
            frame_id,
            call_site_file: file,
            call_site_line: line,
            recommendation: format!(
                "Same query ran {}× from one frame{}. Eager-load the relation (->with('...')) or chunk the parent collection.",
                evs.len(),
                where_str
            ),
        });
    }
    // Heaviest first.
    out.sort_by(|a, b| b.count.cmp(&a.count));
    out
}

fn rank_slow_frames(frames: &[FrameJson]) -> Vec<SlowFrameInsight> {
    let mut sorted: Vec<&FrameJson> = frames.iter().filter(|f| f.duration_micros > 0).collect();
    sorted.sort_by(|a, b| b.duration_micros.cmp(&a.duration_micros));
    sorted
        .into_iter()
        .take(SLOW_FRAME_TOP_N)
        .map(|f| SlowFrameInsight {
            frame_id: f.id,
            function: f.function.clone(),
            file: f.file.clone(),
            line: f.line,
            duration_micros: f.duration_micros,
            recommendation: if f.duration_micros > 200_000 {
                format!(
                    "{} dominates the request ({:.0}ms). Profile its body or consider caching its result.",
                    f.function,
                    f.duration_micros as f64 / 1000.0
                )
            } else {
                format!(
                    "{} took {:.0}ms — review if this is on the hot path.",
                    f.function,
                    f.duration_micros as f64 / 1000.0
                )
            },
        })
        .collect()
}

fn detect_db_in_loop(frames: &[FrameJson], events: &[EventJson]) -> Vec<DbInLoopInsight> {
    use std::collections::HashMap;

    // Per-frame SQL count. If a frame *itself* (not just its descendants)
    // fires N queries, that's likely a loop running queries.
    let mut by_frame: HashMap<u32, usize> = HashMap::new();
    for e in events {
        if e.type_tag == "sql" {
            *by_frame.entry(e.in_frame_id).or_default() += 1;
        }
    }
    let frame_lookup: HashMap<u32, &FrameJson> = frames.iter().map(|f| (f.id, f)).collect();

    let mut out: Vec<DbInLoopInsight> = by_frame
        .into_iter()
        .filter(|(_, count)| *count >= DB_IN_LOOP_THRESHOLD)
        .map(|(frame_id, count)| {
            let function = frame_lookup
                .get(&frame_id)
                .map(|f| f.function.clone())
                .unwrap_or_else(|| format!("frame#{}", frame_id));
            DbInLoopInsight {
                frame_id,
                function,
                query_count: count,
                recommendation: format!(
                    "{} queries fired inside one frame — chunk, batch, or eager-load to avoid per-iteration round-trips.",
                    count
                ),
            }
        })
        .collect();
    out.sort_by(|a, b| b.query_count.cmp(&a.query_count));
    out
}

fn detect_serial_http(frames: &[FrameJson], events: &[EventJson]) -> Vec<SerialHttpInsight> {
    use std::collections::HashMap;

    let mut by_frame: HashMap<u32, (usize, f64)> = HashMap::new();
    for e in events {
        if e.type_tag != "http" {
            continue;
        }
        let dur_ms = e
            .payload
            .get("duration_ms")
            .and_then(|v| v.as_f64())
            .unwrap_or(0.0);
        let entry = by_frame.entry(e.in_frame_id).or_default();
        entry.0 += 1;
        entry.1 += dur_ms;
    }
    let frame_lookup: HashMap<u32, &FrameJson> = frames.iter().map(|f| (f.id, f)).collect();

    let mut out: Vec<SerialHttpInsight> = by_frame
        .into_iter()
        .filter(|(_, (count, total))| *count >= SERIAL_HTTP_THRESHOLD && *total >= SERIAL_HTTP_MS_THRESHOLD)
        .map(|(frame_id, (count, total))| SerialHttpInsight {
            frame_id,
            function: frame_lookup
                .get(&frame_id)
                .map(|f| f.function.clone())
                .unwrap_or_default(),
            call_count: count,
            total_ms: total,
            recommendation: format!(
                "{} HTTP calls totaling {:.0}ms ran serially. Use Http::pool() to fan out concurrently.",
                count, total
            ),
        })
        .collect();
    out.sort_by(|a, b| b.total_ms.partial_cmp(&a.total_ms).unwrap_or(std::cmp::Ordering::Equal));
    out
}

fn detect_cache_miss_storm(events: &[EventJson]) -> Vec<CacheMissInsight> {
    use std::collections::HashMap;

    let mut by_key: HashMap<String, usize> = HashMap::new();
    for e in events {
        if e.type_tag != "cache" {
            continue;
        }
        let op = e
            .payload
            .get("operation")
            .and_then(|v| v.as_str())
            .unwrap_or("");
        if op != "miss" {
            continue;
        }
        let key = e
            .payload
            .get("key")
            .and_then(|v| v.as_str())
            .unwrap_or("")
            .to_string();
        if key.is_empty() {
            continue;
        }
        *by_key.entry(key).or_default() += 1;
    }
    let mut out: Vec<CacheMissInsight> = by_key
        .into_iter()
        .filter(|(_, count)| *count >= CACHE_MISS_STORM_THRESHOLD)
        .map(|(key, count)| CacheMissInsight {
            key,
            miss_count: count,
            recommendation: format!(
                "{} consecutive misses on the same key — confirm the key is being written somewhere on the hot path.",
                count
            ),
        })
        .collect();
    out.sort_by(|a, b| b.miss_count.cmp(&a.miss_count));
    out
}

fn detect_slow_queries(events: &[EventJson]) -> Vec<SlowQueryInsight> {
    let mut out: Vec<SlowQueryInsight> = events
        .iter()
        .filter(|e| e.type_tag == "sql")
        .filter_map(|e| {
            let time_ms = e.payload.get("time_ms").and_then(|v| v.as_f64())?;
            if time_ms < SLOW_QUERY_THRESHOLD_MS {
                return None;
            }
            let sql = e
                .payload
                .get("sql")
                .and_then(|v| v.as_str())
                .unwrap_or("")
                .to_string();
            Some(SlowQueryInsight {
                event_id: e.id,
                sql,
                time_ms,
                recommendation: format!(
                    "Query took {:.0}ms. Add an index, paginate the result set, or cache the response.",
                    time_ms
                ),
            })
        })
        .collect();
    out.sort_by(|a, b| {
        b.time_ms
            .partial_cmp(&a.time_ms)
            .unwrap_or(std::cmp::Ordering::Equal)
    });
    out
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::trace_view::MetaJson;

    fn ev(id: u32, frame: u32, type_tag: &str, payload: serde_json::Value) -> EventJson {
        EventJson {
            id,
            at_micros: 0,
            in_frame_id: frame,
            type_tag: type_tag.to_string(),
            payload,
            user_call_site: None,
        }
    }

    #[test]
    fn n_plus_one_collapses_bindings() {
        let events = vec![
            ev(
                1,
                10,
                "sql",
                serde_json::json!({"sql": "select * from agencies where id = 1"}),
            ),
            ev(
                2,
                10,
                "sql",
                serde_json::json!({"sql": "select * from agencies where id = 2"}),
            ),
            ev(
                3,
                10,
                "sql",
                serde_json::json!({"sql": "select * from agencies where id = 3"}),
            ),
            ev(
                4,
                10,
                "sql",
                serde_json::json!({"sql": "select * from agencies where id = 4"}),
            ),
        ];
        let trace = TraceJson {
            id: "t".to_string(),
            meta: MetaJson::test_default(),
            frames: vec![],
            observability_events: events,
        };
        let i = compute(&trace);
        assert_eq!(i.n_plus_one.len(), 1);
        assert_eq!(i.n_plus_one[0].count, 4);
    }

    #[test]
    fn slow_query_threshold() {
        let events = vec![
            ev(1, 1, "sql", serde_json::json!({"time_ms": 5.0, "sql": "x"})),
            ev(2, 1, "sql", serde_json::json!({"time_ms": 250.0, "sql": "y"})),
        ];
        let trace = TraceJson {
            id: "t".to_string(),
            meta: MetaJson::test_default(),
            frames: vec![],
            observability_events: events,
        };
        let i = compute(&trace);
        assert_eq!(i.slow_queries.len(), 1);
        assert_eq!(i.slow_queries[0].event_id, 2);
    }

    impl MetaJson {
        fn test_default() -> Self {
            MetaJson {
                php_version: "8.3".into(),
                periscope_version: "0.1".into(),
                sapi: "cli".into(),
                entry_point: "".into(),
                working_dir: "".into(),
                hostname: "".into(),
                pid: 0,
                started_at_unix_micros: 0,
                duration_micros: 0,
                request: None,
                response: None,
            }
        }
    }
}
