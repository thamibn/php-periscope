package dev.periscope.phpstorm.dap

import kotlinx.coroutines.*
import kotlinx.coroutines.channels.Channel
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.SharedFlow
import kotlinx.coroutines.flow.asSharedFlow
import kotlinx.serialization.encodeToString
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.encodeToJsonElement
import java.io.BufferedReader
import java.io.IOException
import java.io.InputStream
import java.io.OutputStream
import java.nio.charset.StandardCharsets
import java.util.concurrent.ConcurrentHashMap
import java.util.concurrent.atomic.AtomicInteger

/**
 * Stdio-based Debug Adapter Protocol client.
 *
 * Spawns `periscope-daemon --dap-stdio`, frames messages with the standard
 * `Content-Length: NN\r\n\r\n{json}` envelope, dispatches responses by `request_seq`
 * and broadcasts events on [events]. Built on Kotlin coroutines.
 *
 * Lifecycle:
 *   1. [start] spawns the process and the reader coroutine.
 *   2. Caller sends requests via [sendRequest] (suspends until the response arrives).
 *   3. Caller subscribes to [events] for asynchronous DAP events (stopped, output, …).
 *   4. [close] tears it all down.
 */
class DapClient(
    private val daemonPath: String,
    private val args: List<String> = listOf("--dap-stdio"),
    private val workingDir: String? = null,
    private val onLog: (String) -> Unit = {},
) : AutoCloseable {

    companion object {
        val JSON: Json = Json {
            ignoreUnknownKeys = true
            encodeDefaults = false
            classDiscriminator = "__variant"  // never collides with DAP `type`
        }
    }

    private val seq = AtomicInteger(1)
    private val pending = ConcurrentHashMap<Int, CompletableDeferred<DapResponse>>()
    private val _events = MutableSharedFlow<DapEvent>(extraBufferCapacity = 256)
    val events: SharedFlow<DapEvent> = _events.asSharedFlow()

    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private lateinit var proc: Process
    private lateinit var stdin: OutputStream
    private lateinit var stdout: InputStream
    private var readerJob: Job? = null
    private var stderrJob: Job? = null

    @Volatile var isAlive: Boolean = false
        private set

    fun start() {
        val command = buildList {
            add(daemonPath)
            addAll(args)
        }
        val pb = ProcessBuilder(command)
        workingDir?.let { pb.directory(java.io.File(it)) }
        pb.redirectErrorStream(false)
        proc = pb.start()
        stdin = proc.outputStream
        stdout = proc.inputStream
        isAlive = true

        readerJob = scope.launch { readLoop() }
        stderrJob = scope.launch { stderrLoop() }
    }

    /**
     * Send a request and suspend until the matching response arrives.
     * Throws [DapException] if the daemon returns `success=false`.
     */
    suspend inline fun <reified A, reified R> sendRequest(
        command: String,
        arguments: A,
    ): R {
        val argJson = JSON.encodeToJsonElement(arguments)
        val resp = sendRequestRaw(command, argJson)
        if (!resp.success) {
            throw DapException(command, resp.message ?: "request failed")
        }
        val body = resp.body ?: throw DapException(command, "empty body")
        return JSON.decodeFromJsonElement(JSON.serializersModule.serializer(), body) as R
    }

    suspend fun sendRequestRaw(command: String, arguments: kotlinx.serialization.json.JsonElement?): DapResponse {
        check(isAlive) { "DAP client not started or already closed" }
        val s = seq.getAndIncrement()
        val req = DapRequest(seq = s, command = command, arguments = arguments)
        val deferred = CompletableDeferred<DapResponse>()
        pending[s] = deferred

        val json = JSON.encodeToString(req)
        writeFramed(json)
        return deferred.await()
    }

    /**
     * Fire-and-forget — used for `disconnect` where we don't care to wait.
     */
    suspend fun sendNotification(command: String, arguments: kotlinx.serialization.json.JsonElement? = null) {
        check(isAlive) { "DAP client not started or already closed" }
        val s = seq.getAndIncrement()
        val req = DapRequest(seq = s, command = command, arguments = arguments)
        writeFramed(JSON.encodeToString(req))
    }

    private fun writeFramed(json: String) {
        val bytes = json.toByteArray(StandardCharsets.UTF_8)
        val header = "Content-Length: ${bytes.size}\r\n\r\n".toByteArray(StandardCharsets.US_ASCII)
        synchronized(stdin) {
            stdin.write(header)
            stdin.write(bytes)
            stdin.flush()
        }
    }

    private suspend fun readLoop() {
        try {
            while (isAlive) {
                val msg = readNext() ?: break
                dispatch(msg)
            }
        } catch (e: IOException) {
            onLog("DAP reader IO error: ${e.message}")
        } finally {
            shutdownPending()
            isAlive = false
        }
    }

    private fun readNext(): String? {
        val headers = mutableMapOf<String, String>()
        while (true) {
            val line = readLine(stdout) ?: return null
            if (line.isEmpty()) break
            val sep = line.indexOf(':')
            if (sep > 0) headers[line.substring(0, sep).trim()] = line.substring(sep + 1).trim()
        }
        val len = headers["Content-Length"]?.toIntOrNull()
            ?: throw IOException("Missing Content-Length header")
        val buf = ByteArray(len)
        var read = 0
        while (read < len) {
            val n = stdout.read(buf, read, len - read)
            if (n < 0) throw IOException("EOF while reading body")
            read += n
        }
        return String(buf, StandardCharsets.UTF_8)
    }

    private fun readLine(input: InputStream): String? {
        val sb = StringBuilder()
        while (true) {
            val b = input.read()
            if (b == -1) return if (sb.isEmpty()) null else sb.toString()
            if (b == '\r'.code) {
                input.read() // consume \n
                return sb.toString()
            }
            sb.append(b.toChar())
        }
    }

    private fun dispatch(json: String) {
        val element = JSON.parseToJsonElement(json) as JsonObject
        when (element["type"]?.toString()?.trim('"')) {
            "response" -> {
                val resp = JSON.decodeFromString<DapResponse>(json)
                pending.remove(resp.requestSeq)?.complete(resp)
            }
            "event" -> {
                val evt = JSON.decodeFromString<DapEvent>(json)
                scope.launch { _events.emit(evt) }
            }
            else -> onLog("DAP: unknown message type: $json")
        }
    }

    private suspend fun stderrLoop() {
        val reader = proc.errorStream.bufferedReader(StandardCharsets.UTF_8)
        try {
            reader.useLines { lines ->
                for (line in lines) onLog("[daemon] $line")
            }
        } catch (_: IOException) {}
    }

    private fun shutdownPending() {
        val ex = DapException("connection", "daemon connection closed")
        pending.values.forEach { it.completeExceptionally(ex) }
        pending.clear()
    }

    override fun close() {
        if (!isAlive) return
        isAlive = false
        runCatching { stdin.close() }
        runCatching { stdout.close() }
        runCatching { proc.destroy() }
        readerJob?.cancel()
        stderrJob?.cancel()
        scope.cancel()
    }
}

class DapException(val command: String, message: String) : RuntimeException("DAP $command: $message")
