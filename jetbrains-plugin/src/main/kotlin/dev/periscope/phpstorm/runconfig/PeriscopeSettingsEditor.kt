package dev.periscope.phpstorm.runconfig

import com.intellij.openapi.fileChooser.FileChooserDescriptorFactory
import com.intellij.openapi.options.SettingsEditor
import com.intellij.openapi.ui.TextFieldWithBrowseButton
import com.intellij.ui.components.JBCheckBox
import com.intellij.ui.dsl.builder.bindSelected
import com.intellij.ui.dsl.builder.bindText
import com.intellij.ui.dsl.builder.panel
import javax.swing.JComponent

/**
 * UI shown in **Run / Edit Configurations…** for editing a Periscope launch.
 *
 * Three fields:
 *   * Trace path — file picker for the .cptrace to replay
 *   * Daemon path — fallback if `periscope-daemon` isn't on PATH
 *   * Stop on entry — pause at the first observed frame
 */
class PeriscopeSettingsEditor : SettingsEditor<PeriscopeRunConfiguration>() {

    private val tracePathField = TextFieldWithBrowseButton().apply {
        addBrowseFolderListener(
            "Periscope Trace File",
            "Select a .cptrace file to replay",
            null,
            FileChooserDescriptorFactory.createSingleFileDescriptor("cptrace"),
        )
    }
    private val daemonPathField = TextFieldWithBrowseButton().apply {
        addBrowseFolderListener(
            "periscope-daemon Binary",
            "Locate the periscope-daemon binary if not on PATH",
            null,
            FileChooserDescriptorFactory.createSingleFileDescriptor(),
        )
    }
    private val stopOnEntryBox = JBCheckBox("Stop on entry")

    private val root: JComponent = panel {
        row("Trace file:") { cell(tracePathField).align(com.intellij.ui.dsl.builder.AlignX.FILL) }
        row("Daemon binary:") { cell(daemonPathField).align(com.intellij.ui.dsl.builder.AlignX.FILL) }
        row { cell(stopOnEntryBox) }
    }

    override fun resetEditorFrom(config: PeriscopeRunConfiguration) {
        tracePathField.text = config.tracePath
        daemonPathField.text = config.daemonPath
        stopOnEntryBox.isSelected = config.stopOnEntry
    }

    override fun applyEditorTo(config: PeriscopeRunConfiguration) {
        config.tracePath = tracePathField.text
        config.daemonPath = daemonPathField.text
        config.stopOnEntry = stopOnEntryBox.isSelected
    }

    override fun createEditor(): JComponent = root
}
