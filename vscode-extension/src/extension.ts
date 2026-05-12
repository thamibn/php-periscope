import { spawn, ChildProcess } from "node:child_process";
import * as http from "node:http";
import * as vscode from "vscode";

const DEBUG_TYPE = "periscope";

let daemonProcess: ChildProcess | undefined;
let daemonStatusItem: vscode.StatusBarItem | undefined;
let statusPollHandle: NodeJS.Timeout | undefined;

export function activate(context: vscode.ExtensionContext): void {
  context.subscriptions.push(
    vscode.debug.registerDebugAdapterDescriptorFactory(DEBUG_TYPE, new DaemonAdapterFactory()),
    vscode.debug.registerDebugConfigurationProvider(DEBUG_TYPE, new DefaultConfigProvider()),
    vscode.commands.registerCommand("periscope.openUi", openUi),
    vscode.commands.registerCommand("periscope.startDaemon", () => startDaemon(context)),
    vscode.commands.registerCommand("periscope.stopDaemon", stopDaemon),
  );

  daemonStatusItem = vscode.window.createStatusBarItem(vscode.StatusBarAlignment.Right, 100);
  daemonStatusItem.command = "periscope.openUi";
  context.subscriptions.push(daemonStatusItem);

  if (config().get<boolean>("autoStartDaemon", true)) {
    void startDaemon(context);
  }
  startStatusPoll();
}

export function deactivate(): void {
  stopStatusPoll();
  stopDaemon();
}

// ---------- daemon lifecycle ----------

async function startDaemon(context: vscode.ExtensionContext): Promise<void> {
  if (daemonProcess && daemonProcess.exitCode === null) {
    vscode.window.showInformationMessage("Periscope daemon already running.");
    return;
  }
  const path = config().get<string>("daemonPath", "periscope-daemon");
  try {
    const proc = spawn(path, [], {
      stdio: ["ignore", "pipe", "pipe"],
      env: { ...process.env },
    });
    daemonProcess = proc;

    const channel = vscode.window.createOutputChannel("Periscope Daemon");
    context.subscriptions.push(channel);
    proc.stdout?.on("data", (b: Buffer) => channel.append(b.toString()));
    proc.stderr?.on("data", (b: Buffer) => channel.append(b.toString()));
    proc.on("exit", (code, signal) => {
      channel.appendLine(`\n[daemon exited code=${code ?? "?"} signal=${signal ?? "?"}]`);
      daemonProcess = undefined;
      updateStatus(false);
    });

    // Don't await — let the status poll mark it live once the HTTP port responds.
    void vscode.window.showInformationMessage("Periscope daemon started.");
  } catch (e) {
    const msg = e instanceof Error ? e.message : String(e);
    void vscode.window.showErrorMessage(
      `Failed to spawn periscope-daemon (${path}): ${msg}. ` +
        "Install via scripts/install.sh or set periscope.daemonPath.",
    );
  }
}

function stopDaemon(): void {
  if (!daemonProcess) return;
  daemonProcess.kill("SIGTERM");
  daemonProcess = undefined;
  updateStatus(false);
}

// ---------- status bar ----------

function startStatusPoll(): void {
  pollOnce();
  statusPollHandle = setInterval(pollOnce, 3000);
}

function stopStatusPoll(): void {
  if (statusPollHandle) clearInterval(statusPollHandle);
  statusPollHandle = undefined;
}

function pollOnce(): void {
  const url = new URL("/api/health", config().get<string>("daemonUrl", "http://127.0.0.1:9999"));
  const req = http.get(url, { timeout: 1500 }, (res) => {
    updateStatus(res.statusCode === 200);
    res.resume();
  });
  req.on("error", () => updateStatus(false));
  req.on("timeout", () => {
    req.destroy();
    updateStatus(false);
  });
}

function updateStatus(live: boolean): void {
  if (!daemonStatusItem) return;
  daemonStatusItem.text = live ? "$(eye) periscope" : "$(eye-closed) periscope (offline)";
  daemonStatusItem.tooltip = live
    ? "Periscope daemon is reachable. Click to open the UI."
    : "Periscope daemon is not reachable. Run 'Periscope: Start Daemon' from the command palette.";
  daemonStatusItem.show();
}

// ---------- DAP wiring ----------

class DaemonAdapterFactory implements vscode.DebugAdapterDescriptorFactory {
  createDebugAdapterDescriptor(
    _session: vscode.DebugSession,
    _executable: vscode.DebugAdapterExecutable | undefined,
  ): vscode.ProviderResult<vscode.DebugAdapterDescriptor> {
    const path = config().get<string>("daemonPath", "periscope-daemon");
    return new vscode.DebugAdapterExecutable(path, ["--dap-stdio"]);
  }
}

class DefaultConfigProvider implements vscode.DebugConfigurationProvider {
  resolveDebugConfiguration(
    folder: vscode.WorkspaceFolder | undefined,
    config: vscode.DebugConfiguration,
  ): vscode.ProviderResult<vscode.DebugConfiguration> {
    if (!config.type && !config.request && !config.name) {
      // User hit F5 without a launch.json — synthesize a sensible default.
      config.type = DEBUG_TYPE;
      config.request = "launch";
      config.name = "Periscope: open latest trace";
      config.tracePath = `${folder?.uri.fsPath ?? "${workspaceFolder}"}/tmp/periscope/latest.cptrace`;
      config.stopOnEntry = false;
    }
    return config;
  }
}

// ---------- commands ----------

async function openUi(): Promise<void> {
  const url = config().get<string>("daemonUrl", "http://127.0.0.1:9999");
  await vscode.env.openExternal(vscode.Uri.parse(url));
}

// ---------- helpers ----------

function config(): vscode.WorkspaceConfiguration {
  return vscode.workspace.getConfiguration("periscope");
}
