#![forbid(unsafe_code)]

//! Phase 8b: simulate a live IDE session pausing on a breakpoint.
//!
//! We don't run the real C extension here (that's the smoke-test layer).
//! This test wires:
//!   - DapServer over an in-memory duplex stream (acts as the IDE).
//!   - LinkBus shared with a "fake extension" that connects via a real
//!     Unix domain socket served by `ext_link::serve`.
//!   - The IDE sends `setBreakpoints` over DAP.
//!   - The fake extension reads `SetBreakpoints` from its socket.
//!   - The fake extension sends `BreakpointHit`.
//!   - The DAP server emits a `stopped` event.
//!   - The IDE sends `continue`.
//!   - The fake extension reads `Continue` from its socket.

use std::sync::Arc;
use std::time::Duration;

use periscope_daemon::dap::DapServer;
use periscope_daemon::ext_link::{self, DaemonMessage, LinkBus};
use tokio::io::{duplex, AsyncReadExt, AsyncWriteExt};
use tokio::net::UnixStream;

async fn write_dap<W: tokio::io::AsyncWrite + Unpin>(w: &mut W, body: &str) {
    let frame = format!("Content-Length: {}\r\n\r\n{}", body.len(), body);
    w.write_all(frame.as_bytes()).await.unwrap();
}

/// Read exactly one DAP frame; returns the JSON body.
async fn read_dap_frame<R: tokio::io::AsyncRead + Unpin>(r: &mut R) -> serde_json::Value {
    // Read header bytes one at a time until we hit "\r\n\r\n", then read body.
    let mut headers = Vec::new();
    let mut byte = [0u8; 1];
    loop {
        r.read_exact(&mut byte).await.unwrap();
        headers.push(byte[0]);
        if headers.ends_with(b"\r\n\r\n") {
            break;
        }
    }
    let header_str = std::str::from_utf8(&headers).unwrap();
    let cl_line = header_str
        .lines()
        .find(|l| l.starts_with("Content-Length:"))
        .unwrap();
    let cl: usize = cl_line
        .trim_start_matches("Content-Length:")
        .trim()
        .parse()
        .unwrap();
    let mut body = vec![0u8; cl];
    r.read_exact(&mut body).await.unwrap();
    serde_json::from_slice(&body).unwrap()
}

async fn read_until<R: tokio::io::AsyncRead + Unpin>(
    r: &mut R,
    pred: impl Fn(&serde_json::Value) -> bool,
) -> serde_json::Value {
    for _ in 0..20 {
        let v = tokio::time::timeout(Duration::from_secs(2), read_dap_frame(r))
            .await
            .expect("dap read timeout");
        if pred(&v) {
            return v;
        }
    }
    panic!("expected DAP frame matching predicate never arrived");
}

/// Read one length-prefixed JSON frame from the ext-side Unix stream.
async fn read_ext_msg(stream: &mut UnixStream) -> DaemonMessage {
    let mut len_buf = [0u8; 4];
    stream.read_exact(&mut len_buf).await.unwrap();
    let n = u32::from_be_bytes(len_buf) as usize;
    let mut body = vec![0u8; n];
    stream.read_exact(&mut body).await.unwrap();
    serde_json::from_slice(&body).unwrap()
}

#[tokio::test]
async fn live_breakpoint_round_trip() {
    let dir = tempfile::tempdir().unwrap();
    let sock = dir.path().join("daemon.sock");
    let bus = Arc::new(LinkBus::new());

    // Ext-link Unix socket server.
    let server_sock = sock.clone();
    let server_bus = bus.clone();
    let _server_task = tokio::spawn(async move { ext_link::serve(server_sock, server_bus).await });
    for _ in 0..50 {
        if sock.exists() {
            break;
        }
        tokio::time::sleep(Duration::from_millis(20)).await;
    }
    assert!(sock.exists());

    // Fake C extension: connect and send Hello so the daemon registers the
    // outbound channel for us.
    let mut ext_client = UnixStream::connect(&sock).await.unwrap();
    let hello = br#"{"type":"hello","pid":1234,"version":"test"}"#;
    let len = (hello.len() as u32).to_be_bytes();
    ext_client.write_all(&len).await.unwrap();
    ext_client.write_all(hello).await.unwrap();
    ext_client.flush().await.unwrap();
    for _ in 0..50 {
        if bus.extension_count() >= 1 {
            break;
        }
        tokio::time::sleep(Duration::from_millis(10)).await;
    }
    assert_eq!(bus.extension_count(), 1);

    // DAP over an in-memory duplex; spawn the server.
    let (client_w, server_r) = duplex(8192);
    let (server_w, mut client_r) = duplex(8192);
    let dap = DapServer::new(server_r, server_w).with_bus(bus.clone());
    let _dap_task = tokio::spawn(dap.run());

    let mut client_w = client_w;

    // 1. initialize → expect response + initialized event.
    write_dap(
        &mut client_w,
        r#"{"seq":1,"type":"request","command":"initialize","arguments":{}}"#,
    )
    .await;
    let _init_resp = read_dap_frame(&mut client_r).await;
    let _init_event = read_dap_frame(&mut client_r).await;

    // 2. setBreakpoints → daemon should push DaemonMessage::SetBreakpoints
    //    out to the fake extension.
    write_dap(
        &mut client_w,
        r#"{"seq":2,"type":"request","command":"setBreakpoints",
            "arguments":{"source":{"path":"/app/Listing.php"},
                         "breakpoints":[{"line":42}]}}"#,
    )
    .await;
    let _setbp_resp = read_dap_frame(&mut client_r).await;
    let pushed = tokio::time::timeout(Duration::from_secs(2), read_ext_msg(&mut ext_client))
        .await
        .expect("ext didn't receive SetBreakpoints");
    match pushed {
        DaemonMessage::SetBreakpoints { file, lines } => {
            assert_eq!(file, "/app/Listing.php");
            assert_eq!(lines, vec![42]);
        }
        other => panic!("expected SetBreakpoints, got {:?}", other),
    }

    // 3. Fake extension hits the breakpoint and notifies the daemon.
    let hit = br#"{"type":"breakpoint_hit","frame_id":7,"file":"/app/Listing.php","line":42}"#;
    let hlen = (hit.len() as u32).to_be_bytes();
    ext_client.write_all(&hlen).await.unwrap();
    ext_client.write_all(hit).await.unwrap();
    ext_client.flush().await.unwrap();

    // 4. DAP server emits a `stopped` event with reason: "breakpoint".
    let stopped = read_until(&mut client_r, |v| v["type"] == "event" && v["event"] == "stopped")
        .await;
    assert_eq!(stopped["body"]["reason"], "breakpoint");

    // 5. IDE sends continue → daemon pushes DaemonMessage::Continue to ext.
    write_dap(
        &mut client_w,
        r#"{"seq":3,"type":"request","command":"continue","arguments":{}}"#,
    )
    .await;
    let _cont_resp = read_dap_frame(&mut client_r).await;
    let pushed = tokio::time::timeout(Duration::from_secs(2), read_ext_msg(&mut ext_client))
        .await
        .expect("ext didn't receive Continue");
    assert!(matches!(pushed, DaemonMessage::Continue));
}
