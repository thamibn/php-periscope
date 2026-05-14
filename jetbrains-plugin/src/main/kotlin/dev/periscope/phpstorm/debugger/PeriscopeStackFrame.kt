package dev.periscope.phpstorm.debugger

import com.intellij.openapi.application.ApplicationManager
import com.intellij.openapi.diagnostic.thisLogger
import com.intellij.openapi.vfs.LocalFileSystem
import com.intellij.xdebugger.XDebuggerUtil
import com.intellij.xdebugger.XSourcePosition
import com.intellij.xdebugger.frame.*
import dev.periscope.phpstorm.dap.*
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.launch

/**
 * Maps one DAP [StackFrame] into JetBrains' [XStackFrame] for the Call Stack panel.
 *
 * On select (user clicks a frame), [computeChildren] is called and we issue a `scopes`
 * + `variables` chain to the daemon to populate the Variables panel.
 */
class PeriscopeStackFrame(
    private val frame: StackFrame,
    private val dap: DapClient,
    private val scope: CoroutineScope,
) : XStackFrame() {

    private val logger = thisLogger()

    private val sourcePos: XSourcePosition? by lazy {
        val path = frame.source?.path ?: return@lazy null
        val vf = LocalFileSystem.getInstance().findFileByPath(path) ?: return@lazy null
        // DAP lines are 1-based; XSourcePosition is 0-based.
        XDebuggerUtil.getInstance().createPosition(vf, frame.line - 1)
    }

    override fun getSourcePosition(): XSourcePosition? = sourcePos

    override fun customizePresentation(component: com.intellij.ui.ColoredTextComponent) {
        component.append(
            frame.name,
            com.intellij.ui.SimpleTextAttributes.REGULAR_ATTRIBUTES,
        )
        frame.source?.path?.let {
            component.append(
                "  ${shortPath(it)}:${frame.line}",
                com.intellij.ui.SimpleTextAttributes.GRAYED_ATTRIBUTES,
            )
        }
    }

    override fun computeChildren(node: XCompositeNode) {
        scope.launch {
            try {
                val scopesResp: ScopesResponse = dap.sendRequest(
                    "scopes",
                    ScopesArguments(frameId = frame.id),
                )
                val list = XValueChildrenList()
                for (s in scopesResp.scopes) {
                    val vars: VariablesResponse = dap.sendRequest(
                        "variables",
                        VariablesArguments(variablesReference = s.variablesReference),
                    )
                    for (v in vars.variables) {
                        list.add(PeriscopeValue(v, dap, scope))
                    }
                }
                ApplicationManager.getApplication().invokeLater {
                    node.addChildren(list, true)
                }
            } catch (e: Exception) {
                logger.warn("scopes/variables request failed", e)
                node.setErrorMessage(e.message ?: "failed to fetch scope")
            }
        }
    }

    private fun shortPath(p: String): String =
        p.substringAfterLast('/').ifEmpty { p }
}
