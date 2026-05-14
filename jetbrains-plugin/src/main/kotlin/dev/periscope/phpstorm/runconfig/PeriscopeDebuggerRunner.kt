package dev.periscope.phpstorm.runconfig

import com.intellij.execution.ExecutionException
import com.intellij.execution.ExecutionResult
import com.intellij.execution.configurations.RunProfile
import com.intellij.execution.configurations.RunProfileState
import com.intellij.execution.executors.DefaultDebugExecutor
import com.intellij.execution.runners.AsyncProgramRunner
import com.intellij.execution.runners.ExecutionEnvironment
import com.intellij.execution.ui.RunContentDescriptor
import com.intellij.openapi.diagnostic.thisLogger
import com.intellij.openapi.fileEditor.FileDocumentManager
import com.intellij.xdebugger.XDebugProcess
import com.intellij.xdebugger.XDebugProcessStarter
import com.intellij.xdebugger.XDebugSession
import com.intellij.xdebugger.XDebuggerManager
import com.intellij.xdebugger.breakpoints.XLineBreakpointType
import dev.periscope.phpstorm.debugger.PeriscopeDebugProcess
import org.jetbrains.concurrency.Promise
import org.jetbrains.concurrency.resolvedPromise

/**
 * Wires a [PeriscopeRunConfiguration] into PhpStorm's debug subsystem.
 *
 * When the user hits Shift+F9 with a Periscope configuration selected, PhpStorm
 * calls [execute], we ask [XDebuggerManager] to start a session, and our
 * [PeriscopeDebugProcess] takes it from there.
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

        val tracePath = resolveTracePath(config.tracePath, environment)

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
                        tracePath = tracePath,
                        stopOnEntry = config.stopOnEntry,
                        breakpointType = anyLineBreakpointType,
                    )
                }
            })

        return resolvedPromise(session.runContentDescriptor)
    }

    private fun resolveTracePath(raw: String, env: ExecutionEnvironment): String {
        val ws = env.project.basePath ?: System.getProperty("user.home")
        return raw.replace("\${workspaceFolder}", ws).replace("{workspaceFolder}", ws)
    }
}
