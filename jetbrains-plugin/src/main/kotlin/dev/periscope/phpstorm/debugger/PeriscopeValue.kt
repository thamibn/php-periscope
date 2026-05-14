package dev.periscope.phpstorm.debugger

import com.intellij.icons.AllIcons
import com.intellij.openapi.diagnostic.thisLogger
import com.intellij.xdebugger.frame.*
import dev.periscope.phpstorm.dap.DapClient
import dev.periscope.phpstorm.dap.Variable
import dev.periscope.phpstorm.dap.VariablesArguments
import dev.periscope.phpstorm.dap.VariablesResponse
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.launch

/**
 * Represents one variable in PhpStorm's debugger Variables panel.
 *
 * Lazily fetches child variables when the user expands a row — keeps the
 * UI responsive even on traces with thousands of variables.
 */
class PeriscopeValue(
    private val variable: Variable,
    private val dap: DapClient,
    private val scope: CoroutineScope,
) : XNamedValue(variable.name) {

    private val logger = thisLogger()

    override fun computePresentation(node: XValueNode, place: XValuePlace) {
        val icon = when {
            variable.type?.startsWith("array") == true -> AllIcons.Debugger.Db_array
            variable.type == "object" -> AllIcons.Debugger.Value
            else -> AllIcons.Debugger.Db_primitive
        }
        val hasChildren = variable.variablesReference > 0
        node.setPresentation(icon, variable.type, variable.value, hasChildren)
    }

    override fun computeChildren(node: XCompositeNode) {
        if (variable.variablesReference == 0) {
            node.addChildren(XValueChildrenList.EMPTY, true)
            return
        }
        scope.launch {
            try {
                val resp: VariablesResponse = dap.sendRequest(
                    "variables",
                    VariablesArguments(variablesReference = variable.variablesReference),
                )
                val list = XValueChildrenList()
                for (child in resp.variables) {
                    list.add(PeriscopeValue(child, dap, scope))
                }
                node.addChildren(list, true)
            } catch (e: Exception) {
                logger.warn("variables request failed", e)
                node.setErrorMessage(e.message ?: "failed to fetch variables")
            }
        }
    }
}
