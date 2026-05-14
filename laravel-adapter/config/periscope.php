<?php

declare(strict_types=1);

/**
 * php-periscope Laravel adapter configuration.
 *
 * All values resolve from .env so day-to-day control is one file. After
 * `composer require --dev thamibn/laravel-periscope` you never touch this
 * file unless you want to override defaults.
 */

return [

    /*
    |---------------------------------------------------------------------
    | Master switch
    |---------------------------------------------------------------------
    | When false the service provider boots but registers no hooks. The
    | C extension still records function frames if it's loaded.
    */
    'enabled' => env('PERISCOPE_ENABLED', true),

    /*
    |---------------------------------------------------------------------
    | Per-hook toggles
    |---------------------------------------------------------------------
    | Disable individual watchers if they're noisy for your workload. All
    | default on; the C extension's filter knobs handle global cost.
    */
    'hooks' => [
        'queries'      => env('PERISCOPE_HOOK_QUERIES', true),
        'logs'         => env('PERISCOPE_HOOK_LOGS', true),
        'cache'        => env('PERISCOPE_HOOK_CACHE', true),
        'events'       => env('PERISCOPE_HOOK_EVENTS', true),
        'jobs'         => env('PERISCOPE_HOOK_JOBS', true),
        'mail'         => env('PERISCOPE_HOOK_MAIL', true),
        'redis'        => env('PERISCOPE_HOOK_REDIS', true),
        'http'         => env('PERISCOPE_HOOK_HTTP', true),
        'exceptions'   => env('PERISCOPE_HOOK_EXCEPTIONS', true),
        'models'       => env('PERISCOPE_HOOK_MODELS', true),
        'notifications'=> env('PERISCOPE_HOOK_NOTIFICATIONS', true),
        'gates'        => env('PERISCOPE_HOOK_GATES', true),
        'requests'     => env('PERISCOPE_HOOK_REQUESTS', true),
        'views'        => env('PERISCOPE_HOOK_VIEWS', true),
        'commands'     => env('PERISCOPE_HOOK_COMMANDS', true),
        'schedule'     => env('PERISCOPE_HOOK_SCHEDULE', true),
        'batch'        => env('PERISCOPE_HOOK_BATCH', true),
        'dump'         => env('PERISCOPE_HOOK_DUMP', false),
    ],

    /*
    |---------------------------------------------------------------------
    | Slow-query threshold
    |---------------------------------------------------------------------
    | Queries above this threshold get tagged `slow=true` in the trace.
    | The N+1 detector runs separately and is independent of this knob.
    */
    'slow_query_ms' => (int) env('PERISCOPE_SLOW_QUERY_MS', 100),

    /*
    |---------------------------------------------------------------------
    | Snippet capture
    |---------------------------------------------------------------------
    | When emitting a CallSite, include `lines * 2 + 1` lines of source
    | around the resolved file:line (four above + four below by default).
    | Default 4 covers a typical multi-line Eloquent / Builder chain
    | (`Model::query() -> ->where(...) -> ->where(...) -> ->first();`)
    | plus the surrounding `if` / `foreach` so the offending line is
    | recognisable without opening the editor. Set to 0 to disable
    | snippets entirely (still records file:line).
    */
    'snippet_lines' => (int) env('PERISCOPE_SNIPPET_LINES', 6),

    /*
    |---------------------------------------------------------------------
    | Vendor path skip list for call-site resolution
    |---------------------------------------------------------------------
    | When walking the backtrace to find the topmost user-code frame,
    | skip frames whose file path contains any of these substrings.
    */
    /*
    |---------------------------------------------------------------------
    | AI advisor (opt-in)
    |---------------------------------------------------------------------
    | Off by default. When enabled, the AiAdvisor calls Laravel 13's
    | first-party AI SDK (`laravel/ai`) for every slow query, N+1 pattern,
    | reportable exception, and error-level log line — emitting an
    | `ai_suggestion` event with concrete fixes.
    |
    | All provider / model / key settings live in `config/ai.php` (the
    | laravel/ai SDK's own config). We don't shadow them here. Pick any
    | provider via `php artisan ai:install` — OpenAI, Anthropic, Gemini,
    | Ollama (free-local), OpenRouter (free-tier), DeepSeek, Groq, etc.
    |
    | Periscope only owns:
    |   - the per-request rate cap (so a burst can't blow your budget)
    |   - the on/off switch
    |
    | To enable:
    |   1. composer require laravel/ai
    |   2. php artisan ai:install   (configures provider + key)
    |   3. PERISCOPE_AI_ENABLED=true in .env
    */
    'ai' => [
        'enabled'                     => (bool) env('PERISCOPE_AI_ENABLED', false),
        'max_suggestions_per_request' => (int)  env('PERISCOPE_AI_MAX_SUGGESTIONS', 3),
    ],

    /*
    |---------------------------------------------------------------------
    | N+1 detector threshold
    |---------------------------------------------------------------------
    | Same SQL pattern executing this many times in one request flips on
    | an `n_plus_one_warning`. Default 4 — high enough to skip casual
    | duplicates, low enough to catch list-rendering N+1s.
    */
    'n_plus_one_threshold' => (int) env('PERISCOPE_N_PLUS_ONE_THRESHOLD', 4),

    /*
    |---------------------------------------------------------------------
    | Browser UI mount (opt-in)
    |---------------------------------------------------------------------
    | Off by default so the package never adds routes to your app without
    | being asked. When enabled, the SolidJS UI is served from inside the
    | Laravel app at `path` (default `periscope`) — i.e. `app.test/periscope`
    | — alongside hashed assets at `app.test/{path}/assets/...`.
    |
    | The UI itself talks back to the Rust daemon's HTTP/WebSocket API at
    | `daemon_base` for trace data; no PHP proxy. Set `daemon_base` to
    | whatever your daemon is reachable at (default localhost:9999).
    |
    | This is *one of several* ways to reach the UI; you can also:
    |   - hit the daemon directly at http://localhost:9999
    |   - open an exported `.html` from `periscope-export`
    | Pick whichever fits the workflow.
    */
    'ui' => [
        'enabled'     => (bool) env('PERISCOPE_UI_ENABLED', false),
        'path'        => trim((string) env('PERISCOPE_UI_PATH', 'periscope'), '/'),
        'middleware'  => array_filter(array_map('trim', explode(',', (string) env('PERISCOPE_UI_MIDDLEWARE', 'web')))),
        'daemon_base' => rtrim((string) env('PERISCOPE_UI_DAEMON_BASE', 'http://127.0.0.1:9999'), '/'),
        // Absolute path to the built `ui/dist/` directory. When unset the
        // adapter probes a few common locations relative to the package.
        'bundle_dir'  => env('PERISCOPE_UI_BUNDLE_DIR'),

        /*
        | Production lock-down
        |---------------------------------------------------------------------
        | When `APP_DEBUG=false`, the UI is locked behind a 403 by default.
        | Trace contents leak cookies, session tokens, captured variables —
        | shipping it open to the internet is a credentials breach waiting
        | to happen. Telescope locks itself the same way.
        |
        | To open the UI in production:
        |   1. Set PERISCOPE_UI_ALLOW_IN_PROD=true
        |   2. Set PERISCOPE_UI_TOKEN to a long random string (>= 32 chars)
        |   3. Visit  https://your-app.example/periscope?token=<the-token>
        |      (the token is stashed in a session cookie, valid until
        |       you clear cookies)
        |
        | For finer-grained control register a closure via:
        |   Periscope\Laravel\Http\UiGate::authorize(fn ($request) => …);
        | (e.g. allow only specific user emails or IPs).
        */
        'allow_in_production' => (bool) env('PERISCOPE_UI_ALLOW_IN_PROD', false),
        'token'               => env('PERISCOPE_UI_TOKEN'),
    ],

    /*
    |---------------------------------------------------------------------
    | Floating in-page toolbar (opt-in)
    |---------------------------------------------------------------------
    | Inject a small chip into HTML responses showing this request's
    | duration, query count, peak memory and status. Clicking it opens
    | the periscope UI in a new tab. Off by default — never auto-injects
    | until you set PERISCOPE_TOOLBAR_ENABLED=true.
    |
    | The chip is HTML-only: nothing is injected into JSON, AJAX,
    | streaming, or non-2xx responses with no `</body>`.
    |
    | `open_url` overrides the link target. When unset the toolbar opens
    | the same `path` as `ui.path` (so `/periscope` by default), which is
    | only useful when `ui.enabled=true`. If neither apply, set this to
    | `http://127.0.0.1:9999` to point at the daemon's UI directly.
    */
    'toolbar' => [
        'enabled'  => (bool) env('PERISCOPE_TOOLBAR_ENABLED', false),
        'open_url' => env('PERISCOPE_TOOLBAR_OPEN_URL'),
    ],

    /*
    |---------------------------------------------------------------------
    | MCP server (AI-native access)
    |---------------------------------------------------------------------
    | Auto-registers a Laravel MCP server under the handle `periscope`
    | when `laravel/mcp` is installed. The server exposes 8 tools that
    | proxy to the local daemon's `/api/*` so AI agents (Claude / Cursor
    | / Codex / etc) can list traces, fetch summaries + insights, query
    | events with our JSON-path filter language, time-travel to a moment
    | with get_state, and read source slices.
    |
    | Wire into Claude Code:
    |   claude mcp add periscope -- php artisan mcp:start periscope
    |
    | The MCP server is local-only (stdio); it never reaches the public
    | internet. Disable with PERISCOPE_MCP_ENABLED=false.
    */
    'mcp' => [
        'enabled' => (bool) env('PERISCOPE_MCP_ENABLED', true),
        'handle'  => env('PERISCOPE_MCP_HANDLE', 'periscope'),
    ],

    'vendor_skip' => [
        '/vendor/laravel/',
        '/vendor/illuminate/',
        '/vendor/symfony/',
        '/vendor/composer/',
        '/vendor/nesbot/',
        '/vendor/psr/',
        '/vendor/spatie/',
        '/vendor/livewire/',
        // Adapter's own frames must be skipped — otherwise every call site
        // resolves to QueryHook.php / CacheHook.php instead of the user code
        // that triggered it. Covers both composer-installed and path-repo layouts.
        '/vendor/thamibn/laravel-periscope/',
        '/laravel-adapter/src/',
    ],

    /*
    |---------------------------------------------------------------------
    | Path ignore — what to exclude from capture
    |---------------------------------------------------------------------
    | Request URIs that start with any of these prefixes are dropped
    | from the trace at request boot (no events recorded, trace file not
    | finalised). The defaults skip the obvious noise: Periscope's own UI,
    | Telescope's UI + its self-polling SPA, Boost's browser-log POSTs,
    | Horizon, Debugbar, Ignition, plus a few static-asset routes that
    | sometimes reach Laravel (manifest.json / favicon / robots).
    |
    | Edit via .env:
    |   PERISCOPE_PATH_IGNORE=/periscope,/telescope,/my-other-route
    |
    | Without these prefixes, Telescope's own self-polling alone fills
    | the entire retention buffer in under a minute on a busy app.
    |
    | Note: an identical list also lives in 99-periscope.ini under
    | `periscope.path_ignore` for engine-level suppression. The engine
    | filter runs at RINIT (before Laravel boots), so it's the cheaper
    | gate; this Laravel-level list is the source of truth for the
    | Settings UI and lets you override per-app via .env without editing
    | system php.ini.
    */
    'path_ignore' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'PERISCOPE_PATH_IGNORE',
        '/periscope,/telescope,/_boost,/_tt,/_debugbar,/horizon,/_ignition,/manifest.json,/favicon.ico,/robots.txt',
    ))))),

];
