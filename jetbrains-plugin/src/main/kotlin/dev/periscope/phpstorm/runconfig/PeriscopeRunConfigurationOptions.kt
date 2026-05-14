package dev.periscope.phpstorm.runconfig

import com.intellij.execution.configurations.LocatableRunConfigurationOptions
import com.intellij.openapi.components.StoredProperty

/**
 * Persistable settings backing one Periscope run configuration. Stored to the
 * project's `.idea/workspace.xml` so a `Periscope: open latest trace` entry
 * survives IDE restart.
 *
 * Extends `LocatableRunConfigurationOptions` because the run-config base
 * class is `LocatableConfigurationBase` which constrains its options type.
 */
class PeriscopeRunConfigurationOptions : LocatableRunConfigurationOptions() {
    private val tracePathProperty: StoredProperty<String?> =
        string("\${workspaceFolder}/tmp/periscope/latest.cptrace").provideDelegate(this, "tracePath")
    private val daemonPathProperty: StoredProperty<String?> =
        string("periscope-daemon").provideDelegate(this, "daemonPath")
    private val stopOnEntryProperty: StoredProperty<Boolean> =
        property(false).provideDelegate(this, "stopOnEntry")

    var tracePath: String
        get() = tracePathProperty.getValue(this) ?: "\${workspaceFolder}/tmp/periscope/latest.cptrace"
        set(v) = tracePathProperty.setValue(this, v)

    var daemonPath: String
        get() = daemonPathProperty.getValue(this) ?: "periscope-daemon"
        set(v) = daemonPathProperty.setValue(this, v)

    var stopOnEntry: Boolean
        get() = stopOnEntryProperty.getValue(this)
        set(v) = stopOnEntryProperty.setValue(this, v)
}
