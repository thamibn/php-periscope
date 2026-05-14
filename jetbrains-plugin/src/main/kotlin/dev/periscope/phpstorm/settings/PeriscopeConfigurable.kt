package dev.periscope.phpstorm.settings

import com.intellij.openapi.options.Configurable
import com.intellij.ui.components.JBCheckBox
import com.intellij.ui.dsl.builder.panel
import javax.swing.JComponent

/**
 * Surfaced in **Settings → Tools → Periscope**. Wired through `plugin.xml`
 * via `applicationConfigurable parentId="tools"`.
 *
 * Hosts the global Periscope preferences (currently one toggle). Kept
 * separate from the per-project Run Config editor — those settings live
 * in `.idea/runConfigurations/Periscope.xml` and are edited via Run > Edit
 * Configurations.
 */
class PeriscopeConfigurable : Configurable {

    private val autoSeedBox = JBCheckBox(
        "Auto-create a Periscope run configuration in new projects",
    )

    private val panel: JComponent = panel {
        row {
            cell(autoSeedBox)
                .comment(
                    "When on, opening a project that doesn't already have a Periscope " +
                        "run config silently creates one in <code>.idea/runConfigurations/</code>. " +
                        "Turn off if you prefer to add the config manually per project, or share a " +
                        "committed config across the team.",
                )
        }
    }

    override fun getDisplayName(): String = "Periscope"

    override fun createComponent(): JComponent = panel

    override fun isModified(): Boolean =
        autoSeedBox.isSelected != PeriscopeApplicationSettings.getInstance().autoSeedRunConfig

    override fun apply() {
        PeriscopeApplicationSettings.getInstance().autoSeedRunConfig = autoSeedBox.isSelected
    }

    override fun reset() {
        autoSeedBox.isSelected = PeriscopeApplicationSettings.getInstance().autoSeedRunConfig
    }
}
