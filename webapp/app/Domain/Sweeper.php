<?php declare(strict_types=1);
if (!defined('GO_BOOT')) { http_response_code(404); exit; }

/**
 * Housekeeping, without depending on cron.
 *
 * Shared hosting may or may not give you a working cron entry, and the brief
 * says not to assume CLI access. So this runs probabilistically after the
 * response has already been flushed to the client: roughly one request in fifty
 * pays for it, and the volume here is a handful of agent runs a day.
 *
 * Every statement is LIMITed so it can never become a long transaction, and a
 * named lock stops two sweeps overlapping.
 */
final class Sweeper
{
    public static function maybeRun(): void
    {
        try {
            if (random_int(1, 50) !== 1) return;
            self::run();
        } catch (Throwable $e) {
            // Housekeeping must never affect a response that has already been sent.
            error_log('[go] sweeper: ' . $e->getMessage());
        }
    }

    public static function run(): void
    {
        if ((int) Db::one("SELECT GET_LOCK('go_sweep', 0)") !== 1) return;
        try {
            Db::exec('DELETE FROM idempotency_keys WHERE expires_at < UTC_TIMESTAMP(3) LIMIT 500');
            Db::exec('DELETE FROM login_attempts WHERE attempted_at < UTC_TIMESTAMP(3) - INTERVAL 7 DAY LIMIT 500');
            Db::exec('DELETE FROM ui_sessions WHERE last_seen_at < UTC_TIMESTAMP(3) - INTERVAL 14 DAY LIMIT 500');

            // A lease whose holder crashed must not strand the work forever.
            Db::exec("UPDATE topics SET pipeline_state='awaiting_copy', claimed_by_run_id=NULL, claim_expires_at=NULL
                       WHERE pipeline_state='copy_in_progress' AND claim_expires_at IS NOT NULL
                         AND claim_expires_at < UTC_TIMESTAMP(3) LIMIT 100");
            Db::exec("UPDATE posts SET lifecycle_state = CASE WHEN scheduled_date_ist IS NULL THEN 'ready' ELSE 'scheduled' END,
                             publish_lease_token=NULL, publish_lease_expires_at=NULL
                       WHERE lifecycle_state='publishing' AND publish_lease_expires_at IS NOT NULL
                         AND publish_lease_expires_at < UTC_TIMESTAMP(3) LIMIT 100");
        } finally {
            Db::one("SELECT RELEASE_LOCK('go_sweep')");
        }
    }
}
