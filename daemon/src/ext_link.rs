//! Extension ↔ daemon Unix domain socket protocol.
//!
//! Phase 6 lays the wire down. The C extension's `periscope_daemon_link`
//! is the client; this module is the server. v1 message types:
//!
//!   ext  → daemon: {"type":"hello", "pid":..., "version":"..."}
//!   ext  → daemon: {"type":"request_started", "request_id":"...", "trace_path":"..."}
//!   ext  → daemon: {"type":"request_finished", "request_id":"...", "trace_path":"..."}
//!
//! Live breakpoint coordination (`set_breakpoints`, `continue`,
//! `breakpoint_hit`) is reserved for Phase 8 — landing the wire here
//! means Phase 8 only adds the C-side pause primitive, no new transport.
//!
//! Frames are length-prefixed JSON: 4-byte big-endian length, then UTF-8
//! body. Tiny, infrequent, easy to debug with `nc -U`.

use std::path::{Path, PathBuf};
use std::sync::Arc;

use anyhow::{Context, Result};
use serde::{Deserialize, Serialize};
use tokio::io::{AsyncReadExt, AsyncWriteExt};
use tokio::net::{UnixListener, UnixStream};
use tokio::sync::broadcast;

#[derive(Debug, Clone, Serialize, Deserialize)]
#[serde(tag = "type", rename_all = "snake_case")]
pub enum ExtMessage {
    Hello {
        pid: u32,
        version: String,
    },
    /// One message per request, fired at RSHUTDOWN. Tells subscribed UI
    /// tabs that a new trace is on disk and ready to read via /api/traces.
    /// Per-frame streaming is intentionally NOT in v1 — for typical 50-200ms
    /// HTTP requests the human reaction loop is too slow to react to live
    /// frames; one ping at the end is the right granularity. Long-running
    /// CLI/queue scenarios get an opt-in `periscope.live_stream=1` flag in
    /// v1.1.
    RequestFinished {
        request_id: String,
        trace_path: String,
        duration_micros: u64,
    },
    /// Reserved for Phase 8b live breakpoint coordination.
    BreakpointHit {
        frame_id: u32,
        file: String,
        line: u32,
    },
}

#[derive(Debug, Clone, Serialize, Deserialize)]
#[serde(tag = "type", rename_all = "snake_case")]
pub enum DaemonMessage {
    Ack,
    /// Reserved for Phase 8.
    SetBreakpoints { file: String, lines: Vec<u32> },
    /// Reserved for Phase 8.
    Continue,
}

#[derive(Clone)]
pub struct LinkBus {
    sender: broadcast::Sender<ExtMessage>,
}

impl LinkBus {
    pub fn new() -> Self {
        let (sender, _) = broadcast::channel(256);
        Self { sender }
    }

    pub fn subscribe(&self) -> broadcast::Receiver<ExtMessage> {
        self.sender.subscribe()
    }
}

impl Default for LinkBus {
    fn default() -> Self {
        Self::new()
    }
}

pub async fn serve(path: PathBuf, bus: Arc<LinkBus>) -> Result<()> {
    // Best-effort: remove any stale socket file so we can rebind.
    let _ = tokio::fs::remove_file(&path).await;
    if let Some(parent) = path.parent() {
        if !parent.as_os_str().is_empty() {
            tokio::fs::create_dir_all(parent)
                .await
                .with_context(|| format!("creating socket dir {}", parent.display()))?;
        }
    }
    let listener = UnixListener::bind(&path)
        .with_context(|| format!("binding unix socket at {}", path.display()))?;
    tracing::info!(path = %path.display(), "ext-link listening");

    loop {
        match listener.accept().await {
            Ok((stream, _addr)) => {
                let bus = bus.clone();
                tokio::spawn(async move {
                    if let Err(e) = handle_client(stream, bus).await {
                        tracing::warn!(error=?e, "ext-link client errored");
                    }
                });
            }
            Err(e) => {
                tracing::warn!(error=?e, "accept failed; retrying");
                tokio::time::sleep(std::time::Duration::from_millis(50)).await;
            }
        }
    }
}

async fn handle_client(mut stream: UnixStream, bus: Arc<LinkBus>) -> Result<()> {
    loop {
        let msg = match read_frame(&mut stream).await? {
            Some(m) => m,
            None => return Ok(()),
        };
        tracing::debug!(?msg, "ext-link recv");
        let _ = bus.sender.send(msg.clone());
        // For now always ack; Phase 8 will return SetBreakpoints/Continue here.
        write_frame(&mut stream, &DaemonMessage::Ack).await?;
    }
}

async fn read_frame(stream: &mut UnixStream) -> Result<Option<ExtMessage>> {
    let mut len_buf = [0u8; 4];
    if let Err(e) = stream.read_exact(&mut len_buf).await {
        if e.kind() == std::io::ErrorKind::UnexpectedEof {
            return Ok(None);
        }
        return Err(e.into());
    }
    let len = u32::from_be_bytes(len_buf) as usize;
    if len == 0 || len > 1024 * 1024 {
        anyhow::bail!("ext-link frame length out of range: {}", len);
    }
    let mut body = vec![0u8; len];
    stream.read_exact(&mut body).await?;
    let msg: ExtMessage = serde_json::from_slice(&body)
        .with_context(|| format!("decoding ext frame: {}", String::from_utf8_lossy(&body)))?;
    Ok(Some(msg))
}

async fn write_frame(stream: &mut UnixStream, msg: &DaemonMessage) -> Result<()> {
    let body = serde_json::to_vec(msg)?;
    let len = (body.len() as u32).to_be_bytes();
    stream.write_all(&len).await?;
    stream.write_all(&body).await?;
    stream.flush().await?;
    Ok(())
}

/// Default Unix socket path. The C extension agrees on this via the
/// `PERISCOPE_DAEMON_SOCKET` environment variable (override) — same default
/// as documented in `docs/ARCHITECTURE.md`.
pub fn default_socket_path() -> PathBuf {
    Path::new("/tmp/periscope/daemon.sock").to_path_buf()
}

#[cfg(test)]
mod tests {
    use super::*;
    use tokio::net::UnixStream as ClientStream;

    #[tokio::test]
    async fn accepts_hello_and_acks() {
        let dir = tempfile::tempdir().unwrap();
        let sock = dir.path().join("daemon.sock");
        let bus = Arc::new(LinkBus::new());
        let mut rx = bus.subscribe();

        let server_sock = sock.clone();
        let server_bus = bus.clone();
        let server = tokio::spawn(async move { serve(server_sock, server_bus).await });

        // Wait for socket to appear.
        for _ in 0..20 {
            if sock.exists() {
                break;
            }
            tokio::time::sleep(std::time::Duration::from_millis(20)).await;
        }

        let mut client = ClientStream::connect(&sock).await.unwrap();
        let hello = ExtMessage::Hello {
            pid: 42,
            version: "test".into(),
        };
        let body = serde_json::to_vec(&hello).unwrap();
        let len = (body.len() as u32).to_be_bytes();
        client.write_all(&len).await.unwrap();
        client.write_all(&body).await.unwrap();
        client.flush().await.unwrap();

        // Read ack.
        let mut len_buf = [0u8; 4];
        client.read_exact(&mut len_buf).await.unwrap();
        let len = u32::from_be_bytes(len_buf) as usize;
        let mut body = vec![0u8; len];
        client.read_exact(&mut body).await.unwrap();
        let ack: DaemonMessage = serde_json::from_slice(&body).unwrap();
        assert!(matches!(ack, DaemonMessage::Ack));

        // Bus should have observed our hello.
        let observed = tokio::time::timeout(std::time::Duration::from_millis(500), rx.recv())
            .await
            .unwrap()
            .unwrap();
        match observed {
            ExtMessage::Hello { pid, .. } => assert_eq!(pid, 42),
            _ => panic!("expected hello"),
        }

        server.abort();
    }
}
