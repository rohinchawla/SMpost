<?php declare(strict_types=1);
if (!defined('GO_BOOT')) { http_response_code(404); exit; }

/**
 * The business calendar.
 *
 * India is UTC+5:30 and has had no daylight saving since 1945, so the offset is
 * a constant and this needs no timezone database. The half-hour offset is the
 * classic bug-maker, which is why it lives in one function rather than being
 * added by hand at each call site.
 *
 * @mock server.mjs:65-66
 */
final class Ist
{
    public const OFFSET_SECONDS = 19800; // +05:30

    /** The IST calendar date, as YYYY-MM-DD. */
    public static function date(?int $unixTs = null): string
    {
        return gmdate('Y-m-d', ($unixTs ?? time()) + self::OFFSET_SECONDS);
    }

    /** The IST date $days from now. Negative for the past. */
    public static function datePlusDays(int $days, ?int $from = null): string
    {
        return self::date(($from ?? time()) + ($days * 86400));
    }

    /** Whole days between two IST dates, as the mock computes age_days. */
    public static function daysBetween(string $fromDate, string $toDate): int
    {
        return (int) round((strtotime($toDate . ' UTC') - strtotime($fromDate . ' UTC')) / 86400);
    }
}
