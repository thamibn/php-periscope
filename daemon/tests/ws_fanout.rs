#![forbid(unsafe_code)]

//! Phase 8a: end-to-end WebSocket fanout. Spin up the daemon's HTTP server
//! and the ext-link Unix socket; connect a WS client; push an ExtMessage
//! into the bus from a fake "extension" client; assert the WS receives it.

use std::sync::Arc;
use std::time::Duration;

use futures_util::StreamExt;
use periscope_daemon::api::{self, ApiState};
use periscope_daemon::ext_link::{self, ExtMessage, LinkBus};
use tokio::io::AsyncWriteExt;
use tokio::net::UnixStream;
use tokio_tungstenite::tungstenite::Message;

#[tokio::test]
async fn extension_socket_message_reaches_websocket_client() {
    // Random-ish port to avoid collisions during parallel test runs.
    let port = 30200u16;
    let trace_dir = tempfile::tempdir().expect("tempdir");
    let sock_dir = tempfile::tempdir().expect("sock tempdir");
    let sock_path = sock_dir.path().join("daemon.sock");

    let bus = Arc::new(LinkBus::new());

    // HTTP + WS server.
    let api_state = ApiState::new(trace_dir.path(), trace_dir.path(), bus.clone());
    let listen = format!("127.0.0.1:{}", port).parse().unwrap();
    let api_handle = tokio::spawn(api::serve(api_state, listen));

    // Ext-link Unix socket server.
    let ext_handle = tokio::spawn(ext_link::serve(sock_path.clone(), bus.clone()));

    // Wait for both listeners to bind.
    for _ in 0..50 {
        if sock_path.exists() && tokio::net::TcpStream::connect(&listen).await.is_ok() {
            break;
        }
        tokio::time::sleep(Duration::from_millis(20)).await;
    }

    // Connect WS client.
    let ws_url = format!("ws://127.0.0.1:{}/ws", port);
    let (mut ws, _) = tokio_tungstenite::connect_async(&ws_url)
        .await
        .expect("ws connect");

    // Read greeting.
    let greet = tokio::time::timeout(Duration::from_secs(2), ws.next())
        .await
        .expect("greet timeout")
        .expect("greet stream end")
        .expect("greet ws err");
    let greet_text = match greet {
        Message::Text(t) => t,
        other => panic!("expected text greet, got {:?}", other),
    };
    assert!(
        greet_text.contains("\"type\":\"hello\""),
        "expected hello greet, got {}",
        greet_text
    );

    // Now mimic the C extension: push a request_finished frame down the
    // unix socket. The daemon should fan out to our WS client.
    let mut ext_client = UnixStream::connect(&sock_path).await.expect("ext connect");
    let body = br#"{"type":"request_finished","request_id":"abc","trace_path":"/tmp/x.cptrace","duration_micros":12345}"#;
    let len = (body.len() as u32).to_be_bytes();
    ext_client.write_all(&len).await.expect("ext len");
    ext_client.write_all(body).await.expect("ext body");
    ext_client.flush().await.expect("ext flush");

    // Read fanout message — may need to skip an ack-style frame in the future,
    // but today the client receives an ExtMessage variant directly.
    let recv = tokio::time::timeout(Duration::from_secs(3), ws.next())
        .await
        .expect("ws recv timeout")
        .expect("ws recv stream end")
        .expect("ws recv err");
    let recv_text = match recv {
        Message::Text(t) => t,
        other => panic!("expected text fanout, got {:?}", other),
    };
    assert!(
        recv_text.contains("request_finished") && recv_text.contains("abc"),
        "expected request_finished payload, got {}",
        recv_text
    );

    // Round-trip the parsed shape so the schema invariant holds.
    let parsed: ExtMessage = serde_json::from_str(&recv_text).expect("parse");
    match parsed {
        ExtMessage::RequestFinished {
            request_id,
            duration_micros,
            ..
        } => {
            assert_eq!(request_id, "abc");
            assert_eq!(duration_micros, 12345);
        }
        other => panic!("unexpected variant {:?}", other),
    }

    api_handle.abort();
    ext_handle.abort();
}
