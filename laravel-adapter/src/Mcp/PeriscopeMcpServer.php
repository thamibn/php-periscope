<?php

declare(strict_types=1);

namespace Periscope\Laravel\Mcp;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Periscope\Laravel\Mcp\Tools\GetInsightsTool;
use Periscope\Laravel\Mcp\Tools\GetStateTool;
use Periscope\Laravel\Mcp\Tools\GetSummaryTool;
use Periscope\Laravel\Mcp\Tools\GetTimelineTool;
use Periscope\Laravel\Mcp\Tools\GetTraceTool;
use Periscope\Laravel\Mcp\Tools\ListTracesTool;
use Periscope\Laravel\Mcp\Tools\QueryEventsTool;
use Periscope\Laravel\Mcp\Tools\ReadFileTool;

/**
 * MCP server that exposes a Laravel app's periscope traces to AI agents
 * (Claude, Cursor, Codex, etc). Each tool proxies to the local
 * `periscope-daemon`'s HTTP API — there's no second source of truth.
 *
 * Register in your host app at the bottom of `routes/ai.php`:
 *
 *     use Laravel\Mcp\Facades\Mcp;
 *     use Periscope\Laravel\Mcp\PeriscopeMcpServer;
 *
 *     Mcp::local('periscope', PeriscopeMcpServer::class);
 *
 * Then wire it into your AI client:
 *
 *     claude mcp add periscope -- php artisan mcp:start periscope
 *
 * The adapter auto-registers this server in its service provider when
 * `periscope.mcp.enabled=true` (default true when `laravel/mcp` is
 * installed), so most apps never touch `routes/ai.php`.
 */
#[Name('periscope')]
#[Version('0.1.0')]
#[Instructions(<<<'MD'
    Time-travel debugger + observability tool for Laravel apps. Use these
    tools to inspect what a specific HTTP request actually did: which SQL
    queries ran (with bindings, durations, slow flags, N+1 patterns), which
    cache/Redis ops, dispatched jobs, fired events, sent mail, outbound
    HTTP, logged messages, captured exceptions, and the function-level
    call stack with arguments and return values at every frame.

    Workflow:
      1. Call `list_traces` to see recent requests. Each row has an id,
         URI, method, status, duration, and counts (frames, events).
      2. Call `get_summary` for a one-shot overview — totals + top hot
         spots without the full trace dump.
      3. Call `get_insights` for periscope's *deterministic* problem
         analysis: N+1 patterns, slow queries (with anti-pattern reasons),
         exceptions, error logs, AI suggestions emitted during the request.
      4. Drill into events with `query_events`:
           - `type` filters to one panel: sql, log, cache, http, redis,
             job, event, mail, exception, model, notification, dump, etc.
           - `filter` is a small JSON-path query language:
             `payload.level:error AND payload.context.user_id:42`
             (unquoted = case-insensitive substring + numeric equality;
             quoted = exact string)
           - `group=true` collapses identical events (same fingerprint
             modulo timestamps) so a 12× repeated log line shows once.
      5. To see *what the code was doing* at a particular moment:
         `get_state(trace_id, at_micros)` returns the deepest frame, full
         stack, scope variables, and prefix events. Use the at_micros
         from a problematic event to time-travel into its execution.
      6. `read_file` reads the project's PHP source around a line so you
         can reason about the offending code, not just its name.

    Prefer `get_summary` + `get_insights` first; only fetch full traces
    or events when you need detail. Traces can be megabytes.
    MD)]
final class PeriscopeMcpServer extends Server
{
    protected array $tools = [
        ListTracesTool::class,
        GetTraceTool::class,
        GetSummaryTool::class,
        GetInsightsTool::class,
        GetTimelineTool::class,
        GetStateTool::class,
        QueryEventsTool::class,
        ReadFileTool::class,
    ];
}
