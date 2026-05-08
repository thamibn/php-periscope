//! Debug Adapter Protocol server.
//!
//! v1 scope: replay-only. The session is opened against a completed
//! `.cptrace` file (passed via `launch.tracePath`); the daemon serves
//! stack/variables/scopes from the recorded snapshots and supports
//! `next`, `stepIn`, `stepOut`, `stepBack`, `reverseContinue`,
//! `continue`. Live-pause-on-breakpoint is deferred to Phase 8 — needs
//! the C extension to add a pause primitive over the Unix socket.
//!
//! Wire format: standard DAP framing on stdio
//! (`Content-Length: <n>\r\n\r\n<body>`), JSON body.

use std::collections::HashMap;
use std::path::PathBuf;
use std::sync::Arc;

use anyhow::{Context, Result};
use serde::{Deserialize, Serialize};
use tokio::io::{AsyncRead, AsyncReadExt, AsyncWrite, AsyncWriteExt, BufReader};
use tokio::sync::Mutex;

use crate::trace::Trace;
use crate::trace_view::{self, FrameJson, TraceJson};

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

pub struct DapServer<R, W> {
    reader: BufReader<R>,
    writer: W,
    out_seq: u64,
    state: Arc<Mutex<SessionState>>,
}

struct SessionState {
    trace: Option<TraceJson>,
    cursor_frame_id: u32,
    /// `frame_id` → 1-based stack index used to invent variable references.
    var_refs: HashMap<u32, FrameJson>,
    next_var_ref: i64,
}

impl Default for SessionState {
    fn default() -> Self {
        Self {
            trace: None,
            cursor_frame_id: 0,
            var_refs: HashMap::new(),
            next_var_ref: 1000,
        }
    }
}

impl<R, W> DapServer<R, W>
where
    R: AsyncRead + Unpin,
    W: AsyncWrite + Unpin,
{
    pub fn new(reader: R, writer: W) -> Self {
        Self {
            reader: BufReader::new(reader),
            writer,
            out_seq: 1,
            state: Arc::new(Mutex::new(SessionState::default())),
        }
    }

    pub async fn run(mut self) -> Result<()> {
        loop {
            match self.read_message().await? {
                Some(msg) => self.handle(msg).await?,
                None => break Ok(()),
            }
        }
    }

    async fn read_message(&mut self) -> Result<Option<DapMessage>> {
        let mut content_length: Option<usize> = None;
        let mut header_buf = Vec::new();

        loop {
            header_buf.clear();
            // Read one header line.
            let n = read_line(&mut self.reader, &mut header_buf).await?;
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
        self.reader.read_exact(&mut body).await?;
        let msg: DapMessage = serde_json::from_slice(&body)
            .with_context(|| format!("parsing DAP body: {}", String::from_utf8_lossy(&body)))?;
        Ok(Some(msg))
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
            "continue" => self.ack_step(request_seq, "continue").await,
            "next" => self.on_step(request_seq, "next", StepKind::Next).await,
            "stepIn" => self.on_step(request_seq, "stepIn", StepKind::In).await,
            "stepOut" => self.on_step(request_seq, "stepOut", StepKind::Out).await,
            "stepBack" => self.on_step(request_seq, "stepBack", StepKind::Back).await,
            "reverseContinue" => self.ack_step(request_seq, "reverseContinue").await,
            "evaluate" => self.on_evaluate(request_seq, args).await,
            "disconnect" => {
                self.write_response(request_seq, "disconnect", true, None, None)
                    .await?;
                Ok(())
            }
            "setBreakpoints" => {
                self.write_response(
                    request_seq,
                    "setBreakpoints",
                    true,
                    Some(serde_json::json!({"breakpoints": []})),
                    None,
                )
                .await
            }
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
                let decoded = trace_view::decode_trace(&trace, &id)?;
                let mut state = self.state.lock().await;
                state.cursor_frame_id =
                    decoded.frames.first().map(|f| f.id).unwrap_or(0);
                state.trace = Some(decoded);
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
        let trace = match &state.trace {
            Some(t) => t,
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

        let frames = stack_for(trace, state.cursor_frame_id);
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
        drop(state);
        self.write_response(request_seq, "stackTrace", true, Some(body), None)
            .await
    }

    async fn on_scopes(&mut self, request_seq: u64, args: serde_json::Value) -> Result<()> {
        let frame_id = args
            .get("frameId")
            .and_then(|v| v.as_u64())
            .unwrap_or(0) as u32;
        let mut state = self.state.lock().await;
        let frame = match state
            .trace
            .as_ref()
            .and_then(|t| t.frames.iter().find(|f| f.id == frame_id))
            .cloned()
        {
            Some(f) => f,
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
        state.var_refs.insert(var_ref as u32, frame.clone());
        drop(state);

        let body = serde_json::json!({
            "scopes": [
                {
                    "name": "Locals",
                    "variablesReference": var_ref,
                    "expensive": false,
                    "presentationHint": "locals",
                    "source": {"path": frame.file}
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
        let frame = match state.var_refs.get(&var_ref).cloned() {
            Some(f) => f,
            None => {
                drop(state);
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
        drop(state);

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

    async fn ack_step(&mut self, request_seq: u64, command: &str) -> Result<()> {
        self.write_response(request_seq, command, true, None, None)
            .await?;
        self.write_event(
            "continued",
            Some(serde_json::json!({"threadId": 1, "allThreadsContinued": true})),
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
        let new_id = match &state.trace {
            None => 0,
            Some(t) => step_cursor(t, state.cursor_frame_id, kind),
        };
        state.cursor_frame_id = new_id;
        state.var_refs.clear();
        drop(state);
        self.write_response(request_seq, command, true, None, None)
            .await?;
        let reason = match kind {
            StepKind::Next | StepKind::In | StepKind::Out => "step",
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

#[derive(Copy, Clone)]
enum StepKind {
    Next,
    In,
    Out,
    Back,
}

fn stack_for(trace: &TraceJson, cursor_frame_id: u32) -> Vec<FrameJson> {
    let lookup: HashMap<u32, &FrameJson> = trace.frames.iter().map(|f| (f.id, f)).collect();
    let mut out = vec![];
    let mut next = cursor_frame_id;
    while next != 0 {
        match lookup.get(&next) {
            Some(f) => {
                out.push((*f).clone());
                next = f.parent_id;
            }
            None => break,
        }
    }
    out
}

fn step_cursor(trace: &TraceJson, cursor_frame_id: u32, kind: StepKind) -> u32 {
    let frames = &trace.frames;
    if frames.is_empty() {
        return 0;
    }
    let idx = frames
        .iter()
        .position(|f| f.id == cursor_frame_id)
        .unwrap_or(0);
    match kind {
        StepKind::Next => frames.get(idx + 1).map(|f| f.id).unwrap_or(cursor_frame_id),
        StepKind::In => frames.get(idx + 1).map(|f| f.id).unwrap_or(cursor_frame_id),
        StepKind::Out => {
            let parent = frames[idx].parent_id;
            if parent == 0 {
                cursor_frame_id
            } else {
                parent
            }
        }
        StepKind::Back => {
            if idx == 0 {
                cursor_frame_id
            } else {
                frames[idx - 1].id
            }
        }
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
