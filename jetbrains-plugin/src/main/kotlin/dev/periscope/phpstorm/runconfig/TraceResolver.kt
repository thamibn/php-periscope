package dev.periscope.phpstorm.runconfig

import kotlinx.coroutines.suspendCancellableCoroutine
import java.nio.file.ClosedWatchServiceException
import java.nio.file.FileSystems
import java.nio.file.Files
import java.nio.file.Path
import java.nio.file.StandardWatchEventKinds
import java.nio.file.WatchService
import kotlin.coroutines.resume
import kotlin.io.path.extension
import kotlin.io.path.getLastModifiedTime
import kotlin.io.path.isRegularFile
import kotlin.io.path.name

/**
 * Picks the trace file to replay.
 *
 * Resolution order:
 *   1. If [explicit] points to an existing readable file → use it as-is.
 *      Explicit wins even when the user typed a stale path (so historical replay
 *      surfaces a clear "file not found" instead of silently switching).
 *   2. Otherwise glob [traceDir] for `*.cptrace` files and return the newest.
 *   3. If none exist, return `null` — caller decides whether to listen or fail.
 */
object TraceResolver {

    fun resolve(explicit: String, traceDir: String): Path? {
        if (explicit.isNotBlank()) {
            val p = Path.of(explicit)
            return if (p.isRegularFile()) p else null
        }
        return newestIn(Path.of(traceDir))
    }

    fun newestIn(dir: Path): Path? {
        if (!Files.isDirectory(dir)) return null
        return Files.list(dir).use { stream ->
            stream
                .filter { it.isRegularFile() && it.extension == "cptrace" && !it.name.startsWith(".") }
                .max(compareBy { it.getLastModifiedTime().toMillis() })
                .orElse(null)
        }
    }

    /**
     * Suspends until a new `.cptrace` file lands in [dir], then returns its path.
     *
     * Uses [java.nio.file.WatchService] (which we already depend on transitively
     * via the JDK — no new gradle deps). Cancellation closes the watcher cleanly.
     *
     * **Race-safe:** if a file appeared between the caller's pre-check and the
     * watcher's registration, we still pick it up by re-scanning once after
     * registering. Without that re-scan, a file written in the gap would be
     * missed forever.
     */
    suspend fun awaitNext(dir: Path): Path = suspendCancellableCoroutine { cont ->
        Files.createDirectories(dir)
        val watcher: WatchService = FileSystems.getDefault().newWatchService()
        dir.register(watcher, StandardWatchEventKinds.ENTRY_CREATE, StandardWatchEventKinds.ENTRY_MODIFY)

        cont.invokeOnCancellation { runCatching { watcher.close() } }

        // Re-scan once after registering so we don't miss a file that landed in
        // the gap between the caller's check and our watch registration.
        newestIn(dir)?.let {
            runCatching { watcher.close() }
            if (cont.isActive) cont.resume(it)
            return@suspendCancellableCoroutine
        }

        val thread = Thread({
            try {
                while (cont.isActive) {
                    val key = watcher.take()
                    for (event in key.pollEvents()) {
                        val ctx = event.context() as? Path ?: continue
                        val resolved = dir.resolve(ctx)
                        if (resolved.extension == "cptrace" && !ctx.name.startsWith(".") && resolved.isRegularFile()) {
                            runCatching { watcher.close() }
                            if (cont.isActive) cont.resume(resolved)
                            return@Thread
                        }
                    }
                    if (!key.reset()) break
                }
            } catch (_: InterruptedException) {
                // cancellation path
            } catch (_: ClosedWatchServiceException) {
                // cancellation path
            } finally {
                runCatching { watcher.close() }
            }
        }, "periscope-trace-watcher")
        thread.isDaemon = true
        thread.start()
    }
}
