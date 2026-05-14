package dev.periscope.phpstorm.runconfig

import com.intellij.execution.RunManager
import com.intellij.execution.RunnerAndConfigurationSettings
import com.intellij.ide.util.PropertiesComponent
import com.intellij.notification.NotificationAction
import com.intellij.notification.NotificationGroupManager
import com.intellij.notification.NotificationType
import com.intellij.openapi.application.ApplicationManager
import com.intellij.openapi.project.Project
import com.intellij.openapi.startup.ProjectActivity
import com.intellij.openapi.util.Key

/**
 * Two-step zero-config UX on project open:
 *
 *   1. **Silently seed** a default "Periscope" run configuration if none
 *      exists. The entry appears in the run-config dropdown alongside any
 *      Xdebug configs the user already has.
 *
 *   2. **Nudge with a balloon** *only when* the user is currently sitting
 *      on a non-Periscope config (Xdebug, PHP Script, etc.). If Periscope
 *      is already the selected config, we stay silent — no point telling
 *      them about something they're already using. One-shot per IDE
 *      session, plus a persistent "Don't show again" opt-out.
 */
class PeriscopeAutoConfigActivity : ProjectActivity {

    override suspend fun execute(project: Project) {
        if (project.isDisposed) return

        val runManager = RunManager.getInstance(project)
        val type = PeriscopeRunConfigurationType()
        val existing = runManager.getConfigurationSettingsList(type)
        val settings: RunnerAndConfigurationSettings =
            if (existing.isNotEmpty()) existing.first() else seedConfig(runManager, type)

        // Only nudge when the user has a different config selected — Xdebug,
        // PHPUnit, plain Run, etc. If Periscope is already current, stay quiet.
        val selected = runManager.selectedConfiguration?.configuration
        if (selected is PeriscopeRunConfiguration) return

        // One-shot per IDE-project session so the balloon doesn't reappear on
        // every config switch within the session.
        if (project.getUserData(NOTIFIED_THIS_SESSION) == true) return
        project.putUserData(NOTIFIED_THIS_SESSION, true)

        val props = PropertiesComponent.getInstance(project)
        if (props.getBoolean(DONT_NOTIFY_KEY, false)) return

        notifyUser(project, settings, props)
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

    private fun notifyUser(
        project: Project,
        settings: RunnerAndConfigurationSettings,
        props: PropertiesComponent,
    ) {
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
            .addAction(NotificationAction.createSimple("Don't show again") {
                props.setValue(DONT_NOTIFY_KEY, true)
            })
            .notify(project)
    }

    private companion object {
        val NOTIFIED_THIS_SESSION = Key.create<Boolean>("periscope.autoConfig.notifiedThisSession")
        const val DONT_NOTIFY_KEY = "periscope.autoConfig.dontNotify"
    }
}
