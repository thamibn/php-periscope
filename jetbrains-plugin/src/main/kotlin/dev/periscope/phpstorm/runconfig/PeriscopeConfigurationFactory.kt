package dev.periscope.phpstorm.runconfig

import com.intellij.execution.configurations.ConfigurationFactory
import com.intellij.execution.configurations.ConfigurationType
import com.intellij.execution.configurations.RunConfiguration
import com.intellij.openapi.components.BaseState
import com.intellij.openapi.project.Project

class PeriscopeConfigurationFactory(type: ConfigurationType) : ConfigurationFactory(type) {

    override fun getId(): String = "periscope"

    override fun createTemplateConfiguration(project: Project): RunConfiguration =
        PeriscopeRunConfiguration(project, this, "Periscope")

    override fun getOptionsClass(): Class<out BaseState> =
        PeriscopeRunConfigurationOptions::class.java
}
