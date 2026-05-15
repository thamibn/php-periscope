package dev.periscope.phpstorm.dap

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonObject

/**
 * Wire-level Debug Adapter Protocol messages.
 *
 * Reference: https://microsoft.github.io/debug-adapter-protocol/specification
 *
 * Only the subset periscope uses. Adding a new request type means adding a
 * data class here and a wrapper in [DapClient].
 */

@Serializable
data class DapRequest(
    val seq: Int,
    // DAP requires every message to carry `type` ("request" | "response" |
    // "event"). The default value is correct, but `DapClient.JSON` has
    // `encodeDefaults = false` which would strip the field from the wire —
    // and the daemon rejects requests with `missing field \`type\`` at
    // line 1 column 47. Force-encode this field always.
    @kotlinx.serialization.EncodeDefault(kotlinx.serialization.EncodeDefault.Mode.ALWAYS)
    val type: String = "request",
    val command: String,
    val arguments: JsonElement? = null,
)

@Serializable
data class DapResponse(
    val seq: Int,
    @kotlinx.serialization.EncodeDefault(kotlinx.serialization.EncodeDefault.Mode.ALWAYS)
    val type: String = "response",
    @SerialName("request_seq") val requestSeq: Int,
    val success: Boolean,
    val command: String,
    val message: String? = null,
    val body: JsonElement? = null,
)

@Serializable
data class DapEvent(
    val seq: Int,
    @kotlinx.serialization.EncodeDefault(kotlinx.serialization.EncodeDefault.Mode.ALWAYS)
    val type: String = "event",
    val event: String,
    val body: JsonObject? = null,
)

// ---------- request argument types ----------

@Serializable
data class InitializeArguments(
    val clientID: String = "periscope-jetbrains",
    val clientName: String = "php-periscope (JetBrains)",
    val adapterID: String = "periscope",
    val pathFormat: String = "path",
    val linesStartAt1: Boolean = true,
    val columnsStartAt1: Boolean = true,
    val supportsVariableType: Boolean = true,
    val supportsRunInTerminalRequest: Boolean = false,
    val locale: String = "en-US",
)

@Serializable
data class LaunchArguments(
    val tracePath: String,
    val stopOnEntry: Boolean = false,
    val noDebug: Boolean = false,
)

@Serializable
data class Source(
    val name: String? = null,
    val path: String? = null,
    val sourceReference: Int? = null,
)

@Serializable
data class SourceBreakpoint(
    val line: Int,
    val column: Int? = null,
    val condition: String? = null,
    val hitCondition: String? = null,
    val logMessage: String? = null,
)

@Serializable
data class SetBreakpointsArguments(
    val source: Source,
    val breakpoints: List<SourceBreakpoint>,
    val sourceModified: Boolean = false,
)

@Serializable
data class ThreadIdArgs(val threadId: Int)

@Serializable
data class StackTraceArguments(
    val threadId: Int,
    val startFrame: Int = 0,
    val levels: Int = 100,
)

@Serializable
data class ScopesArguments(val frameId: Int)

@Serializable
data class VariablesArguments(
    val variablesReference: Int,
    val start: Int? = null,
    val count: Int? = null,
)

@Serializable
data class EvaluateArguments(
    val expression: String,
    val frameId: Int? = null,
    val context: String = "watch",
)

@Serializable
data class ContinueArguments(val threadId: Int)

@Serializable
data class StepArgs(val threadId: Int, val granularity: String = "statement")

// ---------- response body types ----------

@Serializable
data class Capabilities(
    val supportsConfigurationDoneRequest: Boolean? = null,
    val supportsConditionalBreakpoints: Boolean? = null,
    val supportsEvaluateForHovers: Boolean? = null,
    val supportsStepBack: Boolean? = null,
    val supportsRestartFrame: Boolean? = null,
    val supportsSetVariable: Boolean? = null,
)

@Serializable
data class BreakpointResp(
    val id: Int? = null,
    val verified: Boolean,
    val line: Int? = null,
    val message: String? = null,
)

@Serializable
data class SetBreakpointsResponse(val breakpoints: List<BreakpointResp>)

@Serializable
data class DapThread(val id: Int, val name: String)

@Serializable
data class ThreadsResponse(val threads: List<DapThread>)

@Serializable
data class StackFrame(
    val id: Int,
    val name: String,
    val source: Source? = null,
    val line: Int,
    val column: Int = 0,
    val endLine: Int? = null,
    val endColumn: Int? = null,
)

@Serializable
data class StackTraceResponse(
    val stackFrames: List<StackFrame>,
    val totalFrames: Int? = null,
)

@Serializable
data class Scope(
    val name: String,
    val variablesReference: Int,
    val expensive: Boolean = false,
    val namedVariables: Int? = null,
    val indexedVariables: Int? = null,
)

@Serializable
data class ScopesResponse(val scopes: List<Scope>)

@Serializable
data class Variable(
    val name: String,
    val value: String,
    val type: String? = null,
    val variablesReference: Int = 0,
    val namedVariables: Int? = null,
    val indexedVariables: Int? = null,
    val evaluateName: String? = null,
)

@Serializable
data class VariablesResponse(val variables: List<Variable>)

@Serializable
data class EvaluateResponse(
    val result: String,
    val type: String? = null,
    val variablesReference: Int = 0,
)

// ---------- event body types ----------

@Serializable
data class StoppedEvent(
    val reason: String,
    val description: String? = null,
    val threadId: Int? = null,
    val preserveFocusHint: Boolean = false,
    val text: String? = null,
    val allThreadsStopped: Boolean = true,
    val hitBreakpointIds: List<Int>? = null,
)

@Serializable
data class OutputEvent(
    val category: String? = null,
    val output: String,
    val source: Source? = null,
    val line: Int? = null,
)

@Serializable
data class TerminatedEvent(val restart: Boolean = false)
