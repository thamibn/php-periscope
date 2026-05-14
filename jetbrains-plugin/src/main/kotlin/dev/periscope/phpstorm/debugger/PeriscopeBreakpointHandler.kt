package dev.periscope.phpstorm.debugger

import com.intellij.openapi.diagnostic.thisLogger
import com.intellij.xdebugger.breakpoints.XBreakpointHandler
import com.intellij.xdebugger.breakpoints.XLineBreakpoint
import com.intellij.xdebugger.breakpoints.XLineBreakpointType
import dev.periscope.phpstorm.dap.*
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonNull

/**
 * Catches breakpoint registration/removal in PhpStorm's gutter and forwards
 * them to the periscope daemon as DAP `setBreakpoints` calls.
 *
 * DAP `setBreakpoints` is per-file (the daemon stores the full set per source),
 * so we group breakpoints by file before sending.
 */
class PeriscopeBreakpointHandler(
    breakpointType: Class<out XLineBreakpointType<*>>,
    private val dap: DapClient,
    private val scope: CoroutineScope,
) : XBreakpointHandler<XLineBreakpoint<*>>(
    @Suppress("UNCHECKED_CAST")
    breakpointType as Class<out XLineBreakpointType<XLineBreakpoint<*>>>
) {
    private val logger = thisLogger()
    private val byFile = mutableMapOf<String, MutableSet<XLineBreakpoint<*>>>()

    override fun registerBreakpoint(bp: XLineBreakpoint<*>) {
        val path = bp.fileUrl.removePrefix("file://")
        byFile.getOrPut(path) { mutableSetOf() }.add(bp)
        sync(path)
    }

    override fun unregisterBreakpoint(bp: XLineBreakpoint<*>, temporary: Boolean) {
        val path = bp.fileUrl.removePrefix("file://")
        byFile[path]?.remove(bp)
        if (byFile[path]?.isEmpty() == true) byFile.remove(path)
        sync(path)
    }

    private fun sync(path: String) {
        scope.launch {
            try {
                val bps = byFile[path].orEmpty().map { bp ->
                    SourceBreakpoint(
                        line = bp.line + 1, // JetBrains is 0-based, DAP is 1-based
                        condition = bp.conditionExpression?.expression,
                        logMessage = bp.logExpressionObject?.expression,
                    )
                }
                dap.sendRequest<SetBreakpointsArguments, SetBreakpointsResponse>(
                    "setBreakpoints",
                    SetBreakpointsArguments(
                        source = Source(name = path.substringAfterLast('/'), path = path),
                        breakpoints = bps,
                    ),
                )
            } catch (e: Exception) {
                logger.warn("setBreakpoints failed for $path", e)
            }
        }
    }
}
