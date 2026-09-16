<?php declare(strict_types=1);
if (!defined('GO_BOOT')) { http_response_code(404); exit; }

/**
 * A2's copy, A3's worklist, and A4's handover to gate 2.
 *
 * @mock mock-api/server.mjs:488-655
 *
 * The countable gates - 100 words, no URL in the body, 3-5 hashtags - are
 * enforced here at the boundary and never trusted from the agent, and
 * submitForReview runs two of them again on the bytes actually stored. An agent
 * that re-words its request cannot get around either.
 */
final class Posts
{
    /**
     * @mock server.mjs:488-534 (createPost)
     *
     * Validation order is word count, then URL, then hashtag count. A draft that
     * breaks all three gets the word-count error, because that is the one that
     * needs a re-draft rather than an edit, and a test asserts it.
     */
    public static function createPost(array $ctx): array
    {
        $b = $ctx['body'];

        // Replay first, before any validation: a retry of a post that was
        // already accepted must not be re-judged against settings that may have
        // changed since, and must not fail on a body the server already stored.
        $existing = Db::row('SELECT * FROM posts WHERE post_uid = ?', [$b['post_uid'] ?? null]);
        if ($existing !== null) {
            return [200, ['data' => Views::postState($existing), 'replayed' => true]];
        }

        $t = Db::row('SELECT * FROM topics WHERE topic_uid = ?', [$b['topic_uid'] ?? null]);
        if ($t === null) throw ApiError::notFound('topic not found');
        if ($t['review_status'] !== 'approved') {
            throw ApiError::conflict('TOPIC_NOT_APPROVED', 'topic is not approved');
        }
        $r = Views::requireRun($b['run_uid'] ?? null);

        // The word cap is enforced at the boundary, not trusted from the agent.
        // A non-string body is coerced rather than fatalling, as String() does
        // in the mock; it then fails the insert on a NOT NULL column.
        $body = is_scalar($b['body'] ?? null) ? (string) $b['body'] : '';
        $wc = Words::bodyWordCount($body);
        $max = Settings::int('max_body_words', 100);
        if ($wc > $max) {
            throw ApiError::unprocessable('WORD_COUNT_EXCEEDED',
                "body is {$wc} words, limit is {$max}. Re-draft rather than truncating.",
                ['word_count' => $wc, 'limit' => $max]);
        }
        // A link in the body suppresses reach, so the contract refuses one outright.
        if (Words::hasUrl($body)) {
            throw ApiError::unprocessable('LINK_IN_BODY',
                'the post body must not contain a URL; put it in first_comment_text instead');
        }
        $tags = is_array($b['hashtags'] ?? null) ? array_values($b['hashtags']) : [];
        $tagCount = count($tags);
        if ($tagCount < 3 || $tagCount > 5) {
            throw ApiError::validation("expected 3-5 hashtags, got {$tagCount}", ['hashtags' => $tagCount]);
        }

        $keywords = is_array($b['keywords'] ?? null) ? $b['keywords'] : [];
        $numbers  = is_array($b['numbers_used'] ?? null) ? $b['numbers_used'] : [];

        $now = Dt::nowDb();
        return Db::tx(static function () use ($b, $t, $r, $tags, $keywords, $numbers, $body, $wc, $now) {
            try {
                Db::exec(
                    'INSERT INTO posts (
                       post_uid, topic_id, revision, copy_run_id,
                       original_hook, original_body, original_cta_text, original_cta_target,
                       original_hashtags, original_keywords, original_word_count,
                       first_comment_text, numbers_used, image_brief, model_name, generation_notes,
                       final_hook, final_body, final_cta_text, final_cta_target,
                       final_hashtags, final_keywords, final_word_count,
                       lifecycle_state, created_at, updated_at)
                     VALUES (?,?,?,?, ?,?,?,?, ?,?,?, ?,?,?,?,?, ?,?,?,?, ?,?,?, ?,?,?)',
                    [
                        $b['post_uid'] ?? null, (int) $t['id'], (int) ($b['revision'] ?? 1), (int) $r['id'],
                        $b['hook'] ?? null, $body, $b['cta_text'] ?? null, $b['cta_target'] ?? null,
                        Canon::tags($tags), Canon::tags($keywords), $wc,
                        $b['first_comment_text'] ?? null, Canon::tags($numbers),
                        self::jsonObject($b['image_brief'] ?? null), $b['model_name'] ?? null,
                        self::jsonObject($b['generation_notes'] ?? null),
                        // original_* and final_* are written identically. final_*
                        // only ever diverges when the owner edits it at gate 2.
                        $b['hook'] ?? null, $body, $b['cta_text'] ?? null, $b['cta_target'] ?? null,
                        Canon::tags($tags), Canon::tags($keywords), $wc,
                        // Not 'draft': the post is not finished until A3 has
                        // attached images, and this is the state A3 polls for.
                        'images_pending', $now, $now,
                    ]
                );
            } catch (PDOException $e) {
                if (!Db::isDuplicate($e)) throw $e;
                // Two A2 workers on the same topic: whoever lost the insert reads
                // the winner's row back rather than reporting a failure. A clash
                // on uq_posts_topic_rev instead is a real error and is rethrown.
                $again = Db::row('SELECT * FROM posts WHERE post_uid = ?', [$b['post_uid'] ?? null]);
                if ($again === null) throw $e;
                return [200, ['data' => Views::postState($again), 'replayed' => true]];
            }

            // The claim is cleared, not left to expire: the topic is done, and a
            // live lease on a finished topic reads as work in progress.
            Db::exec("UPDATE topics SET pipeline_state='copy_done', claim_expires_at=NULL, updated_at=? WHERE id=?",
                [$now, (int) $t['id']]);

            $row = Db::row('SELECT * FROM posts WHERE post_uid = ?', [$b['post_uid'] ?? null]);
            return [201, ['data' => Views::postState($row)]];
        });
    }

    /** @mock server.mjs:543-563 (listPosts) */
    public static function listPosts(array $ctx): array
    {
        $state = $ctx['query']['lifecycle_state'] ?? 'images_pending';
        $limit = min((int) ($ctx['query']['limit'] ?? 50), 200);

        // image_count as a correlated subquery rather than the mock's per-row
        // query: same output, one round trip instead of fifty.
        $rows = Db::limit(
            'SELECT p.*, t.final_title, t.source_url, t.source_quote, t.post_type, t.theme_tag,
                    (SELECT COUNT(*) FROM post_images pi WHERE pi.post_id = p.id) AS image_count
               FROM posts p JOIN topics t ON t.id = p.topic_id
              WHERE p.lifecycle_state = ? ORDER BY p.id LIMIT ?',
            [$state], $limit
        )->fetchAll();

        $data = [];
        foreach ($rows as $p) {
            $data[] = [
                'post_uid'    => $p['post_uid'],
                'topic_title' => $p['final_title'],
                'hook'        => $p['final_hook'],
                'body'        => $p['final_body'],
                'word_count'  => (int) $p['final_word_count'],
                'hashtags'    => Canon::decode($p['final_hashtags'], []),
                'keywords'    => Canon::decode($p['final_keywords'], []),
                'cta_text'    => $p['final_cta_text'],
                'cta_target'  => $p['final_cta_target'],
                'first_comment_text' => $p['first_comment_text'],
                'image_brief'   => self::asObject(Canon::decode($p['image_brief'], [])),
                'numbers_used'  => Canon::decode($p['numbers_used'], []),
                'post_type'     => $p['post_type'],
                'theme_tag'     => $p['theme_tag'],
                'source_url'    => $p['source_url'],
                'lifecycle_state' => $p['lifecycle_state'],
                'review_status'   => $p['review_status'],
                'image_count'     => (int) $p['image_count'],
            ];
        }

        return [200, ['data' => $data,
                      'page' => ['limit' => $limit, 'has_more' => count($rows) === $limit]]];
    }

    /**
     * @mock server.mjs:624-655 (submitForReview)
     *
     * A4 hands the package to gate 2. One image is allowed but flagged degraded,
     * because a post that is ready except for a second option should still reach
     * the owner - with the shortfall on the screen, not hidden.
     */
    public static function submitForReview(array $ctx): array
    {
        $b = $ctx['body'];
        $postUid = $ctx['params']['postUid'] ?? null;

        $p = Db::row('SELECT * FROM posts WHERE post_uid = ?', [$postUid]);
        if ($p === null) throw ApiError::notFound('post not found');

        if ($p['lifecycle_state'] === 'in_review') {
            return [200, ['data' => Views::postState($p), 'replayed' => true]];
        }
        if (!in_array($p['lifecycle_state'], ['images_ready', 'images_pending'], true)) {
            throw ApiError::conflict('ILLEGAL_TRANSITION', "cannot submit from '{$p['lifecycle_state']}'");
        }

        $images = Db::all('SELECT * FROM post_images WHERE post_id = ? ORDER BY option_index', [(int) $p['id']]);
        if (count($images) === 0) {
            throw ApiError::validation('no images attached; cannot submit for review');
        }

        // Re-run A2's countable gates on the stored bytes, to catch corruption in
        // transit. These read final_body, not the request: what the owner will
        // see is what has to pass.
        $wc = Words::bodyWordCount((string) $p['final_body']);
        if ($wc > Settings::int('max_body_words', 100)) {
            throw ApiError::unprocessable('WORD_COUNT_EXCEEDED', "stored body is {$wc} words");
        }
        if (Words::hasUrl((string) $p['final_body'])) {
            throw ApiError::unprocessable('LINK_IN_BODY', 'stored body contains a URL');
        }

        $degraded = count($images) < 2 ? ['single_image'] : [];
        $r = Db::row('SELECT id FROM agent_runs WHERE run_uid = ?', [$b['run_uid'] ?? null]);
        // A package the owner never looked at expires rather than going out
        // stale months later.
        $expires = Ist::datePlusDays(Settings::int('package_ttl_days', 45));

        $moved = Db::exec(
            "UPDATE posts SET lifecycle_state='in_review', review_status='pending', submit_run_id=?,
                    degraded_flags=?, expires_at_ist=?, updated_at=?
              WHERE id=? AND lifecycle_state IN ('images_ready','images_pending')",
            [$r === null ? null : (int) $r['id'], Canon::tags($degraded), $expires, Dt::nowDb(), (int) $p['id']]
        );
        if ($moved === 0) {
            // Lost the race. Re-read: an A4 retry that arrives twice reports a
            // replay, anything else is a genuine illegal transition.
            $again = Db::row('SELECT * FROM posts WHERE id = ?', [(int) $p['id']]);
            if ($again !== null && $again['lifecycle_state'] === 'in_review') {
                return [200, ['data' => Views::postState($again), 'replayed' => true]];
            }
            throw ApiError::conflict('ILLEGAL_TRANSITION',
                "cannot submit from '" . ($again['lifecycle_state'] ?? $p['lifecycle_state']) . "'");
        }

        return [200, ['data' => [
            'post_uid'        => $p['post_uid'],
            'lifecycle_state' => 'in_review',
            'review_status'   => 'pending',
            'image_count'     => count($images),
            'degraded_flags'  => $degraded,
            'expires_at_ist'  => $expires,
            'review_url'      => "/review/posts/{$p['post_uid']}",
        ]]];
    }

    /**
     * image_brief and generation_notes are JSON objects in the contract, and the
     * mock's default for both is `{}`. PHP would write an empty array as `[]`,
     * which is a different JSON type and breaks a client reading brief.concept.
     */
    private static function jsonObject(mixed $v): string
    {
        return Canon::encode(self::asObject(is_array($v) || is_object($v) ? $v : []));
    }

    private static function asObject(mixed $v): mixed
    {
        return $v === [] || $v === null ? new stdClass() : $v;
    }
}
