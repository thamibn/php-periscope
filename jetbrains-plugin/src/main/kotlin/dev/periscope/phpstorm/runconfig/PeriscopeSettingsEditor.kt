package dev.periscope.phpstorm.runconfig

import com.intellij.openapi.fileChooser.FileChooserDescriptor
import com.intellij.openapi.fileChooser.FileChooserDescriptorFactory
import com.intellij.openapi.options.SettingsEditor
import com.intellij.openapi.ui.TextBrowseFolderListener
import com.intellij.openapi.ui.TextFieldWithBrowseButton
import com.intellij.ui.components.JBCheckBox
import com.intellij.ui.dsl.builder.AlignX
import com.intellij.ui.dsl.builder.panel
import javax.swing.JComponent

/**
 * UI shown in **Run / Edit Configurations…** for editing a Periscope launch.
 *
 * Fields:
 *   * Trace file — specific .cptrace to replay (blank = auto: latest in dir)
 *   * Trace directory — where periscope-daemon writes traces (default /tmp/periscope)
 *   * Daemon binary — fallback if `periscope-daemon` isn't on PATH
 *   * Listen — when trace file is blank and none exists, watch for the next
 *   * Stop on entry — pause at the first observed frame
 *
 * Uses the listener-based `addBrowseFolderListener(BrowseFolderActionListener)`
 * overload, which is the non-deprecated path in 2024.2+. The title and
 * description live on the descriptor itself via `withTitle` / `withDescription`.
 * The legacy 4-arg form is slated for removal in a future PhpStorm release.
 */
class PeriscopeSettingsEditor : SettingsEditor<PeriscopeRunConfiguration>() {

    private val tracePathField = TextFieldWithBrowseButton().apply {
        addBrowseFolderListener(TextBrowseFolderListener(traceFileDescriptor()))
    }
    private val traceDirField = TextFieldWithBrowseButton().apply {
        addBrowseFolderListener(TextBrowseFolderListener(traceDirDescriptor()))
    }
    private val daemonPathField = TextFieldWithBrowseButton().apply {
        addBrowseFolderListener(TextBrowseFolderListener(daemonBinaryDescriptor()))
    }
    private val listenBox = JBCheckBox("Listen for new traces (auto-attach when one lands)")
    private val stopOnEntryBox = JBCheckBox("Stop on entry")

    private val root: JComponent = panel {
        row("Trace file:") {
            cell(tracePathField).align(AlignX.FILL)
                .comment("Leave blank to auto-pick the newest trace, or to listen if none exists yet.")
        }
        row("Trace directory:") { cell(traceDirField).align(AlignX.FILL) }
        row("Daemon binary:") { cell(daemonPathField).align(AlignX.FILL) }
        row { cell(listenBox) }
        row { cell(stopOnEntryBox) }
    }

    override fun resetEditorFrom(config: PeriscopeRunConfiguration) {
        tracePathField.text = config.tracePath
        traceDirField.text = config.traceDir
        daemonPathField.text = config.daemonPath
        listenBox.isSelected = config.listen
        stopOnEntryBox.isSelected = config.stopOnEntry
    }

    override fun applyEditorTo(config: PeriscopeRunConfiguration) {
        config.tracePath = tracePathField.text
        config.traceDir = traceDirField.text.ifBlank { PeriscopeRunConfigurationOptions.DEFAULT_TRACE_DIR }
        config.daemonPath = daemonPathField.text
        config.listen = listenBox.isSelected
        config.stopOnEntry = stopOnEntryBox.isSelected
    }

    override fun createEditor(): JComponent = root

    private fun traceFileDescriptor(): FileChooserDescriptor =
        FileChooserDescriptorFactory.createSingleFileDescriptor("cptrace")
            .withTitle("Periscope Trace File")
            .withDescription("Leave blank to auto-pick the newest .cptrace")

    private fun traceDirDescriptor(): FileChooserDescriptor =
        FileChooserDescriptorFactory.createSingleFolderDescriptor()
            .withTitle("Periscope Trace Directory")
            .withDescription("Directory where periscope-daemon writes .cptrace files")

    private fun daemonBinaryDescriptor(): FileChooserDescriptor =
        FileChooserDescriptorFactory.createSingleFileDescriptor()
            .withTitle("periscope-daemon Binary")
            .withDescription("Locate the periscope-daemon binary if not on PATH")
}
