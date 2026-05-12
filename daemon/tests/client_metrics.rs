#![forbid(unsafe_code)]

//! Phase 9b: Web Vitals capture endpoint. Toolbar JS POSTs vitals; daemon
//! finds the best-matching `.cptrace` (by pid + started-at proximity) and
//! writes a sidecar JSON; `GET /api/traces/{id}/client-metrics` returns it.

use std::sync::Arc;

use axum::body::{to_bytes, Body};
use axum::http::{Request, StatusCode};
use periscope_daemon::api::{self, ApiState};
use periscope_daemon::ext_link::LinkBus;
use tower::ServiceExt;

#[tokio::test]
async fn post_metrics_matches_trace_by_pid_and_proximity() {
    let trace_dir = tempfile::tempdir().expect("tempdir");
    let started = 1_700_000_000_000_000u64;
    let pid = 4242u32;

    // Seed two trace stubs: one matches pid + close started_at, one doesn't.
    std::fs::write(
        trace_dir.path().join(format!("{}-{}.cptrace", started, pid)),
        b"x",
    )
    .unwrap();
    std::fs::write(
        trace_dir
            .path()
            .join(format!("{}-{}.cptrace", started + 5_000_000, pid + 1)),
        b"x",
    )
    .unwrap();

    let bus = Arc::new(LinkBus::new());
    let app = api::router(ApiState::new(trace_dir.path(), trace_dir.path(), bus));

    let body = serde_json::json!({
        "pid": pid,
        "started_at_unix_micros": started + 100,
        "uri": "/dashboard",
        "vitals": { "lcp_ms": 1200, "cls": 0.05, "fcp_ms": 800, "inp_ms": 18 },
        "navigation": { "ttfb_ms": 80, "dom_content_loaded_ms": 600, "load_event_ms": 1300 }
    });

    let req = Request::builder()
        .method("POST")
        .uri("/api/client-metrics")
        .header("content-type", "application/json")
        .body(Body::from(serde_json::to_vec(&body).unwrap()))
        .unwrap();

    let resp = app.clone().oneshot(req).await.expect("post");
    assert_eq!(resp.status(), StatusCode::OK);

    let bytes = to_bytes(resp.into_body(), 64 * 1024).await.unwrap();
    let post_body: serde_json::Value = serde_json::from_slice(&bytes).unwrap();
    assert_eq!(
        post_body["trace_id"],
        serde_json::Value::String(format!("{}-{}", started, pid)),
        "expected match by pid + time proximity, got {:?}",
        post_body
    );

    // GET round trip.
    let id = post_body["trace_id"].as_str().unwrap().to_string();
    let req = Request::builder()
        .method("GET")
        .uri(format!("/api/traces/{}/client-metrics", id))
        .body(Body::empty())
        .unwrap();
    let resp = app.oneshot(req).await.expect("get");
    assert_eq!(resp.status(), StatusCode::OK);
    let bytes = to_bytes(resp.into_body(), 64 * 1024).await.unwrap();
    let stored: serde_json::Value = serde_json::from_slice(&bytes).unwrap();
    assert_eq!(stored["pid"], pid);
    assert_eq!(stored["vitals"]["lcp_ms"], 1200);
    assert_eq!(stored["navigation"]["ttfb_ms"], 80);
}

#[tokio::test]
async fn post_metrics_falls_back_to_orphan_when_no_match() {
    let trace_dir = tempfile::tempdir().expect("tempdir");
    let bus = Arc::new(LinkBus::new());
    let app = api::router(ApiState::new(trace_dir.path(), trace_dir.path(), bus));

    let body = serde_json::json!({
        "pid": 999,
        "started_at_unix_micros": 1u64,
        "vitals": { "lcp_ms": 500 }
    });
    let req = Request::builder()
        .method("POST")
        .uri("/api/client-metrics")
        .header("content-type", "application/json")
        .body(Body::from(serde_json::to_vec(&body).unwrap()))
        .unwrap();
    let resp = app.oneshot(req).await.expect("post");
    assert_eq!(resp.status(), StatusCode::OK);
    let bytes = to_bytes(resp.into_body(), 64 * 1024).await.unwrap();
    let json: serde_json::Value = serde_json::from_slice(&bytes).unwrap();
    let id = json["trace_id"].as_str().unwrap();
    assert!(id.starts_with("orphan-999-"), "got {}", id);
}
