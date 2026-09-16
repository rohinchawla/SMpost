<?php declare(strict_types=1);
if (!defined('GO_BOOT')) { http_response_code(404); exit; }

/**
 * Path resolution and route matching.
 *
 * @mock mock-api/server.mjs:1049-1067 (matchRoute)
 *
 * The path comes from REQUEST_URI, never PATH_INFO. PATH_INFO is mangled by
 * AcceptPathInfo, MultiViews and some LSAPI builds, and one of our routes is
 * `/api/v1/agent/metrics:bulk-upsert` with a literal colon in it. REQUEST_URI is
 * the only form that survives that reliably.
 */
final class Router
{
    /** The request path, query stripped and base path removed. */
    public static function path(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $q = strpos($uri, '?');
        if ($q !== false) $uri = substr($uri, 0, $q);

        // Supports both a docroot at public/ and the fallback layout where the
        // app sits in a subfolder of public_html.
        $base = rtrim(str_replace(chr(92), '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        if ($base !== '' && $base !== '/' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base));
        }
        $uri = '/' . ltrim($uri, '/');
        if ($uri === '//') return '/';
        $trimmed = rtrim($uri, '/');
        return $trimmed === '' ? '/' : $trimmed;
    }

    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function query(): array
    {
        return $_GET ?? [];
    }

    /**
     * Segment-count-exact matching, literal segments compared as strings and
     * `:name` segments captured. Identical to the mock.
     *
     * $routes: [ [method, pattern, scope, handler], ... ]
     * Returns [entry, params] or null.
     */
    public static function match(array $routes, string $method, string $path): ?array
    {
        $actual = explode('/', $path);
        foreach ($routes as $entry) {
            if ($entry[0] !== $method) continue;
            $pattern = explode('/', $entry[1]);
            if (count($pattern) !== count($actual)) continue;

            $params = [];
            $ok = true;
            foreach ($pattern as $i => $seg) {
                if ($seg !== '' && $seg[0] === ':') {
                    $params[substr($seg, 1)] = rawurldecode($actual[$i]);
                } elseif ($seg !== $actual[$i]) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) return [$entry, $params];
        }
        return null;
    }

    /**
     * Longest literal prefix first, so `/posts/published` is matched before
     * `/posts/:postUid`. @mock server.mjs:1067
     */
    public static function sort(array $routes): array
    {
        usort($routes, static function (array $a, array $b): int {
            $la = strlen(explode(':', $a[1])[0]);
            $lb = strlen(explode(':', $b[1])[0]);
            return $lb <=> $la;
        });
        return $routes;
    }

    /**
     * Normalise the last path segment.
     *
     * A proxy or client that percent-encodes the colon in `metrics:bulk-upsert`
     * should still reach the same handler, and a host whose WAF blocks a literal
     * colon can use the `metrics/bulk-upsert` alias instead. Both are additive:
     * the canonical path in the contract is unchanged and the test suite uses it.
     */
    public static function normalise(string $path): string
    {
        if (str_contains($path, '%3A') || str_contains($path, '%3a')) {
            $path = str_replace(['%3A', '%3a'], ':', $path);
        }
        if (str_ends_with($path, '/metrics/bulk-upsert')) {
            $path = substr($path, 0, -strlen('/metrics/bulk-upsert')) . '/metrics:bulk-upsert';
        }
        return $path;
    }

    /** The bearer token, from any of the three places a host might leave it. */
    public static function bearer(): ?string
    {
        $candidates = [
            $_SERVER['HTTP_AUTHORIZATION'] ?? null,
            $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null,
        ];
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                if (strcasecmp($k, 'Authorization') === 0) { $candidates[] = $v; break; }
            }
        }
        foreach ($candidates as $h) {
            if (is_string($h) && stripos($h, 'Bearer ') === 0) {
                $t = trim(substr($h, 7));
                if ($t !== '') return $t;
            }
        }
        return null;
    }

    /** True when an Authorization header is present at all, in any form. */
    public static function hasAuthorizationHeader(): bool
    {
        if (!empty($_SERVER['HTTP_AUTHORIZATION']) || !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) return true;
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                if (strcasecmp($k, 'Authorization') === 0 && trim((string) $v) !== '') return true;
            }
        }
        return false;
    }
}
