<?php declare(strict_types=1);
if (!defined('GO_BOOT')) { http_response_code(404); exit; }

/**
 * A6. Read-only on LinkedIn, and never allowed to estimate a number.
 *
 * @mock mock-api/server.mjs:802-888
 *
 * Two rules carry all the weight in this file, and both exist because a wrong
 * number here is worse than a missing one - it survives into the six-month trend
 * the owner makes decisions from:
 *
 *  - a metric the API did not return is stored NULL, never 0. A zero reads as
 *    "the post died" rather than "we could not look", and it would corrupt every
 *    day-over-day delta computed after it.
 *  - cumulative counters never regress. A partial read returning 40 impressions
 *    for a post that already showed 812 keeps 812 and counts the anomaly.
 */
final class Metrics
{
    /** @mock server.mjs:802-818 (publishedPosts) */
    public static function publishedPosts(array $ctx): array
    {
        $since = !empty($ctx['query']['since'])
            ? (string) $ctx['query']['since']
            : Ist::datePlusDays(-Settings::int('metrics_window_days', 183));

        $rows = Db::all(
            "SELECT p.*, t.final_title FROM posts p JOIN topics t ON t.id = p.topic_id
              WHERE p.lifecycle_state = 'posted' AND p.posted_date_ist >= ?
              ORDER BY p.posted_date_ist DESC",
            [$since]
        );

        $today = Ist::date();
        $data = [];
        foreach ($rows as $p) {
            $last = Db::row(
                'SELECT * FROM post_metrics_daily WHERE post_id = ? ORDER BY metric_date_ist DESC LIMIT 1',
                [(int) $p['id']]
            );
            $data[] = [
                'post_uid'   => $p['post_uid'],
                'topic_title' => $p['final_title'],
                'linkedin_urn' => $p['linkedin_urn'],
                'linkedin_permalink' => $p['linkedin_permalink'],
                'posted_date_ist' => $p['posted_date_ist'],
                'age_days'   => Ist::daysBetween((string) $p['posted_date_ist'], $today),
                'last_metric_date_ist' => $last['metric_date_ist'] ?? null,
                // A stored NULL has to survive the cast. (int) null is 0, which
                // is the one value this whole file exists to never invent.
                'last_impressions' => self::intOrNull($last['impressions'] ?? null),
                'last_shares'      => self::intOrNull($last['shares'] ?? null),
            ];
        }

        return [200, ['data' => $data, 'page' => ['count' => count($rows), 'window_start_ist' => $since]]];
    }

    /**
     * @mock server.mjs:830-888 (metricsUpsert)
     *
     * One row per post per day, so re-running A6 updates that row rather than
     * inserting a second one. The whole batch is one transaction and each item
     * locks its post row, which is what makes the read-then-upsert safe: without
     * it, two A6 runs firing together both see "no row for today" and the second
     * insert dies on the primary key.
     */
    public static function metricsUpsert(array $ctx): array
    {
        $b = $ctx['body'];
        $date = !empty($b['metric_date_ist']) ? (string) $b['metric_date_ist'] : Ist::date();
        $items = isset($b['items']) && is_array($b['items']) ? $b['items'] : [];

        return Db::tx(static function () use ($b, $date, $items) {
            // collected_by_run_id is NOT NULL with a foreign key, so unlike the
            // mock this cannot fall back to null.
            $r = Views::requireRun($b['run_uid'] ?? null);

            $inserted = 0;
            $updated = 0;
            $skipped = 0;
            $unknown = [];
            $results = [];

            /**
             * The two rules, in four lines. Counted per field, not per item: one
             * bad read that drags three counters backwards is three anomalies,
             * and rolling them into one would hide how bad the read was.
             */
            $keep = static function (mixed $incoming, mixed $current) use (&$skipped): ?int {
                if ($incoming === null) return $current === null ? null : (int) $current;
                if ($current === null) return (int) $incoming;
                if ((int) $incoming < (int) $current) { $skipped++; return (int) $current; }
                return (int) $incoming;
            };

            foreach ($items as $it) {
                $p = Db::row('SELECT id, posted_date_ist FROM posts WHERE post_uid = ? FOR UPDATE',
                    [$it['post_uid'] ?? '']);
                // A uid nobody recognises is reported, not raised: one renamed
                // post must not cost the other forty their snapshot.
                if ($p === null) { $unknown[] = $it['post_uid'] ?? null; continue; }

                $prev = Db::row(
                    'SELECT * FROM post_metrics_daily WHERE post_id = ? AND metric_date_ist < ?
                      ORDER BY metric_date_ist DESC LIMIT 1',
                    [(int) $p['id'], $date]
                );
                $existing = Db::row(
                    'SELECT * FROM post_metrics_daily WHERE post_id = ? AND metric_date_ist = ?',
                    [(int) $p['id'], $date]
                );

                $impressions = $keep($it['impressions'] ?? null, $existing['impressions'] ?? null);
                $shares      = $keep($it['shares'] ?? null, $existing['shares'] ?? null);
                $reactions   = $keep($it['reactions'] ?? null, $existing['reactions'] ?? null);
                $comments    = $keep($it['comments'] ?? null, $existing['comments'] ?? null);
                $clicks      = $keep($it['clicks'] ?? null, $existing['clicks'] ?? null);
                $uniq        = $keep($it['unique_impressions'] ?? null, $existing['unique_impressions'] ?? null);

                // A delta needs both ends. Treating a missing yesterday as zero
                // would turn the first snapshot after a gap into a fake spike.
                $prevImp = self::intOrNull($prev['impressions'] ?? null);
                $prevShr = self::intOrNull($prev['shares'] ?? null);
                $dImp = $impressions !== null && $prevImp !== null ? $impressions - $prevImp : null;
                $dShr = $shares !== null && $prevShr !== null ? $shares - $prevShr : null;

                // posted_date_ist is null for anything not actually published,
                // and an age computed from null would be nonsense rather than
                // missing.
                //
                // A reading dated BEFORE the post went out yields a negative age.
                // SQLite stores that happily, so the Node mock never sees it; a
                // SMALLINT UNSIGNED column under STRICT mode rejects it outright
                // and the whole upsert becomes a 500. Null is the honest value
                // here - the age of a post on a day before it existed is not
                // zero, it is unanswerable.
                $age = $p['posted_date_ist'] === null
                    ? null
                    : Ist::daysBetween((string) $p['posted_date_ist'], $date);
                if ($age !== null && $age < 0) $age = null;

                if ($existing !== null) {
                    Db::exec(
                        'UPDATE post_metrics_daily SET impressions=?, unique_impressions=?, shares=?, reactions=?,
                                comments=?, clicks=?, delta_impressions=?, delta_shares=?, age_days=?, source=?,
                                raw_field_map=?, api_version=?, gap_reason=?, collected_by_run_id=?, collected_at=?,
                                revision = revision + 1
                          WHERE post_id=? AND metric_date_ist=?',
                        [
                            $impressions, $uniq, $shares, $reactions, $comments, $clicks, $dImp, $dShr, $age,
                            $b['source'] ?? 'linkedin_api', Canon::encode($it['raw_field_map'] ?? null),
                            $b['api_version'] ?? null, $it['gap_reason'] ?? null,
                            (int) $r['id'], Dt::nowDb(), (int) $p['id'], $date,
                        ]
                    );
                    $updated++;
                    $action = 'updated';
                } else {
                    Db::exec(
                        'INSERT INTO post_metrics_daily (post_id, metric_date_ist, impressions, unique_impressions,
                           shares, reactions, comments, clicks, delta_impressions, delta_shares, age_days, source,
                           raw_field_map, api_version, gap_reason, collected_by_run_id, collected_at)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                        [
                            (int) $p['id'], $date, $impressions, $uniq, $shares, $reactions, $comments, $clicks,
                            $dImp, $dShr, $age, $b['source'] ?? 'linkedin_api',
                            Canon::encode($it['raw_field_map'] ?? null), $b['api_version'] ?? null,
                            $it['gap_reason'] ?? null, (int) $r['id'], Dt::nowDb(),
                        ]
                    );
                    $inserted++;
                    $action = 'inserted';
                }

                $results[] = [
                    'post_uid' => $it['post_uid'] ?? null,
                    'action'   => $action,
                    'impressions' => $impressions,
                    'shares'   => $shares,
                    'delta_impressions' => $dImp,
                ];
            }

            return [200, ['data' => [
                'metric_date_ist'   => $date,
                'inserted'          => $inserted,
                'updated'           => $updated,
                'skipped_regression' => $skipped,
                'unknown_post_uids' => $unknown,
                'results'           => $results,
            ]]];
        });
    }

    /** (int) on a NULL gives 0, and 0 is a claim this file is not allowed to make. */
    private static function intOrNull(mixed $v): ?int
    {
        return $v === null ? null : (int) $v;
    }
}
