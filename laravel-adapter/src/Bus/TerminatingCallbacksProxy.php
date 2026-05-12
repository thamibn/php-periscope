<?php

declare(strict_types=1);

namespace Periscope\Laravel\Bus;

use ArrayObject;
use Periscope\Laravel\Support\CallSiteResolver;

/**
 * Stand-in for Application's `$terminatingCallbacks` array. Laravel writes
 * to this property via `$this->terminatingCallbacks[] = $callback` — which
 * routes through ArrayObject::offsetSet — and reads via `foreach`.
 *
 * On every append we capture the user's call site (where `app()->terminating()`
 * was called from) and wrap the callback so that, when the callback eventually
 * runs after the response is sent, the tracker exposes that captured site.
 * Hooks downstream attribute observability events to the registration site
 * instead of `public/index.php:75`.
 */
final class TerminatingCallbacksProxy extends ArrayObject
{
    public function __construct(
        array $existing,
        private readonly CallSiteResolver $resolver,
        private readonly TerminatingTracker $tracker,
    ) {
        parent::__construct($existing);
    }

    /**
     * @param mixed $key
     * @param mixed $value
     */
    public function offsetSet($key, $value): void
    {
        if (is_callable($value)) {
            $site = $this->resolver->resolve();
            $tracker = $this->tracker;
            $original = $value;
            $value = static function (...$args) use ($original, $site, $tracker) {
                $tracker->pushSite($site);
                try {
                    return $original(...$args);
                } finally {
                    $tracker->popSite();
                }
            };
        }
        parent::offsetSet($key, $value);
    }

    /** @param mixed $value */
    public function append($value): void
    {
        $this->offsetSet(null, $value);
    }
}
