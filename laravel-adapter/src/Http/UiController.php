<?php

declare(strict_types=1);

namespace Periscope\Laravel\Http;

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
    ) {}

    /**
     * The HTML entrypoint. Inlined `<meta>` tells the SolidJS app where to
     * find the daemon's `/api/*` and `/ws`.
     */
    public function index(): Response
    {
        $index = $this->bundleDir . '/index.html';
        if (!is_file($index)) {
            return new Response($this->missingBundleHtml(), 200, ['content-type' => 'text/html; charset=utf-8']);
        }

        $html = (string) file_get_contents($index);
        $meta = sprintf(
            '<meta name="periscope-daemon-base" content="%s">',
            htmlspecialchars($this->daemonBase, ENT_QUOTES, 'UTF-8'),
        );
        // Inject just before </head> so the API client picks it up on boot.
        $html = preg_replace('#</head>#i', $meta . '</head>', $html, 1) ?? $html;

        return new Response($html, 200, [
            'content-type' => 'text/html; charset=utf-8',
            'cache-control' => 'no-store',
        ]);
    }

    /**
     * Serve a hashed asset (`assets/index-XXXX.js`, `assets/style-XXXX.css`).
     * Vite filenames are content-hashed so we can cache these forever.
     */
    public function asset(Request $request, string $path): SymfonyResponse
    {
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
