<?php declare(strict_types=1);
if (!defined('GO_BOOT')) { http_response_code(404); exit; }

/**
 * The database handle and a few helpers.
 *
 * Connection settings that are not optional:
 *   EMULATE_PREPARES=false  real prepared statements, so a LIMIT must be bound
 *                           as an int (see limit()).
 *   FOUND_ROWS=true         rowCount() returns rows MATCHED, not rows changed.
 *                           A conditional UPDATE used as a lock relies on this:
 *                           without it, an update that happens to write the same
 *                           values reports 0 and the code wrongly concludes it
 *                           lost the race.
 *   time_zone='+00:00'      every DATETIME(3) is UTC. The IST calendar date is
 *                           computed in PHP, never by the database.
 *   READ-COMMITTED          avoids gap locks on the publish-queue range scans,
 *                           and matches the "read the current truth" semantics
 *                           the lease checks assume.
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function connect(array $cfg): PDO
    {
        if (self::$pdo instanceof PDO) return self::$pdo;

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $cfg['host'], (int) ($cfg['port'] ?? 3306), $cfg['name']);

        try {
            $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_FOUND_ROWS   => true,
            ]);
        } catch (PDOException $e) {
            error_log('[go] db connect failed: ' . $e->getMessage());
            throw new ApiError(503, 'SERVICE_UNAVAILABLE', 'the database is unreachable');
        }

        $pdo->exec("SET SESSION time_zone='+00:00', sql_mode='STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        // MariaDB spells this differently from MySQL 8 and the app runs on both.
        try { $pdo->exec("SET SESSION transaction_isolation='READ-COMMITTED'"); }
        catch (PDOException) { try { $pdo->exec("SET SESSION tx_isolation='READ-COMMITTED'"); } catch (PDOException) {} }

        return self::$pdo = $pdo;
    }

    public static function pdo(): PDO
    {
        if (!self::$pdo instanceof PDO) throw new RuntimeException('database not connected');
        return self::$pdo;
    }

    public static function run(string $sql, array $params = []): PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st;
    }

    /** A LIMIT cannot be a string once prepares are real. Bind it as an int. */
    public static function limit(string $sql, array $params, int $limit): PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $i = 1;
        foreach ($params as $p) {
            $st->bindValue($i++, $p, is_int($p) ? PDO::PARAM_INT : ($p === null ? PDO::PARAM_NULL : PDO::PARAM_STR));
        }
        $st->bindValue($i, $limit, PDO::PARAM_INT);
        $st->execute();
        return $st;
    }

    public static function row(string $sql, array $params = []): ?array
    {
        $r = self::run($sql, $params)->fetch();
        return $r === false ? null : $r;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /** First column of the first row. */
    public static function one(string $sql, array $params = []): mixed
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function exec(string $sql, array $params = []): int
    {
        return self::run($sql, $params)->rowCount();
    }

    public static function lastId(): int
    {
        return (int) self::pdo()->lastInsertId();
    }

    /** Run $fn inside a transaction, rolling back on any throw. */
    public static function tx(callable $fn): mixed
    {
        $pdo = self::pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) $pdo->beginTransaction();
        try {
            $out = $fn();
            if ($owns) $pdo->commit();
            return $out;
        } catch (Throwable $e) {
            if ($owns && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** True when a PDOException is a duplicate-key violation. */
    public static function isDuplicate(PDOException $e): bool
    {
        return $e->getCode() === '23000' && (int) ($e->errorInfo[1] ?? 0) === 1062;
    }
}
