package dev.periscope.phpstorm.runconfig

import com.intellij.execution.RunManager
import com.intellij.execution.RunnerAndConfigurationSettings
import com.intellij.notification.NotificationAction
import com.intellij.notification.NotificationGroupManager
import com.intellij.notification.NotificationType
import com.intellij.openapi.application.ApplicationManager
import com.intellij.openapi.project.Project
import com.intellij.openapi.startup.ProjectActivity

/**
 * Zero-config UX on **first** project open — strictly one-shot per project.
 *
 *   * If a Periscope run config already exists in `.idea/runConfigurations/`,
 *     do nothing. Don't re-seed, don't re-nudge.
 *   * Otherwise: silently create a default "Periscope" run config (so it
 *     appears in the dropdown next to Xdebug) and, if the user is currently
 *     sitting on a different config, surface a one-time balloon telling them
 *     the new entry is available with a "Switch to Periscope" action.
 *
 * Because run configs live in `.idea/`, they're inherently per-project — each
 * project gets its own Periscope entry. The factory defaults are identical
 * across projects, so functionally they behave the same everywhere.
 */
class PeriscopeAutoConfigActivity : ProjectActivity {

    override suspend fun execute(project: Project) {
        if (project.isDisposed) return

        val runManager = RunManager.getInstance(project)
        val type = PeriscopeRunConfigurationType()

        // Early-exit: a Periscope config already exists in this project. Don't
        // re-seed, don't re-nudge — the user has already been introduced to it.
        if (runManager.getConfigurationSettingsList(type).isNotEmpty()) return

        val settings = seedConfig(runManager, type)

        // Fresh seed → user hasn't seen Periscope in this project before. If
        // they're currently on another config (Xdebug, PHPUnit, plain Run),
        // surface a one-time balloon so they discover the new entry. Skip
        // when Periscope is somehow already the selected one (edge case).
        val selected = runManager.selectedConfiguration?.configuration
        if (selected is PeriscopeRunConfiguration) return

        notifyUser(project, settings)
    }

    private suspend fun seedConfig(
        runManager: RunManager,
        type: PeriscopeRunConfigurationType,
    ): RunnerAndConfigurationSettings {
        val factory = type.configurationFactories.first()
        val settings = runManager.createConfiguration("Periscope", factory)
        // Persist under `.idea/runConfigurations/` so the entry survives a
        // VCS pull or IDE reopen on a fresh checkout.
        settings.storeInDotIdeaFolder()
        ApplicationManager.getApplication().invokeAndWait {
            runManager.addConfiguration(settings)
        }
        return settings
    }

    private fun notifyUser(project: Project, settings: RunnerAndConfigurationSettings) {
        NotificationGroupManager.getInstance()
            .getNotificationGroup("Periscope")
            .createNotification(
                "Periscope is enabled — switch your active debug config to 'Periscope' to use it.",
                NotificationType.INFORMATION,
            )
            .addAction(NotificationAction.createSimple("Switch to Periscope") {
                ApplicationManager.getApplication().invokeLater {
                    if (!project.isDisposed) {
                        RunManager.getInstance(project).selectedConfiguration = settings
                    }
                }
            })
            .notify(project)
    }
}
