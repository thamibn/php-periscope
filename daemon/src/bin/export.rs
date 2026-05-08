#![forbid(unsafe_code)]
#![deny(warnings)]

//! `periscope-export` — package a recorded trace for sharing.
//!
//! Three formats, one CLI (per plan §9b "Static export + drag-and-drop"):
//!   - `html`    : the SolidJS UI bundled with the trace JSON inlined as
//!                 `window.PERISCOPE_TRACE`. Self-contained, opens in any
//!                 browser, no daemon required.
//!   - `json`    : the same `GET /api/traces/{id}` shape with insights and
//!                 summary pre-baked. Ideal for AI agents and scripting.
//!   - `cptrace` : a `cp` of the binary trace file. Round-trips through any
//!                 daemon (re-host, archive).

use std::fs;
use std::io::Write;
use std::path::{Path, PathBuf};

use anyhow::{anyhow, bail, Context, Result};
use clap::{Parser, ValueEnum};
use serde::Serialize;

use periscope_daemon::insights;
use periscope_daemon::summary;
use periscope_daemon::trace::Trace;
use periscope_daemon::trace_view::{self, TraceJson};

#[derive(Parser, Debug)]
#[command(version, about = "package a periscope trace as html / json / cptrace")]
struct Args {
    /// Trace identifier or absolute path to the `.cptrace` file.
    #[arg()]
    trace: String,

    /// Directory holding `.cptrace` files (used when `trace` is just an id).
    #[arg(long, env = "PERISCOPE_TRACE_DIR", default_value = "/tmp/periscope")]
    trace_dir: PathBuf,

    /// Output format.
    #[arg(long, default_value = "html")]
    format: Format,

    /// Output file. Defaults to `<trace-id>.<ext>` in the current dir.
    #[arg(long, short = 'o')]
    out: Option<PathBuf>,

    /// Path to the built UI bundle (defaults to sibling `ui/dist`).
    /// Only used when `--format html`.
    #[arg(long, env = "PERISCOPE_UI_DIR")]
    ui_dir: Option<PathBuf>,

    /// Pretty-print JSON output.
    #[arg(long)]
    pretty: bool,
}

#[derive(Copy, Clone, Debug, ValueEnum)]
enum Format {
    Html,
    Json,
    Cptrace,
}

#[derive(Serialize)]
struct ExportPayload<'a> {
    trace: &'a TraceJson,
    summary: summary::Summary,
    insights: insights::Insights,
}

fn main() -> Result<()> {
    let args = Args::parse();
    let path = resolve_trace_path(&args)?;
    let id = path
        .file_stem()
        .and_then(|s| s.to_str())
        .map(|s| s.to_string())
        .ok_or_else(|| anyhow!("trace path has no stem: {}", path.display()))?;

    match args.format {
        Format::Cptrace => {
            let out = args
                .out
                .clone()
                .unwrap_or_else(|| PathBuf::from(format!("{id}.cptrace")));
            fs::copy(&path, &out)
                .with_context(|| format!("copying {} -> {}", path.display(), out.display()))?;
            println!("wrote {}", out.display());
            return Ok(());
        }
        Format::Json => {
            let payload = build_payload(&path, &id)?;
            let out = args
                .out
                .clone()
                .unwrap_or_else(|| PathBuf::from(format!("{id}.json")));
            let bytes = if args.pretty {
                serde_json::to_vec_pretty(&payload)?
            } else {
                serde_json::to_vec(&payload)?
            };
            fs::write(&out, bytes)
                .with_context(|| format!("writing {}", out.display()))?;
            println!("wrote {}", out.display());
            return Ok(());
        }
        Format::Html => {
            let payload = build_payload(&path, &id)?;
            let ui_dir = resolve_ui_dir(args.ui_dir.clone())?;
            let out = args
                .out
                .clone()
                .unwrap_or_else(|| PathBuf::from(format!("{id}.html")));
            write_html(&ui_dir, &payload, &out)?;
            println!("wrote {}", out.display());
            return Ok(());
        }
    }
}

fn resolve_trace_path(args: &Args) -> Result<PathBuf> {
    let raw = PathBuf::from(&args.trace);
    if raw.exists() {
        return Ok(raw);
    }
    let by_id = args.trace_dir.join(format!("{}.cptrace", args.trace));
    if by_id.exists() {
        return Ok(by_id);
    }
    bail!(
        "trace not found: tried {} and {}",
        raw.display(),
        by_id.display()
    );
}

fn build_payload(path: &Path, id: &str) -> Result<ExportPayload<'static>> {
    // We Box::leak the decoded trace so the &'static lifetime in the payload
    // is real for the duration of this short-lived process. This avoids
    // threading lifetimes through an export-only struct.
    let trace = Trace::open(path).with_context(|| format!("opening {}", path.display()))?;
    let decoded: TraceJson = trace_view::decode_trace(&trace, id)?;
    let summary = summary::compute(&decoded);
    let insights = insights::compute(&decoded);
    let leaked: &'static TraceJson = Box::leak(Box::new(decoded));
    Ok(ExportPayload {
        trace: leaked,
        summary,
        insights,
    })
}

fn resolve_ui_dir(explicit: Option<PathBuf>) -> Result<PathBuf> {
    if let Some(d) = explicit {
        if !d.is_dir() {
            bail!("--ui-dir does not exist: {}", d.display());
        }
        return Ok(d);
    }
    let candidates = [
        PathBuf::from("ui/dist"),
        PathBuf::from("../ui/dist"),
    ];
    for c in candidates {
        if c.is_dir() {
            return Ok(c);
        }
    }
    bail!(
        "no UI bundle found. Run `cd ui && bun run build`, or pass --ui-dir."
    );
}

fn write_html(ui_dir: &Path, payload: &ExportPayload<'_>, out: &Path) -> Result<()> {
    let index_path = ui_dir.join("index.html");
    let html = fs::read_to_string(&index_path)
        .with_context(|| format!("reading {}", index_path.display()))?;

    // Inline every CSS and JS asset Vite emitted, so the resulting file
    // works without any network access (and without ./assets/ next to it).
    let inlined = inline_assets(&html, ui_dir)?;

    // Inject the trace blob just before </head> so the SPA can read it on boot.
    let json = serde_json::to_string(payload).context("serialising trace payload")?;
    let escaped = json.replace("</", "<\\/");
    let snippet = format!(
        "<script>window.PERISCOPE_TRACE={escaped};</script>"
    );
    let final_html = if let Some(idx) = inlined.find("</head>") {
        let mut s = String::with_capacity(inlined.len() + snippet.len());
        s.push_str(&inlined[..idx]);
        s.push_str(&snippet);
        s.push_str(&inlined[idx..]);
        s
    } else {
        format!("{snippet}{inlined}")
    };

    let mut f = fs::File::create(out)
        .with_context(|| format!("creating {}", out.display()))?;
    f.write_all(final_html.as_bytes())?;
    Ok(())
}

/// Replace `<link rel="stylesheet" href="/assets/...">` and
/// `<script src="/assets/..."></script>` with their inlined contents.
fn inline_assets(html: &str, ui_dir: &Path) -> Result<String> {
    let mut out = String::with_capacity(html.len() * 2);
    let mut rest = html;

    while let Some(open) = rest.find('<') {
        out.push_str(&rest[..open]);
        // find the end of the tag
        let after = &rest[open..];
        let close = after.find('>').map(|i| i + 1).unwrap_or(after.len());
        let tag = &after[..close];
        rest = &after[close..];

        if let Some(href) =
            extract_attr(tag, "href").filter(|_| tag.contains("rel=\"stylesheet\""))
        {
            // Only inline same-origin assets — leave absolute URLs (e.g. fonts) alone.
            if !is_absolute(&href) {
                let asset = read_asset(ui_dir, &href)?;
                out.push_str("<style>");
                out.push_str(&asset);
                out.push_str("</style>");
                continue;
            }
        }
        if tag.starts_with("<script") {
            if let Some(src) = extract_attr(tag, "src") {
                if !is_absolute(&src) {
                    let asset = read_asset(ui_dir, &src)?;
                    if let Some(end) = rest.find("</script>") {
                        rest = &rest[end + "</script>".len()..];
                    }
                    out.push_str("<script type=\"module\">");
                    out.push_str(&asset);
                    out.push_str("</script>");
                    continue;
                }
            }
        }
        out.push_str(tag);
    }

    Ok(out)
}

fn extract_attr(tag: &str, name: &str) -> Option<String> {
    let needle = format!("{name}=\"");
    let i = tag.find(&needle)?;
    let start = i + needle.len();
    let end = tag[start..].find('"')? + start;
    Some(tag[start..end].to_string())
}

fn is_absolute(url: &str) -> bool {
    url.starts_with("http://")
        || url.starts_with("https://")
        || url.starts_with("//")
        || url.starts_with("data:")
}

fn read_asset(ui_dir: &Path, href: &str) -> Result<String> {
    let cleaned = href.trim_start_matches('/');
    let path = ui_dir.join(cleaned);
    fs::read_to_string(&path).with_context(|| format!("reading asset {}", path.display()))
}
