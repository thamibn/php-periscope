package dev.periscope.phpstorm.debugger

import com.intellij.execution.ExecutionResult
import com.intellij.execution.filters.TextConsoleBuilderFactory
import com.intellij.execution.process.ProcessHandler
import com.intellij.execution.process.ProcessOutputTypes
import com.intellij.execution.ui.ConsoleView
import com.intellij.execution.ui.ConsoleViewContentType
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
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.decodeFromJsonElement
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

    // The platform builds the ConsoleView lazily on first `createConsole()`
    // call. We push daemon stdout/stderr into it once it exists; before that
    // we buffer lines and replay on first attach so the user doesn't lose
    // any startup output.
    private val consoleBuffer = mutableListOf<Pair<String, ConsoleViewContentType>>()
    @Volatile private var consoleView: ConsoleView? = null

    private val dap: DapClient = DapClient(
        daemonPath = daemonPath,
        args = listOf("--dap-stdio"),
        onLog = { line -> pushConsole(line, ConsoleViewContentType.NORMAL_OUTPUT) },
    )

    private val editors = PeriscopeEditorsProvider()
    private val breakpointHandler = PeriscopeBreakpointHandler(breakpointType as Class<*>, dap, scope)

    @Volatile private var threadId: Int = 1
    @Volatile private var sessionInitialized: Boolean = false
    @Volatile private var pendingRunToLine: Int? = null
    @Volatile private var pendingRunToFile: String? = null

    init {
        scope.launch { runSession() }
    }

    private suspend fun runSession() {
        try {
            dap.start()

            // 1. Subscribe to events BEFORE sending the first request — the daemon
            //    emits the `initialized` event immediately after the initialize
            //    response, and we can't afford to race with that.
            scope.launch { listenForEvents() }

            // 2. initialize → capabilities (we read but don't gate on them yet)
            dap.sendRequest<InitializeArguments, Capabilities>(
                "initialize",
                InitializeArguments(),
            )

            // 3. launch — daemon opens the trace and emits `stopped: entry`.
            //    Daemon returns an empty response body for `launch`, so we use
            //    sendRequestRaw to avoid the "empty body" check in sendRequest<>().
            dap.sendRequestRaw(
                "launch",
                DapClient.JSON.encodeToJsonElement(
                    LaunchArguments(tracePath = tracePath, stopOnEntry = stopOnEntry),
                ),
            )

            // 4. configurationDone — acknowledges the config phase is complete.
            //    The daemon's `launch` already kicks off replay, so this is a
            //    formality, but DAP-compliant clients should send it.
            dap.sendRequestRaw("configurationDone", null)

            // 5. Session is live: flush any breakpoints PhpStorm registered
            //    before dap.start() returned, and mark the toolbar enabled so
            //    Step Back stops flickering grey on session open.
            sessionInitialized = true
            breakpointHandler.flushPending()
        } catch (e: Exception) {
            logger.warn("Failed to start DAP session", e)
            pushConsole(
                "periscope: failed to start session: ${e.message}\n",
                ConsoleViewContentType.ERROR_OUTPUT,
            )
            ApplicationManager.getApplication().invokeLater {
                session.reportError("periscope-daemon failed to start: ${e.message}")
                session.stop()
            }
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

                // Run-to-Position: if the daemon paused but we haven't reached
                // the user's run-to target yet, transparently continue. The
                // platform's runToPosition contract is "continue until we hit
                // the target line or any other breakpoint" — Periscope models
                // this client-side because the trace already contains every
                // line and the daemon doesn't expose a one-shot location bp.
                val runToFile = pendingRunToFile
                val runToLine = pendingRunToLine
                if (runToFile != null && runToLine != null) {
                    val curFile = top.source?.path
                    val curLine = top.line
                    if (curFile != runToFile || curLine != runToLine) {
                        // Not at target yet → continue replay. Any breakpoint
                        // hit during the continue will land us back in
                        // handleStopped naturally; if we hit the target line
                        // first, the clause below clears the target.
                        dap.sendRequestRaw(
                            "continue",
                            DapClient.JSON.encodeToJsonElement(ContinueArguments(threadId)),
                        )
                        return@launch
                    }
                    pendingRunToFile = null
                    pendingRunToLine = null
                }

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
        // DAP `output` events come from the user's PHP code (echo, var_dump,
        // etc.) reconstructed from the trace. Send them to the IDE's
        // ConsoleView so they appear in the Debug → Console panel.
        val contentType = when (out.category) {
            "stderr" -> ConsoleViewContentType.ERROR_OUTPUT
            else -> ConsoleViewContentType.NORMAL_OUTPUT
        }
        pushConsole(out.output, contentType)
    }

    private fun pushConsole(text: String, type: ConsoleViewContentType) {
        val cv = consoleView
        if (cv != null) {
            cv.print(text, type)
        } else {
            synchronized(consoleBuffer) {
                consoleBuffer.add(text to type)
            }
        }
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

    /**
     * Time-travel: the request LSP4IJ doesn't wire up.
     *
     * `XDebugProcess` doesn't expose a `startStepBack` override (IntelliJ
     * Platform doesn't model reverse stepping as a first-class debug-process
     * operation). We expose a public method here and invoke it from a custom
     * AnAction registered in plugin.xml — that gets us the Step Back toolbar
     * button + keyboard shortcut.
     */
    fun performStepBack() {
        scope.launch { dap.sendRequestRaw("stepBack", DapClient.JSON.encodeToJsonElement(StepArgs(threadId))) }
    }

    /** Pair with [performStepBack] for `Continue Backward`. */
    fun performReverseContinue() {
        scope.launch { dap.sendRequestRaw("reverseContinue", DapClient.JSON.encodeToJsonElement(ContinueArguments(threadId))) }
    }

    override fun stop() {
        scope.launch {
            try {
                if (dap.isAlive) dap.sendNotification("disconnect")
            } catch (e: Exception) {
                logger.debug("disconnect notification failed", e)
            } finally {
                dap.close()
                scope.cancel()
            }
        }
    }

    /**
     * Run-to-Position — continue replay until we land on the given source/line
     * or hit any other breakpoint along the way. Records the target on the
     * process; [handleStopped] consumes it on each stop and either auto-resumes
     * or surfaces the pause to the user once the target is reached.
     */
    override fun runToPosition(position: com.intellij.xdebugger.XSourcePosition, context: XSuspendContext?) {
        pendingRunToFile = position.file.path
        pendingRunToLine = position.line + 1 // DAP is 1-based, XSourcePosition is 0-based
        resume(context)
    }

    override fun createConsole(): ConsoleView {
        // Default text console — gives us all the standard ConsoleView UI
        // (timestamps, ANSI colors, search, copy/paste, fold-on-pattern) for
        // free. We just feed strings into it from `pushConsole`.
        val cv = TextConsoleBuilderFactory.getInstance().createBuilder(session.project).console
        // Drain anything buffered before the platform asked us for a console
        // so the user doesn't lose startup output.
        synchronized(consoleBuffer) {
            for ((text, type) in consoleBuffer) cv.print(text, type)
            consoleBuffer.clear()
        }
        consoleView = cv
        return cv
    }

    override fun getEditorsProvider(): XDebuggerEditorsProvider = editors

    override fun getBreakpointHandlers(): Array<XBreakpointHandler<*>> =
        arrayOf<XBreakpointHandler<*>>(breakpointHandler)

    /** PhpStorm asks this to decide whether the Step Back button is enabled. */
    override fun checkCanInitBreakpoints(): Boolean = true

    /**
     * Show the toolbar Step Back button — this is the periscope-vs-Xdebug
     * differentiator. We use a sticky `sessionInitialized` flag instead of
     * `dap.isAlive` directly so the button doesn't flicker grey during the
     * tiny window between subprocess spawn and the `initialize` reply.
     */
    override fun checkCanPerformCommands(): Boolean = sessionInitialized && dap.isAlive
}
