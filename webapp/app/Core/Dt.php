<?php declare(strict_types=1);
if (!defined('GO_BOOT')) { http_response_code(404); exit; }

/**
 * Time, in exactly two shapes.
 *
 * The mock stores ISO-8601 "...Z" strings in SQLite TEXT columns and hands them
 * straight back out as JSON. MySQL DATETIME(3) rejects that format outright
 * under STRICT_ALL_TABLES, so every timestamp has to be converted in both
 * directions. Every one of them goes through this class. No exceptions.
 */
final class Dt
{
    /** For binding into a DATETIME(3) column: '2026-09-16 12:34:56.789'. */
    public static function nowDb(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
    }

    /** For rendering into JSON: '2026-09-16T12:34:56.789Z'. @mock server.mjs:62 */
    public static function nowIso(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    }

    /** A DATETIME(3) value read from the database, rendered as the mock would. */
    public static function iso(?string $dbValue): ?string
    {
        if ($dbValue === null || $dbValue === '') return null;
        $d = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $dbValue, new DateTimeZone('UTC'))
            ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dbValue, new DateTimeZone('UTC'));
        return $d ? $d->format('Y-m-d\TH:i:s.v\Z') : null;
    }

    /** Milliseconds between two DATETIME(3) values, for agent_runs.duration_ms. */
    public static function diffMs(string $from, string $to): int
    {
        return (int) round((strtotime($to . ' UTC') - strtotime($from . ' UTC')) * 1000);
    }

    /** now + $seconds, as a DATETIME(3) bind value. */
    public static function plusSecondsDb(int $seconds): string
    {
        return (new DateTimeImmutable("+{$seconds} seconds", new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
    }
}
