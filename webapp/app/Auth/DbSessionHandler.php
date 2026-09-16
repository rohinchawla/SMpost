<?php declare(strict_types=1);
if (!defined('GO_BOOT')) { http_response_code(404); exit; }

/**
 * Sessions in MySQL rather than in files.
 *
 * On shared hosting the session save path is often a shared /tmp that has
 * historically been readable across accounts. A table also gives "sign out
 * everywhere" and a session list for nothing, and removes a writable-directory
 * dependency from the deployment.
 */
final class DbSessionHandler implements SessionHandlerInterface
{
    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }

    public function read(string $id): string
    {
        $row = Db::row('SELECT payload FROM ui_sessions WHERE sid = ?', [$id]);
        return $row === null ? '' : (string) $row['payload'];
    }

    public function write(string $id, string $data): bool
    {
        $uid = null;
        if (preg_match('/uid\|i:(\d+);/', $data, $m)) $uid = (int) $m[1];

        Db::exec(
            'INSERT INTO ui_sessions (sid, user_id, payload, ua_hash, ip, created_at, last_seen_at)
             VALUES (?,?,?,?,?,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))
             ON DUPLICATE KEY UPDATE payload = VALUES(payload), user_id = VALUES(user_id),
                                     last_seen_at = UTC_TIMESTAMP(3)',
            [$id, $uid, $data,
             hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')),
             @inet_pton((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0')) ?: null]
        );
        return true;
    }

    public function destroy(string $id): bool
    {
        Db::exec('DELETE FROM ui_sessions WHERE sid = ?', [$id]);
        return true;
    }

    /** Sweeper handles expiry on a schedule; this is the fallback path. */
    public function gc(int $maxLifetime): int|false
    {
        return Db::exec('DELETE FROM ui_sessions WHERE last_seen_at < UTC_TIMESTAMP(3) - INTERVAL ? SECOND LIMIT 500',
            [max($maxLifetime, 3600)]);
    }
}
