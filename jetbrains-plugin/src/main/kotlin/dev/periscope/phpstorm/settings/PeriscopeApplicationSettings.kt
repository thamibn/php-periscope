package dev.periscope.phpstorm.settings

import com.intellij.openapi.application.ApplicationManager
import com.intellij.openapi.components.PersistentStateComponent
import com.intellij.openapi.components.Service
import com.intellij.openapi.components.State
import com.intellij.openapi.components.Storage
import com.intellij.util.xmlb.XmlSerializerUtil

/**
 * Application-wide Periscope settings — persists to `<config>/options/periscope.xml`
 * so they apply across every project the user opens with this IDE install.
 *
 * Currently a single toggle: whether the IDE should auto-seed a Periscope
 * run configuration on first project open. Defaults to ON because that's
 * the zero-config UX we want for first-time users; flipping it off is for
 * power users who manage their `.idea/runConfigurations/` manually or share
 * one already-committed config across a team.
 */
@Service(Service.Level.APP)
@State(name = "PeriscopeApplicationSettings", storages = [Storage("periscope.xml")])
class PeriscopeApplicationSettings : PersistentStateComponent<PeriscopeApplicationSettings.State> {

    data class State(var autoSeedRunConfig: Boolean = true)

    private var state = State()

    override fun getState(): State = state

    override fun loadState(loaded: State) {
        XmlSerializerUtil.copyBean(loaded, state)
    }

    var autoSeedRunConfig: Boolean
        get() = state.autoSeedRunConfig
        set(value) { state.autoSeedRunConfig = value }

    companion object {
        fun getInstance(): PeriscopeApplicationSettings =
            ApplicationManager.getApplication().getService(PeriscopeApplicationSettings::class.java)
    }
}
