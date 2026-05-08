#![forbid(unsafe_code)]
#![deny(warnings)]

//! Public crate root for `periscope-daemon`.
//!
//! v1 surface is intentionally tiny: a `Trace` reader. DAP server +
//! replay engine come in Phase 6 / 7.

pub mod trace;

#[allow(clippy::all)]
#[allow(unused_imports)]
pub mod trace_capnp {
    include!(concat!(env!("OUT_DIR"), "/trace_capnp.rs"));
}
