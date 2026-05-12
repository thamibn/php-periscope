//! Event grouping / de-dup for `/api/traces/{id}/events?group=true`.
//!
//! Two events collapse into one row when they are *the same*: same
//! `type_tag` and a byte-identical canonicalised payload. "Canonicalised"
//! means object keys sorted, and a small set of timing-like fields stripped
//! so that the same log line firing at two different timestamps still
//! collapses — but a log line referencing `user 42` never collapses with
//! one referencing `user 43`.
//!
//! Raw trace is untouched. Grouping is a view at the API layer.

use serde::Serialize;
use sha2::{Digest, Sha256};

use crate::trace_view::EventJson;

/// Keys excluded from the fingerprint hash. These vary across otherwise
/// identical events and would prevent the desired collapse:
///   - Wall-clock and elapsed timing values.
///   - Per-event durations the framework attaches (Laravel's QueryExecuted
///     records `time_ms`, the HTTP client phase events occasionally carry
///     `duration_ms`).
///
/// Anything not listed here — including bindings, user ids, message bodies,
/// SQL strings — is preserved so distinct variable values fingerprint
/// distinctly.
const TIMING_KEYS: &[&str] = &[
    "at",
    "at_micros",
    "at_unix_micros",
    "duration_micros",
    "duration_ms",
    "elapsed_ms",
    "ended_at",
    "enter_micros",
    "exit_micros",
    "fired_at",
    "started_at",
    "time",
    "time_ms",
    "timestamp",
];

#[derive(Serialize, Clone)]
pub struct EventGroup {
    pub fingerprint: String,
    #[serde(rename = "type")]
    pub type_tag: String,
    pub count: usize,
    pub first_at_micros: u64,
    pub last_at_micros: u64,
    pub sample: EventJson,
    pub event_ids: Vec<u32>,
}

/// Collapse an ordered slice of events into groups keyed by
/// `(type_tag, sha256(canonical(payload)))`. Order of first occurrence is
/// preserved so the output reads as "first time this happened" top-down.
pub fn group_events(events: Vec<EventJson>) -> Vec<EventGroup> {
    let mut order: Vec<String> = Vec::new();
    let mut groups: std::collections::HashMap<String, EventGroup> =
        std::collections::HashMap::new();

    for ev in events {
        let fingerprint = fingerprint_for(&ev.type_tag, &ev.payload);
        let key = fingerprint.clone();
        if let Some(g) = groups.get_mut(&key) {
            g.count += 1;
            g.last_at_micros = ev.at_micros.max(g.last_at_micros);
            g.event_ids.push(ev.id);
        } else {
            order.push(key.clone());
            groups.insert(
                key,
                EventGroup {
                    fingerprint,
                    type_tag: ev.type_tag.clone(),
                    count: 1,
                    first_at_micros: ev.at_micros,
                    last_at_micros: ev.at_micros,
                    event_ids: vec![ev.id],
                    sample: ev,
                },
            );
        }
    }

    order
        .into_iter()
        .filter_map(|k| groups.remove(&k))
        .collect()
}

/// `sha256(type_tag || 0x1f || canonical_payload_bytes)`, hex-truncated to
/// 16 chars. 64-bit entropy is plenty for collision-resistance inside a
/// single trace (worst case a few thousand events).
pub fn fingerprint_for(type_tag: &str, payload: &serde_json::Value) -> String {
    let canonical = canonicalise(payload);
    let bytes = serde_json::to_vec(&canonical).unwrap_or_default();
    let mut hasher = Sha256::new();
    hasher.update(type_tag.as_bytes());
    hasher.update([0x1f]);
    hasher.update(&bytes);
    let digest = hasher.finalize();
    let mut hex = String::with_capacity(16);
    for b in digest.iter().take(8) {
        use std::fmt::Write;
        let _ = write!(hex, "{:02x}", b);
    }
    hex
}

/// Recursive canonicalisation:
///   - Objects: keys sorted lexicographically, timing-like keys removed.
///   - Arrays: order preserved (order is semantic for SQL bindings, stack
///     frames, etc.).
///   - Primitives: returned as-is.
fn canonicalise(v: &serde_json::Value) -> serde_json::Value {
    use serde_json::Value;
    match v {
        Value::Object(map) => {
            let mut pairs: Vec<(&String, &Value)> = map
                .iter()
                .filter(|(k, _)| !TIMING_KEYS.contains(&k.as_str()))
                .collect();
            pairs.sort_by(|a, b| a.0.cmp(b.0));
            let mut out = serde_json::Map::new();
            for (k, val) in pairs {
                out.insert(k.clone(), canonicalise(val));
            }
            Value::Object(out)
        }
        Value::Array(items) => Value::Array(items.iter().map(canonicalise).collect()),
        other => other.clone(),
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    fn mk_event(id: u32, at: u64, type_tag: &str, payload: serde_json::Value) -> EventJson {
        EventJson {
            id,
            at_micros: at,
            in_frame_id: 0,
            type_tag: type_tag.to_string(),
            payload,
            user_call_site: None,
        }
    }

    #[test]
    fn same_payload_different_timestamps_groups() {
        let a = mk_event(1, 100, "log", json!({"level": "error", "message": "boom"}));
        let b = mk_event(2, 250, "log", json!({"level": "error", "message": "boom"}));
        let groups = group_events(vec![a, b]);
        assert_eq!(groups.len(), 1);
        assert_eq!(groups[0].count, 2);
        assert_eq!(groups[0].first_at_micros, 100);
        assert_eq!(groups[0].last_at_micros, 250);
        assert_eq!(groups[0].event_ids, vec![1, 2]);
    }

    #[test]
    fn different_variables_do_not_group() {
        let a = mk_event(1, 100, "log", json!({"message": "user 42 logged in"}));
        let b = mk_event(2, 200, "log", json!({"message": "user 43 logged in"}));
        let groups = group_events(vec![a, b]);
        assert_eq!(groups.len(), 2, "different message bodies must stay separate");
    }

    #[test]
    fn different_types_do_not_group() {
        let a = mk_event(1, 100, "log", json!({"x": 1}));
        let b = mk_event(2, 200, "cache", json!({"x": 1}));
        let groups = group_events(vec![a, b]);
        assert_eq!(groups.len(), 2);
    }

    #[test]
    fn key_order_does_not_matter() {
        let a = mk_event(1, 10, "sql", json!({"sql": "select 1", "connection": "mysql"}));
        let b = mk_event(2, 20, "sql", json!({"connection": "mysql", "sql": "select 1"}));
        let groups = group_events(vec![a, b]);
        assert_eq!(groups.len(), 1);
    }

    #[test]
    fn timing_fields_are_stripped_from_fingerprint() {
        let a = mk_event(
            1,
            10,
            "sql",
            json!({"sql": "select 1", "time_ms": 5.2, "bindings": [1]}),
        );
        let b = mk_event(
            2,
            20,
            "sql",
            json!({"sql": "select 1", "time_ms": 8.9, "bindings": [1]}),
        );
        let groups = group_events(vec![a, b]);
        assert_eq!(groups.len(), 1, "time_ms varies per execution and must not split groups");
    }

    #[test]
    fn different_bindings_do_not_group_even_with_same_sql() {
        let a = mk_event(1, 10, "sql", json!({"sql": "select * from x where id=?", "bindings": [1]}));
        let b = mk_event(2, 20, "sql", json!({"sql": "select * from x where id=?", "bindings": [2]}));
        let groups = group_events(vec![a, b]);
        assert_eq!(groups.len(), 2, "bindings are variables — must stay distinct");
    }

    #[test]
    fn array_order_matters() {
        let a = mk_event(1, 10, "x", json!({"stack": ["a", "b"]}));
        let b = mk_event(2, 20, "x", json!({"stack": ["b", "a"]}));
        let groups = group_events(vec![a, b]);
        assert_eq!(groups.len(), 2);
    }

    #[test]
    fn nested_timing_is_stripped() {
        let a = mk_event(
            1,
            10,
            "http",
            json!({"req": {"url": "/x", "duration_ms": 12}, "phase": "received"}),
        );
        let b = mk_event(
            2,
            20,
            "http",
            json!({"req": {"url": "/x", "duration_ms": 50}, "phase": "received"}),
        );
        let groups = group_events(vec![a, b]);
        assert_eq!(groups.len(), 1, "nested timing fields strip recursively");
    }

    #[test]
    fn group_order_preserves_first_occurrence() {
        let a = mk_event(1, 10, "log", json!({"message": "first"}));
        let b = mk_event(2, 20, "log", json!({"message": "second"}));
        let c = mk_event(3, 30, "log", json!({"message": "first"}));
        let groups = group_events(vec![a, b, c]);
        assert_eq!(groups.len(), 2);
        assert_eq!(groups[0].sample.payload["message"], "first");
        assert_eq!(groups[0].count, 2);
        assert_eq!(groups[0].event_ids, vec![1, 3]);
        assert_eq!(groups[1].sample.payload["message"], "second");
        assert_eq!(groups[1].count, 1);
    }
}
