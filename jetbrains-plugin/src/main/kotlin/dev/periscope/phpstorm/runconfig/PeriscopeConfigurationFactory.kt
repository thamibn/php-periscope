package dev.periscope.phpstorm.runconfig

import com.intellij.execution.configurations.ConfigurationFactory
import com.intellij.execution.configurations.ConfigurationType
import com.intellij.execution.configurations.RunConfiguration
import com.intellij.openapi.components.BaseState
import com.intellij.openapi.project.Project

class PeriscopeConfigurationFactory(type: ConfigurationType) : ConfigurationFactory(type) {

    override fun getId(): String = "periscope"

    override fun createTemplateConfiguration(project: Project): RunConfiguration =
        PeriscopeRunConfiguration(project, this, DEFAULT_NAME)

    // PhpStorm calls this when the user picks "+ → Periscope" in Edit Configurations.
    // The platform passes a blank name; override to land a pre-filled default so the
    // user doesn't see an empty Name field. RunManager applies "(1)", "(2)", … suffix
    // automatically on collision.
    override fun createConfiguration(name: String?, template: RunConfiguration): RunConfiguration =
        super.createConfiguration(name?.ifBlank { DEFAULT_NAME } ?: DEFAULT_NAME, template)

    override fun getOptionsClass(): Class<out BaseState> =
        PeriscopeRunConfigurationOptions::class.java

    private companion object {
        const val DEFAULT_NAME = "Periscope"
    }
}
