plugins {
    // Auto-downloads required JVM toolchains (e.g. JDK 17) when not installed locally.
    // One-time cost on the contributor's machine; end users never run this — they download
    // the pre-built plugin zip from GitHub Releases.
    id("org.gradle.toolchains.foojay-resolver-convention") version "0.8.0"
}

rootProject.name = "periscope-jetbrains"
