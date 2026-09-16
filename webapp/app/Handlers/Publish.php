<?php declare(strict_types=1);
if (!defined('GO_BOOT')) { http_response_code(404); exit; }

/**
 * A5. The only agent that can write to the company page.
 *
 * @mock mock-api/server.mjs:666-798
 *
 * A5 has no text generation and no discretion: it asks what to publish, takes a
 * lease, does the LinkedIn call, and reports back. Every decision - which post,
 * whether it is still allowed to go out, what exact string is sent - is made
 * here, server-side, where it can be tested.
 */
final class Publish
{
    /** Retries stop here. Five attempts is the whole budget for one post. */
    private const MAX_ATTEMPTS = 5;

    /**
     * @mock server.mjs:666-691 (publishQueueNext)
     *
     * Selection order, decided server-side so A5 has none:
     *   1. yesterday's failure, retried first
     *   2. anything the owner dated for today or earlier, earliest first
     *   3. otherwise the oldest approved post with no date
     * A future-dated post is simply not returned. No timer, no scheduler table.
     */
    public static function publishQueueNext(array $ctx): array
    {
        $today = !empty($ctx['query']['date']) ? (string) $ctx['query']['date'] : Ist::date();

        $base = "SELECT p.*, t.final_title FROM posts p JOIN topics t ON t.id = p.topic_id
                  WHERE p.review_status = 'approved' AND p.selected_image_id IS NOT NULL";
        $notExpired = 'AND (p.expires_at_ist IS NULL OR p.expires_at_ist >= ?)';

        // One post per day, full stop - and this is checked before anything is
        // selected, so a second A5 run at 08:05 gets 204 even with a queue behind
        // it. Two posts in one day is the failure the owner would notice first.
        $postedToday = (int) Db::one('SELECT COUNT(*) FROM posts WHERE posted_date_ist = ?', [$today]);
        if ($postedToday > 0) return [204, null];

        $row = Db::row("{$base} AND p.lifecycle_state = 'failed' AND p.publish_attempts < ? {$notExpired}
                        ORDER BY p.id LIMIT 1", [self::MAX_ATTEMPTS, $today]);
        $reason = 'retry_previous_failure';

        if ($row === null) {
            $row = Db::row("{$base} AND p.lifecycle_state = 'scheduled' AND p.scheduled_date_ist <= ? {$notExpired}
                            ORDER BY p.scheduled_date_ist, p.id LIMIT 1", [$today, $today]);
            $reason = 'scheduled_for_today';
        }
        if ($row === null) {
            $row = Db::row("{$base} AND p.lifecycle_state = 'ready' AND p.scheduled_date_ist IS NULL {$notExpired}
                            ORDER BY p.id LIMIT 1", [$today]);
            $reason = 'oldest_approved';
        }
        if ($row === null) return [204, null];

        return [200, ['data' => [
            'post_uid'          => $row['post_uid'],
            'reason'            => $reason,
            'scheduled_date_ist' => $row['scheduled_date_ist'],
            'publish_attempts'  => (int) $row['publish_attempts'],
            'topic_title'       => $row['final_title'],
        ]]];
    }

    /**
     * @mock server.mjs:697-743 (publishLease)
     *
     * The most dangerous function in the system. Two A5 runs that both believe
     * they hold the lease put the same post on the company page twice, and there
     * is no undo that the audience does not see.
     *
     * So the row is taken with FOR UPDATE and the lease is claimed by an UPDATE
     * whose WHERE clause repeats the free-lease condition. The mock's read-then-
     * write is a race on a real server; here the second run either blocks on the
     * lock or matches zero rows, and both outcomes end in LEASE_HELD_BY_OTHER_RUN.
     *
     * Taking the lease is also the moment every publish precondition is
     * re-checked. If the owner put the post on hold at 07:55, hold wins at 08:00.
     */
    public static function publishLease(array $ctx): array
    {
        $b = $ctx['body'];
        $uid = (string) ($ctx['params']['postUid'] ?? '');

        return Db::tx(static function () use ($b, $uid) {
            // No join: locking topics as well would serialise A2 behind A5 for no
            // reason, and the lease payload does not carry the title anyway.
            $p = Db::row('SELECT * FROM posts WHERE post_uid = ? FOR UPDATE', [$uid]);
            if ($p === null) throw ApiError::notFound('post not found');

            if ($p['lifecycle_state'] === 'posted') {
                throw ApiError::conflict('ALREADY_POSTED', 'this post is already live on LinkedIn',
                    ['linkedin_urn' => $p['linkedin_urn'], 'posted_at' => Dt::iso($p['posted_at_utc'])]);
            }
            if ($p['review_status'] !== 'approved') {
                throw ApiError::unprocessable('POST_NOT_APPROVED',
                    "post is '{$p['review_status']}', not approved", ['review_status' => $p['review_status']]);
            }
            if (empty($p['selected_image_id'])) {
                throw ApiError::unprocessable('NO_IMAGE_SELECTED', 'the owner has not chosen an image');
            }
            if (!empty($p['expires_at_ist']) && $p['expires_at_ist'] < Ist::date()) {
                throw ApiError::unprocessable('POST_EXPIRED', "this package expired on {$p['expires_at_ist']}");
            }
            // ContentHash::atLease decodes the stored column before composing.
            // Re-deriving the string here from the raw JSON would reintroduce the
            // exact bug its docblock exists to prevent.
            if (!empty($p['approved_content_hash'])
                && !hash_equals((string) $p['approved_content_hash'], ContentHash::atLease($p))) {
                throw ApiError::conflict('CONTENT_CHANGED_AFTER_APPROVAL',
                    'the post was edited after approval; re-approve before publishing');
            }

            $now = Dt::nowDb();
            if (!empty($p['publish_lease_expires_at']) && $p['publish_lease_expires_at'] > $now) {
                throw ApiError::conflict('LEASE_HELD_BY_OTHER_RUN', 'another run is publishing this post');
            }

            $r = Db::row('SELECT id FROM agent_runs WHERE run_uid = ?', [$b['run_uid'] ?? '']);
            $token = Uuid::v4();
            $seconds = (int) ($b['lease_seconds'] ?? 900);
            $expires = Dt::plusSecondsDb($seconds);

            $claimed = Db::exec(
                "UPDATE posts SET lifecycle_state='publishing', publish_lease_token=?, publish_lease_expires_at=?,
                        publish_run_id=?, updated_at=?
                  WHERE id=? AND (publish_lease_expires_at IS NULL OR publish_lease_expires_at <= ?)",
                [$token, $expires, $r === null ? null : (int) $r['id'], $now, (int) $p['id'], $now]
            );
            if ($claimed === 0) {
                throw ApiError::conflict('LEASE_HELD_BY_OTHER_RUN', 'another run is publishing this post');
            }

            $img = Db::row('SELECT * FROM post_images WHERE id = ?', [(int) $p['selected_image_id']]);
            if ($img === null) throw ApiError::unprocessable('NO_IMAGE_SELECTED', 'the selected image is missing');

            $tags = Canon::decode($p['final_hashtags'] ?? null, []);
            // The server composes the exact string sent to LinkedIn, so it is
            // decided in one place and hashed once for the audit record.
            $commentary = Words::commentary((string) $p['final_body'], is_array($tags) ? $tags : []);

            return [200, ['data' => [
                'post_uid'      => $p['post_uid'],
                'lease_token'   => $token,
                'lease_expires_at' => Dt::iso($expires),
                'attempt_no'    => (int) $p['publish_attempts'] + 1,
                'linkedin'      => [
                    'organization_urn' => Settings::str('linkedin_org_urn'),
                    'visibility'       => 'PUBLIC',
                    'media_category'   => 'IMAGE',
                ],
                'commentary'        => $commentary,
                'commentary_sha256' => hash('sha256', $commentary),
                'first_comment_text' => $p['first_comment_text'],
                'image' => [
                    'image_uid'  => $img['image_uid'],
                    'public_url' => $img['public_url'],
                    'sha256'     => $img['sha256'],
                    'mime_type'  => $img['mime_type'],
                    'bytes'      => (int) $img['bytes'],
                    'alt_text'   => $img['alt_text'],
                ],
                // No attempt number here, deliberately. This key is per post per
                // day, so every attempt of today's publish carries the same one
                // and LinkedIn itself deduplicates a retry we are unsure about.
                // publishResult's default key is per attempt, because there the
                // point is the opposite: each attempt must record its own row.
                'idempotency_key' => hash('sha256', "A5:publish:{$p['post_uid']}:" . Ist::date()),
            ]]];
        });
    }

    /**
     * @mock server.mjs:745-798 (publishResult)
     *
     * What A5 reports is what the row becomes. The attempt number is derived
     * under the same lock that writes it, so two reports cannot both claim to be
     * attempt 3 and quietly overwrite each other's outcome.
     */
    public static function publishResult(array $ctx): array
    {
        $b = $ctx['body'];
        $uid = (string) ($ctx['params']['postUid'] ?? '');

        $outcome = (string) ($b['outcome'] ?? '');
        // publish_attempts.outcome is an ENUM. The mock's SQLite accepts any
        // string; MySQL under STRICT_ALL_TABLES rejects it, and a 500 INTERNAL
        // reads as "retry" to A5 when the truth is "your request is malformed".
        if (!in_array($outcome, ['success', 'dry_run', 'retryable_error', 'permanent_error', 'skipped'], true)) {
            throw ApiError::validation('outcome is not a recognised value', ['outcome' => $b['outcome'] ?? null]);
        }

        return Db::tx(static function () use ($b, $uid, $outcome) {
            $p = Db::row('SELECT * FROM posts WHERE post_uid = ? FOR UPDATE', [$uid]);
            if ($p === null) throw ApiError::notFound('post not found');

            if (!empty($b['lease_token']) && !empty($p['publish_lease_token'])
                && !hash_equals((string) $p['publish_lease_token'], (string) $b['lease_token'])) {
                throw ApiError::conflict('LEASE_HELD_BY_OTHER_RUN', 'lease token does not match');
            }

            // publish_attempts.run_id is NOT NULL with a foreign key, so unlike
            // the mock this cannot fall back to null.
            $r = Views::requireRun($b['run_uid'] ?? null);

            $attempt = (int) $p['publish_attempts'] + 1;
            $idem = !empty($b['idempotency_key'])
                ? (string) $b['idempotency_key']
                : hash('sha256', "A5:publish:{$p['post_uid']}:" . Ist::date() . ":{$attempt}");

            try {
                Db::exec(
                    'INSERT INTO publish_attempts (post_id, run_id, attempt_no, idempotency_key, outcome, http_status,
                       linkedin_urn, error_code, error_message, request_snapshot)
                     VALUES (?,?,?,?,?,?,?,?,?,?)',
                    [
                        (int) $p['id'], (int) $r['id'], $attempt, $idem, $outcome,
                        isset($b['http_status']) ? (int) $b['http_status'] : null,
                        $b['linkedin_urn'] ?? null, $b['error_code'] ?? null, $b['error_message'] ?? null,
                        Canon::encode($b['request_snapshot'] ?? []),
                    ]
                );
            } catch (PDOException $e) {
                if (!Db::isDuplicate($e)) throw $e;
                // A replayed result for an attempt already recorded is a no-op,
                // not a second post. Nothing is mutated, so the post keeps
                // whatever state the first report left it in.
                return [200, ['data' => [
                    'post_uid'        => $p['post_uid'],
                    'lifecycle_state' => $p['lifecycle_state'],
                    'replayed'        => true,
                ]]];
            }

            $now = Dt::nowDb();

            if ($outcome === 'success' || $outcome === 'dry_run') {
                // One branch, two very different rows. A dry run must leave every
                // LinkedIn field null: a permalink recorded for a post that was
                // never sent would make A6 collect metrics for a URN that does
                // not exist, and would tell the owner it went out.
                $live = $outcome === 'success';
                $watch = Ist::datePlusDays(Settings::int('metrics_window_days', 183));

                Db::exec(
                    'UPDATE posts SET lifecycle_state=?, posted_at_utc=?, posted_date_ist=?, linkedin_urn=?,
                            linkedin_permalink=?, first_comment_urn=?, metrics_watch_until_ist=?, publish_attempts=?,
                            publish_lease_token=NULL, publish_lease_expires_at=NULL, updated_at=? WHERE id=?',
                    [
                        $live ? 'posted' : 'ready',
                        $live ? self::dbTimestamp($b['posted_at'] ?? null) : null,
                        $live ? Ist::date() : null,
                        $live ? ($b['linkedin_urn'] ?? null) : null,
                        $live ? ($b['linkedin_permalink'] ?? null) : null,
                        $live ? ($b['first_comment_urn'] ?? null) : null,
                        $live ? $watch : null,
                        $attempt, $now, (int) $p['id'],
                    ]
                );

                return [200, ['data' => [
                    'post_uid'        => $p['post_uid'],
                    'lifecycle_state' => $live ? 'posted' : 'ready',
                    'dry_run'         => !$live,
                    'posted_date_ist' => $live ? Ist::date() : null,
                    'metrics_watch_until_ist' => $live ? $watch : null,
                ]]];
            }

            Db::exec(
                "UPDATE posts SET lifecycle_state='failed', publish_attempts=?, last_error_code=?, last_error_message=?,
                        publish_lease_token=NULL, publish_lease_expires_at=NULL, updated_at=? WHERE id=?",
                [$attempt, $b['error_code'] ?? null, $b['error_message'] ?? null, $now, (int) $p['id']]
            );

            return [200, ['data' => [
                'post_uid'         => $p['post_uid'],
                'lifecycle_state'  => 'failed',
                'publish_attempts' => $attempt,
                // The queue enforces the same bound. This field only tells A5
                // whether tomorrow's run will pick the post up again.
                'will_retry'       => $attempt < self::MAX_ATTEMPTS,
            ]]];
        });
    }

    /**
     * An ISO-8601 instant from A5, as a DATETIME(3) bind value.
     *
     * The mock stores the string it is handed. MySQL rejects the trailing 'Z'
     * under strict mode, and an unparseable value must not become a silent NULL
     * in posted_at_utc - that column is the audit answer to "when did this go
     * out" - so now() is the fallback, which is what the mock uses when the
     * field is absent.
     */
    private static function dbTimestamp(mixed $iso): string
    {
        if (!is_string($iso) || $iso === '') return Dt::nowDb();
        try {
            return (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
        } catch (Exception) {
            return Dt::nowDb();
        }
    }
}
