<?php declare(strict_types=1);
if (!defined('GO_BOOT')) { http_response_code(404); exit; }

/**
 * Config and back-pressure.
 *
 * @mock mock-api/server.mjs:325-345 (getConfig)
 * @mock mock-api/server.mjs:352-370 (queueDepth)
 */
final class Meta
{
    public static function getConfig(array $ctx): array
    {
        return [200, ['data' => Settings::configPayload()]];
    }

    /**
     * A1 calls this first and skips the week when told to.
     *
     * A1 produces 30-60 topics a week and A5 publishes 7. Without a ceiling the
     * approved queue grows by 10-25 items every week forever, and posts go out
     * stale. Skipping a week is correct behaviour, not a failure.
     */
    public static function queueDepth(array $ctx): array
    {
        $approved = (int) Db::one(
            "SELECT COUNT(*) FROM posts
              WHERE review_status = 'approved' AND lifecycle_state IN ('ready','scheduled')"
        );
        $pending = (int) Db::one("SELECT COUNT(*) FROM topics WHERE review_status = 'pending'");
        $oldest  = Db::one("SELECT MIN(created_at) FROM topics WHERE review_status = 'pending'");
        $ceiling = Settings::int('approved_queue_ceiling', 21);

        return [200, ['data' => [
            'approved_posts_waiting'  => $approved,
            'pending_topics'          => $pending,
            'oldest_pending_topic_at' => Dt::iso($oldest === null ? null : (string) $oldest),
            'ceiling'                 => $ceiling,
            'should_skip'             => $approved >= $ceiling,
            'runway_days'             => $approved,
        ]]];
    }
}
