<?php

declare(strict_types=1);

namespace Periscope\Laravel\Http;

use Closure;
use Illuminate\Http\Request;

/**
 * Decides whether a given HTTP request is allowed to view the periscope UI.
 *
 * Default policy:
 *   - APP_DEBUG=true                   → allow (local dev)
 *   - APP_DEBUG=false:
 *       - allow_in_production=false    → deny
 *       - allow_in_production=true:
 *           - no token configured      → deny (refuse to serve "open UI")
 *           - token cookie matches     → allow
 *           - ?token=<value> matches   → allow + stash cookie for follow-ups
 *           - else                     → deny
 *
 * Apps can replace the policy entirely with `UiGate::authorize($closure)` —
 * useful when access should be tied to authenticated users, roles, IP allow-
 * lists, etc. Mirrors Telescope's `Telescope::auth($callback)` API.
 */
final class UiGate
{
    public const COOKIE = 'periscope_token';

    private static ?Closure $custom = null;

    public static function authorize(Closure $callback): void
    {
        self::$custom = $callback;
    }

    public static function reset(): void
    {
        self::$custom = null;
    }

    public static function check(Request $request, array $config): bool
    {
        if (self::$custom !== null) {
            return (bool) (self::$custom)($request);
        }

        if (config('app.debug') === true) {
            return true;
        }

        if (!($config['allow_in_production'] ?? false)) {
            return false;
        }

        $expected = (string) ($config['token'] ?? '');
        if ($expected === '' || strlen($expected) < 16) {
            // Refuse to authorise on a missing / short token — the whole point
            // of `allow_in_production` is paired with a strong shared secret.
            return false;
        }

        $supplied = self::extractToken($request);
        if ($supplied === null) {
            return false;
        }

        return hash_equals($expected, $supplied);
    }

    private static function extractToken(Request $request): ?string
    {
        $candidates = [
            $request->cookie(self::COOKIE),
            $request->query('token'),
            $request->bearerToken(),
            $request->header('X-Periscope-Token'),
        ];
        foreach ($candidates as $c) {
            if (is_string($c) && $c !== '') {
                return $c;
            }
        }
        return null;
    }
}
