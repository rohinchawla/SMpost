<?php declare(strict_types=1);
if (!defined('GO_BOOT')) { http_response_code(404); exit; }

/**
 * The numbers the owner and the ops screen need, and the alerts that matter.
 *
 * @mock mock-api/server.mjs:903-915 (M.state) for the shape the test suite reads
 */
final class Dashboard
{
    /** The whole-system snapshot. Used by the ops screen and by the test suite. */
    public static function state(): array
    {
        return [
            'runs' => Db::all("SELECT agent_code, status, business_date_ist, items_in, items_ok, items_failed, skip_reason
                                 FROM agent_runs ORDER BY id"),
            'batches' => Db::all('SELECT batch_uid, state, topic_count, business_date_ist FROM topic_batches ORDER BY id'),
            'topic_counts' => Db::all('SELECT review_status, pipeline_state, COUNT(*) n FROM topics GROUP BY 1,2'),
            'post_counts'  => Db::all('SELECT review_status, lifecycle_state, COUNT(*) n FROM posts GROUP BY 1,2'),
            'images'       => (int) Db::one('SELECT COUNT(*) FROM post_images'),
            'metrics_rows' => (int) Db::one('SELECT COUNT(*) FROM post_metrics_daily'),
            'dead_letters' => Db::all('SELECT agent_code, stage, error_code, attempts, status FROM dead_letters ORDER BY id'),
        ];
    }

    /** How many days of publishing are already approved and waiting. */
    public static function runwayDays(): int
    {
        return (int) Db::one("SELECT COUNT(*) FROM posts
                               WHERE review_status='approved' AND lifecycle_state IN ('ready','scheduled')");
    }

    public static function counts(): array
    {
        return [
            'topics_pending' => (int) Db::one("SELECT COUNT(*) FROM topics WHERE review_status='pending'"),
            'posts_waiting'  => (int) Db::one("SELECT COUNT(*) FROM posts WHERE lifecycle_state='in_review' AND review_status='pending'"),
            'runway'         => self::runwayDays(),
            'published'      => (int) Db::one("SELECT COUNT(*) FROM posts WHERE lifecycle_state='posted'"),
        ];
    }

    /**
     * The things worth interrupting someone for.
     *
     * Deliberately few. A screen of amber badges trains you to ignore all of
     * them, so only two states are ever rendered as a failure: a run that failed,
     * and no runway at all.
     */
    public static function alerts(): array
    {
        $a = [];
        $today = Ist::date();

        $lastPost = Db::one("SELECT MAX(posted_date_ist) FROM posts WHERE lifecycle_state='posted'");
        if ($lastPost !== null && Ist::daysBetween((string) $lastPost, $today) > 2) {
            $a[] = ['level' => 'warn', 'text' => 'Nothing has gone out since ' . self::prettyDate((string) $lastPost) . '.',
                    'action' => ['Check the runs', 'ops']];
        }

        $oldest = Db::one("SELECT MIN(created_at) FROM topics WHERE review_status='pending'");
        if ($oldest !== null) {
            $days = (int) floor((time() - strtotime((string) $oldest . ' UTC')) / 86400);
            if ($days >= 7) {
                $n = (int) Db::one("SELECT COUNT(*) FROM topics WHERE review_status='pending'");
                $a[] = ['level' => 'warn',
                        'text' => "{$n} topics have been waiting {$days} days.",
                        'action' => ['Review them', 'topics']];
            }
        }

        // A run that succeeded and produced nothing is the failure that hides for
        // weeks, because the dashboard shows what exists rather than what is missing.
        foreach (Db::all("SELECT agent_code, business_date_ist FROM agent_runs
                           WHERE status='succeeded' AND items_ok=0 AND items_in=0
                             AND skip_reason IS NULL ORDER BY id DESC LIMIT 3") as $r) {
            $a[] = ['level' => 'error',
                    'text' => "{$r['agent_code']} finished on " . self::prettyDate((string) $r['business_date_ist'])
                              . ' and produced nothing. That is a failure wearing a success badge.',
                    'action' => ['Open the log', 'ops']];
        }

        $runway = self::runwayDays();
        if ($runway === 0) {
            $a[] = ['level' => 'error', 'text' => 'No approved posts. Tomorrow morning there is nothing to publish.',
                    'action' => ['Review packages', 'posts']];
        } elseif ($runway >= Settings::int('approved_queue_ceiling', 21)) {
            $a[] = ['level' => 'warn',
                    'text' => "{$runway} approved posts, at the ceiling. A1 will skip its next research run.",
                    'action' => ['See the queue', 'queue']];
        }

        $stale = (int) Db::one("SELECT COUNT(*) FROM posts WHERE review_status='approved' AND approved_content_hash IS NOT NULL");
        if ($stale > 0) {
            $bad = ContentHash::selfTest();
            if ($bad['mismatched'] !== []) {
                $n = count($bad['mismatched']);
                $a[] = ['level' => 'warn',
                        'text' => "{$n} approved " . ($n === 1 ? 'post was' : 'posts were')
                                  . ' edited after approval, so ' . ($n === 1 ? 'it' : 'they') . ' will not publish.',
                        'action' => ['Approve again', 'posts?status=approved']];
            }
        }

        return $a;
    }

    /** The publishing order, exactly as A5 computes it. */
    public static function queue(int $limit = 20): array
    {
        $today = Ist::date();
        $rows = Db::limit(
            "SELECT p.*, t.final_title FROM posts p JOIN topics t ON t.id = p.topic_id
              WHERE p.review_status='approved' AND p.selected_image_id IS NOT NULL
                AND p.lifecycle_state IN ('ready','scheduled','failed')
                AND (p.expires_at_ist IS NULL OR p.expires_at_ist >= ?)
              ORDER BY CASE p.lifecycle_state WHEN 'failed' THEN 0 WHEN 'scheduled' THEN 1 ELSE 2 END,
                       p.scheduled_date_ist IS NULL, p.scheduled_date_ist, p.id
              LIMIT ?",
            [$today], $limit
        )->fetchAll();

        foreach ($rows as &$r) {
            $r['queue_reason'] = match ($r['lifecycle_state']) {
                'failed'    => 'retry of a failed publish',
                'scheduled' => 'scheduled for ' . self::prettyDate((string) $r['scheduled_date_ist']),
                default     => 'oldest approved',
            };
        }
        return $rows;
    }

    public static function prettyDate(?string $ymd): string
    {
        if ($ymd === null || $ymd === '') return '-';
        $t = strtotime($ymd . ' UTC');
        return $t === false ? $ymd : gmdate('j M', $t);
    }
}
