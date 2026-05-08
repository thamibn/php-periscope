//! "What was the world like at time T?"
//!
//! Given a `TraceIndex` and a target time (or frame), build the full picture
//! the UI / DAP needs: current frame, call stack, scoped variables, and every
//! observability event up to that point. This is what powers the timeline
//! scrubber and the DAP `stackTrace` / `scopes` / `variables` flow.

use std::sync::Arc;

use serde::Serialize;

use crate::replay::index::TraceIndex;
use crate::trace_view::{EventJson, FrameJson};

#[derive(Serialize, Clone)]
pub struct ReconstructedState {
    pub at_micros: u64,
    pub current_frame: Option<FrameJson>,
    pub stack: Vec<FrameJson>,
    pub scope_variables: Vec<ScopeVariable>,
    pub events_so_far: Vec<EventJson>,
}

#[derive(Serialize, Clone)]
pub struct ScopeVariable {
    pub name: String,
    pub value: String,
    pub kind: ScopeKind,
}

#[derive(Serialize, Clone, Copy, Debug, PartialEq, Eq)]
#[serde(rename_all = "snake_case")]
pub enum ScopeKind {
    Args,
    Return,
}

pub fn at_time(index: Arc<TraceIndex>, time_micros: u64) -> ReconstructedState {
    let current_id = index.frame_at(time_micros);
    at_frame_internal(index, current_id, time_micros)
}

pub fn at_frame(index: Arc<TraceIndex>, frame_id: u32) -> ReconstructedState {
    let time = index.frame(frame_id).map(|f| f.enter_micros).unwrap_or(0);
    at_frame_internal(index, Some(frame_id), time)
}

fn at_frame_internal(
    index: Arc<TraceIndex>,
    frame_id: Option<u32>,
    time_micros: u64,
) -> ReconstructedState {
    let current_frame = frame_id.and_then(|id| index.frame(id)).cloned();

    let stack: Vec<FrameJson> = match frame_id {
        Some(id) => index
            .stack_from(id)
            .into_iter()
            .filter_map(|fid| index.frame(fid).cloned())
            .collect(),
        None => vec![],
    };

    let scope_variables = match &current_frame {
        Some(f) => collect_scope(f),
        None => vec![],
    };

    let events_so_far: Vec<EventJson> = index
        .events_before(time_micros)
        .into_iter()
        .filter_map(|eid| index.event(eid).cloned())
        .collect();

    ReconstructedState {
        at_micros: time_micros,
        current_frame,
        stack,
        scope_variables,
        events_so_far,
    }
}

fn collect_scope(frame: &FrameJson) -> Vec<ScopeVariable> {
    let mut out = vec![];
    if let Some(args) = &frame.args_summary {
        out.push(ScopeVariable {
            name: "$args".to_string(),
            value: args.clone(),
            kind: ScopeKind::Args,
        });
    }
    if let Some(ret) = &frame.return_value_summary {
        out.push(ScopeVariable {
            name: "$return".to_string(),
            value: ret.clone(),
            kind: ScopeKind::Return,
        });
    }
    out
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::trace_view::{EventJson, FrameJson, MetaJson, TraceJson};

    fn f(id: u32, parent: u32, enter: u64, exit: u64, depth: u32) -> FrameJson {
        FrameJson {
            id,
            parent_id: parent,
            function: format!("f{}", id),
            file: "x.php".into(),
            line: 1,
            enter_micros: enter,
            exit_micros: exit,
            duration_micros: exit - enter,
            depth,
            flags: 0,
            args_summary: None,
            return_value_summary: None,
            observability_event_ids: vec![],
        }
    }

    fn evt(id: u32, frame: u32, at: u64) -> EventJson {
        EventJson {
            id,
            at_micros: at,
            in_frame_id: frame,
            type_tag: "log".into(),
            payload: serde_json::Value::Null,
            user_call_site: None,
        }
    }

    fn meta() -> MetaJson {
        MetaJson {
            php_version: "8.3".into(),
            periscope_version: "0".into(),
            sapi: "cli".into(),
            entry_point: "".into(),
            working_dir: "".into(),
            hostname: "".into(),
            pid: 0,
            started_at_unix_micros: 0,
            duration_micros: 100,
            request: None,
            response: None,
        }
    }

    #[test]
    fn at_time_returns_deepest_frame_and_prefix_events() {
        let frames = vec![
            f(1, 0, 0, 100, 1),
            f(2, 1, 10, 60, 2),
            f(3, 2, 20, 50, 3),
        ];
        let events = vec![evt(1, 1, 5), evt(2, 2, 25), evt(3, 3, 40)];
        let trace = Arc::new(TraceJson {
            id: "t".into(),
            meta: meta(),
            frames,
            observability_events: events,
        });
        let idx = Arc::new(TraceIndex::build(trace));
        let s = at_time(idx, 30);
        assert_eq!(s.current_frame.as_ref().unwrap().id, 3);
        let stack_ids: Vec<u32> = s.stack.iter().map(|f| f.id).collect();
        assert_eq!(stack_ids, vec![3, 2, 1]);
        let event_ids: Vec<u32> = s.events_so_far.iter().map(|e| e.id).collect();
        assert_eq!(event_ids, vec![1, 2]); // only events up to t=30
    }
}
