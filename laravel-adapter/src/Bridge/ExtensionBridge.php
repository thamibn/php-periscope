<?php

declare(strict_types=1);

namespace Periscope\Laravel\Bridge;

use Periscope\Laravel\Bus\TerminatingTracker;

/**
 * Wraps the C extension's userland API.
 *
 * If the periscope extension isn't loaded (e.g. dev installed the package
 * but hasn't installed the .so yet), every method becomes a silent no-op.
 * This means the adapter is harmless to leave installed in environments
 * without the extension — the `composer require` is reversible.
 */
/**
 * Not `final` so test code can extend it via anonymous class for assertion
 * fakes (see tests/Feature/QueryHookTest.php). The single instance property
 * is `readonly` via constructor promotion — extension does not change that.
 */
class ExtensionBridge
{
    private ?TerminatingTracker $terminatingTracker = null;

    /** @var array<string,int> */
    private array $counters = [];

    /**
     * URIs starting with any of these prefixes are short-circuited — no
     * events recorded. Engine-level `periscope.path_ignore` should also
     * catch these, but FPM/CLI worker-pool quirks can let one slip past
     * (e.g. multi-version php-fpm on the same socket). Keeping a PHP
     * mirror here means *no observability hooks fire for our own UI*,
     * regardless of what the engine does.
     *
     * @var list<string>
     */
    private array $pathIgnore = [];

    public function __construct(
        private readonly bool $enabled = true,
    ) {}

    public function setTerminatingTracker(?TerminatingTracker $tracker): void
    {
        $this->terminatingTracker = $tracker;
    }

    /**
     * @param list<string> $prefixes
     */
    public function setPathIgnore(array $prefixes): void
    {
        $this->pathIgnore = array_values(array_filter(array_map(
            static fn ($p) => is_string($p) ? trim($p) : '',
            $prefixes,
        )));
    }

    public function isAvailable(): bool
    {
        return $this->enabled
            && function_exists('periscope_record_event');
    }

    /**
     * Match the current request URI against the configured prefixes. CLI
     * (no $_SERVER['REQUEST_URI']) is never ignored — CLI paths come from
     * Artisan commands and should always be traced.
     */
    private function isIgnoredPath(): bool
    {
        if ($this->pathIgnore === []) return false;
        $uri = $_SERVER['REQUEST_URI'] ?? null;
        if (!is_string($uri) || $uri === '') return false;
        // Strip query string for matching.
        $q = strpos($uri, '?');
        $path = $q === false ? $uri : substr($uri, 0, $q);
        foreach ($this->pathIgnore as $prefix) {
            if ($prefix === '') continue;
            if ($path === $prefix) return true;
            if (str_starts_with($path, rtrim($prefix, '/') . '/')) return true;
        }
        return false;
    }

    /**
     * @param array<string, mixed>      $payload
     * @param array<string, mixed>|null $callSite
     */
    public function recordEvent(string $type, array $payload, ?array $callSite = null): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        // Path-ignore short-circuit: keep the adapter's UI routes registered
        // (so the user can still load /periscope) but suppress event recording
        // for those paths so we don't observe ourselves.
        if ($this->isIgnoredPath()) {
            return false;
        }

        [$payload, $callSite] = $this->prepareEvent($payload, $callSite);

        $this->counters[$type] = ($this->counters[$type] ?? 0) + 1;

        return periscope_record_event($type, $payload, $callSite);
    }

    /**
     * Per-type event counts collected during this request. Used by the
     * floating toolbar middleware to render "queries × N" without re-asking
     * the daemon. Not authoritative — the trace on disk is — just a fast
     * in-process tally.
     *
     * @return array<string,int>
     */
    public function counters(): array
    {
        return $this->counters;
    }

    /**
     * Apply cross-cutting attribution to the payload + call site before the
     * event is shipped. Currently the only transformation is the
     * "after response" flag for events fired from a terminating callback.
     * Exposed as protected so test fakes that override `recordEvent()` can
     * still benefit from the same attribution.
     *
     * @param array<string, mixed>      $payload
     * @param array<string, mixed>|null $callSite
     * @return array{0: array<string, mixed>, 1: array<string, mixed>|null}
     */
    protected function prepareEvent(array $payload, ?array $callSite): array
    {
        if ($this->terminatingTracker !== null && $this->terminatingTracker->inTerminating()) {
            $payload['after_response'] = true;
            $registeredSite = $this->terminatingTracker->peekSite();
            if ($registeredSite !== null) {
                $callSite = $this->mergeCallSite($callSite, $registeredSite);
            }
        }

        return [$payload, $callSite];
    }

    /**
     * Prefer the registered site. We keep the live site's `stack` if the
     * registered one didn't capture frames (e.g. an empty-stack edge case),
     * but file/line/snippet always come from the registration moment.
     *
     * @param array<string, mixed>|null $live
     * @param array<string, mixed>      $registered
     * @return array<string, mixed>
     */
    private function mergeCallSite(?array $live, array $registered): array
    {
        if (empty($registered['stack']) && $live !== null && !empty($live['stack'])) {
            $registered['stack'] = $live['stack'];
        }
        return $registered;
    }

    /**
     * Drop a labeled marker on the timeline.
     */
    public function checkpoint(string $label, mixed $context = null): bool
    {
        if (!$this->isAvailable() || !function_exists('periscope_checkpoint')) {
            return false;
        }

        return periscope_checkpoint($label, $context);
    }
}
