<?php

declare(strict_types=1);

/**
 * php-periscope Laravel adapter configuration.
 *
 * All values resolve from .env so day-to-day control is one file. After
 * `composer require --dev periscopephp/laravel` you never touch this
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
    'snippet_lines' => (int) env('PERISCOPE_SNIPPET_LINES', 4),

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
        '/vendor/periscopephp/laravel/',
        '/laravel-adapter/src/',
    ],

];
