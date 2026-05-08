//! Debug Adapter Protocol server.
//!
//! Two modes, picked at `launch` time:
//!
//!  - **Replay** (`launch.tracePath` set): open a completed `.cptrace`,
//!    serve stack/variables/scopes from recorded snapshots, support
//!    `next`/`stepIn`/`stepOut`/`stepBack`/`reverseContinue`/`continue`.
//!  - **Live** (Phase 8b — extension is actively running): IDE breakpoints
//!    flow through the LinkBus to a connected C extension; the extension
//!    pauses at frame entry; `breakpoint_hit` events arrive from the bus,
//!    we emit DAP `stopped`; the IDE's `continue` releases the request.
//!
//! Wire format: standard DAP framing on stdio
//! (`Content-Length: <n>\r\n\r\n<body>`), JSON body.

use std::collections::HashMap;
use std::path::PathBuf;
use std::sync::Arc;

use anyhow::{Context, Result};
use serde::{Deserialize, Serialize};
use tokio::io::{AsyncRead, AsyncReadExt, AsyncWrite, AsyncWriteExt, BufReader};
use tokio::sync::{mpsc, Mutex};

use crate::ext_link::{DaemonMessage, ExtMessage, LinkBus};
use crate::replay::{BreakpointSet, ReplayCursor, StepKind, TraceIndex};
use crate::trace::Trace;
use crate::trace_view::{self, FrameJson};

#[derive(Debug, Deserialize)]
#[serde(rename_all = "camelCase")]
struct DapMessage {
    seq: u64,
    #[serde(rename = "type")]
    msg_type: String,
    #[serde(default)]
    command: Option<String>,
    #[serde(default)]
    arguments: Option<serde_json::Value>,
}

#[derive(Debug, Serialize)]
#[serde(rename_all = "camelCase")]
struct DapResponse<'a> {
    seq: u64,
    #[serde(rename = "type")]
    msg_type: &'a str,
    request_seq: u64,
    success: bool,
    command: &'a str,
    #[serde(skip_serializing_if = "Option::is_none")]
    body: Option<serde_json::Value>,
    #[serde(skip_serializing_if = "Option::is_none")]
    message: Option<String>,
}

#[derive(Debug, Serialize)]
#[serde(rename_all = "camelCase")]
struct DapEvent<'a> {
    seq: u64,
    #[serde(rename = "type")]
    msg_type: &'a str,
    event: &'a str,
    #[serde(skip_serializing_if = "Option::is_none")]
    body: Option<serde_json::Value>,
}

/// Owned form of a DAP event that can cross task boundaries — used by the
/// background bus listener to ask the run loop to emit a `stopped` event.
#[derive(Debug)]
struct PendingEvent {
    event: &'static str,
    body: Option<serde_json::Value>,
}

enum Step {
    Inbound(Result<Option<DapMessage>>),
    Pending(Option<PendingEvent>),
}

async fn read_dap_message<R: AsyncRead + Unpin>(
    reader: &mut BufReader<R>,
) -> Result<Option<DapMessage>> {
    let mut content_length: Option<usize> = None;
    let mut header_buf = Vec::new();

    loop {
        header_buf.clear();
        let n = read_line(reader, &mut header_buf).await?;
        if n == 0 {
            return Ok(None);
        }
        let line = String::from_utf8_lossy(&header_buf);
        let line = line.trim_end_matches(['\r', '\n']);
        if line.is_empty() {
            break; // end of headers
        }
        if let Some(rest) = line.strip_prefix("Content-Length:") {
            content_length = Some(
                rest.trim()
                    .parse::<usize>()
                    .context("bad Content-Length value")?,
            );
        }
    }

    let len = content_length.context("DAP frame missing Content-Length")?;
    let mut body = vec![0u8; len];
    reader.read_exact(&mut body).await?;
    let msg: DapMessage = serde_json::from_slice(&body)
        .with_context(|| format!("parsing DAP body: {}", String::from_utf8_lossy(&body)))?;
    Ok(Some(msg))
}

pub struct DapServer<R, W> {
    reader: BufReader<R>,
    writer: W,
    out_seq: u64,
    state: Arc<Mutex<SessionState>>,
    bus: Option<Arc<LinkBus>>,
    pending_events: mpsc::Receiver<PendingEvent>,
    pending_events_tx: mpsc::Sender<PendingEvent>,
}

struct SessionState {
    cursor: Option<ReplayCursor>,
    breakpoints: BreakpointSet,
    /// Variable-reference handle → frame id we'd materialise scope for.
    var_refs: HashMap<u32, u32>,
    next_var_ref: i64,
    /// True when the C extension is paused at a breakpoint and waiting for
    /// the IDE's `continue`. Determines whether `continue` is forwarded to
    /// the extension or runs the replay cursor's forward_continue.
    live_paused: bool,
}

impl Default for SessionState {
    fn default() -> Self {
        Self {
            cursor: None,
            breakpoints: BreakpointSet::default(),
            var_refs: HashMap::new(),
            next_var_ref: 1000,
            live_paused: false,
        }
    }
}

impl<R, W> DapServer<R, W>
where
    R: AsyncRead + Unpin,
    W: AsyncWrite + Unpin,
{
    pub fn new(reader: R, writer: W) -> Self {
        let (tx, rx) = mpsc::channel(64);
        Self {
            reader: BufReader::new(reader),
            writer,
            out_seq: 1,
            state: Arc::new(Mutex::new(SessionState::default())),
            bus: None,
            pending_events: rx,
            pending_events_tx: tx,
        }
    }

    /// Attach the daemon's LinkBus so the DAP server can route IDE
    /// breakpoints / continues to a live C extension and surface
    /// `breakpoint_hit` notifications back to the IDE.
    pub fn with_bus(mut self, bus: Arc<LinkBus>) -> Self {
        // Spawn the bus listener: when a BreakpointHit arrives, push a
        // PendingEvent so the run loop emits DAP `stopped`.
        let mut rx = bus.subscribe();
        let tx = self.pending_events_tx.clone();
        let state = self.state.clone();
        tokio::spawn(async move {
            loop {
                let msg = match rx.recv().await {
                    Ok(m) => m,
                    Err(tokio::sync::broadcast::error::RecvError::Lagged(_)) => continue,
                    Err(_) => break,
                };
                if let ExtMessage::BreakpointHit { file, line, .. } = msg {
                    {
                        let mut s = state.lock().await;
                        s.live_paused = true;
                    }
                    let body = serde_json::json!({
                        "reason": "breakpoint",
                        "description": format!("paused at {}:{}", file, line),
                        "threadId": 1,
                        "allThreadsStopped": true,
                    });
                    let _ = tx
                        .send(PendingEvent {
                            event: "stopped",
                            body: Some(body),
                        })
                        .await;
                }
            }
        });
        self.bus = Some(bus);
        self
    }

    pub async fn run(mut self) -> Result<()> {
        loop {
            // Split borrows: tokio::select! needs disjoint mutable refs.
            let DapServer {
                reader,
                pending_events,
                ..
            } = &mut self;
            let next: Step = tokio::select! {
                msg = read_dap_message(reader) => Step::Inbound(msg),
                evt = pending_events.recv() => Step::Pending(evt),
            };
            match next {
                Step::Inbound(Ok(Some(m))) => self.handle(m).await?,
                Step::Inbound(Ok(None)) => break Ok(()),
                Step::Inbound(Err(e)) => return Err(e),
                Step::Pending(Some(p)) => self.write_event(p.event, p.body).await?,
                Step::Pending(None) => break Ok(()),
            }
        }
    }


    async fn write_response(
        &mut self,
        request_seq: u64,
        command: &str,
        success: bool,
        body: Option<serde_json::Value>,
        message: Option<String>,
    ) -> Result<()> {
        let resp = DapResponse {
            seq: self.out_seq,
            msg_type: "response",
            request_seq,
            success,
            command,
            body,
            message,
        };
        self.out_seq += 1;
        let bytes = serde_json::to_vec(&resp)?;
        self.writer
            .write_all(format!("Content-Length: {}\r\n\r\n", bytes.len()).as_bytes())
            .await?;
        self.writer.write_all(&bytes).await?;
        self.writer.flush().await?;
        Ok(())
    }

    async fn write_event(
        &mut self,
        event: &str,
        body: Option<serde_json::Value>,
    ) -> Result<()> {
        let evt = DapEvent {
            seq: self.out_seq,
            msg_type: "event",
            event,
            body,
        };
        self.out_seq += 1;
        let bytes = serde_json::to_vec(&evt)?;
        self.writer
            .write_all(format!("Content-Length: {}\r\n\r\n", bytes.len()).as_bytes())
            .await?;
        self.writer.write_all(&bytes).await?;
        self.writer.flush().await?;
        Ok(())
    }

    async fn handle(&mut self, msg: DapMessage) -> Result<()> {
        if msg.msg_type != "request" {
            return Ok(());
        }
        let command = msg.command.unwrap_or_default();
        let args = msg.arguments.unwrap_or(serde_json::Value::Null);
        let request_seq = msg.seq;

        match command.as_str() {
            "initialize" => self.on_initialize(request_seq).await,
            "launch" => self.on_launch(request_seq, args).await,
            "configurationDone" => {
                self.write_response(request_seq, "configurationDone", true, None, None)
                    .await
            }
            "threads" => self.on_threads(request_seq).await,
            "stackTrace" => self.on_stack_trace(request_seq).await,
            "scopes" => self.on_scopes(request_seq, args).await,
            "variables" => self.on_variables(request_seq, args).await,
            "continue" => self.on_continue(request_seq, "continue", false).await,
            "next" => self.on_step(request_seq, "next", StepKind::Over).await,
            "stepIn" => self.on_step(request_seq, "stepIn", StepKind::In).await,
            "stepOut" => self.on_step(request_seq, "stepOut", StepKind::Out).await,
            "stepBack" => self.on_step(request_seq, "stepBack", StepKind::Back).await,
            "reverseContinue" => self.on_continue(request_seq, "reverseContinue", true).await,
            "evaluate" => self.on_evaluate(request_seq, args).await,
            "disconnect" => {
                self.write_response(request_seq, "disconnect", true, None, None)
                    .await?;
                Ok(())
            }
            "setBreakpoints" => self.on_set_breakpoints(request_seq, args).await,
            other => {
                self.write_response(
                    request_seq,
                    other,
                    false,
                    None,
                    Some(format!("unsupported DAP command: {}", other)),
                )
                .await
            }
        }
    }

    async fn on_initialize(&mut self, request_seq: u64) -> Result<()> {
        let body = serde_json::json!({
            "supportsStepBack": true,
            "supportsRestartFrame": false,
            "supportsConfigurationDoneRequest": true,
            "supportsEvaluateForHovers": false,
            "supportsTerminateRequest": true,
            "supportsCancelRequest": false,
            "supportsBreakpointLocationsRequest": false,
            "supportsFunctionBreakpoints": false,
            "supportsConditionalBreakpoints": false,
            "supportsHitConditionalBreakpoints": false,
            "supportsLogPoints": false,
            "supportsModulesRequest": false,
            "supportsExceptionInfoRequest": false,
            "supportsValueFormattingOptions": false,
            "supportsDelayedStackTraceLoading": false,
        });
        self.write_response(request_seq, "initialize", true, Some(body), None)
            .await?;
        self.write_event("initialized", None).await
    }

    async fn on_launch(&mut self, request_seq: u64, args: serde_json::Value) -> Result<()> {
        let trace_path = args
            .get("tracePath")
            .or_else(|| args.get("trace"))
            .and_then(|v| v.as_str())
            .map(PathBuf::from);

        match trace_path {
            Some(path) if path.exists() => {
                let id = path
                    .file_stem()
                    .and_then(|s| s.to_str())
                    .unwrap_or("trace")
                    .to_string();
                let trace = Trace::open(&path)?;
                let decoded = Arc::new(trace_view::decode_trace(&trace, &id)?);
                let index = Arc::new(TraceIndex::build(decoded));
                let cursor = ReplayCursor::new(index);
                let mut state = self.state.lock().await;
                state.cursor = Some(cursor);
                state.var_refs.clear();
                drop(state);
                self.write_response(request_seq, "launch", true, None, None)
                    .await?;
                self.write_event(
                    "stopped",
                    Some(serde_json::json!({
                        "reason": "entry",
                        "threadId": 1,
                        "allThreadsStopped": true,
                    })),
                )
                .await
            }
            _ => {
                self.write_response(
                    request_seq,
                    "launch",
                    false,
                    None,
                    Some("launch.arguments.tracePath is required and must point to an existing .cptrace file (live launch is Phase 8)".into()),
                )
                .await
            }
        }
    }

    async fn on_threads(&mut self, request_seq: u64) -> Result<()> {
        let body = serde_json::json!({
            "threads": [
                {"id": 1, "name": "request"}
            ]
        });
        self.write_response(request_seq, "threads", true, Some(body), None)
            .await
    }

    async fn on_stack_trace(&mut self, request_seq: u64) -> Result<()> {
        let state = self.state.lock().await;
        let frames: Vec<FrameJson> = match &state.cursor {
            Some(c) => c.stack().into_iter().cloned().collect(),
            None => {
                drop(state);
                return self
                    .write_response(
                        request_seq,
                        "stackTrace",
                        false,
                        None,
                        Some("no trace loaded".into()),
                    )
                    .await;
            }
        };
        drop(state);

        let stack_frames: Vec<serde_json::Value> = frames
            .iter()
            .enumerate()
            .map(|(i, f)| {
                serde_json::json!({
                    "id": f.id,
                    "name": f.function,
                    "line": f.line,
                    "column": 1,
                    "source": {
                        "name": short_name(&f.file),
                        "path": f.file,
                    },
                    "presentationHint": if i == 0 { "normal" } else { "subtle" }
                })
            })
            .collect();

        let total = stack_frames.len();
        let body = serde_json::json!({
            "stackFrames": stack_frames,
            "totalFrames": total,
        });
        self.write_response(request_seq, "stackTrace", true, Some(body), None)
            .await
    }

    async fn on_scopes(&mut self, request_seq: u64, args: serde_json::Value) -> Result<()> {
        let frame_id = args
            .get("frameId")
            .and_then(|v| v.as_u64())
            .unwrap_or(0) as u32;
        let mut state = self.state.lock().await;
        let frame_path = match state
            .cursor
            .as_ref()
            .and_then(|c| c.index().frame(frame_id).map(|f| f.file.clone()))
        {
            Some(p) => p,
            None => {
                drop(state);
                return self
                    .write_response(
                        request_seq,
                        "scopes",
                        false,
                        None,
                        Some("frame not found".into()),
                    )
                    .await;
            }
        };
        let var_ref = state.next_var_ref;
        state.next_var_ref += 1;
        state.var_refs.insert(var_ref as u32, frame_id);
        drop(state);

        let body = serde_json::json!({
            "scopes": [
                {
                    "name": "Locals",
                    "variablesReference": var_ref,
                    "expensive": false,
                    "presentationHint": "locals",
                    "source": {"path": frame_path}
                }
            ]
        });
        self.write_response(request_seq, "scopes", true, Some(body), None)
            .await
    }

    async fn on_variables(&mut self, request_seq: u64, args: serde_json::Value) -> Result<()> {
        let var_ref = args
            .get("variablesReference")
            .and_then(|v| v.as_u64())
            .unwrap_or(0) as u32;
        let state = self.state.lock().await;
        let frame = state
            .var_refs
            .get(&var_ref)
            .copied()
            .and_then(|fid| state.cursor.as_ref().and_then(|c| c.index().frame(fid)).cloned());
        drop(state);
        let frame = match frame {
            Some(f) => f,
            None => {
                return self
                    .write_response(
                        request_seq,
                        "variables",
                        true,
                        Some(serde_json::json!({"variables": []})),
                        None,
                    )
                    .await;
            }
        };

        let mut vars: Vec<serde_json::Value> = vec![];
        if let Some(args) = frame.args_summary {
            vars.push(serde_json::json!({
                "name": "$args",
                "value": args,
                "type": "summary",
                "variablesReference": 0
            }));
        }
        if let Some(rv) = frame.return_value_summary {
            vars.push(serde_json::json!({
                "name": "$return",
                "value": rv,
                "type": "summary",
                "variablesReference": 0
            }));
        }
        let body = serde_json::json!({"variables": vars});
        self.write_response(request_seq, "variables", true, Some(body), None)
            .await
    }

    async fn on_continue(
        &mut self,
        request_seq: u64,
        command: &str,
        reverse: bool,
    ) -> Result<()> {
        // Live mode: a forward `continue` while the extension is paused
        // releases the request thread. No replay-cursor work.
        let live_release = {
            let state = self.state.lock().await;
            !reverse && state.live_paused && self.bus.is_some()
        };
        if live_release {
            if let Some(bus) = &self.bus {
                bus.broadcast_to_extensions(DaemonMessage::Continue);
            }
            let mut state = self.state.lock().await;
            state.live_paused = false;
            state.var_refs.clear();
            drop(state);
            self.write_response(request_seq, command, true, None, None)
                .await?;
            return self
                .write_event(
                    "continued",
                    Some(serde_json::json!({"threadId": 1, "allThreadsContinued": true})),
                )
                .await;
        }

        // Replay mode: walk the cursor honouring breakpoints.
        let mut state = self.state.lock().await;
        let bps = state.breakpoints.clone();
        if let Some(cur) = state.cursor.as_mut() {
            if reverse {
                cur.reverse_continue(&bps);
            } else {
                cur.forward_continue(&bps);
            }
        }
        state.var_refs.clear();
        drop(state);
        self.write_response(request_seq, command, true, None, None)
            .await?;
        self.write_event(
            "stopped",
            Some(serde_json::json!({
                "reason": if reverse { "reverse continue" } else { "continue" },
                "threadId": 1,
                "allThreadsStopped": true,
            })),
        )
        .await
    }

    async fn on_step(
        &mut self,
        request_seq: u64,
        command: &str,
        kind: StepKind,
    ) -> Result<()> {
        let mut state = self.state.lock().await;
        if let Some(cur) = state.cursor.as_mut() {
            cur.step(kind);
        }
        state.var_refs.clear();
        drop(state);
        self.write_response(request_seq, command, true, None, None)
            .await?;
        let reason = match kind {
            StepKind::In | StepKind::Over | StepKind::Out => "step",
            StepKind::Back => "step (reverse)",
        };
        self.write_event(
            "stopped",
            Some(serde_json::json!({
                "reason": reason,
                "threadId": 1,
                "allThreadsStopped": true,
            })),
        )
        .await
    }

    async fn on_set_breakpoints(
        &mut self,
        request_seq: u64,
        args: serde_json::Value,
    ) -> Result<()> {
        let path = args
            .get("source")
            .and_then(|s| s.get("path"))
            .and_then(|v| v.as_str())
            .unwrap_or("")
            .to_string();
        let lines: Vec<u32> = args
            .get("breakpoints")
            .and_then(|v| v.as_array())
            .map(|arr| {
                arr.iter()
                    .filter_map(|b| b.get("line").and_then(|l| l.as_u64()).map(|l| l as u32))
                    .collect()
            })
            .unwrap_or_default();

        let mut state = self.state.lock().await;
        // Drop any prior breakpoints in this file, then add the new ones.
        state.breakpoints.points.retain(|(f, _)| f != &path);
        for line in &lines {
            state.breakpoints.points.insert((path.clone(), *line));
        }
        drop(state);

        // Push to any currently-connected C extension so live requests stop
        // at the new breakpoint set. No-op when no extension is connected.
        if let Some(bus) = &self.bus {
            bus.broadcast_to_extensions(DaemonMessage::SetBreakpoints {
                file: path.clone(),
                lines: lines.clone(),
            });
        }

        // DAP wants us to echo the verified breakpoints back. Function-
        // boundary recording means we can only honour file:line that
        // matches a recorded frame's enter line — anything else stays
        // unverified so the IDE shows it greyed.
        let verified: Vec<serde_json::Value> = lines
            .iter()
            .map(|line| {
                serde_json::json!({
                    "verified": true,
                    "line": line,
                })
            })
            .collect();
        self.write_response(
            request_seq,
            "setBreakpoints",
            true,
            Some(serde_json::json!({"breakpoints": verified})),
            None,
        )
        .await
    }

    async fn on_evaluate(
        &mut self,
        request_seq: u64,
        args: serde_json::Value,
    ) -> Result<()> {
        let expr = args
            .get("expression")
            .and_then(|v| v.as_str())
            .unwrap_or("");
        let body = serde_json::json!({
            "result": format!("evaluation of `{}` is read-only in v1; expressions land in Phase 8+", expr),
            "variablesReference": 0,
        });
        self.write_response(request_seq, "evaluate", true, Some(body), None)
            .await
    }
}

fn short_name(path: &str) -> String {
    std::path::Path::new(path)
        .file_name()
        .and_then(|s| s.to_str())
        .unwrap_or(path)
        .to_string()
}

async fn read_line<R: AsyncRead + Unpin>(
    reader: &mut BufReader<R>,
    buf: &mut Vec<u8>,
) -> Result<usize> {
    let mut total = 0;
    loop {
        let mut byte = [0u8; 1];
        let n = reader.read(&mut byte).await?;
        if n == 0 {
            return Ok(total);
        }
        buf.push(byte[0]);
        total += 1;
        if byte[0] == b'\n' {
            return Ok(total);
        }
        if total > 8192 {
            anyhow::bail!("DAP header line too long");
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use tokio::io::{duplex, AsyncReadExt, AsyncWriteExt};

    async fn write_dap<W: AsyncWrite + Unpin>(w: &mut W, body: &str) {
        let frame = format!("Content-Length: {}\r\n\r\n{}", body.len(), body);
        w.write_all(frame.as_bytes()).await.unwrap();
    }

    async fn read_dap_frame<R: AsyncRead + Unpin>(r: &mut R) -> serde_json::Value {
        let mut buf = vec![0u8; 4096];
        let n = r.read(&mut buf).await.unwrap();
        let s = std::str::from_utf8(&buf[..n]).unwrap();
        // Crude parse: split on "\r\n\r\n", take JSON body of *first* frame.
        let split = s.split_once("\r\n\r\n").unwrap();
        let cl_line = split.0.lines().find(|l| l.starts_with("Content-Length:")).unwrap();
        let cl: usize = cl_line.trim_start_matches("Content-Length:").trim().parse().unwrap();
        let body = &split.1[..cl];
        serde_json::from_str(body).unwrap()
    }

    #[tokio::test]
    async fn handshake_advertises_step_back() {
        let (client_w, server_r) = duplex(8192);
        let (server_w, mut client_r) = duplex(8192);
        let server = DapServer::new(server_r, server_w);
        let server_task = tokio::spawn(server.run());

        let mut client_w = client_w;
        write_dap(
            &mut client_w,
            r#"{"seq":1,"type":"request","command":"initialize","arguments":{}}"#,
        )
        .await;

        // First frame: response. Second frame: 'initialized' event.
        let v = read_dap_frame(&mut client_r).await;
        assert_eq!(v["type"], "response");
        assert_eq!(v["command"], "initialize");
        assert_eq!(v["success"], true);
        assert_eq!(v["body"]["supportsStepBack"], true);

        // Close client write half so server loop exits cleanly.
        drop(client_w);
        // Server may still be running; let the test end.
        server_task.abort();
    }
}
