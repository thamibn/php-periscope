<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Periscope\Laravel\Hooks\ModelHook;
use Periscope\Laravel\Support\CallSiteResolver;

class FakePeriscopeListing extends Model
{
    protected $table = 'listings';
    public $exists = true;
    protected $primaryKey = 'id';
    protected $keyType = 'int';
}

it('aggregates per-class hydration counts and emits a summary on terminate', function (): void {
    $bridge = periscopeRecordingBridge();

    $hook = new ModelHook(
        bridge:    $bridge,
        callSites: new CallSiteResolver(snippetLines: 0),
        events:    app(Dispatcher::class),
        app:       app(),
    );
    $hook->register();

    $model = (new FakePeriscopeListing())->forceFill(['id' => 1]);

    for ($i = 0; $i < 3; $i++) {
        Event::dispatch('eloquent.retrieved: ' . FakePeriscopeListing::class, [$model]);
    }

    expect($bridge->events)->toBeEmpty(); // retrieved is silent until summary

    app()->terminate();

    $summary = collect($bridge->events)->firstWhere('type', 'model_summary');
    expect($summary)->not->toBeNull()
        ->and($summary['payload']['hydrated'][FakePeriscopeListing::class])->toBe(3)
        ->and($summary['payload']['total'])->toBe(3);
});
