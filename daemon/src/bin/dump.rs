#![forbid(unsafe_code)]
#![deny(warnings)]

//! `periscope-dump <trace.cptrace>` — print the meta block + every frame.
//!
//! `--json` emits machine-readable JSON for AI agents (Claude Code, Cursor,
//! Codex, etc.) to consume via shell-out today. Phase 6 adds an HTTP API
//! and Phase 11 adds an MCP server for richer integration.

use std::path::PathBuf;

use anyhow::Result;
use clap::Parser;
use serde::Serialize;

use periscope_daemon::trace::Trace;
use periscope_daemon::trace_capnp;

#[derive(Parser, Debug)]
#[command(version, about = "dump a periscope trace as text or JSON")]
struct Args {
    /// Path to a `.cptrace` file.
    trace: PathBuf,

    /// Maximum number of frames to print (0 = all).
    #[arg(long, default_value_t = 0)]
    limit: usize,

    /// Emit JSON instead of human-readable text. Designed for AI agents
    /// and other tooling — schema is documented in docs/AI_ACCESS.md.
    #[arg(long)]
    json: bool,
}

#[derive(Serialize)]
struct TraceJson {
    meta: MetaJson,
    frames: Vec<FrameJson>,
    observability_events: Vec<EventJson>,
}

#[derive(Serialize)]
struct EventJson {
    id: u32,
    at_micros: u64,
    in_frame_id: u32,
    #[serde(rename = "type")]
    type_tag: String,
    payload: serde_json::Value,
    #[serde(skip_serializing_if = "Option::is_none")]
    user_call_site: Option<CallSiteJson>,
}

#[derive(Serialize)]
struct CallSiteJson {
    file: String,
    line: u32,
    snippet: Vec<SnippetLineJson>,
    frame_stack: Vec<u32>,
}

#[derive(Serialize)]
struct SnippetLineJson {
    number: u32,
    source: String,
}

#[derive(Serialize)]
struct MetaJson {
    php_version: String,
    periscope_version: String,
    sapi: String,
    entry_point: String,
    working_dir: String,
    pid: u32,
    started_at_unix_micros: u64,
    duration_micros: u64,
}

#[derive(Serialize)]
struct FrameJson {
    id: u32,
    parent_id: u32,
    function: String,
    file: String,
    line: u32,
    enter_micros: u64,
    exit_micros: u64,
    duration_micros: u64,
    depth: u32,
    args: Option<String>,
    return_value: Option<String>,
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

/// Pull `(type, payload)` out of an ObservabilityEvent. v1 emits everything
/// as the `genericJson` variant — Phase 6 will switch to typed variants and
/// this fn will need extending. Until then, anything other than genericJson
/// surfaces as `{"type":"<unhandled>","payload":null}` so the caller can see
/// it but doesn't crash.
fn decode_event_payload(
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
        Ok(Which::RequestResolved(_)) => Ok(("request_resolved".to_string(), serde_json::Value::Null)),
        Err(_) => Ok(("<unknown>".to_string(), serde_json::Value::Null)),
    }
}

fn decode_call_site(
    e: trace_capnp::observability_event::Reader<'_>,
) -> Result<Option<CallSiteJson>> {
    // v1: call site is stored as raw JSON inside the GenericJsonEvent variant
    // (the userCallSite Cap'n Proto struct is reserved for Phase 6+ typed
    // variants). Parse it here on read.
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
        let file = obj.get("file").and_then(|v| v.as_str()).unwrap_or("").to_string();
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
                            source: o.get("source").and_then(|v| v.as_str()).unwrap_or("").to_string(),
                        })
                    })
                    .collect()
            })
            .unwrap_or_default();
        let frame_stack: Vec<u32> = obj
            .get("frame_stack")
            .and_then(|v| v.as_array())
            .map(|arr| arr.iter().filter_map(|n| n.as_u64().map(|x| x as u32)).collect())
            .unwrap_or_default();
        return Ok(Some(CallSiteJson { file, line, snippet, frame_stack }));
    }
    Ok(None)
}

fn main() -> Result<()> {
    let args = Args::parse();
    let trace = Trace::open(&args.trace)?;
    let root = trace.root()?;
    let meta = root.get_meta()?;
    let frames = root.get_frames()?;
    let events = root.get_observability_events()?;

    let total = frames.len() as usize;
    let limit = if args.limit == 0 || args.limit > total {
        total
    } else {
        args.limit
    };

    if args.json {
        let frames_json: Vec<FrameJson> = frames
            .iter()
            .take(limit)
            .map(|f| -> Result<FrameJson> {
                let function = read_text(f.get_function()?);
                let file = read_text(f.get_file()?);
                let args_summary = f
                    .get_args()?
                    .iter()
                    .next()
                    .and_then(|a| a.get_value().ok().and_then(opaque_or_empty));
                let return_value = f.get_return_value().ok().and_then(opaque_or_empty);
                let dur = f.get_exit_micros().saturating_sub(f.get_enter_micros());
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
                    args: args_summary,
                    return_value,
                })
            })
            .collect::<Result<_>>()?;

        let events_json: Vec<EventJson> = events
            .iter()
            .map(|e| -> Result<EventJson> {
                let (type_tag, payload) = decode_event_payload(e)?;
                Ok(EventJson {
                    id: e.get_id(),
                    at_micros: e.get_at_micros(),
                    in_frame_id: e.get_in_frame_id(),
                    type_tag,
                    payload,
                    user_call_site: decode_call_site(e)?,
                })
            })
            .collect::<Result<_>>()?;

        let out = TraceJson {
            meta: MetaJson {
                php_version: read_text(meta.get_php_version()?),
                periscope_version: read_text(meta.get_periscope_version()?),
                sapi: read_text(meta.get_sapi()?),
                entry_point: read_text(meta.get_entry_point()?),
                working_dir: read_text(meta.get_working_dir()?),
                pid: meta.get_pid(),
                started_at_unix_micros: meta.get_started_at_unix_micros(),
                duration_micros: meta.get_duration_micros(),
            },
            frames: frames_json,
            observability_events: events_json,
        };
        println!("{}", serde_json::to_string_pretty(&out)?);
        return Ok(());
    }

    println!("trace: {}", args.trace.display());
    println!("  php           {}", read_text(meta.get_php_version()?));
    println!("  periscope     {}", read_text(meta.get_periscope_version()?));
    println!("  sapi          {}", read_text(meta.get_sapi()?));
    println!("  entry         {}", read_text(meta.get_entry_point()?));
    println!("  cwd           {}", read_text(meta.get_working_dir()?));
    println!("  pid           {}", meta.get_pid());
    println!("  started_at_us {}", meta.get_started_at_unix_micros());
    println!("  duration_us   {}", meta.get_duration_micros());
    println!();

    println!("frames ({} total, showing {}):", total, limit);
    for f in frames.iter().take(limit) {
        let function = read_text(f.get_function()?);
        let file = read_text(f.get_file()?);
        let dur_us = f.get_exit_micros().saturating_sub(f.get_enter_micros());
        println!(
            "  #{:<4} parent=#{:<4} d={:<2} {:>8}us  {}{}",
            f.get_id(),
            f.get_parent_id(),
            f.get_depth(),
            dur_us,
            function,
            if file.is_empty() {
                String::new()
            } else {
                format!("  ({}:{})", file, f.get_line())
            }
        );

        if let Some(args) = f
            .get_args()?
            .iter()
            .next()
            .and_then(|a| a.get_value().ok().and_then(opaque_or_empty))
        {
            if !args.is_empty() {
                println!("        args = {}", args);
            }
        }
        if let Some(ret) = f.get_return_value().ok().and_then(opaque_or_empty) {
            if !ret.is_empty() {
                println!("        ret  = {}", ret);
            }
        }
    }

    println!();
    println!("observability events ({} total):", events.len());
    for e in events.iter() {
        let (tag, payload) = decode_event_payload(e)?;
        let cs = decode_call_site(e)?;
        let cs_str = match &cs {
            Some(c) if !c.file.is_empty() => format!("  ({}:{})", c.file, c.line),
            _ => String::new(),
        };
        let payload_preview = match &payload {
            serde_json::Value::Null => String::new(),
            v => {
                let s = v.to_string();
                if s.len() > 120 { format!("  {}…", &s[..117]) } else { format!("  {}", s) }
            }
        };
        println!(
            "  #{:<4} @{:>10}us  frame=#{:<4}  {}{}{}",
            e.get_id(),
            e.get_at_micros(),
            e.get_in_frame_id(),
            tag,
            cs_str,
            payload_preview,
        );
    }

    Ok(())
}
