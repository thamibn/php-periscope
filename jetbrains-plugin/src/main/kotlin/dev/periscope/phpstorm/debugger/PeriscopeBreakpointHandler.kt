package dev.periscope.phpstorm.debugger

import com.intellij.openapi.diagnostic.thisLogger
import com.intellij.xdebugger.breakpoints.XBreakpointHandler
import com.intellij.xdebugger.breakpoints.XBreakpointType
import com.intellij.xdebugger.breakpoints.XLineBreakpoint
import dev.periscope.phpstorm.dap.*
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.launch

/**
 * Catches breakpoint registration/removal in PhpStorm's gutter and forwards
 * them to the periscope daemon as DAP `setBreakpoints` calls.
 *
 * DAP `setBreakpoints` is per-file (the daemon stores the full set per source),
 * so we group breakpoints by file before sending.
 *
 * The constructor takes a raw `Class<*>` and casts it to the [XBreakpointType]
 * shape `XBreakpointHandler` expects. The variance dance is awkward in Kotlin
 * because `XLineBreakpointType<P>` extends `XBreakpointType<XLineBreakpoint<P>, P>`
 * but Kotlin won't infer the cast through nested wildcards. Raw cast + suppress
 * is the cleanest path.
 */
@Suppress("UNCHECKED_CAST")
class PeriscopeBreakpointHandler(
    breakpointTypeClass: Class<*>,
    private val dap: DapClient,
    private val scope: CoroutineScope,
) : XBreakpointHandler<XLineBreakpoint<*>>(
    breakpointTypeClass as Class<out XBreakpointType<XLineBreakpoint<*>, *>>
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
