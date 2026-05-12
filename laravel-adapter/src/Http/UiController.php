<?php

declare(strict_types=1);

namespace Periscope\Laravel\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Serves the periscope SolidJS bundle from inside the Laravel app.
 *
 * Mount-point is configurable via `periscope.ui.path` (env: `PERISCOPE_UI_PATH`),
 * default `periscope`. Disabled by default — set `PERISCOPE_UI_ENABLED=true`
 * to expose `app.test/{path}` and `app.test/{path}/assets/...`.
 *
 * The bundle is the *exact same* `ui/dist/` that the Rust daemon serves at
 * `localhost:9999`; we just inject a `<meta name="periscope-daemon-base">`
 * so XHRs from the bundle reach the daemon's HTTP API + WebSocket.
 *
 * Why offer this when the daemon already serves the UI?
 *   - "I already have app.test in my browser, I don't want a second port."
 *   - team setups behind reverse proxies where localhost:9999 isn't reachable
 *     but the app is.
 *   - sharing a UI link in a colleague's browser via the app's own auth.
 */
final class UiController
{
    public function __construct(
        private readonly string $bundleDir,
        private readonly string $daemonBase,
        private readonly string $mountPrefix = 'periscope',
        /** @var array{allow_in_production?:bool, token?:?string} */
        private readonly array $gateConfig = [],
    ) {}

    /**
     * The HTML entrypoint. Inlined `<meta>` tells the SolidJS app where to
     * find the daemon's `/api/*` and `/ws`. We also rewrite the absolute
     * `/assets/...` paths Vite emits to include the configured mount prefix
     * (e.g. `/periscope/assets/...`) so deep-mounted UIs don't 404 their
     * own bundle.
     */
    public function index(Request $request): Response|SymfonyResponse
    {
        if (!UiGate::check($request, $this->gateConfig)) {
            return $this->lockedResponse($request);
        }

        $index = $this->bundleDir . '/index.html';
        if (!is_file($index)) {
            return new Response($this->missingBundleHtml(), 200, ['content-type' => 'text/html; charset=utf-8']);
        }

        $html = (string) file_get_contents($index);

        // Rewrite Vite's absolute asset paths to live under the mount prefix.
        // Matches src="/assets/..." and href="/assets/..." but not "https://…".
        $prefix = trim($this->mountPrefix, '/');
        if ($prefix !== '') {
            $html = preg_replace(
                '#((?:src|href)=")/assets/#i',
                '$1/' . $prefix . '/assets/',
                $html,
            ) ?? $html;
        }

        $mountUrl = '/' . trim($this->mountPrefix, '/');
        $meta = sprintf(
            '<meta name="periscope-daemon-base" content="%s">' .
            '<meta name="periscope-mount-prefix" content="%s">',
            htmlspecialchars($this->daemonBase, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($mountUrl === '/' ? '' : $mountUrl, ENT_QUOTES, 'UTF-8'),
        );
        // Inject just before </head> so the API client picks it up on boot.
        $html = preg_replace('#</head>#i', $meta . '</head>', $html, 1) ?? $html;

        $response = new Response($html, 200, [
            'content-type' => 'text/html; charset=utf-8',
            'cache-control' => 'no-store',
        ]);

        // If the user supplied ?token=… on the URL and the gate let it
        // through, stash the token in a cookie so subsequent navigation
        // (asset fetches, page reloads) doesn't need the query param.
        if ($request->query('token') !== null && config('app.debug') !== true) {
            $token = (string) $request->query('token');
            $response->headers->setCookie(
                cookie(UiGate::COOKIE, $token, 60 * 8, null, null, $request->isSecure(), true, false, 'lax'),
            );
        }

        return $response;
    }

    /**
     * Serve a hashed asset (`assets/index-XXXX.js`, `assets/style-XXXX.css`).
     * Vite filenames are content-hashed so we can cache these forever.
     */
    public function asset(Request $request, string $path): SymfonyResponse
    {
        if (!UiGate::check($request, $this->gateConfig)) {
            return $this->lockedResponse($request);
        }

        // Defeat path traversal — Vite emits flat `assets/<name>` only.
        $clean = ltrim($path, '/');
        if (str_contains($clean, '..') || !preg_match('#^[A-Za-z0-9_./-]+$#', $clean)) {
            abort(404);
        }

        $abs = realpath($this->bundleDir . '/assets/' . $clean);
        $root = realpath($this->bundleDir . '/assets');
        if ($abs === false || $root === false || !str_starts_with($abs, $root)) {
            abort(404);
        }

        $resp = new BinaryFileResponse($abs);
        $resp->headers->set('content-type', $this->mimeFor($abs));
        $resp->setPublic();
        $resp->setMaxAge(31_536_000); // 1y; filenames are content-hashed
        $resp->headers->set('immutable', '');
        return $resp;
    }

    /**
     * Read-only settings dump for the UI's Settings view.
     *
     * Reports the framework-level Laravel config plus the engine-level INI
     * values, so users can see the merged state of their tool without
     * rummaging through `config/periscope.php` and `99-periscope.ini`
     * separately. Sensitive fields (UI token) are redacted.
     */
    public function settings(Request $request): JsonResponse
    {
        if (!UiGate::check($request, $this->gateConfig)) {
            return new JsonResponse(['error' => 'forbidden'], 403);
        }

        $config = config('periscope', []);

        // Strip sensitive fields. The UI token gates the production UI.
        if (isset($config['ui']['token']) && is_string($config['ui']['token']) && $config['ui']['token'] !== '') {
            $config['ui']['token'] = '[redacted · ' . strlen($config['ui']['token']) . ' chars]';
        }

        $engine = [
            'extension_loaded'        => extension_loaded('periscope'),
            'enabled'                 => (bool) ini_get('periscope.enabled'),
            'verbose'                 => (bool) ini_get('periscope.verbose'),
            'skip_internal'           => (bool) ini_get('periscope.skip_internal'),
            'trace_dir'               => (string) ini_get('periscope.trace_dir'),
            'max_traces'              => (int) ini_get('periscope.max_traces'),
            'max_trace_age_seconds'   => (int) ini_get('periscope.max_trace_age_seconds'),
            'namespace_filter'        => (string) ini_get('periscope.namespace_filter'),
            'path_ignore'             => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) ini_get('periscope.path_ignore')),
            ))),
            'max_depth'               => (int) ini_get('periscope.max_depth'),
            'max_string'              => (int) ini_get('periscope.max_string'),
            'max_array_items'         => (int) ini_get('periscope.max_array_items'),
            'max_object_props'        => (int) ini_get('periscope.max_object_props'),
        ];

        $env = [
            'app_env'        => (string) config('app.env', '?'),
            'app_debug'      => (bool) config('app.debug', false),
            'php_version'    => PHP_VERSION,
            'php_sapi'       => PHP_SAPI,
            'laravel_version' => app()->version(),
            'queue_default'  => (string) config('queue.default', '?'),
            'cache_default'  => (string) config('cache.default', '?'),
            'session_driver' => (string) config('session.driver', '?'),
        ];

        return new JsonResponse([
            'environment' => $env,
            'engine'      => $engine,
            'framework'   => $config,
        ]);
    }

    private function mimeFor(string $path): string
    {
        return match (pathinfo($path, PATHINFO_EXTENSION)) {
            'js', 'mjs' => 'application/javascript; charset=utf-8',
            'css'       => 'text/css; charset=utf-8',
            'svg'       => 'image/svg+xml',
            'json'      => 'application/json',
            'map'       => 'application/json',
            'woff2'     => 'font/woff2',
            'woff'      => 'font/woff',
            default     => 'application/octet-stream',
        };
    }

    private function lockedResponse(Request $request): Response
    {
        $isProd = config('app.debug') !== true;
        $title  = $isProd ? 'periscope is locked in production' : 'periscope locked';
        $body   = $isProd
            ? 'Set <code>PERISCOPE_UI_ALLOW_IN_PROD=true</code> and <code>PERISCOPE_UI_TOKEN=&lt;long-random-string&gt;</code>, '
              . 'then visit <code>?token=…</code> once. For tighter control register '
              . '<code>UiGate::authorize($closure)</code> from a service provider.'
            : 'A custom UiGate denied this request.';
        $accept = (string) $request->header('accept', '');
        if (str_contains($accept, 'application/json')) {
            return new Response(
                json_encode(['error' => 'periscope_ui_locked', 'detail' => strip_tags($body)], JSON_UNESCAPED_SLASHES),
                403,
                ['content-type' => 'application/json'],
            );
        }
        $html = <<<HTML
            <!doctype html><html lang="en"><head><meta charset="utf-8"><title>{$title}</title>
            <style>body{font-family:system-ui;background:#0b0f15;color:#e7eaf0;padding:3rem;max-width:38rem;margin:auto}
            code{background:#11161f;padding:.1rem .35rem;border-radius:.25rem}h1{font-weight:600}</style></head>
            <body><h1>{$title}</h1><p>{$body}</p></body></html>
            HTML;
        return new Response($html, 403, ['content-type' => 'text/html; charset=utf-8']);
    }

    private function missingBundleHtml(): string
    {
        $base = htmlspecialchars($this->daemonBase, ENT_QUOTES, 'UTF-8');
        return <<<HTML
            <!doctype html>
            <html lang="en">
              <head><meta charset="utf-8"><title>periscope</title>
              <style>body{font-family:system-ui;background:#0b0f15;color:#e7eaf0;padding:3rem;max-width:36rem;margin:auto}
              code{background:#11161f;padding:.1rem .35rem;border-radius:.25rem}h1{font-weight:600}</style></head>
              <body>
                <h1>periscope UI bundle not built</h1>
                <p>Run <code>cd ui && bun install && bun run build</code> in the periscope checkout,
                then point <code>PERISCOPE_UI_BUNDLE_DIR</code> at the resulting <code>ui/dist/</code>.</p>
                <p>Or open the daemon's UI directly at <a href="{$base}">{$base}</a>.</p>
              </body>
            </html>
            HTML;
    }
}
