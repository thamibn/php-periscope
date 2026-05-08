//! Cursor that walks the recorded execution.
//!
//! The cursor is a position inside the pre-order frame walk that
//! `TraceIndex` precomputes. Every step operation is integer arithmetic on
//! that index plus a parent-id check. No re-traversal, no allocation per
//! step.
//!
//! Step semantics, mapped against DAP:
//!
//!   - `step_in`     → next frame in pre-order. If the current frame has a
//!                     child, that's it; otherwise it's the next sibling /
//!                     ancestor's next sibling.
//!   - `step_over`   → next frame whose depth ≤ current frame's depth. Skips
//!                     into child frames as if the call returned in one tick.
//!   - `step_out`    → the current frame's parent.
//!   - `step_back`   → previous frame in pre-order.
//!   - `forward_continue` / `reverse_continue` → walk forward/backward
//!     until a breakpoint matches or we hit the boundary.
//!
//! Breakpoints are kept simple: a `(file, line)` set the cursor matches
//! against `frame.file` + `frame.line`. Conditional / hit-count / log-point
//! breakpoints are post-v1.

use std::collections::HashSet;
use std::sync::Arc;

use serde::{Deserialize, Serialize};

use crate::replay::index::TraceIndex;
use crate::trace_view::FrameJson;

#[derive(Copy, Clone, Debug, PartialEq, Eq)]
pub enum StepKind {
    In,
    Over,
    Out,
    Back,
}

#[derive(Default, Debug, Clone, Serialize, Deserialize)]
pub struct BreakpointSet {
    /// `(file, line)` pairs the cursor stops on.
    pub points: HashSet<(String, u32)>,
}

impl BreakpointSet {
    pub fn matches(&self, frame: &FrameJson) -> bool {
        if self.points.is_empty() {
            return false;
        }
        self.points.contains(&(frame.file.clone(), frame.line))
    }
}

pub struct ReplayCursor {
    index: Arc<TraceIndex>,
    /// Position into `index.preorder()`.
    pos: usize,
}

impl ReplayCursor {
    pub fn new(index: Arc<TraceIndex>) -> Self {
        Self { index, pos: 0 }
    }

    pub fn index(&self) -> &TraceIndex {
        &self.index
    }

    pub fn current_frame_id(&self) -> Option<u32> {
        self.index.preorder().get(self.pos).copied()
    }

    pub fn current_frame(&self) -> Option<&FrameJson> {
        self.current_frame_id().and_then(|id| self.index.frame(id))
    }

    pub fn time(&self) -> u64 {
        self.current_frame().map(|f| f.enter_micros).unwrap_or(0)
    }

    /// Stack from current frame to the root, leaf-first.
    pub fn stack(&self) -> Vec<&FrameJson> {
        self.current_frame_id()
            .map(|id| {
                self.index
                    .stack_from(id)
                    .into_iter()
                    .filter_map(|fid| self.index.frame(fid))
                    .collect()
            })
            .unwrap_or_default()
    }

    pub fn step(&mut self, kind: StepKind) -> Option<u32> {
        match kind {
            StepKind::In => self.step_in(),
            StepKind::Over => self.step_over(),
            StepKind::Out => self.step_out(),
            StepKind::Back => self.step_back(),
        }
    }

    pub fn step_in(&mut self) -> Option<u32> {
        let preorder = self.index.preorder();
        if self.pos + 1 < preorder.len() {
            self.pos += 1;
        }
        self.current_frame_id()
    }

    /// Skip over any child frames of the current frame.
    pub fn step_over(&mut self) -> Option<u32> {
        let preorder = self.index.preorder();
        let cur_depth = match self.current_frame() {
            Some(f) => f.depth,
            None => return None,
        };
        let mut next = self.pos + 1;
        while next < preorder.len() {
            if let Some(f) = self.index.frame(preorder[next]) {
                if f.depth <= cur_depth {
                    break;
                }
            }
            next += 1;
        }
        if next < preorder.len() {
            self.pos = next;
        }
        self.current_frame_id()
    }

    pub fn step_out(&mut self) -> Option<u32> {
        let parent_id = match self.current_frame() {
            Some(f) if f.parent_id != 0 => f.parent_id,
            _ => return self.current_frame_id(),
        };
        // Move forward in pre-order to the *next* frame whose depth is
        // less than the current frame's depth — that's "after the parent
        // returns." If none exists, sit on the parent's pre-order slot.
        let cur_depth = match self.current_frame() {
            Some(f) => f.depth,
            None => return None,
        };
        let preorder = self.index.preorder();
        let mut next = self.pos + 1;
        while next < preorder.len() {
            if let Some(f) = self.index.frame(preorder[next]) {
                if f.depth < cur_depth {
                    self.pos = next;
                    return self.current_frame_id();
                }
            }
            next += 1;
        }
        // Fall back to the parent itself — keeps the user looking at a
        // valid frame instead of running off the end.
        if let Some(parent_pos) = self.index.preorder_pos_of(parent_id) {
            self.pos = parent_pos;
        }
        self.current_frame_id()
    }

    pub fn step_back(&mut self) -> Option<u32> {
        if self.pos > 0 {
            self.pos -= 1;
        }
        self.current_frame_id()
    }

    pub fn forward_continue(&mut self, breakpoints: &BreakpointSet) -> Option<u32> {
        let preorder = self.index.preorder();
        let mut next = self.pos + 1;
        while next < preorder.len() {
            if let Some(f) = self.index.frame(preorder[next]) {
                if breakpoints.matches(f) {
                    self.pos = next;
                    return Some(preorder[next]);
                }
            }
            next += 1;
        }
        // No breakpoint hit — park on the last frame.
        if !preorder.is_empty() {
            self.pos = preorder.len() - 1;
        }
        self.current_frame_id()
    }

    pub fn reverse_continue(&mut self, breakpoints: &BreakpointSet) -> Option<u32> {
        if self.pos == 0 {
            return self.current_frame_id();
        }
        let preorder = self.index.preorder();
        let mut next = self.pos.saturating_sub(1);
        loop {
            if let Some(f) = self.index.frame(preorder[next]) {
                if breakpoints.matches(f) {
                    self.pos = next;
                    return Some(preorder[next]);
                }
            }
            if next == 0 {
                break;
            }
            next -= 1;
        }
        self.pos = 0;
        self.current_frame_id()
    }

    /// Park the cursor at the deepest frame whose interval contains `time`.
    /// Used by the browser timeline scrubber.
    pub fn seek_time(&mut self, time: u64) -> Option<u32> {
        let frame_id = self.index.frame_at(time)?;
        if let Some(p) = self.index.preorder_pos_of(frame_id) {
            self.pos = p;
        }
        Some(frame_id)
    }

    /// Park the cursor on a specific frame.
    pub fn seek_frame(&mut self, frame_id: u32) -> Option<u32> {
        let p = self.index.preorder_pos_of(frame_id)?;
        self.pos = p;
        self.current_frame_id()
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::replay::index::TraceIndex;
    use crate::trace_view::{FrameJson, MetaJson, TraceJson};

    fn f(id: u32, parent: u32, enter: u64, exit: u64, depth: u32, file: &str, line: u32) -> FrameJson {
        FrameJson {
            id,
            parent_id: parent,
            function: format!("f{}", id),
            file: file.to_string(),
            line,
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

    /// {main}(1)
    /// ├── a(2)
    /// │   └── b(3)
    /// └── c(4)
    fn fixture() -> Arc<TraceIndex> {
        let frames = vec![
            f(1, 0, 0, 100, 1, "main.php", 1),
            f(2, 1, 5, 60, 2, "a.php", 10),
            f(3, 2, 10, 50, 3, "b.php", 20),
            f(4, 1, 70, 90, 2, "c.php", 30),
        ];
        let trace = Arc::new(TraceJson {
            id: "t".into(),
            meta: meta(),
            frames,
            observability_events: vec![],
        });
        Arc::new(TraceIndex::build(trace))
    }

    #[test]
    fn step_in_walks_preorder() {
        let mut cur = ReplayCursor::new(fixture());
        assert_eq!(cur.current_frame_id(), Some(1));
        assert_eq!(cur.step_in(), Some(2));
        assert_eq!(cur.step_in(), Some(3));
        assert_eq!(cur.step_in(), Some(4));
        // No more — sticks at end.
        assert_eq!(cur.step_in(), Some(4));
    }

    #[test]
    fn step_over_skips_subtree() {
        let mut cur = ReplayCursor::new(fixture());
        // From {main}, step_over → next frame at depth ≤ 1; there's none,
        // so we park on the last frame.
        cur.seek_frame(2);
        // From a (depth 2), step_over should skip b and land on c.
        assert_eq!(cur.step_over(), Some(4));
    }

    #[test]
    fn step_out_returns_to_caller() {
        let mut cur = ReplayCursor::new(fixture());
        cur.seek_frame(3); // currently in b
        // step_out from b → next frame whose depth < 3; that's c (depth 2).
        assert_eq!(cur.step_out(), Some(4));
    }

    #[test]
    fn step_back_walks_preorder_in_reverse() {
        let mut cur = ReplayCursor::new(fixture());
        cur.seek_frame(4);
        assert_eq!(cur.step_back(), Some(3));
        assert_eq!(cur.step_back(), Some(2));
        assert_eq!(cur.step_back(), Some(1));
        assert_eq!(cur.step_back(), Some(1)); // floor at root
    }

    #[test]
    fn forward_continue_stops_on_breakpoint() {
        let mut cur = ReplayCursor::new(fixture());
        let mut bps = BreakpointSet::default();
        bps.points.insert(("c.php".into(), 30));
        assert_eq!(cur.forward_continue(&bps), Some(4));
    }

    #[test]
    fn reverse_continue_stops_on_breakpoint() {
        let mut cur = ReplayCursor::new(fixture());
        cur.seek_frame(4);
        let mut bps = BreakpointSet::default();
        bps.points.insert(("a.php".into(), 10));
        assert_eq!(cur.reverse_continue(&bps), Some(2));
    }

    #[test]
    fn seek_time_picks_deepest_window() {
        let mut cur = ReplayCursor::new(fixture());
        assert_eq!(cur.seek_time(25), Some(3));
        assert_eq!(cur.current_frame_id(), Some(3));
    }
}
