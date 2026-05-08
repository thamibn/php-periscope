<?php

declare(strict_types=1);

/**
 * php-periscope Laravel adapter configuration.
 *
 * All values resolve from .env so day-to-day control is one file. After
 * `composer require --dev thamibn/periscope-laravel` you never touch this
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
    | around the resolved file:line (one above + one below by default).
    | Set to 0 to disable snippet capture entirely (still records file:line).
    */
    'snippet_lines' => (int) env('PERISCOPE_SNIPPET_LINES', 2),

    /*
    |---------------------------------------------------------------------
    | Vendor path skip list for call-site resolution
    |---------------------------------------------------------------------
    | When walking the backtrace to find the topmost user-code frame,
    | skip frames whose file path contains any of these substrings.
    */
    'vendor_skip' => [
        '/vendor/laravel/',
        '/vendor/illuminate/',
        '/vendor/symfony/',
        '/vendor/composer/',
        '/vendor/nesbot/',
        '/vendor/psr/',
        '/vendor/spatie/',
        '/vendor/livewire/',
    ],

];
