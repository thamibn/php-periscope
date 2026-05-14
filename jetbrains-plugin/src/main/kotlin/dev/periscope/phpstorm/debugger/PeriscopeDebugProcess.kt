package dev.periscope.phpstorm.debugger

import com.intellij.execution.ui.ConsoleView
import com.intellij.openapi.application.ApplicationManager
import com.intellij.openapi.diagnostic.thisLogger
import com.intellij.xdebugger.XDebugProcess
import com.intellij.xdebugger.XDebugSession
import com.intellij.xdebugger.breakpoints.XBreakpointHandler
import com.intellij.xdebugger.breakpoints.XLineBreakpointType
import com.intellij.xdebugger.evaluation.XDebuggerEditorsProvider
import com.intellij.xdebugger.frame.XSuspendContext
import dev.periscope.phpstorm.dap.*
import kotlinx.coroutines.*
import kotlinx.coroutines.flow.collect
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.encodeToJsonElement

/**
 * The core debug-process glue.
 *
 * Subclass of JetBrains' [XDebugProcess] — PhpStorm uses *its* toolbar / panels /
 * keyboard shortcuts to drive the debug session and calls into our overrides for
 * each user action (step over, step back, resume, etc.). We translate each call
 * to a DAP request, ship it down stdio to `periscope-daemon`, and handle the
 * resulting events to push state back into PhpStorm's UI.
 *
 * **No custom UI is built here** — every panel the user sees (Variables, Call Stack,
 * Threads, Watches, Console, breakpoint gutter, debug toolbar) is rendered by
 * PhpStorm itself, identical to the Xdebug experience but with the Step Back button
 * now functional.
 */
class PeriscopeDebugProcess(
    session: XDebugSession,
    private val daemonPath: String,
    private val tracePath: String,
    private val stopOnEntry: Boolean = false,
    breakpointType: Class<out XLineBreakpointType<*>>,
) : XDebugProcess(session) {

    private val logger = thisLogger()
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)

    private val dap: DapClient = DapClient(
        daemonPath = daemonPath,
        args = listOf("--dap-stdio"),
        onLog = { line -> logger.debug(line) },
    )

    private val editors = PeriscopeEditorsProvider()
    private val breakpointHandler = PeriscopeBreakpointHandler(breakpointType, dap, scope)

    @Volatile private var threadId: Int = 1

    init {
        scope.launch { runSession() }
    }

    private suspend fun runSession() {
        try {
            dap.start()

            // 1. initialize → capabilities (we ignore most of these for now)
            dap.sendRequest<InitializeArguments, Capabilities>(
                "initialize",
                InitializeArguments(),
            )

            // 2. start the event loop concurrently so we don't miss the `stopped`
            //    event that arrives after launch.
            val eventJob = scope.launch { listenForEvents() }

            // 3. launch with the requested trace path
            dap.sendRequest<LaunchArguments, JsonNull>(
                "launch",
                LaunchArguments(tracePath = tracePath, stopOnEntry = stopOnEntry),
            )

            // 4. configurationDone — tells the daemon breakpoints are set
            dap.sendRequestRaw("configurationDone", null)
        } catch (e: Exception) {
            logger.warn("Failed to start DAP session", e)
            session.reportError("periscope-daemon failed to start: ${e.message}")
            session.stop()
        }
    }

    private suspend fun listenForEvents() {
        dap.events.collect { evt ->
            when (evt.event) {
                "stopped" -> handleStopped(evt.body)
                "terminated", "exited" -> {
                    ApplicationManager.getApplication().invokeLater { session.stop() }
                }
                "output" -> evt.body?.let { handleOutput(it) }
                "thread" -> {} // we only have one thread
                else -> logger.debug("ignored event: ${evt.event}")
            }
        }
    }

    private fun handleStopped(body: JsonObject?) {
        val stopped = body?.let { DapClient.JSON.decodeFromJsonElement<StoppedEvent>(it) } ?: return
        threadId = stopped.threadId ?: threadId

        scope.launch {
            try {
                val stack: StackTraceResponse = dap.sendRequest(
                    "stackTrace",
                    StackTraceArguments(threadId = threadId, levels = 1),
                )
                val top = stack.stackFrames.firstOrNull() ?: return@launch
                val execStack = PeriscopeExecutionStack(threadId, dap, scope)
                execStack.setTopFrame(PeriscopeStackFrame(top, dap, scope))
                val context = PeriscopeSuspendContext(execStack)
                ApplicationManager.getApplication().invokeLater {
                    session.positionReached(context)
                }
            } catch (e: Exception) {
                logger.warn("stopped-event handling failed", e)
            }
        }
    }

    private fun handleOutput(body: JsonObject) {
        val out = DapClient.JSON.decodeFromJsonElement<OutputEvent>(body)
        // ConsoleView is created by XDebugSession; we'd push lines here.
        // For v0.1.0-alpha, log to the IDE log; ConsoleView wiring lands in v0.2.
        logger.info("[periscope-daemon stdout] ${out.output.trimEnd()}")
    }

    // ---------- XDebugProcess overrides — what PhpStorm's toolbar calls ----------

    override fun resume(context: XSuspendContext?) {
        scope.launch { dap.sendRequestRaw("continue", DapClient.JSON.encodeToJsonElement(ContinueArguments(threadId))) }
    }

    override fun startStepOver(context: XSuspendContext?) {
        scope.launch { dap.sendRequestRaw("next", DapClient.JSON.encodeToJsonElement(StepArgs(threadId))) }
    }

    override fun startStepInto(context: XSuspendContext?) {
        scope.launch { dap.sendRequestRaw("stepIn", DapClient.JSON.encodeToJsonElement(StepArgs(threadId))) }
    }

    override fun startStepOut(context: XSuspendContext?) {
        scope.launch { dap.sendRequestRaw("stepOut", DapClient.JSON.encodeToJsonElement(StepArgs(threadId))) }
    }

    /** Time-travel: the button LSP4IJ doesn't wire up. */
    override fun startStepBack() {
        scope.launch { dap.sendRequestRaw("stepBack", DapClient.JSON.encodeToJsonElement(StepArgs(threadId))) }
    }

    override fun stop() {
        scope.launch {
            try {
                dap.sendNotification("disconnect")
            } finally {
                dap.close()
                scope.cancel()
            }
        }
    }

    override fun runToPosition(position: com.intellij.xdebugger.XSourcePosition, context: XSuspendContext?) {
        // TODO: implement via temporary breakpoint + continue. v0.2 work.
        resume(context)
    }

    override fun getEditorsProvider(): XDebuggerEditorsProvider = editors

    @Suppress("UNCHECKED_CAST")
    override fun getBreakpointHandlers(): Array<XBreakpointHandler<*>> =
        arrayOf(breakpointHandler) as Array<XBreakpointHandler<*>>

    /** PhpStorm asks this to decide whether the Step Back button is enabled. */
    override fun checkCanInitBreakpoints(): Boolean = true

    /** Show the toolbar Step Back button — this is the periscope-vs-Xdebug differentiator. */
    override fun checkCanPerformCommands(): Boolean = dap.isAlive
}
