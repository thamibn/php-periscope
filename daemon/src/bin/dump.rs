#![forbid(unsafe_code)]
#![deny(warnings)]

//! `periscope-dump <trace.cptrace>` — print the meta block + every frame.
//! Useful for "did the C extension actually write something sensible?".

use std::path::PathBuf;

use anyhow::Result;
use clap::Parser;

use periscope_daemon::trace::Trace;
use periscope_daemon::trace_capnp;

#[derive(Parser, Debug)]
#[command(version, about = "dump a periscope trace as text")]
struct Args {
    /// Path to a `.cptrace` file.
    trace: PathBuf,

    /// Maximum number of frames to print (0 = all).
    #[arg(long, default_value_t = 0)]
    limit: usize,
}

fn main() -> Result<()> {
    let args = Args::parse();
    let trace = Trace::open(&args.trace)?;
    let root = trace.root()?;
    let meta = root.get_meta()?;

    println!("trace: {}", args.trace.display());
    println!(
        "  php           {}",
        meta.get_php_version()?.to_str().unwrap_or("?")
    );
    println!(
        "  periscope     {}",
        meta.get_periscope_version()?.to_str().unwrap_or("?")
    );
    println!(
        "  sapi          {}",
        meta.get_sapi()?.to_str().unwrap_or("?")
    );
    println!(
        "  entry         {}",
        meta.get_entry_point()?.to_str().unwrap_or("?")
    );
    println!(
        "  cwd           {}",
        meta.get_working_dir()?.to_str().unwrap_or("?")
    );
    println!("  pid           {}", meta.get_pid());
    println!("  started_at_us {}", meta.get_started_at_unix_micros());
    println!("  duration_us   {}", meta.get_duration_micros());
    println!();

    let frames = root.get_frames()?;
    let total = frames.len() as usize;
    let limit = if args.limit == 0 || args.limit > total {
        total
    } else {
        args.limit
    };

    println!("frames ({} total, showing {}):", total, limit);
    for (i, f) in frames.iter().take(limit).enumerate() {
        let function = f.get_function()?.to_str().unwrap_or("?");
        let file = f.get_file()?.to_str().unwrap_or("");
        let dur_us = f.get_exit_micros().saturating_sub(f.get_enter_micros());
        let depth = f.get_depth();
        let parent = f.get_parent_id();
        println!(
            "  #{:<4} parent=#{:<4} d={:<2} {:>8}us  {}{}",
            f.get_id(),
            parent,
            depth,
            dur_us,
            function,
            if file.is_empty() {
                String::new()
            } else {
                format!("  ({}:{})", file, f.get_line())
            }
        );

        let args = f.get_args()?;
        if args.len() > 0 {
            for a in args.iter() {
                let val = a.get_value()?;
                if let Ok(text) = val.which() {
                    if let trace_capnp::value::Which::Opaque(t) = text {
                        let s = t?.to_str().unwrap_or("");
                        if !s.is_empty() {
                            println!("        args = {}", s);
                        }
                    }
                }
            }
        }

        if let Ok(rv) = f.get_return_value() {
            if let Ok(trace_capnp::value::Which::Opaque(t)) = rv.which() {
                let s = t?.to_str().unwrap_or("");
                if !s.is_empty() {
                    println!("        ret  = {}", s);
                }
            }
        }

        let _ = i;
    }

    Ok(())
}

