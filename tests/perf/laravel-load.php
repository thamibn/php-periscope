<?php

declare(strict_types=1);

/**
 * Phase 5 perf fixture for `scripts/bench-vs-xdebug.sh`.
 *
 * Boots a minimal Laravel kernel via Orchestra Testbench, registers the
 * PeriscopeServiceProvider against the real ExtensionBridge, then fires a
 * realistic mix of observable events:
 *   - 100 SQL queries (sqlite :memory:)
 *   - 100 cache events
 *   - 100 log lines
 *   - 100 user events
 *
 * Prints the inner-loop elapsed time on stderr in the same shape as the
 * fib(25) fixture so bench-vs-xdebug.sh can grep it. Boot time isn't
 * measured — only the per-event marginal cost matters here.
 */

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Orchestra\Testbench\Foundation\Application as Testbench;
use Periscope\Laravel\PeriscopeServiceProvider;

require __DIR__ . '/../../laravel-adapter/vendor/autoload.php';

$basePath = __DIR__ . '/../integration/laravel/sandbox';

$app = Testbench::create(
    basePath: $basePath,
    options: [
        'load_environment_variables' => false,
        'extra' => [
            'providers' => [PeriscopeServiceProvider::class],
            'config'    => [
                'database.default'              => 'periscope_bench',
                'database.connections.periscope_bench' => [
                    'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
                ],
                'periscope.enabled'             => true,
            ],
        ],
    ],
);

DB::statement('CREATE TABLE bench (id INTEGER PRIMARY KEY)');
$conn = DB::connection();
DB::insert('INSERT INTO bench (id) VALUES (1)');

// Warmup — pay JIT/listen-resolution costs once before timing.
for ($i = 0; $i < 5; $i++) {
    DB::selectOne('SELECT id FROM bench WHERE id = ?', [1]);
    Event::dispatch(new CacheHit('redis', 'k', 1, []));
}

$N = 100;
$start = microtime(true);

for ($i = 0; $i < $N; $i++) {
    // SQL
    DB::selectOne('SELECT id FROM bench WHERE id = ?', [1]);

    // Cache trio (synthetic events; CacheHook listens on the dispatcher)
    Event::dispatch(new CacheMissed('redis', "k:$i", []));
    Event::dispatch(new KeyWritten('redis', "k:$i", 'v', 60, []));
    Event::dispatch(new CacheHit('redis', "k:$i", 'v', []));

    // Log line — LogHook listens to MessageLogged
    Event::dispatch(new MessageLogged('info', "bench iteration $i", ['i' => $i]));

    // User event
    Event::dispatch('App\\Bench\\Tick', ['i' => $i]);
}

$elapsed = (microtime(true) - $start) * 1000.0;
$total   = $N * 6; // queries + 3 cache + log + event = 6 events per iter

fprintf(STDOUT, "laravel-load(%d events) = ok  in %.3fms\n", $total, $elapsed);

$app->terminate();
