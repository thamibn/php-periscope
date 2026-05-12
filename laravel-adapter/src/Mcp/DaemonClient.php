<?php

declare(strict_types=1);

namespace Periscope\Laravel\Mcp;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Read-only HTTP client over `periscope-daemon`'s `/api/*` surface. Used
 * by the MCP tools to proxy AI agent requests to the local daemon.
 *
 * The daemon's base URL is read from `periscope.ui.daemon_base`
 * (env: `PERISCOPE_UI_DAEMON_BASE`, default `http://127.0.0.1:9999`).
 * Methods return the raw decoded JSON the daemon emitted — the MCP layer
 * wraps responses with `Response::json()` so AI agents see the same
 * shape a `curl /api/...` would.
 */
final readonly class DaemonClient
{
    private string $baseUrl;

    public function __construct(
        private HttpFactory $http,
        string $baseUrl,
    ) {
        $this->baseUrl = (string) Str::of($baseUrl)->rtrim('/');
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /** @return list<array<string, mixed>> */
    public function listTraces(?int $limit = null): array
    {
        return $this->fetchList('/api/traces', Arr::whereNotNull(['limit' => $limit]));
    }

    /** @return array<string, mixed> */
    public function getTrace(string $id): array
    {
        return $this->fetchAssoc("/api/traces/{$id}");
    }

    /** @return array<string, mixed> */
    public function getSummary(string $id): array
    {
        return $this->fetchAssoc("/api/traces/{$id}/summary");
    }

    /** @return array<string, mixed> */
    public function getInsights(string $id): array
    {
        return $this->fetchAssoc("/api/traces/{$id}/insights");
    }

    /** @return list<array<string, mixed>> */
    public function getTimeline(string $id): array
    {
        return $this->fetchList("/api/traces/{$id}/timeline");
    }

    /** @return array<string, mixed> */
    public function getState(string $id, int $atMicros): array
    {
        return $this->fetchAssoc("/api/traces/{$id}/state", ['at' => $atMicros]);
    }

    /**
     * Returns either a list of events (raw mode) or a list of event groups
     * (group=true). The MCP layer relays whichever shape the daemon picks.
     *
     * @return list<array<string, mixed>>
     */
    public function listEvents(string $id, ?string $type = null, ?string $filter = null, bool $group = false): array
    {
        return $this->fetchList(
            "/api/traces/{$id}/events",
            $this->compact([
                'type'   => $type,
                'filter' => $filter,
                'group'  => $group ? 'true' : null,
            ]),
        );
    }

    /** @return array<string, mixed> */
    public function readFile(string $path, ?int $line = null, ?int $radius = null): array
    {
        return $this->fetchAssoc('/api/file', $this->compact([
            'path'   => $path,
            'line'   => $line,
            'radius' => $radius,
        ]));
    }

    /**
     * Drop nullish + empty-string entries so the daemon never receives
     * `?filter=` or similar no-op params.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, scalar>
     */
    private function compact(array $params): array
    {
        return collect($params)
            ->reject(fn ($v) => $v === null || $v === '')
            ->all();
    }

    /** @return array<string, mixed> */
    public function health(): array
    {
        return $this->fetchAssoc('/api/health');
    }

    /**
     * @param  array<string, scalar>  $query
     * @return list<array<string, mixed>>
     */
    private function fetchList(string $path, array $query = []): array
    {
        $body = $this->request($path, $query);
        throw_unless(
            Arr::isList($body),
            new RuntimeException("expected JSON list from {$path}, got " . get_debug_type($body)),
        );
        return $body;
    }

    /**
     * @param  array<string, scalar>  $query
     * @return array<string, mixed>
     */
    private function fetchAssoc(string $path, array $query = []): array
    {
        return $this->request($path, $query);
    }

    /**
     * @param  array<string, scalar>  $query
     * @return array<int|string, mixed>
     */
    private function request(string $path, array $query): array
    {
        $response = tap($this->pending()->get($path, $query), function (Response $r) use ($path): void {
            $this->ensureOk($r, $path);
        });
        $body = $response->json();
        throw_unless(
            is_array($body),
            new RuntimeException("expected JSON object/list from {$path}, got " . get_debug_type($body)),
        );
        return $body;
    }

    /**
     * Build a baseUrl-anchored client. Centralised so future timeout /
     * retry policy lands in one place.
     */
    private function pending(): PendingRequest
    {
        return $this->http
            ->baseUrl($this->baseUrl)
            ->acceptJson()
            ->timeout(5);
    }

    private function ensureOk(Response $response, string $path): void
    {
        throw_if(
            $response->failed(),
            new RuntimeException(sprintf(
                'daemon %s returned %d %s',
                $path,
                $response->status(),
                $response->reason(),
            )),
        );
    }
}
