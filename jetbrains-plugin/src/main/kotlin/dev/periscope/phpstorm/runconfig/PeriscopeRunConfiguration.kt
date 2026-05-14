package dev.periscope.phpstorm.runconfig

import com.intellij.execution.ExecutionException
import com.intellij.execution.Executor
import com.intellij.execution.configurations.ConfigurationFactory
import com.intellij.execution.configurations.LocatableConfigurationBase
import com.intellij.execution.runners.RunConfigurationWithSuppressedDefaultRunAction
import com.intellij.execution.configurations.RunProfileState
import com.intellij.execution.runners.ExecutionEnvironment
import com.intellij.openapi.options.SettingsEditor
import com.intellij.openapi.project.Project

/**
 * `RunConfigurationWithSuppressedDefaultRunAction` removes the green Run button
 * from the Periscope config's toolbar — there's nothing to "run", only to "debug
 * (replay)". The bug icon (Shift+F9) is the only entry point.
 *
 * We deliberately do NOT suppress the default debug action — that's exactly the
 * one we want.
 */
class PeriscopeRunConfiguration(
    project: Project,
    factory: ConfigurationFactory,
    name: String,
) : LocatableConfigurationBase<PeriscopeRunConfigurationOptions>(project, factory, name),
    RunConfigurationWithSuppressedDefaultRunAction {

    override fun getOptions(): PeriscopeRunConfigurationOptions =
        super.getOptions() as PeriscopeRunConfigurationOptions

    var tracePath: String
        get() = options.tracePath
        set(v) { options.tracePath = v }

    var traceDir: String
        get() = options.traceDir
        set(v) { options.traceDir = v }

    var daemonPath: String
        get() = options.daemonPath
        set(v) { options.daemonPath = v }

    var stopOnEntry: Boolean
        get() = options.stopOnEntry
        set(v) { options.stopOnEntry = v }

    var listen: Boolean
        get() = options.listen
        set(v) { options.listen = v }

    override fun getConfigurationEditor(): SettingsEditor<out com.intellij.execution.configurations.RunConfiguration> =
        PeriscopeSettingsEditor()

    // Surfaced by RunManager when the user hasn't typed a custom name. Without this,
    // the Name field in Edit Configurations renders blank on first open.
    override fun suggestedName(): String = "Periscope"

    @Throws(ExecutionException::class)
    override fun getState(executor: Executor, environment: ExecutionEnvironment): RunProfileState {
        // CRITICAL: must NOT return null. The IntelliJ platform calls
        // RunConfiguration.getState() before invoking the runner; a null
        // result silently aborts the launch *before* PeriscopeDebuggerRunner
        // ever gets called, so the user clicks Debug and nothing happens.
        //
        // We have no command line to launch (the daemon is spawned later
        // from PeriscopeDebugProcess), so this is a no-op state whose
        // execute() returns null. PeriscopeDebuggerRunner ignores `state`
        // entirely; this exists only to keep the platform from bailing.
        return RunProfileState { _, _ -> null }
    }
}
