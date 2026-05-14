import org.jetbrains.intellij.platform.gradle.IntelliJPlatformType

plugins {
    kotlin("jvm") version "2.0.21"
    kotlin("plugin.serialization") version "2.0.21"
    id("org.jetbrains.intellij.platform") version "2.1.0"
}

group = providers.gradleProperty("pluginGroup").get()
version = providers.gradleProperty("pluginVersion").get()

repositories {
    mavenCentral()
    intellijPlatform { defaultRepositories() }
}

dependencies {
    intellijPlatform {
        create(
            IntelliJPlatformType.PhpStorm,
            providers.gradleProperty("platformVersion").get(),
        )
        instrumentationTools()
        pluginVerifier()
    }

    implementation("org.jetbrains.kotlinx:kotlinx-serialization-json:1.7.3")
    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-core:1.9.0")

    testImplementation(kotlin("test"))
}

kotlin {
    jvmToolchain(17)
}

intellijPlatform {
    pluginConfiguration {
        name = "php-periscope"
        description = """
            <p>Live observability + time-travel debugger for Laravel. Pauses any request, shows every
            variable, SQL query, log line, dispatched job, fired event, cache hit, Redis command, and
            outbound HTTP call that occurred up to the paused line — and lets you scrub backward in time.</p>
            <p>Connects to <code>periscope-daemon</code> over DAP-stdio. Supports breakpoints,
            step over / into / out, <strong>step back</strong>, variables, watches, evaluate.
            See <a href="https://periscope.thamibn.com">periscope.thamibn.com</a> for the full picture.</p>
        """.trimIndent()

        ideaVersion {
            sinceBuild = providers.gradleProperty("pluginSinceBuild").get()
            untilBuild = providers.gradleProperty("pluginUntilBuild").get()
        }

        vendor {
            name = "periscopephp"
            url = "https://periscope.thamibn.com"
        }
    }

    publishing {
        // Marketplace token wired in CI via env var, not committed.
        token = providers.environmentVariable("JETBRAINS_MARKETPLACE_TOKEN")
    }

    pluginVerification {
        ides {
            recommended()
        }
    }
}

tasks {
    wrapper {
        gradleVersion = "8.10"
    }
}
