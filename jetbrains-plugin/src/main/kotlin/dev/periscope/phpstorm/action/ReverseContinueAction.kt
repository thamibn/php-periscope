package dev.periscope.phpstorm.action

import com.intellij.openapi.actionSystem.ActionUpdateThread
import com.intellij.openapi.actionSystem.AnAction
import com.intellij.openapi.actionSystem.AnActionEvent
import com.intellij.openapi.actionSystem.CommonDataKeys
import com.intellij.xdebugger.XDebuggerManager
import dev.periscope.phpstorm.debugger.PeriscopeDebugProcess

/**
 * Toolbar + keymap action — "Continue Backward" — paired with [StepBackAction]
 * for the reverse-execution UX. Sends DAP `reverseContinue` to the daemon.
 */
class ReverseContinueAction : AnAction() {
    override fun getActionUpdateThread(): ActionUpdateThread = ActionUpdateThread.BGT

    override fun update(e: AnActionEvent) {
        val process = currentProcess(e)
        e.presentation.isEnabledAndVisible = process != null
    }

    override fun actionPerformed(e: AnActionEvent) {
        currentProcess(e)?.performReverseContinue()
    }

    private fun currentProcess(e: AnActionEvent): PeriscopeDebugProcess? {
        val project = e.getData(CommonDataKeys.PROJECT) ?: return null
        val session = XDebuggerManager.getInstance(project).currentSession ?: return null
        return session.debugProcess as? PeriscopeDebugProcess
    }
}
