//! HTTP API for AI agents and the browser UI.
//!
//! Per Appendix A.6: the same daemon binary that serves the browser UI
//! also exposes a JSON REST API at `/api/*` so Claude Code, Cursor, Codex,
//! Continue, aider — and any tool that speaks HTTP — can read traces.
//!
//! Default bind is `127.0.0.1:9999`. Privacy guardrail: never expose
//! externally without an explicit `--listen 0.0.0.0` flag (handled by the
//! caller — this module only takes a `SocketAddr`).

use std::net::SocketAddr;
use std::path::{Path, PathBuf};
use std::sync::Arc;

use anyhow::{Context, Result};
use axum::{
    extract::{Path as AxPath, Query, State},
    http::StatusCode,
    response::{IntoResponse, Json, Response},
    routing::{get, post},
    Router,
};
use serde::{Deserialize, Serialize};
use tower_http::cors::{Any, CorsLayer};

use crate::insights;
use crate::summary;
use crate::trace::Trace;
use crate::trace_view::{self, EventJson, FrameJson, TraceJson};

#[derive(Clone)]
pub struct ApiState {
    pub trace_dir: PathBuf,
    pub project_root: PathBuf,
}

impl ApiState {
    pub fn new(trace_dir: impl Into<PathBuf>, project_root: impl Into<PathBuf>) -> Self {
        Self {
            trace_dir: trace_dir.into(),
            project_root: project_root.into(),
        }
    }
}

pub fn router(state: ApiState) -> Router {
    let cors = CorsLayer::new()
        .allow_origin(Any)
        .allow_methods(Any)
        .allow_headers(Any);

    Router::new()
        .route("/api/health", get(health))
        .route("/api/traces", get(list_traces))
        .route("/api/traces/:id", get(get_trace))
        .route("/api/traces/:id/frames", get(list_frames))
        .route("/api/traces/:id/frames/:frame_id", get(get_frame))
        .route("/api/traces/:id/queries", get(list_queries))
        .route("/api/traces/:id/timeline", get(timeline))
        .route("/api/traces/:id/exceptions", get(list_exceptions))
        .route("/api/traces/:id/insights", get(get_insights))
        .route("/api/traces/:id/summary", get(get_summary))
        .route("/api/traces/:id/events", get(list_events))
        .route("/api/file", get(read_file))
        .route("/api/traces/:id/rerun", post(rerun_stub))
        .with_state(Arc::new(state))
        .layer(cors)
}

pub async fn serve(state: ApiState, addr: SocketAddr) -> Result<()> {
    let app = router(state);
    let listener = tokio::net::TcpListener::bind(&addr)
        .await
        .with_context(|| format!("binding HTTP listener on {}", addr))?;
    tracing::info!(%addr, "http api listening");
    axum::serve(listener, app)
        .await
        .context("axum::serve returned")?;
    Ok(())
}

// ---------- handlers ----------

#[derive(Serialize)]
struct HealthResponse {
    status: &'static str,
    version: &'static str,
}

async fn health() -> Json<HealthResponse> {
    Json(HealthResponse {
        status: "ok",
        version: env!("CARGO_PKG_VERSION"),
    })
}

#[derive(Serialize)]
struct TraceSummary {
    id: String,
    path: String,
    started_at_unix_micros: u64,
    duration_micros: u64,
    method: String,
    uri: String,
    status_code: u16,
    frame_count: usize,
    event_count: usize,
    has_exception: bool,
    size_bytes: u64,
}

#[derive(Deserialize, Default)]
struct ListQuery {
    #[serde(default)]
    limit: Option<usize>,
}

async fn list_traces(
    State(state): State<Arc<ApiState>>,
    Query(q): Query<ListQuery>,
) -> ApiResult<Json<Vec<TraceSummary>>> {
    let limit = q.limit.unwrap_or(50);
    let mut entries: Vec<(PathBuf, std::fs::Metadata)> = vec![];
    let read = std::fs::read_dir(&state.trace_dir).with_context(|| {
        format!(
            "reading trace dir {}",
            state.trace_dir.display()
        )
    })?;
    for entry in read {
        let entry = entry?;
        let path = entry.path();
        if path.extension().and_then(|s| s.to_str()) != Some("cptrace") {
            continue;
        }
        let md = entry.metadata()?;
        entries.push((path, md));
    }
    // Newest first.
    entries.sort_by(|a, b| {
        b.1.modified()
            .ok()
            .cmp(&a.1.modified().ok())
    });

    let mut summaries = Vec::with_capacity(entries.len().min(limit));
    for (path, md) in entries.into_iter().take(limit) {
        match summarise_trace(&path, md.len()) {
            Ok(s) => summaries.push(s),
            Err(e) => {
                tracing::warn!(?path, error=?e, "skipping unreadable trace");
            }
        }
    }
    Ok(Json(summaries))
}

fn summarise_trace(path: &Path, size_bytes: u64) -> Result<TraceSummary> {
    let trace = Trace::open(path)?;
    let root = trace.root()?;
    let meta = root.get_meta()?;
    let id = trace_id_from_path(path);

    let (method, uri) = if meta.has_request() {
        let r = meta.get_request()?;
        (
            r.get_method()
                .ok()
                .and_then(|t| t.to_str().ok())
                .unwrap_or("")
                .to_string(),
            r.get_uri()
                .ok()
                .and_then(|t| t.to_str().ok())
                .unwrap_or("")
                .to_string(),
        )
    } else {
        ("".to_string(), "".to_string())
    };
    let status_code = if meta.has_response() {
        meta.get_response()?.get_status_code()
    } else {
        0
    };
    let frames = root.get_frames()?;
    let events = root.get_observability_events()?;
    let has_exception = events.iter().any(|e| {
        if let Ok(crate::trace_capnp::observability_event::payload::Which::GenericJson(Ok(g))) =
            e.get_payload().which()
        {
            matches!(g.get_type().ok().and_then(|t| t.to_str().ok()), Some("exception"))
        } else {
            false
        }
    });

    Ok(TraceSummary {
        id,
        path: path.display().to_string(),
        started_at_unix_micros: meta.get_started_at_unix_micros(),
        duration_micros: meta.get_duration_micros(),
        method,
        uri,
        status_code,
        frame_count: frames.len() as usize,
        event_count: events.len() as usize,
        has_exception,
        size_bytes,
    })
}

fn trace_id_from_path(path: &Path) -> String {
    path.file_stem()
        .and_then(|s| s.to_str())
        .unwrap_or("")
        .to_string()
}

fn trace_path_for(state: &ApiState, id: &str) -> Result<PathBuf, ApiError> {
    if id.contains('/') || id.contains("..") || id.is_empty() {
        return Err(ApiError::not_found("invalid trace id"));
    }
    let path = state.trace_dir.join(format!("{}.cptrace", id));
    if !path.exists() {
        return Err(ApiError::not_found("trace not found"));
    }
    Ok(path)
}

fn open_trace(state: &ApiState, id: &str) -> Result<TraceJson, ApiError> {
    let path = trace_path_for(state, id)?;
    let trace = Trace::open(&path).map_err(ApiError::internal)?;
    trace_view::decode_trace(&trace, id).map_err(ApiError::internal)
}

async fn get_trace(
    State(state): State<Arc<ApiState>>,
    AxPath(id): AxPath<String>,
) -> ApiResult<Json<TraceJson>> {
    Ok(Json(open_trace(&state, &id)?))
}

#[derive(Deserialize, Default)]
struct PaginateQuery {
    #[serde(default)]
    limit: Option<usize>,
    #[serde(default)]
    offset: Option<usize>,
}

#[derive(Serialize)]
struct Page<T> {
    total: usize,
    offset: usize,
    limit: usize,
    items: Vec<T>,
}

async fn list_frames(
    State(state): State<Arc<ApiState>>,
    AxPath(id): AxPath<String>,
    Query(q): Query<PaginateQuery>,
) -> ApiResult<Json<Page<FrameJson>>> {
    let trace = open_trace(&state, &id)?;
    let total = trace.frames.len();
    let offset = q.offset.unwrap_or(0).min(total);
    let limit = q.limit.unwrap_or(500);
    let items = trace
        .frames
        .into_iter()
        .skip(offset)
        .take(limit)
        .collect::<Vec<_>>();
    Ok(Json(Page {
        total,
        offset,
        limit,
        items,
    }))
}

async fn get_frame(
    State(state): State<Arc<ApiState>>,
    AxPath((id, frame_id)): AxPath<(String, u32)>,
) -> ApiResult<Json<FrameJson>> {
    let trace = open_trace(&state, &id)?;
    let frame = trace
        .frames
        .into_iter()
        .find(|f| f.id == frame_id)
        .ok_or_else(|| ApiError::not_found("frame not found"))?;
    Ok(Json(frame))
}

async fn list_queries(
    State(state): State<Arc<ApiState>>,
    AxPath(id): AxPath<String>,
) -> ApiResult<Json<Vec<EventJson>>> {
    let trace = open_trace(&state, &id)?;
    Ok(Json(
        trace
            .observability_events
            .into_iter()
            .filter(|e| e.type_tag == "sql")
            .collect(),
    ))
}

async fn list_exceptions(
    State(state): State<Arc<ApiState>>,
    AxPath(id): AxPath<String>,
) -> ApiResult<Json<Vec<EventJson>>> {
    let trace = open_trace(&state, &id)?;
    Ok(Json(
        trace
            .observability_events
            .into_iter()
            .filter(|e| e.type_tag == "exception")
            .collect(),
    ))
}

#[derive(Deserialize, Default)]
struct EventQuery {
    #[serde(default)]
    r#type: Option<String>,
}

async fn list_events(
    State(state): State<Arc<ApiState>>,
    AxPath(id): AxPath<String>,
    Query(q): Query<EventQuery>,
) -> ApiResult<Json<Vec<EventJson>>> {
    let trace = open_trace(&state, &id)?;
    let events = match q.r#type {
        Some(t) => trace
            .observability_events
            .into_iter()
            .filter(|e| e.type_tag == t)
            .collect(),
        None => trace.observability_events,
    };
    Ok(Json(events))
}

#[derive(Serialize)]
struct TimelineEntry {
    at_micros: u64,
    kind: &'static str,
    id: u32,
    label: String,
}

async fn timeline(
    State(state): State<Arc<ApiState>>,
    AxPath(id): AxPath<String>,
) -> ApiResult<Json<Vec<TimelineEntry>>> {
    let trace = open_trace(&state, &id)?;
    let mut entries: Vec<TimelineEntry> = vec![];
    for f in &trace.frames {
        entries.push(TimelineEntry {
            at_micros: f.enter_micros,
            kind: "frame_enter",
            id: f.id,
            label: f.function.clone(),
        });
        entries.push(TimelineEntry {
            at_micros: f.exit_micros,
            kind: "frame_exit",
            id: f.id,
            label: f.function.clone(),
        });
    }
    for e in &trace.observability_events {
        entries.push(TimelineEntry {
            at_micros: e.at_micros,
            kind: "event",
            id: e.id,
            label: e.type_tag.clone(),
        });
    }
    entries.sort_by(|a, b| a.at_micros.cmp(&b.at_micros));
    Ok(Json(entries))
}

async fn get_insights(
    State(state): State<Arc<ApiState>>,
    AxPath(id): AxPath<String>,
) -> ApiResult<Json<insights::Insights>> {
    let trace = open_trace(&state, &id)?;
    Ok(Json(insights::compute(&trace)))
}

async fn get_summary(
    State(state): State<Arc<ApiState>>,
    AxPath(id): AxPath<String>,
) -> ApiResult<Json<summary::Summary>> {
    let trace = open_trace(&state, &id)?;
    Ok(Json(summary::compute(&trace)))
}

#[derive(Deserialize)]
struct FileQuery {
    path: String,
    line: Option<u32>,
    radius: Option<u32>,
}

#[derive(Serialize)]
struct FileSlice {
    path: String,
    start_line: u32,
    end_line: u32,
    total_lines: u32,
    mtime_unix: u64,
    lines: Vec<FileLine>,
}

#[derive(Serialize)]
struct FileLine {
    number: u32,
    source: String,
}

async fn read_file(
    State(state): State<Arc<ApiState>>,
    Query(q): Query<FileQuery>,
) -> ApiResult<Json<FileSlice>> {
    // Resolve to an absolute path.
    let raw = PathBuf::from(&q.path);
    let abs = if raw.is_absolute() {
        raw
    } else {
        state.project_root.join(&raw)
    };
    // Canonicalise to defeat `..` traversal, then enforce that the result
    // sits under the configured project root.
    let canon = std::fs::canonicalize(&abs).map_err(|_| ApiError::not_found("file not found"))?;
    let root_canon = std::fs::canonicalize(&state.project_root)
        .unwrap_or_else(|_| state.project_root.clone());
    if !canon.starts_with(&root_canon) {
        return Err(ApiError::forbidden(
            "path is outside the project root",
        ));
    }

    let bytes = std::fs::read(&canon).map_err(ApiError::internal)?;
    let text = String::from_utf8_lossy(&bytes);
    let all_lines: Vec<&str> = text.lines().collect();
    let total = all_lines.len() as u32;

    let (start, end) = match (q.line, q.radius) {
        (Some(line), Some(radius)) => (line.saturating_sub(radius).max(1), line.saturating_add(radius).min(total)),
        (Some(line), None) => (line.saturating_sub(20).max(1), line.saturating_add(20).min(total)),
        _ => (1, total),
    };

    let lines = all_lines
        .iter()
        .enumerate()
        .filter(|(idx, _)| {
            let n = (*idx as u32) + 1;
            n >= start && n <= end
        })
        .map(|(idx, src)| FileLine {
            number: (idx as u32) + 1,
            source: (*src).to_string(),
        })
        .collect();

    let mtime_unix = std::fs::metadata(&canon)
        .ok()
        .and_then(|m| m.modified().ok())
        .and_then(|t| t.duration_since(std::time::UNIX_EPOCH).ok())
        .map(|d| d.as_secs())
        .unwrap_or(0);

    Ok(Json(FileSlice {
        path: canon.display().to_string(),
        start_line: start,
        end_line: end,
        total_lines: total,
        mtime_unix,
        lines,
    }))
}

#[derive(Serialize)]
struct RerunStub {
    status: &'static str,
    detail: &'static str,
}

async fn rerun_stub(
    State(_state): State<Arc<ApiState>>,
    AxPath(_id): AxPath<String>,
) -> Json<RerunStub> {
    // The C extension has shipped per-request `X-Periscope-Mode` (commit 7210bed).
    // Wiring HTTP rerun is Phase 6 follow-up; the endpoint is reserved here
    // so the UI can feature-detect now.
    Json(RerunStub {
        status: "not_implemented",
        detail: "POST /api/traces/{id}/rerun is reserved; full implementation lands in 6.1+",
    })
}

// ---------- error type ----------

pub struct ApiError {
    code: StatusCode,
    message: String,
}

impl ApiError {
    fn not_found(msg: impl Into<String>) -> Self {
        Self {
            code: StatusCode::NOT_FOUND,
            message: msg.into(),
        }
    }
    fn forbidden(msg: impl Into<String>) -> Self {
        Self {
            code: StatusCode::FORBIDDEN,
            message: msg.into(),
        }
    }
    fn internal(e: impl std::fmt::Display) -> Self {
        Self {
            code: StatusCode::INTERNAL_SERVER_ERROR,
            message: e.to_string(),
        }
    }
}

impl From<anyhow::Error> for ApiError {
    fn from(value: anyhow::Error) -> Self {
        Self::internal(value)
    }
}

impl From<std::io::Error> for ApiError {
    fn from(value: std::io::Error) -> Self {
        Self::internal(value)
    }
}

impl IntoResponse for ApiError {
    fn into_response(self) -> Response {
        let body = Json(serde_json::json!({
            "error": self.message,
            "status": self.code.as_u16(),
        }));
        (self.code, body).into_response()
    }
}

type ApiResult<T> = Result<T, ApiError>;
