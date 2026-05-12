#![forbid(unsafe_code)]

//! Phase 9b: timeline cursor fan-out across browser tabs. Connect two WS
//! clients to the same daemon. Tab A sends `cursor_set`; tab B receives it.
//! This is the round-trip the plan calls out as a Phase 9b unchecked item.

use std::sync::Arc;
use std::time::Duration;

use futures_util::{SinkExt, StreamExt};
use periscope_daemon::api::{self, ApiState};
use periscope_daemon::ext_link::{LinkBus, UiMessage};
use tokio_tungstenite::tungstenite::Message;

#[tokio::test]
async fn cursor_set_fans_out_to_other_tabs() {
    let port = 30210u16;
    let trace_dir = tempfile::tempdir().expect("tempdir");
    let bus = Arc::new(LinkBus::new());

    let api_state = ApiState::new(trace_dir.path(), trace_dir.path(), bus.clone());
    let listen = format!("127.0.0.1:{}", port).parse().unwrap();
    let api_handle = tokio::spawn(api::serve(api_state, listen));

    for _ in 0..50 {
        if tokio::net::TcpStream::connect(&listen).await.is_ok() {
            break;
        }
        tokio::time::sleep(Duration::from_millis(20)).await;
    }

    let ws_url = format!("ws://127.0.0.1:{}/ws", port);
    let (mut tab_a, _) = tokio_tungstenite::connect_async(&ws_url)
        .await
        .expect("tab A connect");
    let (mut tab_b, _) = tokio_tungstenite::connect_async(&ws_url)
        .await
        .expect("tab B connect");

    // Drain greeting on both tabs.
    for tab in [&mut tab_a, &mut tab_b] {
        let g = tokio::time::timeout(Duration::from_secs(2), tab.next())
            .await
            .expect("greet timeout")
            .expect("greet end")
            .expect("greet err");
        match g {
            Message::Text(t) => assert!(t.contains("\"type\":\"hello\"")),
            other => panic!("expected text greet, got {:?}", other),
        }
    }

    // Tab A sends cursor_set.
    let payload = serde_json::json!({
        "type": "cursor_set",
        "trace_id": "abc",
        "at_micros": 12345u64,
        "frame_id": 7u32,
    })
    .to_string();
    tab_a
        .send(Message::Text(payload))
        .await
        .expect("send cursor_set");

    // Tab B should receive the same payload.
    let recv = tokio::time::timeout(Duration::from_secs(3), tab_b.next())
        .await
        .expect("ws recv timeout")
        .expect("ws recv end")
        .expect("ws recv err");
    let recv_text = match recv {
        Message::Text(t) => t,
        other => panic!("expected text fanout, got {:?}", other),
    };

    let parsed: UiMessage = serde_json::from_str(&recv_text).expect("parse");
    let UiMessage::CursorSet {
        trace_id,
        at_micros,
        frame_id,
    } = parsed;
    assert_eq!(trace_id, "abc");
    assert_eq!(at_micros, 12345);
    assert_eq!(frame_id, Some(7));

    api_handle.abort();
}

#[tokio::test]
async fn cursor_set_round_trips_back_to_sender() {
    // The simpler one-tab case: a client that sends a cursor_set should also
    // see it (broadcast::send fans out to every subscriber, including itself).
    // This is what lets the integration test in the plan run with one client.
    let port = 30211u16;
    let trace_dir = tempfile::tempdir().expect("tempdir");
    let bus = Arc::new(LinkBus::new());

    let api_state = ApiState::new(trace_dir.path(), trace_dir.path(), bus.clone());
    let listen = format!("127.0.0.1:{}", port).parse().unwrap();
    let api_handle = tokio::spawn(api::serve(api_state, listen));

    for _ in 0..50 {
        if tokio::net::TcpStream::connect(&listen).await.is_ok() {
            break;
        }
        tokio::time::sleep(Duration::from_millis(20)).await;
    }

    let ws_url = format!("ws://127.0.0.1:{}/ws", port);
    let (mut ws, _) = tokio_tungstenite::connect_async(&ws_url)
        .await
        .expect("ws connect");

    // Drain greet.
    let _ = tokio::time::timeout(Duration::from_secs(2), ws.next())
        .await
        .expect("greet timeout");

    let payload = serde_json::json!({
        "type": "cursor_set",
        "trace_id": "trace-7",
        "at_micros": 999u64,
    })
    .to_string();
    ws.send(Message::Text(payload)).await.expect("send");

    let recv = tokio::time::timeout(Duration::from_secs(3), ws.next())
        .await
        .expect("recv timeout")
        .expect("recv end")
        .expect("recv err");
    let txt = match recv {
        Message::Text(t) => t,
        other => panic!("expected text, got {:?}", other),
    };
    assert!(
        txt.contains("\"type\":\"cursor_set\"") && txt.contains("trace-7"),
        "expected cursor_set fanout, got {}",
        txt
    );

    api_handle.abort();
}
