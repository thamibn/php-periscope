package dev.periscope.phpstorm.debugger

import com.intellij.icons.AllIcons
import com.intellij.xdebugger.frame.XExecutionStack
import com.intellij.xdebugger.frame.XStackFrame
import dev.periscope.phpstorm.dap.DapClient
import dev.periscope.phpstorm.dap.StackTraceArguments
import dev.periscope.phpstorm.dap.StackTraceResponse
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.launch

/**
 * The single thread for a periscope trace (PHP requests are single-threaded in v1).
 *
 * Asks the daemon for the full stack on first display, then hands frames to PhpStorm.
 */
class PeriscopeExecutionStack(
    private val threadId: Int,
    private val dap: DapClient,
    private val scope: CoroutineScope,
) : XExecutionStack("Request", AllIcons.Debugger.ThreadCurrent) {

    @Volatile private var topFrame: XStackFrame? = null

    fun setTopFrame(frame: XStackFrame) {
        topFrame = frame
    }

    override fun getTopFrame(): XStackFrame? = topFrame

    override fun computeStackFrames(firstFrameIndex: Int, container: XStackFrameContainer) {
        scope.launch {
            try {
                val resp: StackTraceResponse = dap.sendRequest(
                    "stackTrace",
                    StackTraceArguments(threadId = threadId, startFrame = firstFrameIndex),
                )
                val frames = resp.stackFrames.map { PeriscopeStackFrame(it, dap, scope) }
                container.addStackFrames(frames, true)
            } catch (e: Exception) {
                container.errorOccurred(e.message ?: "stackTrace failed")
            }
        }
    }
}
