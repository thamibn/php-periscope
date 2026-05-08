#![forbid(unsafe_code)]
#![deny(warnings)]

//! Public crate root for `periscope-daemon`.

pub mod api;
pub mod dap;
pub mod ext_link;
pub mod insights;
pub mod summary;
pub mod trace;
pub mod trace_view;

#[allow(clippy::all)]
#[allow(unused_imports)]
pub mod trace_capnp {
    include!(concat!(env!("OUT_DIR"), "/trace_capnp.rs"));
}
