//! Reader for `.cptrace` files written by the C extension.
//!
//! v1 reads the whole file into a `Vec<u8>` (no `unsafe` per crate
//! invariant). Phase 7 (the replay engine) will revisit if zero-copy
//! mmap becomes a measurable bottleneck — we'd quarantine the unsafe
//! mmap call into a tightly-scoped helper crate at that point.

use std::path::{Path, PathBuf};

use anyhow::{Context, Result};
use capnp::serialize;

use crate::trace_capnp;

pub struct Trace {
    path: PathBuf,
    message: capnp::message::Reader<capnp::serialize::OwnedSegments>,
}

impl Trace {
    pub fn open(path: impl AsRef<Path>) -> Result<Self> {
        let path = path.as_ref().to_path_buf();
        let bytes = std::fs::read(&path)
            .with_context(|| format!("opening {}", path.display()))?;

        let mut slice: &[u8] = &bytes;
        let message =
            serialize::read_message(&mut slice, capnp::message::ReaderOptions::new())
                .with_context(|| format!("parsing capnp from {}", path.display()))?;

        Ok(Self { path, message })
    }

    pub fn path(&self) -> &Path {
        &self.path
    }

    pub fn root(&self) -> Result<trace_capnp::trace::Reader<'_>> {
        Ok(self.message.get_root::<trace_capnp::trace::Reader<'_>>()?)
    }

    pub fn frames(
        &self,
    ) -> Result<impl Iterator<Item = trace_capnp::frame::Reader<'_>> + '_> {
        Ok(self.root()?.get_frames()?.iter())
    }
}
