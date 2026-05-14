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
import dev.periscope.phpstorm.settings.PeriscopeApplicationSettings
import java.io.File

/**
 * On project open:
 *
 *   1. **Ensure a Periscope run config exists** in this project. Check both
 *      RunManager AND `.idea/runConfigurations/Periscope.xml` on disk — the
 *      latter catches the race where ProjectActivity fires before the scheme
 *      manager has finished loading existing run configs. Without the on-disk
 *      check we silently created a duplicate, which the platform rejected
 *      with `Scheme file "Periscope.xml" is not loaded because defines
 *      duplicated name "Periscope"` — and the returned settings then pointed
 *      at a ghost config that wouldn't switch the dropdown.
 *
 *   2. **Nudge the user with a balloon every time** the project opens —
 *      unless the user clicked "Don't show again" (per-project flag) or is
 *      already on the Periscope config.
 *
 * Toggleable globally via Settings → Tools → Periscope.
 */
class PeriscopeAutoConfigActivity : ProjectActivity {

    override suspend fun execute(project: Project) {
        if (project.isDisposed) return

        // Global kill-switch — Settings → Tools → Periscope.
        if (!PeriscopeApplicationSettings.getInstance().autoSeedRunConfig) return

        val runManager = RunManager.getInstance(project)
        if (!periscopeConfigExists(project, runManager)) {
            seedConfig(runManager)
        }

        // Always nudge when the active config is not Periscope. The user can
        // opt out per-project via "Don't show again".
        val selected = runManager.selectedConfiguration?.configuration
        if (selected is PeriscopeRunConfiguration) return

        val props = PropertiesComponent.getInstance(project)
        if (props.getBoolean(DONT_NOTIFY_KEY, false)) return

        notifyUser(project, props)
    }

    /**
     * True if any Periscope config is already known — either loaded into
     * RunManager (by type or by name) or sitting on disk as a scheme file
     * the platform will load on its own schedule. The disk check guards
     * against a load race where ProjectActivity beats the scheme manager.
     */
    private fun periscopeConfigExists(project: Project, runManager: RunManager): Boolean {
        val inMemory = runManager.allSettings.any {
            it.configuration is PeriscopeRunConfiguration || it.name == DEFAULT_NAME
        }
        if (inMemory) return true
        val base = project.basePath ?: return false
        return File(base, ".idea/runConfigurations/Periscope.xml").exists()
    }

    private fun seedConfig(runManager: RunManager): RunnerAndConfigurationSettings {
        val type = PeriscopeRunConfigurationType()
        val factory = type.configurationFactories.first()
        val settings = runManager.createConfiguration(DEFAULT_NAME, factory)
        settings.storeInDotIdeaFolder()
        ApplicationManager.getApplication().invokeAndWait {
            runManager.addConfiguration(settings)
        }
        return settings
    }

    private fun notifyUser(project: Project, props: PropertiesComponent) {
        NotificationGroupManager.getInstance()
            .getNotificationGroup("Periscope")
            .createNotification(
                "Periscope is enabled — switch your active debug config to 'Periscope' to use it.",
                NotificationType.INFORMATION,
            )
            .addAction(NotificationAction.createSimple("Switch to Periscope") {
                ApplicationManager.getApplication().invokeLater {
                    if (project.isDisposed) return@invokeLater
                    val rm = RunManager.getInstance(project)
                    // Always resolve the live settings at click-time — the
                    // object we held at construction can be stale if the
                    // scheme system reloaded.
                    val live = rm.findConfigurationByName(DEFAULT_NAME)
                        ?: rm.allSettings.firstOrNull { it.configuration is PeriscopeRunConfiguration }
                    if (live != null) {
                        rm.selectedConfiguration = live
                    }
                }
            })
            .addAction(NotificationAction.createSimple("Don't show again") {
                props.setValue(DONT_NOTIFY_KEY, true)
            })
            .notify(project)
    }

    private companion object {
        const val DEFAULT_NAME = "Periscope"
        const val DONT_NOTIFY_KEY = "periscope.autoConfig.dontNotify"
    }
}
