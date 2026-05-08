<?php

declare(strict_types=1);

namespace Periscope\Laravel\Hooks;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Periscope\Laravel\Bridge\ExtensionBridge;
use Periscope\Laravel\Support\CallSiteResolver;

/**
 * Headline differentiator: per-class hydration counts.
 *
 * Telescope shows individual `eloquent.retrieved` events. We do that, AND we
 * accumulate counts per model class across the whole request and emit a
 * single `model_summary` event at request end:
 *
 *   { "Listing": 5432, "Agency": 1200, "User": 45 }
 *
 * That is the at-a-glance over-fetching detector that drove DebugBar's footer
 * and is missing from Telescope's pure event stream.
 */
final class ModelHook implements Hook
{
    /** @var array<string, int> */
    private array $hydrationCounts = [];

    /** @var array<string, array<string, int>> action => class => count */
    private array $actionCounts = [];

    public function __construct(
        private readonly ExtensionBridge $bridge,
        private readonly CallSiteResolver $callSites,
        private readonly Dispatcher $events,
        private readonly Application $app,
    ) {}

    public function register(): void
    {
        if (!$this->bridge->isAvailable()) {
            return;
        }

        $this->events->listen('eloquent.*', function (string $event, array $payload): void {
            $action = $this->parseAction($event);
            if ($action === null) {
                return;
            }

            $model = $payload[0] ?? null;
            if (!$model instanceof Model) {
                return;
            }

            $class = $model::class;
            $this->actionCounts[$action][$class] = ($this->actionCounts[$action][$class] ?? 0) + 1;

            if ($action === 'retrieved') {
                $this->hydrationCounts[$class] = ($this->hydrationCounts[$class] ?? 0) + 1;
                // High-volume path — only emit per-event for non-retrieved actions.
                return;
            }

            $this->bridge->recordEvent('model', [
                'action'  => $action,
                'class'   => $class,
                'key'     => $model->getKey(),
                'changes' => array_keys($model->getChanges()),
            ], $this->callSites->resolve());
        });

        $this->app->terminating(function (): void {
            if ($this->hydrationCounts === [] && $this->actionCounts === []) {
                return;
            }

            arsort($this->hydrationCounts);

            $this->bridge->recordEvent('model_summary', [
                'hydrated' => $this->hydrationCounts,
                'actions'  => $this->actionCounts,
                'total'    => array_sum($this->hydrationCounts),
            ], null);
        });
    }

    private function parseAction(string $eventName): ?string
    {
        // Format: eloquent.{action}: {ModelClass}
        if (!str_starts_with($eventName, 'eloquent.')) {
            return null;
        }

        $rest = substr($eventName, strlen('eloquent.'));
        $colonAt = strpos($rest, ':');
        $action = $colonAt === false ? $rest : substr($rest, 0, $colonAt);

        return match ($action) {
            'created', 'updated', 'deleted', 'retrieved', 'saved',
            'creating', 'updating', 'deleting', 'saving', 'restored', 'forceDeleted' => $action,
            default => null,
        };
    }
}
