package dev.periscope.phpstorm.runconfig

import com.intellij.execution.configurations.ConfigurationFactory
import com.intellij.execution.configurations.ConfigurationType
import com.intellij.icons.AllIcons
import javax.swing.Icon

class PeriscopeRunConfigurationType : ConfigurationType {
    override fun getDisplayName(): String = "Periscope"
    override fun getConfigurationTypeDescription(): String =
        "Replay a periscope .cptrace file with full step / step-back debugging."
    override fun getIcon(): Icon = AllIcons.Debugger.Watch  // TODO: replace with our own SVG in v0.2
    override fun getId(): String = "PERISCOPE_RUN_CONFIGURATION"
    override fun getConfigurationFactories(): Array<ConfigurationFactory> =
        arrayOf(PeriscopeConfigurationFactory(this))
}
