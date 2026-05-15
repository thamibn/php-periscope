package dev.periscope.phpstorm.runconfig

import org.junit.Test
import java.nio.file.Files
import java.nio.file.Path
import kotlin.io.path.createFile
import kotlin.test.assertEquals
import kotlin.test.assertNull
import kotlin.test.assertTrue

/**
 * Pure-Kotlin tests for [TraceResolver] — no IntelliJ Platform needed.
 *
 * The resolver is the hot path for the auto-pick-latest UX shipped in v0.1.4
 * and the listen-mode feature in v0.1.7. Regression here would silently send
 * the wrong trace to the daemon or hang forever on a fresh project.
 */
class TraceResolverTest {

    @Test fun `newestIn returns null for missing directory`() {
        assertNull(TraceResolver.newestIn(Path.of("/tmp/__nope_periscope_does_not_exist__")))
    }

    @Test fun `newestIn returns null for empty directory`() {
        val dir = Files.createTempDirectory("periscope-test-empty-")
        try {
            assertNull(TraceResolver.newestIn(dir))
        } finally {
            dir.toFile().deleteRecursively()
        }
    }

    @Test fun `newestIn ignores files that are not cptrace`() {
        val dir = Files.createTempDirectory("periscope-test-mixed-")
        try {
            dir.resolve("foo.txt").createFile()
            dir.resolve("bar.log").createFile()
            assertNull(TraceResolver.newestIn(dir))
        } finally {
            dir.toFile().deleteRecursively()
        }
    }

    @Test fun `newestIn returns the most recently modified cptrace`() {
        val dir = Files.createTempDirectory("periscope-test-newest-")
        try {
            val older = dir.resolve("100-old.cptrace").createFile()
            val newer = dir.resolve("200-new.cptrace").createFile()
            // Force older mtime explicitly to avoid filesystem mtime granularity races.
            older.toFile().setLastModified(1_000_000L)
            newer.toFile().setLastModified(2_000_000L)
            assertEquals(newer, TraceResolver.newestIn(dir))
        } finally {
            dir.toFile().deleteRecursively()
        }
    }

    @Test fun `newestIn ignores dot-prefixed cptrace candidates`() {
        val dir = Files.createTempDirectory("periscope-test-dot-")
        try {
            // VS Code-style swap files / atomic-write temps should not be returned.
            dir.resolve(".inprogress.cptrace").createFile()
            assertNull(TraceResolver.newestIn(dir))
        } finally {
            dir.toFile().deleteRecursively()
        }
    }

    @Test fun `resolve prefers explicit path when it exists`() {
        val dir = Files.createTempDirectory("periscope-test-resolve-")
        try {
            val explicit = dir.resolve("explicit.cptrace").createFile()
            dir.resolve("newer.cptrace").createFile().toFile().setLastModified(Long.MAX_VALUE / 2)
            assertEquals(explicit, TraceResolver.resolve(explicit.toString(), dir.toString()))
        } finally {
            dir.toFile().deleteRecursively()
        }
    }

    @Test fun `resolve returns null when explicit path is missing`() {
        // Even if the directory has a newer trace, an explicit-but-missing path
        // surfaces as null so the runner can fail fast with a clear error — not
        // silently fall back to a different trace.
        val dir = Files.createTempDirectory("periscope-test-resolve-missing-")
        try {
            dir.resolve("present.cptrace").createFile()
            val missing = dir.resolve("does-not-exist.cptrace")
            assertNull(TraceResolver.resolve(missing.toString(), dir.toString()))
        } finally {
            dir.toFile().deleteRecursively()
        }
    }

    @Test fun `resolve falls back to newest when explicit is blank`() {
        val dir = Files.createTempDirectory("periscope-test-resolve-blank-")
        try {
            val trace = dir.resolve("a.cptrace").createFile()
            val resolved = TraceResolver.resolve("", dir.toString())
            assertEquals(trace, resolved)
        } finally {
            dir.toFile().deleteRecursively()
        }
    }
}
