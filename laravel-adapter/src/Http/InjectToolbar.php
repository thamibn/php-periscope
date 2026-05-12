<?php

declare(strict_types=1);

namespace Periscope\Laravel\Http;

use Closure;
use Illuminate\Http\Request;
use Periscope\Laravel\Bridge\ExtensionBridge;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Phase 9b: in-page floating toolbar.
 *
 * Lowest-friction entry point to the periscope UI: a small chip in the page
 * corner showing this request's duration, query count, peak memory and
 * status. Clicking it opens the UI for the trace that just finished.
 *
 * Inspired by Clockwork's "Toolbar" feature, with two differences:
 *   1. It links to *our* UI (or the daemon's HTTP UI), not a Chrome extension.
 *   2. It's opt-in via config — never injected without `PERISCOPE_TOOLBAR_ENABLED=true`.
 *
 * Injection rules — we only touch the response when *all* of:
 *   - `periscope.toolbar.enabled` is true
 *   - the response is HTML (rules out JSON / files / streams)
 *   - the response body contains `</body>` (rules out partials, fragments)
 *   - the request isn't AJAX/JSON/XHR-y (rules out Livewire reloads)
 *   - the request URI isn't a periscope route itself (no recursive chip)
 *
 * Anything that fails a rule passes through untouched. Failure mode is
 * "no toolbar shown", never "page broken".
 */
final class InjectToolbar
{
    /**
     * @param array{enabled?:bool, open_url?:?string, mount_path?:?string} $config
     */
    public function __construct(
        private readonly ExtensionBridge $bridge,
        private readonly array $config = [],
    ) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $response = $next($request);

        if (!($this->config['enabled'] ?? false)) {
            return $response;
        }
        if (!$response instanceof SymfonyResponse) {
            return $response;
        }
        if ($request->expectsJson() || $request->ajax() || $request->wantsJson()) {
            return $response;
        }
        // Don't inject into our own routes (UI, settings, asset).
        $path = '/' . ltrim($request->path(), '/');
        $mount = '/' . trim((string) ($this->config['mount_path'] ?? ''), '/');
        if ($mount !== '/' && str_starts_with($path, $mount)) {
            return $response;
        }

        $contentType = (string) $response->headers->get('content-type', '');
        if (!str_contains($contentType, 'text/html')) {
            return $response;
        }

        $body = (string) $response->getContent();
        if ($body === '' || !str_contains($body, '</body>')) {
            return $response;
        }

        $html = $this->renderToolbar($response->getStatusCode());
        if ($html === '') {
            return $response;
        }

        $patched = preg_replace('#</body>#i', $html . '</body>', $body, 1);
        if (!is_string($patched)) {
            return $response;
        }

        $response->setContent($patched);
        // Content-length, if previously set, would now be wrong. Drop it so
        // the framework recomputes (or chunks).
        $response->headers->remove('content-length');

        return $response;
    }

    private function renderToolbar(int $statusCode): string
    {
        $start = defined('LARAVEL_START') ? (float) LARAVEL_START : null;
        $durationUs = $start !== null
            ? (int) round((microtime(true) - $start) * 1_000_000)
            : 0;
        $startedAtMicros = $start !== null ? (int) round($start * 1_000_000) : 0;

        $counters = $this->bridge->counters();
        $queries = (int) ($counters['sql'] ?? 0);
        $exceptions = (int) ($counters['exception'] ?? 0);

        $payload = [
            'duration_us'       => $durationUs,
            'peak_memory_bytes' => memory_get_peak_usage(true),
            'queries'           => $queries,
            'exceptions'        => $exceptions,
            'status'            => $statusCode,
            'open_url'          => $this->config['open_url'] ?? null,
            // Web Vitals correlation hints. The toolbar JS POSTs these back
            // alongside client-side timings so the daemon can find the matching
            // trace on disk (no exact trace_id is exposed yet — see
            // ClientMetricsController in the daemon for the lookup heuristic).
            'pid'                       => getmypid(),
            'started_at_unix_micros'    => $startedAtMicros,
            'metrics_endpoint'          => $this->config['metrics_endpoint'] ?? null,
        ];

        $script = $this->loadToolbarJs();
        if ($script === null) {
            return '';
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return '';
        }

        // Wrap both the data and the script in one safe block. The script
        // is read from disk at request time so deployment doesn't need a
        // build step. Cache the read after the first hit.
        return "<script>window.__PERISCOPE_TB__={$json};{$script}</script>";
    }

    private static ?string $cachedScript = null;

    private function loadToolbarJs(): ?string
    {
        if (self::$cachedScript !== null) {
            return self::$cachedScript;
        }
        $path = __DIR__ . '/../../resources/js/toolbar.js';
        if (!is_file($path)) {
            return null;
        }
        $body = @file_get_contents($path);
        if ($body === false) {
            return null;
        }
        self::$cachedScript = $body;
        return $body;
    }
}
