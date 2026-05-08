<?php

declare(strict_types=1);

/**
 * Phase 5d integration scenario.
 *
 * Boots a real Laravel application via Orchestra Testbench's headless
 * Application factory, registers the PeriscopeServiceProvider, then triggers
 * one event of each type (sql / log / cache / event / exception). Designed
 * to be invoked by `scripts/smoke-laravel-adapter.sh` with the periscope C
 * extension loaded — the resulting `.cptrace` is then verified by the shell
 * script to contain every expected event type with a call site attached.
 */

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Orchestra\Testbench\Foundation\Application as Testbench;
use Periscope\Laravel\PeriscopeServiceProvider;

require __DIR__ . '/../../../laravel-adapter/vendor/autoload.php';

if (!function_exists('periscope_record_event')) {
    fwrite(STDERR, "FAIL: periscope C extension not loaded — aborting.\n");
    exit(2);
}

$app = Testbench::create(
    basePath: __DIR__ . '/sandbox',
    options: [
        'load_environment_variables' => false,
        'extra' => [
            'providers' => [PeriscopeServiceProvider::class],
            'config'    => [
                'database.default'              => 'periscope_smoke',
                'database.connections.periscope_smoke' => [
                    'driver'   => 'sqlite',
                    'database' => ':memory:',
                    'prefix'   => '',
                ],
                // Lean on the real bridge — no fake.
                'periscope.enabled'             => true,
            ],
        ],
    ],
);

// Trigger a real DB query so QueryHook fires through DB::listen.
DB::statement('CREATE TABLE smoke (id INTEGER PRIMARY KEY, name TEXT)');
DB::statement('INSERT INTO smoke (id, name) VALUES (1, ?)', ['phase-5d']);
$row = DB::selectOne('SELECT name FROM smoke WHERE id = ?', [1]);
fwrite(STDOUT, "scenario: db row = " . ($row->name ?? 'null') . "\n");

// Trigger the same SQL several times so NPlusOneDetector fires.
for ($i = 0; $i < 5; $i++) {
    DB::selectOne('SELECT name FROM smoke WHERE id = ?', [$i]);
}

// Trigger cache events — CacheHook listens to these via the dispatcher.
Event::dispatch(new CacheMissed('redis', 'smoke:bar', []));
Event::dispatch(new KeyWritten('redis', 'smoke:bar', 'value', 60, []));
Event::dispatch(new CacheHit('redis', 'smoke:bar', 'value', []));

// Trigger a log line — LogHook listens to MessageLogged.
Log::warning('phase-5d smoke: disk almost full', ['percent' => 92]);

// Trigger an arbitrary user event — EventHook should pick it up.
Event::dispatch('App\\Events\\PhaseFiveDSmokeFired', ['hello']);

// Trigger an exception via MessageLogged with an exception in context —
// ExceptionHook should record it.
$boom = new \RuntimeException('phase-5d intentional boom');
Event::dispatch(new MessageLogged('error', 'op failed', ['exception' => $boom]));

fwrite(STDOUT, "scenario: complete\n");

// Allow Laravel to terminate cleanly so ModelHook's terminating callback fires.
$app->terminate();
