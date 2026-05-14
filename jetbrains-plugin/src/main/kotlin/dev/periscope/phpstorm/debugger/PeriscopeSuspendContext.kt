package dev.periscope.phpstorm.debugger

import com.intellij.xdebugger.frame.XExecutionStack
import com.intellij.xdebugger.frame.XSuspendContext

/**
 * Represents the "where are we paused?" state, surfaced by PhpStorm's Threads panel.
 *
 * PHP requests are single-threaded in v1 — one execution stack per suspend context.
 */
class PeriscopeSuspendContext(
    private val stack: PeriscopeExecutionStack,
) : XSuspendContext() {
    override fun getActiveExecutionStack(): XExecutionStack = stack
    override fun getExecutionStacks(): Array<XExecutionStack> = arrayOf(stack)
}
