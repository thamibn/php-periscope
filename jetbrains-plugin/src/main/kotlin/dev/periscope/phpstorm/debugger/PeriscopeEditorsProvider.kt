package dev.periscope.phpstorm.debugger

import com.intellij.openapi.fileTypes.FileType
import com.intellij.openapi.fileTypes.PlainTextFileType
import com.intellij.openapi.project.Project
import com.intellij.xdebugger.XExpression
import com.intellij.xdebugger.XSourcePosition
import com.intellij.xdebugger.evaluation.EvaluationMode
import com.intellij.xdebugger.evaluation.XDebuggerEditorsProviderBase
import com.intellij.psi.PsiElement

/**
 * Provides the editor for breakpoint conditions, Evaluate Expression dialog input,
 * and watch expressions. We accept any text for now — PhpStorm's bundled PHP plugin
 * already handles syntax-highlighted PHP editing for `.php` files; this fallback covers
 * non-PHP source positions (e.g. evaluating expressions when paused mid-trace at a
 * vendor file).
 */
class PeriscopeEditorsProvider : XDebuggerEditorsProviderBase() {
    override fun getFileType(): FileType = PlainTextFileType.INSTANCE

    override fun createExpressionCodeFragment(
        project: Project,
        expression: XExpression,
        context: PsiElement?,
        isPhysical: Boolean,
    ): PsiElement = com.intellij.psi.PsiFileFactory.getInstance(project)
        .createFileFromText("periscope.expr", PlainTextFileType.INSTANCE, expression.expression)
}
