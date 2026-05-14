package dev.periscope.phpstorm.runconfig

import com.intellij.execution.ExecutionException
import com.intellij.execution.configurations.RunProfile
import com.intellij.execution.configurations.RunProfileState
import com.intellij.execution.executors.DefaultDebugExecutor
import com.intellij.execution.runners.AsyncProgramRunner
import com.intellij.execution.runners.ExecutionEnvironment
import com.intellij.execution.ui.RunContentDescriptor
import com.intellij.notification.NotificationGroupManager
import com.intellij.notification.NotificationType
import com.intellij.openapi.Disposable
import com.intellij.openapi.application.ApplicationManager
import com.intellij.openapi.diagnostic.thisLogger
import com.intellij.openapi.fileEditor.FileDocumentManager
import com.intellij.openapi.project.Project
import com.intellij.openapi.util.Disposer
import com.intellij.xdebugger.XDebugProcess
import com.intellij.xdebugger.XDebugProcessStarter
import com.intellij.xdebugger.XDebugSession
import com.intellij.xdebugger.XDebuggerManager
import com.intellij.xdebugger.breakpoints.XLineBreakpointType
import dev.periscope.phpstorm.debugger.PeriscopeDebugProcess
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import org.jetbrains.concurrency.AsyncPromise
import org.jetbrains.concurrency.Promise
import java.io.File
import java.nio.file.Path

/**
 * Wires a [PeriscopeRunConfiguration] into PhpStorm's debug subsystem.
 *
 * Three startup paths depending on the run config:
 *   * **Explicit trace** (Trace file non-blank) → load that file. If it's
 *     missing, fail fast with a clear error so the user doesn't sit watching a
 *     spinner.
 *   * **Auto + trace exists** → pick the newest `.cptrace` in `traceDir`.
 *   * **Auto + no trace yet + Listen on** → register a WatchService and start
 *     the debug session as soon as the next trace lands. The watcher is
 *     parented to a project [Disposable] so cancelling the debug attempt
 *     (Stop button, project close) tears it down — without that, every
 *     cancelled Listen attempt leaked an inotify handle.
 */
class PeriscopeDebuggerRunner : AsyncProgramRunner<com.intellij.execution.configurations.RunnerSettings>() {

    private val logger = thisLogger()

    override fun getRunnerId(): String = "PeriscopeDebuggerRunner"

    override fun canRun(executorId: String, profile: RunProfile): Boolean =
        executorId == DefaultDebugExecutor.EXECUTOR_ID && profile is PeriscopeRunConfiguration

    @Throws(ExecutionException::class)
    override fun execute(
        environment: ExecutionEnvironment,
        state: RunProfileState,
    ): Promise<RunContentDescriptor?> {
        val config = environment.runProfile as PeriscopeRunConfiguration

        FileDocumentManager.getInstance().saveAllDocuments()

        // Preflight: the daemon binary needs to exist before we attempt to
        // spawn it from PeriscopeDebugProcess. Without this check, a missing
        // binary surfaced as a debug-window-open-then-close with no visible
        // error — the same silent-failure class as the getState() null bug
        // fixed in v0.1.6.
        checkDaemonExists(config.daemonPath)

        val explicit = config.tracePath.trim()
        val traceDir = config.traceDir.ifBlank { PeriscopeRunConfigurationOptions.DEFAULT_TRACE_DIR }

        // Path 1: explicit trace file. Fail fast if it doesn't exist.
        if (explicit.isNotEmpty()) {
            val resolved = TraceResolver.resolve(explicit, traceDir)
                ?: throw ExecutionException(
                    "Periscope trace file not found: '$explicit'. " +
                        "Either point Trace file at an existing .cptrace or leave it blank to auto-pick the latest.",
                )
            return startSession(environment, config, resolved)
        }

        // Path 2: blank trace path → pick newest.
        val newest = TraceResolver.newestIn(Path.of(traceDir))
        if (newest != null) {
            return startSession(environment, config, newest)
        }
        if (!config.listen) {
            throw ExecutionException(
                "No .cptrace found in '$traceDir' and Listen mode is off. " +
                    "Enable Listen in the run configuration, or visit a route first to produce a trace.",
            )
        }

        // Path 3: listen for the next trace. The watcher coroutine is parented
        // to a project-level Disposable so:
        //   - project close cancels the watcher (closes the inotify handle)
        //   - the user hitting "stop" on the wait cancels the watcher
        // Either is necessary; the previous singleton-scope leaked handles.
        notify(
            environment.project,
            "Periscope: listening for traces in $traceDir — visit a route in your browser to start debugging.",
            NotificationType.INFORMATION,
        )

        val promise = AsyncPromise<RunContentDescriptor?>()
        val sessionScope = newSessionScope(environment.project)

        sessionScope.launch {
            try {
                val landed = TraceResolver.awaitNext(Path.of(traceDir))
                ApplicationManager.getApplication().invokeLater {
                    try {
                        startSession(environment, config, landed)
                            .onSuccess { promise.setResult(it) }
                            .onError { promise.setError(it) }
                    } catch (e: Exception) {
                        promise.setError(e)
                    }
                }
            } catch (e: Exception) {
                logger.warn("Listen mode failed", e)
                promise.setError(e)
            }
        }
        // If the promise is cancelled (user hits Stop) or completes by other
        // means, tear the watcher coroutine down so the WatchService closes.
        promise.onError { sessionScope.cancel() }
        promise.onSuccess { sessionScope.cancel() }
        return promise
    }

    private fun startSession(
        environment: ExecutionEnvironment,
        config: PeriscopeRunConfiguration,
        tracePath: Path,
    ): Promise<RunContentDescriptor?> {
        val session: XDebugSession = XDebuggerManager.getInstance(environment.project)
            .startSession(environment, object : XDebugProcessStarter() {
                override fun start(session: XDebugSession): XDebugProcess {
                    @Suppress("UNCHECKED_CAST")
                    val anyLineBreakpointType =
                        com.intellij.xdebugger.XDebuggerUtil.getInstance().lineBreakpointTypes
                            .firstOrNull() as Class<out XLineBreakpointType<*>>?
                            ?: error("No XLineBreakpointType registered in PhpStorm")

                    return PeriscopeDebugProcess(
                        session = session,
                        daemonPath = config.daemonPath,
                        tracePath = tracePath.toString(),
                        stopOnEntry = config.stopOnEntry,
                        breakpointType = anyLineBreakpointType,
                    )
                }
            })

        return org.jetbrains.concurrency.resolvedPromise(session.runContentDescriptor)
    }

    private fun checkDaemonExists(daemonPath: String) {
        // If the user typed an absolute path, just check it.
        val pathFile = File(daemonPath)
        if (pathFile.isAbsolute) {
            if (!pathFile.canExecute()) {
                throw ExecutionException(
                    "periscope-daemon not found at '$daemonPath'. " +
                        "Install it via the install.sh one-liner, or correct the Daemon binary field.",
                )
            }
            return
        }
        // Otherwise walk PATH the same way the shell would. ProcessBuilder
        // does this internally, but does so silently — by the time start()
        // throws IOException("No such file"), our debug window is already
        // open and the failure is hard to attribute.
        val pathEnv = System.getenv("PATH") ?: ""
        val found = pathEnv.split(File.pathSeparator)
            .asSequence()
            .filter { it.isNotEmpty() }
            .map { File(it, daemonPath) }
            .firstOrNull { it.canExecute() }
        if (found == null) {
            throw ExecutionException(
                "periscope-daemon not found on PATH (looked for '$daemonPath'). " +
                    "Install it via the install.sh one-liner, or set an absolute path in the Daemon binary field.",
            )
        }
    }

    /**
     * Per-session coroutine scope tied to a project-level Disposable so the
     * watcher is torn down on project close. The earlier implementation used
     * a singleton field on the runner, which is shared across all projects
     * and never cancelled — every cancelled Listen attempt leaked.
     */
    private fun newSessionScope(project: Project): CoroutineScope {
        val job = SupervisorJob()
        val scope = CoroutineScope(job + Dispatchers.IO)
        val disposable = Disposable { job.cancel() }
        Disposer.register(project, disposable)
        // Make sure the disposable is unregistered once the job ends so we
        // don't pile parents on the project disposer tree.
        job.invokeOnCompletion { Disposer.dispose(disposable) }
        return scope
    }

    private fun CoroutineScope.cancel() {
        (this as? CoroutineScope)?.coroutineContext?.get(Job)?.cancel()
    }

    private fun notify(project: Project, message: String, type: NotificationType) {
        NotificationGroupManager.getInstance()
            .getNotificationGroup("Periscope")
            .createNotification(message, type)
            .notify(project)
    }
}
