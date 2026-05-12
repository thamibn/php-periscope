//! Extension ↔ daemon Unix domain socket protocol.
//!
//! Bidirectional length-prefixed JSON over a single Unix stream socket per
//! connected PHP request. The C extension is the client; this module is
//! the server.
//!
//! Phase 6 laid the wire down (one-way ext→daemon).
//! Phase 8a added end-of-request fanout to UI WebSocket clients.
//! Phase 8b adds the daemon→ext direction so the daemon can push
//! `set_breakpoints` and `continue` commands; the C extension reads them
//! at frame boundaries to decide whether to pause + resume.
//!
//! Wire format (both directions): 4-byte big-endian length, UTF-8 JSON body.

use std::path::{Path, PathBuf};
use std::sync::{Arc, Mutex};

use anyhow::{Context, Result};
use serde::{Deserialize, Serialize};
use tokio::io::{AsyncReadExt, AsyncWriteExt};
use tokio::net::{
    unix::{OwnedReadHalf, OwnedWriteHalf},
    UnixListener, UnixStream,
};
use tokio::sync::{broadcast, mpsc};

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
    /// frames; one ping at the end is the right granularity.
    RequestFinished {
        request_id: String,
        trace_path: String,
        duration_micros: u64,
    },
    /// Phase 8b: a userland frame matched a registered breakpoint. The
    /// extension is now blocked waiting for `Continue` from the daemon.
    BreakpointHit {
        frame_id: u32,
        file: String,
        line: u32,
    },
}

#[derive(Debug, Clone, Serialize, Deserialize)]
#[serde(tag = "type", rename_all = "snake_case")]
pub enum DaemonMessage {
    /// Phase 8b: replace the extension's per-file breakpoint set. An empty
    /// `lines` list clears all breakpoints in that file.
    SetBreakpoints { file: String, lines: Vec<u32> },
    /// Phase 8b: release the request thread from a breakpoint hit. The
    /// extension blocks-reads waiting for this message after sending
    /// BreakpointHit.
    Continue,
}

/// Phase 9b: messages from a browser tab to the daemon (and fanned out to
/// every other connected tab). Currently the only inbound message is the
/// timeline cursor — dragging the scrubber in one tab moves the cursor in
/// every other tab viewing the same trace.
///
/// Wire format on the WebSocket is JSON text frames; the daemon never sends
/// these to the C extension, only to other UI clients.
#[derive(Debug, Clone, Serialize, Deserialize, PartialEq)]
#[serde(tag = "type", rename_all = "snake_case")]
pub enum UiMessage {
    /// User moved the timeline cursor. `at_micros` is the offset from
    /// `Trace.meta.started_at_unix_micros`. `frame_id` is optional — the
    /// scrubber may resolve to a frame, but a raw time drag has no frame.
    CursorSet {
        trace_id: String,
        at_micros: u64,
        #[serde(default, skip_serializing_if = "Option::is_none")]
        frame_id: Option<u32>,
    },
}

/// Bus shared across all daemon services.
///
/// - `bus_tx`: broadcast channel — every connected ext message is fanned
///   out here. Subscribers: WebSocket UI clients, DAP server.
/// - `outbound`: per-client outbound queue. Each connected ext client
///   gets one mpsc::Sender registered here so the DAP server can push
///   `SetBreakpoints` / `Continue` to it without coupling layers.
pub struct LinkBus {
    bus_tx: broadcast::Sender<ExtMessage>,
    outbound: Mutex<Vec<mpsc::Sender<DaemonMessage>>>,
    /// Phase 9b: UI ↔ UI broadcast (e.g. timeline cursor). Lives on the same
    /// bus type so the WS handler can fan out to every connected tab without
    /// needing a second pipeline.
    ui_tx: broadcast::Sender<UiMessage>,
}

impl LinkBus {
    pub fn new() -> Self {
        let (bus_tx, _) = broadcast::channel(256);
        let (ui_tx, _) = broadcast::channel(256);
        Self {
            bus_tx,
            outbound: Mutex::new(Vec::new()),
            ui_tx,
        }
    }

    pub fn subscribe(&self) -> broadcast::Receiver<ExtMessage> {
        self.bus_tx.subscribe()
    }

    /// Subscribe to UI-broadcast messages (cursor moves, etc.).
    pub fn subscribe_ui(&self) -> broadcast::Receiver<UiMessage> {
        self.ui_tx.subscribe()
    }

    /// Publish a UI message from one tab to every connected tab. Returns the
    /// number of subscribers the message was delivered to (best-effort).
    pub fn publish_ui(&self, msg: UiMessage) -> usize {
        self.ui_tx.send(msg).unwrap_or(0)
    }

    /// Register a per-client outbound channel. Returns the receiver the
    /// per-client writer task drains. Caller owns the receiver.
    pub fn register_outbound(&self) -> mpsc::Receiver<DaemonMessage> {
        let (tx, rx) = mpsc::channel::<DaemonMessage>(64);
        let mut guard = self.outbound.lock().expect("LinkBus outbound poisoned");
        guard.push(tx);
        rx
    }

    /// Send a daemon message to every connected extension client. v1 is
    /// "one live extension per session" so this is effectively a unicast
    /// in practice; multi-pid coordination is post-v1.
    pub fn broadcast_to_extensions(&self, msg: DaemonMessage) {
        let mut guard = self.outbound.lock().expect("LinkBus outbound poisoned");
        // Drop any closed senders so the list doesn't grow unbounded.
        guard.retain(|tx| !tx.is_closed());
        for tx in guard.iter() {
            let _ = tx.try_send(msg.clone());
        }
    }

    /// Number of currently-connected extension clients. Mostly for tests.
    pub fn extension_count(&self) -> usize {
        let guard = self.outbound.lock().expect("LinkBus outbound poisoned");
        guard.iter().filter(|tx| !tx.is_closed()).count()
    }
}

impl Default for LinkBus {
    fn default() -> Self {
        Self::new()
    }
}

pub async fn serve(path: PathBuf, bus: Arc<LinkBus>) -> Result<()> {
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

async fn handle_client(stream: UnixStream, bus: Arc<LinkBus>) -> Result<()> {
    let (read_half, write_half) = stream.into_split();
    let outbound_rx = bus.register_outbound();

    let read_bus = bus.clone();
    let read_task = tokio::spawn(async move { read_loop(read_half, read_bus).await });
    let write_task = tokio::spawn(async move { write_loop(write_half, outbound_rx).await });

    // If either side errors out, drop both. tokio::join lets us await both.
    let (r, w) = tokio::join!(read_task, write_task);
    if let Err(e) = r {
        tracing::debug!(error=?e, "ext-link reader joined with error");
    }
    if let Err(e) = w {
        tracing::debug!(error=?e, "ext-link writer joined with error");
    }
    Ok(())
}

async fn read_loop(mut reader: OwnedReadHalf, bus: Arc<LinkBus>) {
    loop {
        match read_frame(&mut reader).await {
            Ok(Some(msg)) => {
                tracing::debug!(?msg, "ext-link recv");
                let _ = bus.bus_tx.send(msg);
            }
            Ok(None) => break,
            Err(e) => {
                tracing::debug!(error=?e, "ext-link read errored; closing");
                break;
            }
        }
    }
}

async fn write_loop(
    mut writer: OwnedWriteHalf,
    mut outbound_rx: mpsc::Receiver<DaemonMessage>,
) {
    while let Some(msg) = outbound_rx.recv().await {
        if let Err(e) = write_frame(&mut writer, &msg).await {
            tracing::debug!(error=?e, "ext-link write errored; closing");
            break;
        }
    }
}

async fn read_frame(reader: &mut OwnedReadHalf) -> Result<Option<ExtMessage>> {
    let mut len_buf = [0u8; 4];
    if let Err(e) = reader.read_exact(&mut len_buf).await {
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
    reader.read_exact(&mut body).await?;
    let msg: ExtMessage = serde_json::from_slice(&body)
        .with_context(|| format!("decoding ext frame: {}", String::from_utf8_lossy(&body)))?;
    Ok(Some(msg))
}

async fn write_frame(writer: &mut OwnedWriteHalf, msg: &DaemonMessage) -> Result<()> {
    let body = serde_json::to_vec(msg)?;
    let len = (body.len() as u32).to_be_bytes();
    writer.write_all(&len).await?;
    writer.write_all(&body).await?;
    writer.flush().await?;
    Ok(())
}

/// Default Unix socket path. The C extension agrees on this via the
/// `PERISCOPE_DAEMON_SOCKET` environment variable (override).
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

    #[tokio::test]
    async fn daemon_can_push_to_connected_extension() {
        let dir = tempfile::tempdir().unwrap();
        let sock = dir.path().join("daemon.sock");
        let bus = Arc::new(LinkBus::new());

        let server_sock = sock.clone();
        let server_bus = bus.clone();
        let server = tokio::spawn(async move { serve(server_sock, server_bus).await });

        for _ in 0..20 {
            if sock.exists() {
                break;
            }
            tokio::time::sleep(std::time::Duration::from_millis(20)).await;
        }

        let mut client = ClientStream::connect(&sock).await.unwrap();

        // Trigger a hello so the daemon registers the outbound channel for
        // this client.
        let hello = ExtMessage::Hello {
            pid: 7,
            version: "t".into(),
        };
        let body = serde_json::to_vec(&hello).unwrap();
        let len = (body.len() as u32).to_be_bytes();
        client.write_all(&len).await.unwrap();
        client.write_all(&body).await.unwrap();
        client.flush().await.unwrap();

        // Wait for the server to register the outbound channel before pushing.
        for _ in 0..50 {
            if bus.extension_count() >= 1 {
                break;
            }
            tokio::time::sleep(std::time::Duration::from_millis(10)).await;
        }
        assert!(bus.extension_count() >= 1, "expected one connected ext");

        // Push a SetBreakpoints from the daemon side; the client should read it.
        bus.broadcast_to_extensions(DaemonMessage::SetBreakpoints {
            file: "Foo.php".into(),
            lines: vec![42, 87],
        });

        let mut len_buf = [0u8; 4];
        tokio::time::timeout(std::time::Duration::from_secs(2), client.read_exact(&mut len_buf))
            .await
            .expect("read len timeout")
            .expect("read len");
        let n = u32::from_be_bytes(len_buf) as usize;
        let mut body = vec![0u8; n];
        client.read_exact(&mut body).await.expect("read body");
        let pushed: DaemonMessage = serde_json::from_slice(&body).unwrap();
        match pushed {
            DaemonMessage::SetBreakpoints { file, lines } => {
                assert_eq!(file, "Foo.php");
                assert_eq!(lines, vec![42, 87]);
            }
            other => panic!("unexpected daemon msg: {:?}", other),
        }

        server.abort();
    }
}
