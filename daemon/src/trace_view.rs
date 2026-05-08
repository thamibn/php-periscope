//! Decode a `.cptrace` file into JSON-friendly Rust structs.
//!
//! Shared by `bin/dump.rs` (CLI) and `api.rs` (HTTP) so both produce the same
//! shapes. The schema returned here is the contract documented in
//! `docs/AI_ACCESS.md`.

use anyhow::Result;
use serde::Serialize;

use crate::trace::Trace;
use crate::trace_capnp;

#[derive(Serialize, Clone)]
pub struct TraceJson {
    pub id: String,
    pub meta: MetaJson,
    pub frames: Vec<FrameJson>,
    pub observability_events: Vec<EventJson>,
}

#[derive(Serialize, Clone)]
pub struct MetaJson {
    pub php_version: String,
    pub periscope_version: String,
    pub sapi: String,
    pub entry_point: String,
    pub working_dir: String,
    pub hostname: String,
    pub pid: u32,
    pub started_at_unix_micros: u64,
    pub duration_micros: u64,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub request: Option<RequestJson>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub response: Option<ResponseJson>,
}

#[derive(Serialize, Clone)]
pub struct RequestJson {
    pub method: String,
    pub uri: String,
    pub remote_addr: String,
    pub scheme: String,
    pub headers: Vec<HeaderJson>,
    pub cookies: Vec<HeaderJson>,
    pub query: Vec<HeaderJson>,
    pub post_params: Vec<HeaderJson>,
    pub total_body_bytes: u32,
    pub body_truncated: bool,
}

#[derive(Serialize, Clone)]
pub struct ResponseJson {
    pub status_code: u16,
    pub headers: Vec<HeaderJson>,
    pub total_body_bytes: u32,
    pub body_truncated: bool,
    pub duration_micros: u64,
    pub peak_memory_bytes: u64,
}

#[derive(Serialize, Clone)]
pub struct HeaderJson {
    pub name: String,
    pub value: String,
    pub redacted: bool,
}

#[derive(Serialize, Clone)]
pub struct FrameJson {
    pub id: u32,
    pub parent_id: u32,
    pub function: String,
    pub file: String,
    pub line: u32,
    pub enter_micros: u64,
    pub exit_micros: u64,
    pub duration_micros: u64,
    pub depth: u32,
    pub flags: u32,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub args_summary: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub return_value_summary: Option<String>,
    pub observability_event_ids: Vec<u32>,
}

#[derive(Serialize, Clone)]
pub struct EventJson {
    pub id: u32,
    pub at_micros: u64,
    pub in_frame_id: u32,
    #[serde(rename = "type")]
    pub type_tag: String,
    pub payload: serde_json::Value,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub user_call_site: Option<CallSiteJson>,
}

#[derive(Serialize, Clone)]
pub struct CallSiteJson {
    pub file: String,
    pub line: u32,
    pub snippet: Vec<SnippetLineJson>,
    pub frame_stack: Vec<u32>,
    #[serde(skip_serializing_if = "Vec::is_empty")]
    pub stack: Vec<StackFrameJson>,
}

#[derive(Serialize, Clone)]
pub struct StackFrameJson {
    pub file: String,
    pub line: u32,
    pub function: String,
}

#[derive(Serialize, Clone)]
pub struct SnippetLineJson {
    pub number: u32,
    pub source: String,
}

fn read_text(r: ::capnp::text::Reader<'_>) -> String {
    r.to_str().map(|s| s.to_owned()).unwrap_or_default()
}

fn opaque_or_empty(v: trace_capnp::value::Reader<'_>) -> Option<String> {
    match v.which() {
        Ok(trace_capnp::value::Which::Opaque(t)) => t.ok().map(read_text),
        _ => None,
    }
}

fn read_headers(
    list: ::capnp::struct_list::Reader<'_, trace_capnp::header::Owned>,
) -> Vec<HeaderJson> {
    list.iter()
        .map(|h| HeaderJson {
            name: h.get_name().map(read_text).unwrap_or_default(),
            value: h.get_value().map(read_text).unwrap_or_default(),
            redacted: h.get_redacted(),
        })
        .collect()
}

pub fn decode_meta(meta: trace_capnp::meta::Reader<'_>) -> Result<MetaJson> {
    let request = if meta.has_request() {
        let r = meta.get_request()?;
        // request is always present; treat as "set" only when method or uri is non-empty.
        let method = r.get_method().map(read_text).unwrap_or_default();
        let uri = r.get_uri().map(read_text).unwrap_or_default();
        if method.is_empty() && uri.is_empty() {
            None
        } else {
            Some(RequestJson {
                method,
                uri,
                remote_addr: r.get_remote_addr().map(read_text).unwrap_or_default(),
                scheme: r.get_scheme().map(read_text).unwrap_or_default(),
                headers: read_headers(r.get_headers()?),
                cookies: read_headers(r.get_cookies()?),
                query: read_headers(r.get_query()?),
                post_params: read_headers(r.get_post_params()?),
                total_body_bytes: r.get_total_body_bytes(),
                body_truncated: r.get_body_truncated(),
            })
        }
    } else {
        None
    };

    let response = if meta.has_response() {
        let r = meta.get_response()?;
        let status = r.get_status_code();
        if status == 0 && !r.has_headers() {
            None
        } else {
            Some(ResponseJson {
                status_code: status,
                headers: read_headers(r.get_headers()?),
                total_body_bytes: r.get_total_body_bytes(),
                body_truncated: r.get_body_truncated(),
                duration_micros: r.get_duration_micros(),
                peak_memory_bytes: r.get_peak_memory_bytes(),
            })
        }
    } else {
        None
    };

    Ok(MetaJson {
        php_version: read_text(meta.get_php_version()?),
        periscope_version: read_text(meta.get_periscope_version()?),
        sapi: read_text(meta.get_sapi()?),
        entry_point: read_text(meta.get_entry_point()?),
        working_dir: read_text(meta.get_working_dir()?),
        hostname: meta.get_hostname().map(read_text).unwrap_or_default(),
        pid: meta.get_pid(),
        started_at_unix_micros: meta.get_started_at_unix_micros(),
        duration_micros: meta.get_duration_micros(),
        request,
        response,
    })
}

pub fn decode_frame(f: trace_capnp::frame::Reader<'_>) -> Result<FrameJson> {
    let function = read_text(f.get_function()?);
    let file = read_text(f.get_file()?);
    let args_summary = f
        .get_args()?
        .iter()
        .next()
        .and_then(|a| a.get_value().ok().and_then(opaque_or_empty));
    let return_value_summary = f.get_return_value().ok().and_then(opaque_or_empty);
    let dur = f.get_exit_micros().saturating_sub(f.get_enter_micros());
    let event_ids = f
        .get_observability_event_ids()?
        .iter()
        .collect::<Vec<u32>>();
    Ok(FrameJson {
        id: f.get_id(),
        parent_id: f.get_parent_id(),
        function,
        file,
        line: f.get_line(),
        enter_micros: f.get_enter_micros(),
        exit_micros: f.get_exit_micros(),
        duration_micros: dur,
        depth: f.get_depth(),
        flags: f.get_flags(),
        args_summary,
        return_value_summary,
        observability_event_ids: event_ids,
    })
}

/// Pull `(type, payload)` out of an ObservabilityEvent.
///
/// v1 emits everything as the `genericJson` variant. Phase 6 readers stay
/// compatible with whatever later phases switch to typed variants for.
pub fn decode_event_payload(
    e: trace_capnp::observability_event::Reader<'_>,
) -> Result<(String, serde_json::Value)> {
    use trace_capnp::observability_event::payload::Which;

    match e.get_payload().which() {
        Ok(Which::GenericJson(reader)) => {
            let r = reader?;
            let tag = read_text(r.get_type()?);
            let raw = read_text(r.get_payload_json()?);
            let payload: serde_json::Value =
                serde_json::from_str(&raw).unwrap_or(serde_json::Value::String(raw));
            Ok((tag, payload))
        }
        Ok(Which::SqlQuery(_)) => Ok(("sql".to_string(), serde_json::Value::Null)),
        Ok(Which::LogLine(_)) => Ok(("log".to_string(), serde_json::Value::Null)),
        Ok(Which::CacheOp(_)) => Ok(("cache".to_string(), serde_json::Value::Null)),
        Ok(Which::HttpCall(_)) => Ok(("http".to_string(), serde_json::Value::Null)),
        Ok(Which::RedisOp(_)) => Ok(("redis".to_string(), serde_json::Value::Null)),
        Ok(Which::EventDispatched(_)) => Ok(("event".to_string(), serde_json::Value::Null)),
        Ok(Which::JobDispatched(_)) => Ok(("job".to_string(), serde_json::Value::Null)),
        Ok(Which::MailSent(_)) => Ok(("mail".to_string(), serde_json::Value::Null)),
        Ok(Which::NPlusOne(_)) => Ok(("n_plus_one".to_string(), serde_json::Value::Null)),
        Ok(Which::RequestResolved(_)) => {
            Ok(("request_resolved".to_string(), serde_json::Value::Null))
        }
        Err(_) => Ok(("<unknown>".to_string(), serde_json::Value::Null)),
    }
}

pub fn decode_call_site(
    e: trace_capnp::observability_event::Reader<'_>,
) -> Result<Option<CallSiteJson>> {
    use trace_capnp::observability_event::payload::Which;
    if let Ok(Which::GenericJson(reader)) = e.get_payload().which() {
        let raw = read_text(reader?.get_call_site_json()?);
        if raw.is_empty() {
            return Ok(None);
        }
        let v: serde_json::Value = match serde_json::from_str(&raw) {
            Ok(v) => v,
            Err(_) => return Ok(None),
        };
        let obj = match v.as_object() {
            Some(o) => o,
            None => return Ok(None),
        };
        let file = obj
            .get("file")
            .and_then(|v| v.as_str())
            .unwrap_or("")
            .to_string();
        if file.is_empty() {
            return Ok(None);
        }
        let line = obj.get("line").and_then(|v| v.as_u64()).unwrap_or(0) as u32;
        let snippet: Vec<SnippetLineJson> = obj
            .get("snippet")
            .and_then(|v| v.as_array())
            .map(|arr| {
                arr.iter()
                    .filter_map(|s| {
                        let o = s.as_object()?;
                        Some(SnippetLineJson {
                            number: o.get("number").and_then(|v| v.as_u64()).unwrap_or(0) as u32,
                            source: o
                                .get("source")
                                .and_then(|v| v.as_str())
                                .unwrap_or("")
                                .to_string(),
                        })
                    })
                    .collect()
            })
            .unwrap_or_default();
        let frame_stack: Vec<u32> = obj
            .get("frame_stack")
            .and_then(|v| v.as_array())
            .map(|arr| {
                arr.iter()
                    .filter_map(|n| n.as_u64().map(|x| x as u32))
                    .collect()
            })
            .unwrap_or_default();
        let stack: Vec<StackFrameJson> = obj
            .get("stack")
            .and_then(|v| v.as_array())
            .map(|arr| {
                arr.iter()
                    .filter_map(|s| {
                        let o = s.as_object()?;
                        Some(StackFrameJson {
                            file: o
                                .get("file")
                                .and_then(|v| v.as_str())
                                .unwrap_or("")
                                .to_string(),
                            line: o.get("line").and_then(|v| v.as_u64()).unwrap_or(0) as u32,
                            function: o
                                .get("function")
                                .and_then(|v| v.as_str())
                                .unwrap_or("")
                                .to_string(),
                        })
                    })
                    .collect()
            })
            .unwrap_or_default();
        return Ok(Some(CallSiteJson {
            file,
            line,
            snippet,
            frame_stack,
            stack,
        }));
    }
    Ok(None)
}

pub fn decode_event(e: trace_capnp::observability_event::Reader<'_>) -> Result<EventJson> {
    let (type_tag, payload) = decode_event_payload(e)?;
    Ok(EventJson {
        id: e.get_id(),
        at_micros: e.get_at_micros(),
        in_frame_id: e.get_in_frame_id(),
        type_tag,
        payload,
        user_call_site: decode_call_site(e)?,
    })
}

/// Decode the whole trace into JSON-friendly structs.
pub fn decode_trace(trace: &Trace, id: &str) -> Result<TraceJson> {
    let root = trace.root()?;
    let meta = decode_meta(root.get_meta()?)?;
    let frames = root
        .get_frames()?
        .iter()
        .map(decode_frame)
        .collect::<Result<Vec<_>>>()?;
    let observability_events = root
        .get_observability_events()?
        .iter()
        .map(decode_event)
        .collect::<Result<Vec<_>>>()?;
    Ok(TraceJson {
        id: id.to_string(),
        meta,
        frames,
        observability_events,
    })
}
