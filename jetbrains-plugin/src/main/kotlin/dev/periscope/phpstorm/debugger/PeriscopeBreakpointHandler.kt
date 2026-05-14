package dev.periscope.phpstorm.debugger

import com.intellij.openapi.diagnostic.thisLogger
import com.intellij.openapi.vfs.VirtualFileManager
import com.intellij.xdebugger.breakpoints.XBreakpointHandler
import com.intellij.xdebugger.breakpoints.XBreakpointType
import com.intellij.xdebugger.breakpoints.XLineBreakpoint
import dev.periscope.phpstorm.dap.*
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.launch
import java.net.URLDecoder
import java.nio.charset.StandardCharsets

/**
 * Catches breakpoint registration/removal in PhpStorm's gutter and forwards
 * them to the periscope daemon as DAP `setBreakpoints` calls.
 *
 * DAP `setBreakpoints` is per-file (the daemon stores the full set per source),
 * so we group breakpoints by file before sending.
 *
 * Two correctness issues this handler used to have:
 *
 *   1. **Race against session start.** PhpStorm calls `registerBreakpoint`
 *      for every pre-existing breakpoint immediately after the handler is
 *      constructed — which happens inside `PeriscopeDebugProcess.<init>`,
 *      before `dap.start()` has run. The send would throw "DAP client not
 *      started", get caught, and the breakpoint would silently never reach
 *      the daemon. Now: we buffer registrations until [flushPending] is
 *      called after the DAP `initialized` event lands.
 *
 *   2. **URL-encoded paths broken.** `fileUrl.removePrefix("file://")` left
 *      `%20` and friends intact, so paths with spaces never matched on the
 *      daemon side. Now we decode via [URLDecoder].
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

    @Volatile
    private var ready: Boolean = false

    override fun registerBreakpoint(bp: XLineBreakpoint<*>) {
        val path = pathOf(bp) ?: return
        byFile.getOrPut(path) { mutableSetOf() }.add(bp)
        if (ready) sync(path)
    }

    override fun unregisterBreakpoint(bp: XLineBreakpoint<*>, temporary: Boolean) {
        val path = pathOf(bp) ?: return
        byFile[path]?.remove(bp)
        if (byFile[path]?.isEmpty() == true) byFile.remove(path)
        if (ready) sync(path)
    }

    /**
     * Called once the DAP session is initialized. Marks the handler as ready
     * and flushes any breakpoint registrations buffered before start-up.
     */
    fun flushPending() {
        ready = true
        // Snapshot the keys — sync() can mutate byFile, but during initial
        // flush it only sends, so a copy is just defensive.
        val paths = byFile.keys.toList()
        for (path in paths) sync(path)
    }

    private fun pathOf(bp: XLineBreakpoint<*>): String? {
        val url = bp.fileUrl
        // Prefer the platform's URL→path machinery — strips the `file://`
        // scheme AND handles URL encoding correctly (spaces, non-ASCII).
        VirtualFileManager.extractPath(url).takeIf { it.isNotEmpty() }?.let { return it }
        // Fallback: manual decode. Some virtual files (in-memory, jar://)
        // never reach here in practice — but if they do, do our best.
        if (!url.startsWith("file://")) return null
        return runCatching {
            URLDecoder.decode(url.removePrefix("file://"), StandardCharsets.UTF_8)
        }.getOrNull()
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
