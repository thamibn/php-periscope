package dev.periscope.phpstorm.runconfig

import com.intellij.execution.configurations.LocatableRunConfigurationOptions
import com.intellij.openapi.components.StoredProperty

/**
 * Persistable settings backing one Periscope run configuration. Stored to the
 * project's `.idea/workspace.xml` so a `Periscope: open latest trace` entry
 * survives IDE restart.
 *
 * Trace path semantics:
 *   * blank → auto: pick the newest `.cptrace` in [traceDir]; if none exists
 *     yet, watch the directory and attach to the first one that lands.
 *   * non-blank → replay that exact file (historical replay).
 *
 * `traceDir` defaults to `/tmp/periscope` to match `periscope-daemon`'s default
 * (`PERISCOPE_TRACE_DIR`). The VSCode-only `${workspaceFolder}` macro that
 * shipped in v0.1.3 was removed in v0.1.4 — it never expanded in JetBrains IDEs.
 */
class PeriscopeRunConfigurationOptions : LocatableRunConfigurationOptions() {
    private val tracePathProperty: StoredProperty<String?> =
        string("").provideDelegate(this, "tracePath")
    private val traceDirProperty: StoredProperty<String?> =
        string(DEFAULT_TRACE_DIR).provideDelegate(this, "traceDir")
    private val daemonPathProperty: StoredProperty<String?> =
        string("periscope-daemon").provideDelegate(this, "daemonPath")
    private val stopOnEntryProperty: StoredProperty<Boolean> =
        property(false).provideDelegate(this, "stopOnEntry")
    private val listenProperty: StoredProperty<Boolean> =
        property(true).provideDelegate(this, "listen")

    var tracePath: String
        get() = tracePathProperty.getValue(this) ?: ""
        set(v) = tracePathProperty.setValue(this, v)

    var traceDir: String
        get() = traceDirProperty.getValue(this) ?: DEFAULT_TRACE_DIR
        set(v) = traceDirProperty.setValue(this, v)

    var daemonPath: String
        get() = daemonPathProperty.getValue(this) ?: "periscope-daemon"
        set(v) = daemonPathProperty.setValue(this, v)

    var stopOnEntry: Boolean
        get() = stopOnEntryProperty.getValue(this)
        set(v) = stopOnEntryProperty.setValue(this, v)

    var listen: Boolean
        get() = listenProperty.getValue(this)
        set(v) = listenProperty.setValue(this, v)

    companion object {
        const val DEFAULT_TRACE_DIR = "/tmp/periscope"
    }
}
