package dev.periscope.phpstorm.action

import com.intellij.openapi.actionSystem.ActionUpdateThread
import com.intellij.openapi.actionSystem.AnAction
import com.intellij.openapi.actionSystem.AnActionEvent
import com.intellij.openapi.actionSystem.CommonDataKeys
import com.intellij.xdebugger.XDebuggerManager
import dev.periscope.phpstorm.debugger.PeriscopeDebugProcess

/**
 * Toolbar + keymap action — "Step Back" — sends DAP `stepBack` to the daemon
 * via the currently-paused [PeriscopeDebugProcess].
 *
 * The IntelliJ Platform's `XDebugProcess` doesn't expose a `startStepBack`
 * override (reverse stepping isn't a first-class platform concept), so we
 * register this AnAction in plugin.xml and group it next to the standard
 * step-forward buttons on the debug toolbar.
 */
class StepBackAction : AnAction() {
    override fun getActionUpdateThread(): ActionUpdateThread = ActionUpdateThread.BGT

    override fun update(e: AnActionEvent) {
        val process = currentProcess(e)
        e.presentation.isEnabledAndVisible = process != null
    }

    override fun actionPerformed(e: AnActionEvent) {
        currentProcess(e)?.performStepBack()
    }

    private fun currentProcess(e: AnActionEvent): PeriscopeDebugProcess? {
        val project = e.getData(CommonDataKeys.PROJECT) ?: return null
        val session = XDebuggerManager.getInstance(project).currentSession ?: return null
        return session.debugProcess as? PeriscopeDebugProcess
    }
}
