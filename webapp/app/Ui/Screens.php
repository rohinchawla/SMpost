<?php declare(strict_types=1);
if (!defined('GO_BOOT')) { http_response_code(404); exit; }

/**
 * The screens. Each one reads, or calls ReviewService and redirects.
 *
 * Every mutation is a POST followed by a redirect, so a refresh never repeats an
 * action and every action works with JavaScript switched off.
 */
final class Screens
{
    // ------------------------------------------------------------ gate 1

    public static function topics(array $user, array $seg): void
    {
        $batchUid = $seg[1] ?? null;

        if (Router::method() === 'POST') { self::topicAction($user, $batchUid); return; }

        if ($batchUid === null) {
            View::render('topics-batches', [
                'title'   => 'Topics',
                'user'    => $user,
                'batches' => Db::all(
                    "SELECT b.*,
                            SUM(t.review_status='pending')  AS pending,
                            SUM(t.review_status='approved') AS approved,
                            SUM(t.review_status='rejected') AS rejected,
                            SUM(t.review_status='hold')     AS onhold
                       FROM topic_batches b LEFT JOIN topics t ON t.batch_id = b.id
                      WHERE b.state = 'submitted'
                      GROUP BY b.id ORDER BY b.business_date_ist DESC, b.id DESC"),
            ]);
            return;
        }

        $batch = Db::row('SELECT * FROM topic_batches WHERE batch_uid = ?', [$batchUid]);
        if ($batch === null) throw ApiError::notFound('no such batch');

        $filter = (string) (Router::query()['status'] ?? '');
        $sql = 'SELECT * FROM topics WHERE batch_id = ?';
        $params = [(int) $batch['id']];
        if (in_array($filter, ['pending', 'approved', 'rejected', 'hold'], true)) {
            $sql .= ' AND review_status = ?';
            $params[] = $filter;
        }
        $topics = Db::all($sql . ' ORDER BY position', $params);

        View::render(isset(Router::query()['i']) ? 'topic-one' : 'topics-list', [
            'title'  => 'Review topics',
            'user'   => $user,
            'batch'  => $batch,
            'topics' => $topics,
            'filter' => $filter,
            'index'  => max(0, (int) (Router::query()['i'] ?? 0)),
            'counts' => self::topicCounts((int) $batch['id']),
        ]);
    }

    private static function topicAction(array $user, ?string $batchUid): void
    {
        $uid    = (string) ($_POST['topic_uid'] ?? '');
        $action = (string) ($_POST['action'] ?? '');
        $actor  = (string) $user['email'];
        $back   = (string) ($_POST['back'] ?? View::url('topics/' . rawurlencode((string) $batchUid)));

        if ($action === 'edit') {
            ReviewService::editTopic($uid, [
                'final_title'     => self::text($_POST['final_title'] ?? ''),
                'final_one_liner' => self::text($_POST['final_one_liner'] ?? ''),
            ], $actor);
        } elseif (in_array($action, ['approved', 'rejected', 'hold', 'pending'], true)) {
            ReviewService::setTopicStatus($uid, $action, $actor);
        } else {
            throw ApiError::validation('unknown action');
        }

        Http::redirect($back . '#t-' . rawurlencode($uid));
    }

    private static function topicCounts(int $batchId): array
    {
        $r = Db::row("SELECT SUM(review_status='pending') pending, SUM(review_status='approved') approved,
                             SUM(review_status='rejected') rejected, SUM(review_status='hold') onhold,
                             COUNT(*) total FROM topics WHERE batch_id = ?", [$batchId]);
        return array_map('intval', $r ?? []);
    }

    // ------------------------------------------------------------ gate 2

    public static function posts(array $user, array $seg): void
    {
        $uid = $seg[1] ?? null;
        if (Router::method() === 'POST') { self::postAction($user, $uid); return; }

        if ($uid === null) {
            $filter = (string) (Router::query()['status'] ?? 'waiting');
            View::render('posts-list', [
                'title'  => 'Posts',
                'user'   => $user,
                'posts'  => self::postList($filter),
                'filter' => $filter,
                'tabs'   => self::postTabs(),
            ]);
            return;
        }

        $p = Db::row('SELECT p.*, t.final_title, t.source_url, t.source_quote, t.post_type, t.theme_tag,
                             t.source_domain, t.source_published_on
                        FROM posts p JOIN topics t ON t.id = p.topic_id WHERE p.post_uid = ?', [$uid]);
        if ($p === null) throw ApiError::notFound('no such post');

        View::render('post-review', [
            'title'   => 'Review post',
            'user'    => $user,
            'p'       => $p,
            'images'  => Db::all('SELECT * FROM post_images WHERE post_id = ? ORDER BY option_index', [(int) $p['id']]),
            'trace'   => Provenance::scan($p),
            'stale'   => ReviewService::isStale($p),
            'history' => Db::all("SELECT * FROM review_actions WHERE entity_type='post' AND entity_uid=?
                                   ORDER BY acted_at DESC LIMIT 10", [$uid]),
        ]);
    }

    private static function postAction(array $user, ?string $uid): void
    {
        $action = (string) ($_POST['action'] ?? '');
        $actor  = (string) $user['email'];
        $uid    = (string) ($_POST['post_uid'] ?? $uid);

        if ($action === 'save') {
            ReviewService::editPost($uid, [
                'final_body'         => self::text($_POST['final_body'] ?? ''),
                'final_hashtags'     => self::parseTags((string) ($_POST['hashtags'] ?? '')),
                'final_cta_text'     => ($_POST['cta_text'] ?? '') ?: null,
                'final_cta_target'   => ($_POST['cta_target'] ?? '') ?: null,
                'first_comment_text' => self::text($_POST['first_comment'] ?? '') ?: null,
                'scheduled_date_ist' => ($_POST['scheduled_date_ist'] ?? '') ?: null,
            ], $actor);
        } elseif ($action === 'approved') {
            ReviewService::approvePost($uid, (int) ($_POST['select_image'] ?? 1), $actor,
                ($_POST['scheduled_date_ist'] ?? '') ?: null, null, ($_POST['note'] ?? '') ?: null);
        } elseif (in_array($action, ['rejected', 'hold', 'pending'], true)) {
            ReviewService::setPostStatus($uid, $action, $actor, ($_POST['note'] ?? '') ?: null);
        } else {
            throw ApiError::validation('unknown action');
        }

        // Say so. Editing a CTA target changes nothing you can see on the page,
        // and a save that looks identical to a save that failed is worse than
        // no save button at all.
        $_SESSION['go_flash'][] = ['level' => 'ok', 'text' => match ($action) {
            'save'     => 'Saved. Nothing is published by this button.',
            'approved' => 'Approved. It is in the publishing queue now.',
            'rejected' => 'Rejected. It will not be published.',
            'hold'     => 'On hold. It stays out of the queue until you decide.',
            default    => 'Back to waiting for you.',
        }];

        Http::redirect(View::url('posts/' . rawurlencode($uid)));
    }

    /**
     * A textarea comes back from the browser with CRLF line endings - that is
     * what the HTML form spec requires - while everything A2 wrote uses LF. Left
     * alone, one human edit silently rewrites every newline in the post, and the
     * bytes LinkedIn receives stop matching the bytes that were reviewed. Strip
     * the carriage returns at the door, which is the only place they enter.
     */
    private static function text(mixed $raw): string
    {
        return str_replace(chr(13), '', (string) $raw);
    }

    /** Accepts "#a #b" or "a, b" and normalises to the stored form. */
    private static function parseTags(string $raw): array
    {
        $parts = preg_split('/[\s,]+/', trim($raw)) ?: [];
        $out = [];
        foreach ($parts as $t) {
            if ($t === '') continue;
            $out[] = str_starts_with($t, '#') ? $t : '#' . $t;
        }
        return $out;
    }

    private static function postTabs(): array
    {
        return [
            'waiting'   => (int) Db::one("SELECT COUNT(*) FROM posts WHERE lifecycle_state='in_review' AND review_status='pending'"),
            'approved'  => (int) Db::one("SELECT COUNT(*) FROM posts WHERE review_status='approved' AND lifecycle_state IN ('ready','scheduled')"),
            'published' => (int) Db::one("SELECT COUNT(*) FROM posts WHERE lifecycle_state='posted'"),
            'hold'      => (int) Db::one("SELECT COUNT(*) FROM posts WHERE review_status='hold'"),
            'rejected'  => (int) Db::one("SELECT COUNT(*) FROM posts WHERE review_status='rejected'"),
            'all'       => (int) Db::one('SELECT COUNT(*) FROM posts'),
        ];
    }

    private static function postList(string $filter): array
    {
        // 'published' filters the machine lifecycle, not the verdict: the owner
        // thinks in "published" and the database keeps the verdict at approved.
        $where = match ($filter) {
            'approved'  => "p.review_status='approved' AND p.lifecycle_state IN ('ready','scheduled')",
            'published' => "p.lifecycle_state='posted'",
            'hold'      => "p.review_status='hold'",
            'rejected'  => "p.review_status='rejected'",
            'all'       => '1=1',
            default     => "p.lifecycle_state='in_review' AND p.review_status='pending'",
        };
        return Db::all(
            "SELECT p.*, t.final_title,
                    (SELECT COUNT(*) FROM post_images i WHERE i.post_id = p.id) image_count
               FROM posts p JOIN topics t ON t.id = p.topic_id
              WHERE {$where} ORDER BY p.updated_at DESC LIMIT 200"
        );
    }

    // ------------------------------------------------------------ the queue

    /**
     * What publishes next, in A5's own order.
     *
     * Dashboard::queue is the same function A5 uses to pick tomorrow's post, so
     * this screen cannot drift from the machine's answer - a queue that shows a
     * different order from the one that runs is worse than no queue at all.
     */
    public static function queue(array $user): void
    {
        if (Router::method() === 'POST') { self::queueAction($user); return; }

        View::render('queue', [
            'title'  => 'Publishing queue',
            'user'   => $user,
            'queue'  => Dashboard::queue(30),
            'runway' => Dashboard::runwayDays(),
        ]);
    }

    /**
     * Moving a post to a date.
     *
     * Goes through ReviewService::editPost rather than an UPDATE here, so the
     * change is recorded in field_edits and the refusal to edit something already
     * live is the same refusal the review screen gives.
     */
    private static function queueAction(array $user): void
    {
        if ((string) ($_POST['action'] ?? '') !== 'date') throw ApiError::validation('unknown action');

        $uid = (string) ($_POST['post_uid'] ?? '');
        ReviewService::editPost($uid, [
            'scheduled_date_ist' => ($_POST['scheduled_date_ist'] ?? '') ?: null,
        ], (string) $user['email']);

        Http::redirect(View::url('queue') . '#q-' . rawurlencode($uid));
    }

    // ------------------------------------------------------------ analytics

    /**
     * Impressions and shares over time, for one post or for all of them.
     *
     * The rows are handed to the view unsmoothed and ungapfilled. Charts draws
     * the holes; nothing here invents a reading for a day A6 could not collect.
     */
    public static function analytics(array $user, array $seg): void
    {
        $uid   = $seg[1] ?? null;
        $range = (int) (Router::query()['range'] ?? 90);
        if (!in_array($range, [30, 90, 183], true)) $range = 90;

        $to   = Ist::date();
        $from = Ist::datePlusDays(-($range - 1));

        $selected = null;
        if ($uid !== null) {
            $selected = Db::row('SELECT p.*, t.final_title FROM posts p JOIN topics t ON t.id = p.topic_id
                                  WHERE p.post_uid = ?', [$uid]);
            if ($selected === null) throw ApiError::notFound('no such post');
        }

        $rows = $selected === null ? self::metricsAllPosts($from, $to)
                                   : Db::all('SELECT * FROM post_metrics_daily
                                               WHERE post_id = ? AND metric_date_ist BETWEEN ? AND ?
                                               ORDER BY metric_date_ist',
                                             [(int) $selected['id'], $from, $to]);

        View::render('analytics', [
            'title'    => 'Performance',
            'user'     => $user,
            'posts'    => self::postedWithLatestMetrics(),
            'rows'     => $rows,
            'range'    => $range,
            'selected' => $selected,
            'gaps'     => Charts::gapList($rows, $from, $to),
            'from'     => $from,
            'to'       => $to,
        ]);
    }

    /**
     * Every post that went out, with its most recent real reading.
     *
     * The join is to the latest date that actually has impressions, not to
     * MAX(metric_date_ist): A6 writes a row with NULL metrics and a gap_reason
     * when it cannot look, and joining to that would report the post as having
     * lost its numbers overnight.
     */
    private static function postedWithLatestMetrics(): array
    {
        return Db::all(
            "SELECT p.post_uid, p.final_hook, p.posted_date_ist, p.linkedin_permalink, p.linkedin_urn,
                    t.final_title, t.theme_tag,
                    m.metric_date_ist AS last_metric_date, m.impressions, m.shares
               FROM posts p
               JOIN topics t ON t.id = p.topic_id
               LEFT JOIN post_metrics_daily m
                      ON m.post_id = p.id
                     AND m.metric_date_ist = (SELECT MAX(m2.metric_date_ist) FROM post_metrics_daily m2
                                               WHERE m2.post_id = p.id AND m2.impressions IS NOT NULL)
              WHERE p.lifecycle_state = 'posted'
              ORDER BY p.posted_date_ist DESC, p.id DESC"
        );
    }

    /**
     * All posts, one row per day.
     *
     * SUM() over a day where every post is NULL is itself NULL, which is what
     * keeps a day nobody could collect a gap rather than a crash to zero. The
     * gap_reason is carried through only when the day has no impressions at all,
     * so a reason never labels a day that does have a reading.
     */
    private static function metricsAllPosts(string $from, string $to): array
    {
        return Db::all(
            "SELECT metric_date_ist,
                    SUM(impressions) AS impressions,
                    SUM(shares)      AS shares,
                    SUM(reactions)   AS reactions,
                    SUM(comments)    AS comments,
                    SUM(clicks)      AS clicks,
                    COUNT(*)         AS posts_counted,
                    CASE WHEN SUM(impressions) IS NULL THEN MAX(gap_reason) END AS gap_reason
               FROM post_metrics_daily
              WHERE metric_date_ist BETWEEN ? AND ?
              GROUP BY metric_date_ist
              ORDER BY metric_date_ist",
            [$from, $to]
        );
    }

    // ------------------------------------------------------------------ ops

    /**
     * The machine room: runs, dead letters, keys, and the hash self-test.
     *
     * The self-test runs on every load rather than on a button, because the state
     * it detects - an approved post that will silently refuse to publish - is one
     * nobody would think to go and check for.
     */
    public static function ops(array $user, array $seg): void
    {
        if (Router::method() === 'POST') { self::opsAction($user); return; }

        if (($seg[1] ?? '') === 'runs' && isset($seg[2])) {
            $run = Db::row('SELECT * FROM agent_runs WHERE run_uid = ?', [(string) $seg[2]]);
            if ($run === null) throw ApiError::notFound('no such run');

            View::render('ops-run', [
                'title'  => 'Run log',
                'user'   => $user,
                'run'    => $run,
                'events' => Db::all('SELECT * FROM run_events WHERE run_id = ? ORDER BY seq', [(int) $run['id']]),
            ]);
            return;
        }

        View::render('ops', [
            'title'       => 'Ops',
            'user'        => $user,
            'alerts'      => Dashboard::alerts(),
            'runs'        => Db::limit(
                "SELECT r.*, (SELECT COUNT(*) FROM run_events e WHERE e.run_id = r.id AND e.level = 'error') AS error_events
                   FROM agent_runs r ORDER BY r.id DESC LIMIT ?", [], 50)->fetchAll(),
            'deadLetters' => Db::all("SELECT * FROM dead_letters
                                      ORDER BY (status = 'open') DESC, (status = 'retrying') DESC, last_seen_at DESC"),
            'keys'        => ApiKeys::listAll(),
            'hash'        => ContentHash::selfTest(),
        ]);
    }

    private static function opsAction(array $user): void
    {
        $action = (string) ($_POST['action'] ?? '');
        $actor  = (string) $user['email'];

        if ($action === 'revoke-key') {
            $agent = (string) ($_POST['agent_code'] ?? '');
            if (!array_key_exists($agent, ApiKeys::SCOPES)) throw ApiError::validation('unknown agent');
            ApiKeys::revoke($agent);
            Http::redirect(View::url('ops') . '#keys');
            return;
        }

        $status = match ($action) {
            'dl-resolve' => 'resolved',
            'dl-ignore'  => 'ignored',
            'dl-retry'   => 'retrying',
            default      => throw ApiError::validation('unknown action'),
        };

        $uid = (string) ($_POST['dl_uid'] ?? '');

        // resolved_at is stamped only for a verdict that ends the item. 'retrying'
        // is still open work, and dating it would make the ops list claim the
        // failure was dealt with when nothing has run yet.
        $n = Db::exec(
            "UPDATE dead_letters
                SET status = ?, resolved_by = ?, resolution_note = ?,
                    resolved_at = CASE WHEN ? IN ('resolved','ignored') THEN UTC_TIMESTAMP(3) ELSE NULL END
              WHERE dl_uid = ?",
            [$status, $actor, ($_POST['note'] ?? '') ?: null, $status, $uid]
        );
        if ($n === 0) throw ApiError::notFound('no such dead letter');

        Http::redirect(View::url('ops') . '#dl-' . rawurlencode($uid));
    }

    // ------------------------------------------------------------- settings

    public static function settings(array $user): void
    {
        if (Router::method() === 'POST') { self::settingsAction($user); return; }

        View::render('settings', [
            'title'    => 'Settings',
            'user'     => $user,
            'settings' => Settings::all(),
            'config'   => Settings::configPayload(),
        ]);
    }

    /**
     * The two settings that are safe to change from a screen.
     *
     * Everything else in app_settings is a contract number the agents and the
     * database CHECK constraints agree on, so it is shown and not edited: raising
     * max_body_words here would leave chk_posts_wordcount refusing the row.
     */
    private static function settingsAction(array $user): void
    {
        $actor = (string) $user['email'];

        $time = trim((string) ($_POST['default_post_time_ist'] ?? ''));
        if ($time !== '') {
            if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
                throw ApiError::validation('the posting time must be HH:MM on the 24-hour clock, in IST');
            }
            Settings::set('default_post_time_ist', $time, $actor);
        }

        $raw = trim((string) ($_POST['approved_queue_ceiling'] ?? ''));
        if ($raw !== '') {
            if (preg_match('/^\d{1,3}$/', $raw) !== 1) throw ApiError::validation('the queue ceiling must be a whole number');
            $ceiling = (int) $raw;
            // Below 1 there is no runway to protect; above 365 the ceiling would
            // never bite and A1 would research forever.
            if ($ceiling < 1 || $ceiling > 365) throw ApiError::validation('the queue ceiling must be between 1 and 365');
            Settings::set('approved_queue_ceiling', $ceiling, $actor);
        }

        Http::redirect(View::url('settings'));
    }
}
