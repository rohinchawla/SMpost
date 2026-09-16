<?php declare(strict_types=1);
if (!defined('GO_BOOT')) { http_response_code(404); exit; }

/**
 * Minting, verifying and revoking the six agent keys.
 *
 * @mock mock-api/server.mjs:83-91
 *
 * The mock hardcodes six literal strings and never touches the api_keys table.
 * The real app has to resolve a presented token against stored rows, which is
 * what makes "revoke A5" - the only agent that can reach the public - a one
 * click operation rather than a redeploy.
 */
final class ApiKeys
{
    /**
     * Transcribed from the mock. Do not edit one without editing the other.
     *
     * Used only when seeding a key row; the scope check at request time reads
     * the stored JSON, so narrowing a scope does not require new code.
     */
    public const SCOPES = [
        'A1' => ['runs:write', 'topics:write', 'deadletters:write'],
        'A2' => ['runs:write', 'topics:read', 'topics:claim', 'posts:write', 'deadletters:write'],
        'A3' => ['runs:write', 'posts:read', 'images:write', 'deadletters:write'],
        'A4' => ['runs:write', 'posts:read', 'posts:submit', 'deadletters:write'],
        'A5' => ['runs:write', 'posts:read', 'publish:write', 'deadletters:write'],
        'A6' => ['runs:write', 'posts:read', 'metrics:write', 'deadletters:write'],
    ];

    /**
     * The hash stored for a key.
     *
     * The agent code is mixed in, so a stolen key_hash row cannot be re-pointed
     * at a different agent_code to gain A5's publish scope.
     *
     * A 256-bit random key has no guessable structure, so SHA-256 is the right
     * primitive here and Argon2 would only add ~100ms to every agent request.
     * Argon2 belongs on the human password, where the input is guessable.
     */
    public static function hash(string $agent, string $raw): string
    {
        return hash('sha256', $agent . ':' . $raw);
    }

    /** go_<env>_<agent>_<43 chars>. The first 12 characters are the lookup prefix. */
    public static function mint(string $agent, string $env = 'prod'): string
    {
        $suffix = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        return sprintf('go_%s_%s_%s', $env, strtolower($agent), $suffix);
    }

    public static function store(string $agent, string $raw, string $label): void
    {
        Db::exec(
            'INSERT INTO api_keys (agent_code, label, key_prefix, key_hash, scopes) VALUES (?,?,?,?,?)',
            [$agent, $label, substr($raw, 0, 12), self::hash($agent, $raw),
             Canon::encode(self::SCOPES[$agent] ?? [])]
        );
    }

    /** Returns [agent_code, scopes[]] or null. Constant-time on the secret. */
    public static function resolve(string $raw): ?array
    {
        $row = Db::row(
            'SELECT id, agent_code, key_hash, scopes FROM api_keys
              WHERE key_prefix = ? AND is_active = 1 AND revoked_at IS NULL',
            [substr($raw, 0, 12)]
        );

        if ($row === null) {
            // Spend the same wall-clock time on an unknown prefix as on a wrong
            // secret, so the two are not distinguishable by timing.
            hash_equals(str_repeat('0', 64), hash('sha256', 'X:' . $raw));
            return null;
        }
        if (!hash_equals((string) $row['key_hash'], self::hash((string) $row['agent_code'], $raw))) {
            return null;
        }

        self::touch((int) $row['id']);
        return [
            'agent'  => (string) $row['agent_code'],
            'scopes' => Canon::decode((string) $row['scopes'], []) ?: [],
        ];
    }

    /** At most one write a minute per key, so this is not a row lock per request. */
    private static function touch(int $id): void
    {
        try {
            Db::exec('UPDATE api_keys SET last_used_at = UTC_TIMESTAMP(3)
                       WHERE id = ? AND (last_used_at IS NULL OR last_used_at < UTC_TIMESTAMP(3) - INTERVAL 60 SECOND)',
                [$id]);
        } catch (Throwable) { /* never fail a request over telemetry */ }
    }

    public static function revoke(string $agent): int
    {
        return Db::exec("UPDATE api_keys SET is_active = 0, revoked_at = UTC_TIMESTAMP(3)
                          WHERE agent_code = ? AND is_active = 1", [$agent]);
    }

    public static function listAll(): array
    {
        return Db::all('SELECT agent_code, label, key_prefix, is_active, last_used_at, created_at, revoked_at
                          FROM api_keys ORDER BY agent_code, id');
    }
}
