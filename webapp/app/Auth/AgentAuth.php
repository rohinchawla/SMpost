<?php declare(strict_types=1);
if (!defined('GO_BOOT')) { http_response_code(404); exit; }

/**
 * Authentication for /api/v1/agent/**.
 *
 * @mock mock-api/server.mjs:150-161 (authenticate)
 *
 * This surface never reads a cookie and never calls session_start(). The
 * disjointness from the UI surface is structural, not a flag: the two
 * dispatchers share no code, so neither can fall through to the other.
 *
 * The distinction the contract tests check:
 *   no token, or an unrecognised one -> 401 UNAUTHORIZED
 *   a valid key lacking the scope     -> 403 FORBIDDEN_SCOPE, with details
 */
final class AgentAuth
{
    private static ?string $agent = null;

    public static function authenticate(?string $needScope): string
    {
        $token = Router::bearer();
        if ($token === null) throw ApiError::unauthorized('missing bearer token');

        $resolved = ApiKeys::resolve($token);
        if ($resolved === null) throw ApiError::unauthorized('unknown API key');

        $agent = $resolved['agent'];
        if ($needScope !== null && !in_array($needScope, $resolved['scopes'], true)) {
            throw ApiError::forbidden(
                "agent {$agent} does not hold scope {$needScope}",
                ['agent' => $agent, 'required' => $needScope]
            );
        }

        return self::$agent = $agent;
    }

    public static function current(): ?string
    {
        return self::$agent;
    }
}
