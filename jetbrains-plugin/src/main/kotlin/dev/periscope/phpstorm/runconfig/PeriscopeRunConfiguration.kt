package dev.periscope.phpstorm.runconfig

import com.intellij.execution.ExecutionException
import com.intellij.execution.Executor
import com.intellij.execution.configurations.ConfigurationFactory
import com.intellij.execution.configurations.LocatableConfigurationBase
import com.intellij.execution.configurations.RunProfileState
import com.intellij.execution.runners.ExecutionEnvironment
import com.intellij.openapi.options.SettingsEditor
import com.intellij.openapi.project.Project

class PeriscopeRunConfiguration(
    project: Project,
    factory: ConfigurationFactory,
    name: String,
) : LocatableConfigurationBase<PeriscopeRunConfigurationOptions>(project, factory, name) {

    override fun getOptions(): PeriscopeRunConfigurationOptions =
        super.getOptions() as PeriscopeRunConfigurationOptions

    var tracePath: String
        get() = options.tracePath
        set(v) { options.tracePath = v }

    var daemonPath: String
        get() = options.daemonPath
        set(v) { options.daemonPath = v }

    var stopOnEntry: Boolean
        get() = options.stopOnEntry
        set(v) { options.stopOnEntry = v }

    override fun getConfigurationEditor(): SettingsEditor<out com.intellij.execution.configurations.RunConfiguration> =
        PeriscopeSettingsEditor()

    @Throws(ExecutionException::class)
    override fun getState(executor: Executor, environment: ExecutionEnvironment): RunProfileState? {
        // The actual DAP session is started by [PeriscopeDebuggerRunner] using XDebugSession;
        // no command-line state to launch here.
        return null
    }
}
