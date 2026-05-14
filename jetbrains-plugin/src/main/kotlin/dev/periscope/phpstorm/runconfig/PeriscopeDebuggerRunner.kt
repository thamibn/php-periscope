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
import com.intellij.openapi.application.ApplicationManager
import com.intellij.openapi.diagnostic.thisLogger
import com.intellij.openapi.fileEditor.FileDocumentManager
import com.intellij.openapi.project.Project
import com.intellij.xdebugger.XDebugProcess
import com.intellij.xdebugger.XDebugProcessStarter
import com.intellij.xdebugger.XDebugSession
import com.intellij.xdebugger.XDebuggerManager
import com.intellij.xdebugger.breakpoints.XLineBreakpointType
import dev.periscope.phpstorm.debugger.PeriscopeDebugProcess
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import org.jetbrains.concurrency.AsyncPromise
import org.jetbrains.concurrency.Promise
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
 *     the debug session as soon as the next trace lands. This is the
 *     "open Debug, visit a URL" flow from issue #1.
 */
class PeriscopeDebuggerRunner : AsyncProgramRunner<com.intellij.execution.configurations.RunnerSettings>() {

    private val logger = thisLogger()
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)

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

        val explicit = config.tracePath.trim()
        val traceDir = config.traceDir.ifBlank { PeriscopeRunConfigurationOptions.DEFAULT_TRACE_DIR }

        // Path 1: user typed an explicit trace file. Fail fast if it doesn't
        // exist instead of hanging the IDE (issue #2: clear error within ~1s).
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

        // Path 3: listen for the next trace, then start. Return an async promise
        // so the Debug button doesn't block while we wait.
        notify(
            environment.project,
            "Periscope: listening for traces in $traceDir — visit a route in your browser to start debugging.",
            NotificationType.INFORMATION,
        )
        val promise = AsyncPromise<RunContentDescriptor?>()
        scope.launch {
            try {
                val landed = TraceResolver.awaitNext(Path.of(traceDir))
                ApplicationManager.getApplication().invokeLater {
                    try {
                        startSession(environment, config, landed).onSuccess { promise.setResult(it) }
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

    private fun notify(project: Project, message: String, type: NotificationType) {
        NotificationGroupManager.getInstance()
            .getNotificationGroup("Periscope")
            .createNotification(message, type)
            .notify(project)
    }
}
