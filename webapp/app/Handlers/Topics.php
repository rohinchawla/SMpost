<?php declare(strict_types=1);
if (!defined('GO_BOOT')) { http_response_code(404); exit; }

/**
 * A1's research batch, and A2's read of what survived gate 1.
 *
 * @mock mock-api/server.mjs:339-486
 *
 * Gate 1 is not a screen, it is one WHERE clause in listTopics. Nothing in this
 * file takes a parameter that can widen it: a filter an agent can relax is the
 * same as no filter at all.
 */
final class Topics
{
    /** @mock server.mjs:339-348 (createBatch) */
    public static function createBatch(array $ctx): array
    {
        $b = $ctx['body'];
        $r = Views::requireRun($b['run_uid'] ?? null);

        $existing = Db::row('SELECT batch_uid, state FROM topic_batches WHERE batch_uid = ?',
            [$b['batch_uid'] ?? null]);
        if ($existing !== null) {
            return [200, ['data' => ['batch_uid' => $existing['batch_uid'], 'state' => $existing['state']],
                          'replayed' => true]];
        }

        $date = $b['business_date_ist'] ?? null;
        try {
            Db::exec(
                'INSERT INTO topic_batches (batch_uid, run_id, business_date_ist, title, sources_json)
                 VALUES (?,?,?,?,?)',
                [$b['batch_uid'] ?? null, (int) $r['id'], $date,
                 $b['title'] ?? "Topics {$date}",
                 isset($b['sources']) ? Canon::encode($b['sources']) : null]
            );
        } catch (PDOException $e) {
            if (!Db::isDuplicate($e)) throw $e;
            // Two A1 processes opening the same batch at once: the unique index
            // is the arbiter, not the read above. uq_batches_run also fires here
            // when a run tries to open a second batch, which is not a replay and
            // is left to surface as an error.
            $again = Db::row('SELECT batch_uid, state FROM topic_batches WHERE batch_uid = ?',
                [$b['batch_uid'] ?? null]);
            if ($again === null) throw $e;
            return [200, ['data' => ['batch_uid' => $again['batch_uid'], 'state' => $again['state']],
                          'replayed' => true]];
        }

        return [201, ['data' => ['batch_uid' => $b['batch_uid'] ?? null, 'state' => 'open']]];
    }

    /**
     * @mock server.mjs:350-363 (dedupeCheck)
     *
     * A1 asks this before uploading, so a topic proposed in the last year is
     * dropped rather than re-researched and re-approved.
     */
    public static function dedupeCheck(array $ctx): array
    {
        $b = $ctx['body'];
        $days = (int) ($b['days'] ?? 365);
        $cutoff = Dt::plusSecondsDb(-$days * 86400);

        $results = [];
        foreach (($b['titles'] ?? []) as $title) {
            $hit = Db::row(
                'SELECT topic_uid, final_title, created_at FROM topics
                  WHERE dedupe_hash = ? AND created_at >= ? ORDER BY created_at DESC LIMIT 1',
                [DedupeHash::of((string) $title), $cutoff]
            );
            $results[] = $hit === null
                ? ['title' => $title, 'is_duplicate' => false]
                : ['title'             => $title,
                   'is_duplicate'      => true,
                   'matched_topic_uid' => $hit['topic_uid'],
                   'matched_title'     => $hit['final_title'],
                   // The mock slices the ISO string; on DATETIME(3) the first
                   // ten characters are the same calendar date.
                   'matched_on'        => substr((string) $hit['created_at'], 0, 10)];
        }
        return [200, ['data' => $results]];
    }

    /**
     * @mock server.mjs:365-415 (addTopics)
     *
     * Always 207: one malformed topic must not lose the other 49, so each item
     * carries its own status and its own error. The transaction is what makes
     * the position counter safe against a second uploader; the per-item
     * SAVEPOINT is what stops one bad row taking the batch down with it.
     */
    public static function addTopics(array $ctx): array
    {
        $b = $ctx['body'];
        $batchUid = $ctx['params']['batchUid'] ?? null;

        return Db::tx(static function () use ($b, $batchUid) {
            // FOR UPDATE before anything else: MAX(position)+1 read outside a
            // lock hands the same position to two concurrent uploads, and
            // uq_topics_batch_pos then rejects the loser's whole batch.
            $batch = Db::row('SELECT * FROM topic_batches WHERE batch_uid = ? FOR UPDATE', [$batchUid]);
            if ($batch === null) throw ApiError::notFound('batch not found');
            if ($batch['state'] !== 'open') {
                throw ApiError::conflict('ILLEGAL_TRANSITION', 'batch is already submitted');
            }
            $r = Views::requireRun($b['run_uid'] ?? null);

            $batchId = (int) $batch['id'];
            $pos = (int) Db::one('SELECT COALESCE(MAX(position),0) FROM topics WHERE batch_id = ?', [$batchId]);

            $pdo = Db::pdo();
            $results = [];
            $accepted = 0; $duplicates = 0; $rejected = 0;
            $n = 0;

            foreach (($b['topics'] ?? []) as $t) {
                // The savepoint name is an identifier, so it cannot be bound. It
                // is a counter this loop owns; nothing from the request reaches it.
                $sp = 'sp_topic_' . (++$n);
                $pdo->exec('SAVEPOINT ' . $sp);
                $uid = $t['topic_uid'] ?? null;

                try {
                    if (empty($t['title']) || empty($t['one_liner'])) {
                        throw ApiError::validation('title and one_liner are required');
                    }
                    // mb_strlen, not strlen: the column is VARCHAR(500) in
                    // characters, and a byte count would reject a perfectly
                    // legal one-liner carrying a rupee sign.
                    if (mb_strlen((string) $t['one_liner'], 'UTF-8') > 500) {
                        throw ApiError::validation('one_liner exceeds 500 chars');
                    }
                    if (mb_strlen((string) $t['title'], 'UTF-8') > 300) {
                        throw ApiError::validation('title exceeds 300 chars');
                    }

                    $hash = DedupeHash::of((string) $t['title']);
                    $dup = Db::row('SELECT topic_uid FROM topics WHERE batch_id = ? AND dedupe_hash = ?',
                        [$batchId, $hash]);
                    if ($dup !== null) {
                        $duplicates++;
                        $results[] = ['topic_uid' => $uid, 'status' => 'duplicate_in_batch',
                                      'existing_topic_uid' => $dup['topic_uid']];
                        $pdo->exec('RELEASE SAVEPOINT ' . $sp);
                        continue;
                    }

                    $exists = Db::one('SELECT topic_uid FROM topics WHERE topic_uid = ?', [$uid]);
                    if ($exists !== null) {
                        // Counted as accepted, exactly as the mock does: a retried
                        // upload must report the same accepted total as the first.
                        $accepted++;
                        $results[] = ['topic_uid' => $uid, 'status' => 'replayed'];
                        $pdo->exec('RELEASE SAVEPOINT ' . $sp);
                        continue;
                    }

                    self::insertTopic($t, $batchId, (int) $r['id'], ++$pos, $hash);
                    $accepted++;
                    $results[] = ['topic_uid' => $uid, 'status' => 'created', 'position' => $pos];
                    $pdo->exec('RELEASE SAVEPOINT ' . $sp);
                } catch (Throwable $e) {
                    // Undo only this item. Without the savepoint a rejected row
                    // would poison the transaction and lose the other 49.
                    $pdo->exec('ROLLBACK TO SAVEPOINT ' . $sp);
                    // $pos keeps the number the failed insert consumed, exactly
                    // as the mock does. positions are unique, not contiguous.
                    $rejected++;
                    $results[] = ['topic_uid' => $uid, 'status' => 'rejected', 'error' => [
                        'code'    => $e instanceof ApiError ? $e->errorCode : 'INTERNAL',
                        'message' => $e->getMessage(),
                    ]];
                }
            }

            Db::exec(
                'UPDATE topic_batches SET topic_count = (SELECT COUNT(*) FROM topics WHERE batch_id = ?),
                        updated_at = ? WHERE id = ?',
                [$batchId, Dt::nowDb(), $batchId]
            );

            return [207, ['data' => ['accepted'   => $accepted,
                                     'duplicates' => $duplicates,
                                     'rejected'   => $rejected,
                                     'results'    => $results]]];
        });
    }

    /**
     * @mock server.mjs:417-431 (submitBatch)
     *
     * Submitting closes the batch and makes it visible to the owner at gate 1.
     */
    public static function submitBatch(array $ctx): array
    {
        $batchUid = $ctx['params']['batchUid'] ?? null;
        $batch = Db::row('SELECT * FROM topic_batches WHERE batch_uid = ?', [$batchUid]);
        if ($batch === null) throw ApiError::notFound('batch not found');

        if ($batch['state'] === 'submitted') {
            return [200, ['data' => ['batch_uid'   => $batch['batch_uid'],
                                     'state'       => 'submitted',
                                     'topic_count' => (int) $batch['topic_count']],
                          'replayed' => true]];
        }

        $count = (int) $batch['topic_count'];
        $min = Settings::int('topics_per_batch_min', 30);
        if ($count < $min) {
            throw ApiError::validation("batch has {$count} topics, minimum is {$min}",
                ['topic_count' => $count, 'minimum' => $min]);
        }

        $now = Dt::nowDb();
        // The conditional UPDATE is the lock. A second submit arriving while the
        // first is in flight matches zero rows and is reported as a replay,
        // which is what it is.
        $locked = Db::exec(
            "UPDATE topic_batches SET state='submitted', submitted_at=?, updated_at=? WHERE id=? AND state='open'",
            [$now, $now, (int) $batch['id']]
        );
        if ($locked === 0) {
            $again = Db::row('SELECT batch_uid, state, topic_count FROM topic_batches WHERE id = ?',
                [(int) $batch['id']]);
            return [200, ['data' => ['batch_uid'   => $again['batch_uid'],
                                     'state'       => $again['state'],
                                     'topic_count' => (int) $again['topic_count']],
                          'replayed' => true]];
        }

        Db::exec("UPDATE topics SET pipeline_state='new', updated_at=? WHERE batch_id=?",
            [$now, (int) $batch['id']]);

        return [200, ['data' => ['batch_uid'   => $batch['batch_uid'],
                                 'state'       => 'submitted',
                                 'topic_count' => $count]]];
    }

    /**
     * @mock server.mjs:435-467 (listTopics)
     *
     * A2 only ever sees approved topics. It cannot reach pending, held or
     * rejected rows even by asking: if it could, the human gate would quietly
     * stop existing. The three conditions are fixed and there is deliberately no
     * review_status, pipeline_state or batch parameter.
     */
    public static function listTopics(array $ctx): array
    {
        $limit = min((int) ($ctx['query']['limit'] ?? 50), 200);

        $rows = Db::limit(
            "SELECT t.*, b.batch_uid FROM topics t JOIN topic_batches b ON b.id = t.batch_id
              WHERE t.review_status='approved' AND t.pipeline_state='awaiting_copy' AND b.state='submitted'
              ORDER BY t.priority, t.id LIMIT ?",
            [], $limit
        )->fetchAll();

        $data = [];
        foreach ($rows as $t) {
            $data[] = [
                'topic_uid' => $t['topic_uid'],
                'batch_uid' => $t['batch_uid'],
                'position'  => (int) $t['position'],
                // final_* is what A2 writes about. The owner's edit always wins.
                'title'     => $t['final_title'],
                'one_liner' => $t['final_one_liner'],
                'source_url'          => $t['source_url'],
                'source_quote'        => $t['source_quote'],
                'source_domain'       => $t['source_domain'],
                'source_published_on' => $t['source_published_on'],
                'post_type'           => $t['post_type'],
                'theme_tag'           => $t['theme_tag'],
                'india_relevance'     => (int) $t['india_relevance'],
                'cta' => [
                    'required' => (bool) $t['final_cta_flag'],
                    'type'     => $t['final_cta_type'],
                    'text'     => $t['final_cta_text'],
                    'target'   => $t['final_cta_target'],
                ],
                'was_edited' => (bool) $t['was_edited'],
                'provenance' => ['original_title'     => $t['original_title'],
                                 'original_one_liner' => $t['original_one_liner']],
                'approved_at' => Dt::iso($t['reviewed_at'] ?? null),
            ];
        }

        return [200, ['data' => $data,
                      'page' => ['limit' => $limit, 'has_more' => count($rows) === $limit],
                      'constraints' => [
                          'max_body_words' => Settings::int('max_body_words', 100),
                          'brand_website'  => Settings::str('company_website'),
                          'brand_email'    => Settings::str('company_email'),
                          'audience'       => 'HR Head / CHRO at Indian companies with 500+ headcount and about INR 500 Cr turnover',
                          'forbidden'      => [
                              'candidate-facing language',
                              'any claim about caste, religion, gender, age, marital status or nationality',
                              'statistics that do not appear verbatim in the source',
                              'financial advice',
                              'URLs in the post body',
                          ],
                      ]]];
    }

    /**
     * @mock server.mjs:469-486 (claimTopic)
     *
     * A 30-minute lease, so a crashed A2 does not strand the topic forever.
     *
     * The pre-checks exist to produce the right error code; the conditional
     * UPDATE is what actually decides who holds the lease. Both are needed: the
     * read alone is a race, and the UPDATE alone cannot tell "not approved"
     * apart from "held by someone else".
     */
    public static function claimTopic(array $ctx): array
    {
        $b = $ctx['body'];
        $t = Db::row('SELECT * FROM topics WHERE topic_uid = ?', [$ctx['params']['topicUid'] ?? null]);
        if ($t === null) throw ApiError::notFound('topic not found');

        if ($t['review_status'] !== 'approved') {
            throw ApiError::conflict('TOPIC_NOT_APPROVED',
                "topic is '{$t['review_status']}' and cannot be claimed",
                ['review_status' => $t['review_status']]);
        }

        // The mock dereferences r.id without a null check and would 500 here.
        // A missing run is a 404 like every other missing run in the contract.
        $r = Views::requireRun($b['run_uid'] ?? null);

        $now = Dt::nowDb();
        $held = $t['claim_expires_at'] !== null && (string) $t['claim_expires_at'] > $now;
        if ($held && (int) $t['claimed_by_run_id'] !== (int) $r['id']) {
            throw ApiError::conflict('LEASE_HELD_BY_OTHER_RUN', 'another run holds this topic');
        }

        $expires = Dt::plusSecondsDb((int) ($b['lease_seconds'] ?? 1800));
        // FOUND_ROWS is on, so re-claiming by the same run still matches its row
        // and refreshes the lease even though the values may be unchanged.
        $won = Db::exec(
            "UPDATE topics SET pipeline_state='copy_in_progress', claimed_by_run_id=?, claim_expires_at=?, updated_at=?
              WHERE id=? AND review_status='approved'
                AND (claim_expires_at IS NULL OR claim_expires_at <= ? OR claimed_by_run_id = ?)",
            [(int) $r['id'], $expires, $now, (int) $t['id'], $now, (int) $r['id']]
        );
        if ($won === 0) {
            throw ApiError::conflict('LEASE_HELD_BY_OTHER_RUN', 'another run holds this topic');
        }

        return [200, ['data' => ['topic_uid' => $t['topic_uid'], 'claim_expires_at' => Dt::iso($expires)]]];
    }

    /**
     * One topic row. final_* is initialised equal to original_*: the owner has
     * not edited anything yet, and a null final_title would make every read
     * downstream branch on which column to trust.
     *
     * @mock server.mjs:390-404
     */
    private static function insertTopic(array $t, int $batchId, int $runId, int $pos, string $hash): void
    {
        $now = Dt::nowDb();
        Db::exec(
            'INSERT INTO topics (
               topic_uid, batch_id, created_by_run_id, position,
               original_title, original_one_liner, original_cta_flag, original_cta_type, original_cta_text, original_cta_target,
               source_url, source_domain, source_title, source_published_on, source_type, source_quote,
               post_type, theme_tag, india_relevance,
               final_title, final_one_liner, final_cta_flag, final_cta_type, final_cta_text, final_cta_target,
               dedupe_hash, created_at, updated_at)
             VALUES (?,?,?,?, ?,?,?,?,?,?, ?,?,?,?,?,?, ?,?,?, ?,?,?,?,?,?, ?,?,?)',
            [
                $t['topic_uid'] ?? null, $batchId, $runId, $pos,
                $t['title'], $t['one_liner'], !empty($t['cta_flag']) ? 1 : 0,
                $t['cta_type'] ?? 'none', $t['cta_text'] ?? null, $t['cta_target'] ?? null,
                $t['source_url'] ?? null, $t['source_domain'] ?? null, $t['source_title'] ?? null,
                $t['source_published_on'] ?? null, $t['source_type'] ?? 'blog', $t['source_quote'] ?? null,
                $t['post_type'] ?? 'data_point', $t['theme_tag'] ?? 'workforce_planning',
                (int) ($t['india_relevance'] ?? 1),
                $t['title'], $t['one_liner'], !empty($t['cta_flag']) ? 1 : 0,
                $t['cta_type'] ?? 'none', $t['cta_text'] ?? null, $t['cta_target'] ?? null,
                $hash, $now, $now,
            ]
        );
    }
}
