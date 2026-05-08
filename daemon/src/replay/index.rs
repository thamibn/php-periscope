//! In-memory index over a decoded trace.
//!
//! Built once when a trace is opened; gives the cursor + state-reconstruction
//! O(1) lookups by frame id and event id, plus a time-sorted pre-order frame
//! walk so stepping is just an integer increment.

use std::collections::HashMap;
use std::sync::Arc;

use crate::trace_view::{EventJson, FrameJson, TraceJson};

pub struct TraceIndex {
    /// The decoded trace itself; kept alive so frames/events can be borrowed.
    pub trace: Arc<TraceJson>,

    /// frame_id → position in `trace.frames`.
    frame_pos: HashMap<u32, usize>,

    /// frame_id → ordered list of child frame_ids (call-tree edges).
    children: HashMap<u32, Vec<u32>>,

    /// Pre-order traversal of the call tree by enter_micros ascending. The
    /// `Vec<u32>` is a sequence of frame ids — index into it is the cursor's
    /// position. step_in / step_over / step_out are integer arithmetic on
    /// this list paired with depth comparisons.
    preorder: Vec<u32>,

    /// frame_id → position in `preorder`.
    preorder_pos: HashMap<u32, usize>,

    /// All observability events in the trace, ordered by `at_micros` ascending.
    /// Index inside this list lets us answer "what fired before time T".
    events_by_time: Vec<u32>,

    /// event_id → position in `trace.observability_events`.
    event_pos: HashMap<u32, usize>,

    /// Root frame ids (parent_id == 0).
    roots: Vec<u32>,
}

impl TraceIndex {
    /// Build the index. Cost is O(F log F + E log E) on F frames + E events
    /// — measured at well under 100ms on traces an order of magnitude beyond
    /// what v1 will produce.
    pub fn build(trace: Arc<TraceJson>) -> Self {
        let frames = &trace.frames;
        let events = &trace.observability_events;

        let mut frame_pos = HashMap::with_capacity(frames.len());
        for (i, f) in frames.iter().enumerate() {
            frame_pos.insert(f.id, i);
        }

        let mut children: HashMap<u32, Vec<u32>> = HashMap::with_capacity(frames.len());
        let mut roots: Vec<u32> = vec![];
        for f in frames {
            if f.parent_id == 0 {
                roots.push(f.id);
            } else {
                children.entry(f.parent_id).or_default().push(f.id);
            }
        }
        // Sort each child list by enter_micros so traversal order is the
        // actual call order, not whatever post-order the writer used.
        for kids in children.values_mut() {
            kids.sort_by_key(|id| frame_pos.get(id).map(|p| frames[*p].enter_micros).unwrap_or(0));
        }
        roots.sort_by_key(|id| frame_pos.get(id).map(|p| frames[*p].enter_micros).unwrap_or(0));

        // Pre-order DFS over the call tree.
        let mut preorder: Vec<u32> = Vec::with_capacity(frames.len());
        let mut stack: Vec<u32> = roots.iter().rev().copied().collect();
        while let Some(id) = stack.pop() {
            preorder.push(id);
            if let Some(kids) = children.get(&id) {
                for k in kids.iter().rev() {
                    stack.push(*k);
                }
            }
        }

        let mut preorder_pos = HashMap::with_capacity(preorder.len());
        for (i, id) in preorder.iter().enumerate() {
            preorder_pos.insert(*id, i);
        }

        let mut event_pos = HashMap::with_capacity(events.len());
        for (i, e) in events.iter().enumerate() {
            event_pos.insert(e.id, i);
        }
        let mut events_by_time: Vec<u32> = events.iter().map(|e| e.id).collect();
        events_by_time.sort_by_key(|id| event_pos.get(id).map(|p| events[*p].at_micros).unwrap_or(0));

        Self {
            trace,
            frame_pos,
            children,
            preorder,
            preorder_pos,
            events_by_time,
            event_pos,
            roots,
        }
    }

    pub fn frame(&self, id: u32) -> Option<&FrameJson> {
        self.frame_pos.get(&id).map(|p| &self.trace.frames[*p])
    }

    pub fn event(&self, id: u32) -> Option<&EventJson> {
        self.event_pos
            .get(&id)
            .map(|p| &self.trace.observability_events[*p])
    }

    pub fn children_of(&self, id: u32) -> &[u32] {
        self.children.get(&id).map(|v| v.as_slice()).unwrap_or(&[])
    }

    pub fn roots(&self) -> &[u32] {
        &self.roots
    }

    pub fn preorder(&self) -> &[u32] {
        &self.preorder
    }

    pub fn preorder_pos_of(&self, id: u32) -> Option<usize> {
        self.preorder_pos.get(&id).copied()
    }

    /// The deepest frame whose `[enter, exit)` interval contains `time`.
    pub fn frame_at(&self, time: u64) -> Option<u32> {
        // Walk the call tree depth-first, descending into the child whose
        // window contains `time`. Cost is O(depth) which is bounded by call
        // stack depth — typically tens, not thousands.
        let mut current: Option<u32> = None;
        let mut candidates: Vec<u32> = self.roots.clone();
        loop {
            let mut found: Option<u32> = None;
            for id in &candidates {
                if let Some(f) = self.frame(*id) {
                    if f.enter_micros <= time && time < f.exit_micros.max(f.enter_micros + 1) {
                        found = Some(*id);
                        break;
                    }
                }
            }
            match found {
                Some(id) => {
                    current = Some(id);
                    let kids = self.children_of(id);
                    if kids.is_empty() {
                        break;
                    }
                    candidates = kids.to_vec();
                }
                None => break,
            }
        }
        current
    }

    /// Walk up the parent chain from `frame_id` to the root.
    /// Returns the chain leaf-first (current frame first, root last).
    pub fn stack_from(&self, frame_id: u32) -> Vec<u32> {
        let mut out = vec![];
        let mut next = frame_id;
        while next != 0 {
            match self.frame(next) {
                Some(f) => {
                    out.push(next);
                    next = f.parent_id;
                }
                None => break,
            }
        }
        out
    }

    /// All event ids whose `at_micros` is <= `time`.
    pub fn events_before(&self, time: u64) -> Vec<u32> {
        // Binary search the sorted list, then return the prefix.
        let cutoff = self
            .events_by_time
            .partition_point(|id| {
                self.event(*id).map(|e| e.at_micros <= time).unwrap_or(false)
            });
        self.events_by_time[..cutoff].to_vec()
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::trace_view::{EventJson, FrameJson, MetaJson};

    fn frame(id: u32, parent: u32, enter: u64, exit: u64, depth: u32) -> FrameJson {
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

    #[test]
    fn preorder_matches_call_tree() {
        // {main}(1) calls a(2), a calls b(3), then a returns, then c(4) is called
        let frames = vec![
            frame(1, 0, 0, 100, 1),
            frame(2, 1, 5, 60, 2),
            frame(3, 2, 10, 50, 3),
            frame(4, 1, 70, 90, 2),
        ];
        let trace = Arc::new(TraceJson {
            id: "t".into(),
            meta: meta(),
            frames,
            observability_events: vec![],
        });
        let idx = TraceIndex::build(trace);
        assert_eq!(idx.preorder(), &[1, 2, 3, 4]);
        assert_eq!(idx.children_of(1), &[2, 4]);
        assert_eq!(idx.children_of(2), &[3]);
        assert_eq!(idx.stack_from(3), vec![3, 2, 1]);
    }

    #[test]
    fn frame_at_picks_deepest_overlapping() {
        let frames = vec![
            frame(1, 0, 0, 100, 1),
            frame(2, 1, 10, 60, 2),
            frame(3, 2, 20, 40, 3),
        ];
        let trace = Arc::new(TraceJson {
            id: "t".into(),
            meta: meta(),
            frames,
            observability_events: vec![],
        });
        let idx = TraceIndex::build(trace);
        assert_eq!(idx.frame_at(5), Some(1));
        assert_eq!(idx.frame_at(15), Some(2));
        assert_eq!(idx.frame_at(25), Some(3));
        assert_eq!(idx.frame_at(70), Some(1));
        assert_eq!(idx.frame_at(200), None);
    }

    #[test]
    fn events_before_returns_prefix() {
        let frames = vec![frame(1, 0, 0, 100, 1)];
        let events = vec![evt(1, 1, 10), evt(2, 1, 30), evt(3, 1, 70)];
        let trace = Arc::new(TraceJson {
            id: "t".into(),
            meta: meta(),
            frames,
            observability_events: events,
        });
        let idx = TraceIndex::build(trace);
        assert_eq!(idx.events_before(0), vec![] as Vec<u32>);
        assert_eq!(idx.events_before(20), vec![1]);
        assert_eq!(idx.events_before(50), vec![1, 2]);
        assert_eq!(idx.events_before(1000), vec![1, 2, 3]);
    }
}
