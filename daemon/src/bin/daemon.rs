#![forbid(unsafe_code)]
#![deny(warnings)]

//! `periscope-daemon` — Phase 6 entry point.
//!
//! Three concurrent services, each optional:
//!   - HTTP API at `--listen` (default 127.0.0.1:9999) — for the browser
//!     UI and AI agents (Claude Code, Cursor, ...).
//!   - Unix-socket server for the C extension to publish lifecycle events.
//!   - DAP stdio server for IDEs (VSCode/Neovim/Zed/JetBrains via plugin).
//!
//! Privacy: bind defaults to localhost. Pass `--listen 0.0.0.0:9999`
//! explicitly to expose externally — the docs make the trade-off loud.

use std::net::SocketAddr;
use std::path::PathBuf;
use std::sync::Arc;

use anyhow::{Context, Result};
use clap::Parser;

use periscope_daemon::api::{self, ApiState};
use periscope_daemon::dap::DapServer;
use periscope_daemon::ext_link::{self, LinkBus};

#[derive(Parser, Debug)]
#[command(version, about = "php-periscope daemon: HTTP API + DAP + extension link")]
struct Args {
    /// Directory holding `.cptrace` files. Created if missing.
    #[arg(long, env = "PERISCOPE_TRACE_DIR", default_value = "/tmp/periscope")]
    trace_dir: PathBuf,

    /// Project root. `/api/file` enforces that requested paths sit under
    /// this directory. Defaults to the current working directory.
    #[arg(long, env = "PERISCOPE_PROJECT_ROOT")]
    project_root: Option<PathBuf>,

    /// HTTP listen address. Defaults to localhost — *do not* bind to
    /// 0.0.0.0 unless you understand that trace contents (cookies, request
    /// bodies, captured variables) become reachable from any host that can
    /// route to the daemon. Override via `--listen` or `PERISCOPE_LISTEN`.
    #[arg(long, env = "PERISCOPE_LISTEN", default_value = "127.0.0.1:9999")]
    listen: SocketAddr,

    /// Unix-domain socket path for the C extension's daemon link.
    #[arg(long, env = "PERISCOPE_DAEMON_SOCKET", default_value = "/tmp/periscope/daemon.sock")]
    socket: PathBuf,

    /// Disable the HTTP API server.
    #[arg(long)]
    no_http: bool,

    /// Disable the extension link Unix socket server.
    #[arg(long)]
    no_socket: bool,

    /// Run the DAP server on stdio. Off by default — IDEs spawn the daemon
    /// with this flag set.
    #[arg(long)]
    dap_stdio: bool,

    /// Directory containing the built SolidJS UI bundle. When set, the daemon
    /// serves it at `/`. Defaults to the sibling `ui/dist` if it exists.
    #[arg(long, env = "PERISCOPE_UI_DIR")]
    ui_dir: Option<PathBuf>,
}

#[tokio::main]
async fn main() -> Result<()> {
    let args = Args::parse();
    init_tracing();

    tokio::fs::create_dir_all(&args.trace_dir)
        .await
        .with_context(|| {
            format!(
                "creating trace dir {}",
                args.trace_dir.display()
            )
        })?;

    let project_root = args
        .project_root
        .unwrap_or_else(|| std::env::current_dir().unwrap_or_else(|_| PathBuf::from(".")));

    let bus = Arc::new(LinkBus::new());

    let mut tasks: Vec<tokio::task::JoinHandle<Result<()>>> = vec![];

    if !args.no_http {
        let ui_dir = resolve_ui_dir(args.ui_dir.clone(), &project_root);
        if let Some(dir) = &ui_dir {
            tracing::info!(ui_dir=%dir.display(), "serving ui bundle at /");
        }
        let state = ApiState::new(args.trace_dir.clone(), project_root.clone(), bus.clone())
            .with_ui_dir(ui_dir);
        let listen = args.listen;
        tasks.push(tokio::spawn(async move {
            api::serve(state, listen).await
        }));
    }

    if !args.no_socket {
        let socket = args.socket.clone();
        let bus = bus.clone();
        tasks.push(tokio::spawn(async move {
            ext_link::serve(socket, bus).await
        }));
    }

    if args.dap_stdio {
        tracing::info!("running DAP server on stdio");
        let stdin = tokio::io::stdin();
        let stdout = tokio::io::stdout();
        let server = DapServer::new(stdin, stdout).with_bus(bus.clone());
        server.run().await?;
        for t in tasks {
            t.abort();
        }
        return Ok(());
    }

    if tasks.is_empty() {
        // Nothing to do — exit instead of looping forever silently.
        eprintln!("nothing to run: pass --dap-stdio or omit --no-http / --no-socket");
        std::process::exit(2);
    }

    // Wait for SIGINT / SIGTERM and then unwind.
    let ctrl_c = async {
        tokio::signal::ctrl_c()
            .await
            .context("listening for ctrl-c")
    };

    #[cfg(unix)]
    let term = async {
        let mut s = tokio::signal::unix::signal(tokio::signal::unix::SignalKind::terminate())
            .context("installing SIGTERM handler")?;
        s.recv().await;
        Ok::<_, anyhow::Error>(())
    };

    #[cfg(unix)]
    tokio::select! {
        r = ctrl_c => { r?; }
        r = term => { r?; }
    }
    #[cfg(not(unix))]
    {
        ctrl_c.await?;
    }

    tracing::info!("shutdown signal received; aborting services");
    for t in tasks {
        t.abort();
    }
    Ok(())
}

fn resolve_ui_dir(explicit: Option<PathBuf>, project_root: &PathBuf) -> Option<PathBuf> {
    if let Some(d) = explicit {
        return d.is_dir().then_some(d);
    }
    // Check sibling locations relative to the daemon binary's project layout.
    let candidates = [
        project_root.join("ui/dist"),
        PathBuf::from("ui/dist"),
        // when running from daemon/, the UI sits one level up
        PathBuf::from("../ui/dist"),
    ];
    candidates.into_iter().find(|p| p.is_dir())
}

fn init_tracing() {
    let filter = tracing_subscriber::EnvFilter::try_from_env("PERISCOPE_LOG")
        .unwrap_or_else(|_| tracing_subscriber::EnvFilter::new("info"));
    // CRITICAL: route logs to stderr. When the daemon runs with --dap-stdio
    // the stdout stream is the DAP protocol channel — any tracing line
    // written to stdout corrupts the next message frame (the IDE plugin
    // reads it as a DAP envelope, finds no Content-Length header, and the
    // session dies). Stderr is the universally correct sink for diagnostic
    // logs anyway; install.sh's --version probe + the HTTP/socket modes
    // are unaffected by this change.
    let _ = tracing_subscriber::fmt()
        .with_writer(std::io::stderr)
        .with_env_filter(filter)
        .with_target(false)
        .try_init();
}
