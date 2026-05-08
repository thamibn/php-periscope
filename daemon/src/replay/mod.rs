//! Replay engine — answers "what was the world like at time T?"
//!
//! Used by the DAP server (for `stepBack`, `reverseContinue`) and by the
//! browser UI's timeline scrubber (`GET /api/traces/{id}/state?at=…`).
//!
//! v1 is function-boundary granularity: frames have entered/exited times
//! and a snapshot of args + return value. We do not interpolate "between"
//! function boundaries — the cursor lands on frame enters.

pub mod cursor;
pub mod index;
pub mod state;

pub use cursor::{BreakpointSet, ReplayCursor, StepKind};
pub use index::TraceIndex;
pub use state::{at_frame, at_time, ReconstructedState, ScopeKind, ScopeVariable};
