<?php declare(strict_types=1);
if (!defined('GO_BOOT')) { http_response_code(404); exit; }

/**
 * The two human approval gates.
 *
 * @mock mock-api/server.mjs:900-1000 (the /__mock/ handlers)
 *
 * This is the only place gate 1 and gate 2 logic lives. The UI controllers call
 * it, and so does the test harness - the harness is parameter marshalling with
 * no business logic of its own. That matters: it means a green contract suite is
 * evidence about the real approval code path rather than about a parallel fake.
 */
final class ReviewService
{
    /**
     * Gate 1. Approving a topic must also release it to A2, in one write.
     *
     * The mock does both in a single UPDATE for a reason: a topic that is
     * "approved" but still sitting at pipeline_state='new' is invisible to A2
     * forever, and nothing on any screen would say so. The live run in Part 1
     * caught exactly that.
     *
     * The pipeline move only applies from new / awaiting_copy / discarded. A
     * topic already being written is not dragged backwards.
     */
    public static function setTopicStatus(string $topicUid, string $status, string $actor, ?string $note = null): array
    {
        self::assertVerdict($status);

        return Db::tx(static function () use ($topicUid, $status, $actor, $note) {
            $t = Db::row('SELECT * FROM topics WHERE topic_uid = ? FOR UPDATE', [$topicUid]);
            if ($t === null) throw ApiError::notFound('topic not found');

            $next = match ($status) {
                'approved' => 'awaiting_copy',
                'rejected' => 'discarded',
                default    => null,
            };
            $movable = in_array($t['pipeline_state'], ['new', 'awaiting_copy', 'discarded'], true);

            if ($next !== null && $movable) {
                Db::exec('UPDATE topics SET review_status=?, pipeline_state=?, reviewed_at=?, reviewed_by=?, review_note=?, updated_at=? WHERE id=?',
                    [$status, $next, Dt::nowDb(), $actor, $note, Dt::nowDb(), (int) $t['id']]);
            } else {
                Db::exec('UPDATE topics SET review_status=?, reviewed_at=?, reviewed_by=?, review_note=?, updated_at=? WHERE id=?',
                    [$status, Dt::nowDb(), $actor, $note, Dt::nowDb(), (int) $t['id']]);
            }

            self::audit('topic', $topicUid, (string) $t['review_status'], $status, $actor, $note);

            return [
                'uid'            => $topicUid,
                'review_status'  => $status,
                'pipeline_state' => ($next !== null && $movable) ? $next : $t['pipeline_state'],
                'already_with_writer' => !$movable,
            ];
        });
    }

    /** The owner may change the title and the one-liner. original_* is immutable. */
    public static function editTopic(string $topicUid, array $fields, string $actor): array
    {
        return Db::tx(static function () use ($topicUid, $fields, $actor) {
            $t = Db::row('SELECT * FROM topics WHERE topic_uid = ? FOR UPDATE', [$topicUid]);
            if ($t === null) throw ApiError::notFound('topic not found');

            $allowed = ['final_title', 'final_one_liner', 'final_cta_text', 'final_cta_target'];
            $changed = [];
            foreach ($allowed as $col) {
                if (!array_key_exists($col, $fields)) continue;
                $new = (string) $fields[$col];
                if ($new === (string) $t[$col]) continue;
                if ($col === 'final_title' && mb_strlen($new) > 300) throw ApiError::validation('title exceeds 300 chars');
                if ($col === 'final_one_liner' && mb_strlen($new) > 500) throw ApiError::validation('one-liner exceeds 500 chars');
                Db::exec("UPDATE topics SET {$col} = ?, updated_at = ? WHERE id = ?", [$new, Dt::nowDb(), (int) $t['id']]);
                self::fieldEdit('topic', $topicUid, $col, (string) $t[$col], $new, $actor);
                $changed[] = $col;
            }
            return ['uid' => $topicUid, 'changed' => $changed];
        });
    }

    /** Bulk gate-1 approval, used by the queue screen and by the test harness. */
    public static function approveTopics(?string $batchUid, int $count, string $actor, bool $editFirstTitle = false): array
    {
        $rows = $batchUid === null
            ? Db::limit("SELECT t.* FROM topics t WHERE t.review_status='pending' ORDER BY t.id LIMIT ?", [], $count)->fetchAll()
            : Db::limit("SELECT t.* FROM topics t JOIN topic_batches b ON b.id=t.batch_id
                          WHERE b.batch_uid = ? AND t.review_status='pending' ORDER BY t.id LIMIT ?", [$batchUid], $count)->fetchAll();

        $touched = [];
        foreach ($rows as $i => $t) {
            $title = (string) $t['final_title'];
            if ($editFirstTitle && $i === 0) {
                $title .= ' (edited by owner)';
                self::editTopic((string) $t['topic_uid'], ['final_title' => $title], $actor);
            }
            self::setTopicStatus((string) $t['topic_uid'], 'approved', $actor);
            $touched[] = ['topic_uid' => $t['topic_uid'], 'title' => $title, 'edited' => $title !== (string) $t['final_title']];
        }
        return ['approved' => count($touched), 'topics' => $touched];
    }

    private static function assertVerdict(string $status): void
    {
        if (!in_array($status, ['pending', 'approved', 'rejected', 'hold'], true)) {
            throw ApiError::validation("unknown review status '{$status}'");
        }
    }

    private static function audit(string $type, string $uid, ?string $from, string $to, string $actor, ?string $note = null): void
    {
        Db::exec('INSERT INTO review_actions (entity_type, entity_uid, from_status, to_status, actor_type, actor_name, note, ip_address)
                  VALUES (?,?,?,?,?,?,?,?)',
            [$type, $uid, $from, $to, 'human', $actor, $note,
             @inet_pton((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0')) ?: null]);
    }

    private static function fieldEdit(string $type, string $uid, string $field, ?string $old, ?string $new, string $actor): void
    {
        Db::exec('INSERT INTO field_edits (entity_type, entity_uid, field_name, old_value, new_value, actor_type, actor_name)
                  VALUES (?,?,?,?,?,?,?)', [$type, $uid, $field, $old, $new, 'human', $actor]);
    }

    // ---------------------------------------------------------------- gate 2

    /**
     * Gate 2. One transaction that picks the image, saves any hashtag edit,
     * approves, sets the lifecycle, and snapshots the content hash.
     *
     * @mock server.mjs:936-969 (M.approvePost)
     *
     * The FOR UPDATE is what makes "hold at 07:55 beats approval at 08:00"
     * deterministic rather than lucky: A5's lease and this approval contend on
     * the same row lock.
     *
     * The hash is computed from the in-memory values being written in this same
     * UPDATE, never from a re-read column. See ContentHash for why.
     */
    public static function approvePost(
        ?string $postUid, int $selectImage, string $actor,
        ?string $scheduledDateIst = null, ?array $hashtags = null, ?string $note = null
    ): array {
        return Db::tx(static function () use ($postUid, $selectImage, $actor, $scheduledDateIst, $hashtags, $note) {
            $p = $postUid === null
                ? Db::row("SELECT * FROM posts WHERE lifecycle_state='in_review' ORDER BY id LIMIT 1 FOR UPDATE")
                : Db::row('SELECT * FROM posts WHERE post_uid = ? FOR UPDATE', [$postUid]);
            if ($p === null) throw ApiError::notFound('no post awaiting review');

            $img = Db::row('SELECT * FROM post_images WHERE post_id = ? AND option_index = ?',
                [(int) $p['id'], $selectImage]);
            if ($img === null) {
                throw ApiError::unprocessable('NO_IMAGE_SELECTED', "image option {$selectImage} does not exist");
            }

            $tags = $hashtags ?? (Canon::decode((string) $p['final_hashtags'], []) ?: []);
            $tags = array_values($tags);
            $oldTags = (string) $p['final_hashtags'];
            $newTags = Canon::tags($tags);

            // The cap is a database CHECK as well as a house rule, so a body that
            // grew past it during editing must be refused here with a sentence
            // rather than as a raw constraint violation.
            $words = Words::bodyWordCount((string) $p['final_body']);
            $max = Settings::int('max_body_words', 100);
            if ($words > $max) {
                throw ApiError::unprocessable('WORD_COUNT_EXCEEDED',
                    "this post is {$words} words and the cap is {$max}. Shorten it before approving.",
                    ['word_count' => $words, 'limit' => $max]);
            }

            $lifecycle = $scheduledDateIst !== null && $scheduledDateIst !== '' ? 'scheduled' : 'ready';
            $hash = ContentHash::atApproval((string) $p['final_body'], (int) $img['id'], $tags);

            Db::exec(
                'UPDATE posts SET final_hashtags=?, selected_image_id=?, review_status=?, lifecycle_state=?,
                        scheduled_date_ist=?, approved_content_hash=?, reviewed_at=?, reviewed_by=?, review_note=?, updated_at=?
                   WHERE id=?',
                [$newTags, (int) $img['id'], 'approved', $lifecycle,
                 $scheduledDateIst ?: null, $hash, Dt::nowDb(), $actor, $note, Dt::nowDb(), (int) $p['id']]
            );

            if ($newTags !== $oldTags) {
                self::fieldEdit('post', (string) $p['post_uid'], 'final_hashtags', $oldTags, $newTags, $actor);
            }
            self::audit('post', (string) $p['post_uid'], (string) $p['review_status'], 'approved', $actor, $note);

            return [
                'post_uid'           => $p['post_uid'],
                'lifecycle_state'    => $lifecycle,
                'selected_image_uid' => $img['image_uid'],
                'selected_option'    => (int) $img['option_index'],
                'hashtags'           => $tags,
                'scheduled_date_ist' => $scheduledDateIst ?: null,
            ];
        });
    }

    /**
     * Hold, reject, or send back to pending.
     *
     * @mock server.mjs:971-993 (M.setStatus, post branch)
     *
     * lifecycle_state is deliberately untouched. That is what lets a hold stop
     * tomorrow's publish while leaving a post that is already live still posted -
     * no dropdown can ever un-post something public.
     */
    public static function setPostStatus(string $postUid, string $status, string $actor, ?string $note = null): array
    {
        self::assertVerdict($status);

        return Db::tx(static function () use ($postUid, $status, $actor, $note) {
            $p = Db::row('SELECT * FROM posts WHERE post_uid = ? FOR UPDATE', [$postUid]);
            if ($p === null) throw ApiError::notFound('post not found');

            // Moving away from approved invalidates the snapshot: leaving a stale
            // hash on a pending row would misreport the post as "edited after
            // approval" on every screen that checks.
            $clearHash = $status !== 'approved';

            Db::exec('UPDATE posts SET review_status=?, approved_content_hash=?, reviewed_at=?, reviewed_by=?, review_note=?, updated_at=? WHERE id=?',
                [$status, $clearHash ? null : $p['approved_content_hash'],
                 Dt::nowDb(), $actor, $note, Dt::nowDb(), (int) $p['id']]);

            self::audit('post', $postUid, (string) $p['review_status'], $status, $actor, $note);
            return ['uid' => $postUid, 'review_status' => $status];
        });
    }

    /**
     * The gate-2 editor.
     *
     * @mock server.mjs:995-1004 (M.editPost)
     *
     * Deliberately does NOT refresh approved_content_hash. That is precisely why
     * the next publish-lease refuses with CONTENT_CHANGED_AFTER_APPROVAL: editing
     * after approval invalidates the approval rather than silently renewing it.
     */
    public static function editPost(string $postUid, array $fields, string $actor): array
    {
        return Db::tx(static function () use ($postUid, $fields, $actor) {
            $p = Db::row('SELECT * FROM posts WHERE post_uid = ? FOR UPDATE', [$postUid]);
            if ($p === null) throw ApiError::notFound('post not found');
            if (in_array($p['lifecycle_state'], ['publishing', 'posted'], true)) {
                throw ApiError::conflict('ILLEGAL_TRANSITION',
                    'this post is already live; editing it here would make the audit record a lie');
            }

            $changed = [];
            $now = Dt::nowDb();

            if (array_key_exists('final_body', $fields)) {
                $new = (string) $fields['final_body'];
                $old = (string) $p['final_body'];
                if ($new !== $old) {
                    self::assertBodyIsPublishable($new);
                    Db::exec('UPDATE posts SET final_body=?, final_word_count=?, final_hook=?, updated_at=? WHERE id=?',
                        [$new, Words::bodyWordCount($new), self::firstLine($new), $now, (int) $p['id']]);
                    self::fieldEdit('post', $postUid, 'final_body', $old, $new, $actor);
                    $changed[] = 'final_body';
                }
            }

            if (array_key_exists('final_hashtags', $fields) && is_array($fields['final_hashtags'])) {
                $new = Canon::tags($fields['final_hashtags']);
                $old = (string) $p['final_hashtags'];
                if ($new !== $old) {
                    Db::exec('UPDATE posts SET final_hashtags=?, updated_at=? WHERE id=?', [$new, $now, (int) $p['id']]);
                    self::fieldEdit('post', $postUid, 'final_hashtags', $old, $new, $actor);
                    $changed[] = 'final_hashtags';
                }
            }

            foreach (['final_cta_text', 'final_cta_target', 'first_comment_text'] as $col) {
                if (!array_key_exists($col, $fields)) continue;
                $new = $fields[$col] === null ? null : (string) $fields[$col];
                if ($new === $p[$col]) continue;
                Db::exec("UPDATE posts SET {$col}=?, updated_at=? WHERE id=?", [$new, $now, (int) $p['id']]);
                self::fieldEdit('post', $postUid, $col, $p[$col], $new, $actor);
                $changed[] = $col;
            }

            if (array_key_exists('scheduled_date_ist', $fields)) {
                Db::exec('UPDATE posts SET scheduled_date_ist=?, updated_at=? WHERE id=?',
                    [$fields['scheduled_date_ist'] ?: null, $now, (int) $p['id']]);
                $changed[] = 'scheduled_date_ist';
            }

            $after = Db::row('SELECT final_word_count FROM posts WHERE id = ?', [(int) $p['id']]);
            return ['post_uid' => $postUid, 'changed' => $changed, 'word_count' => (int) $after['final_word_count']];
        });
    }

    /**
     * The two rules the owner can break by editing that the agents cannot.
     *
     * The word cap is also a database CHECK constraint, so without this the
     * owner would see a raw constraint violation instead of a sentence.
     */
    private static function assertBodyIsPublishable(string $body): void
    {
        $words = Words::bodyWordCount($body);
        $max = Settings::int('max_body_words', 100);
        if ($words > $max) {
            throw ApiError::unprocessable('WORD_COUNT_EXCEEDED',
                "that is {$words} words and the cap is {$max}. Cut an idea rather than trimming a sentence.",
                ['word_count' => $words, 'limit' => $max]);
        }
        if (Words::hasUrl($body)) {
            throw ApiError::unprocessable('LINK_IN_BODY',
                'a link in the body suppresses reach. Put it in the first comment instead.');
        }
    }

    private static function firstLine(string $body): string
    {
        $line = explode("\n", $body)[0] ?? '';
        return mb_substr(Js::trim($line), 0, 300);
    }

    /**
     * True when a post was edited after it was approved, so it will not publish.
     *
     * This is the state that would otherwise be invisible: the database still
     * says "approved", and A5 refuses it silently at 08:00. Every screen that
     * shows an approved post checks this.
     */
    public static function isStale(array $post): bool
    {
        if (($post['review_status'] ?? '') !== 'approved') return false;
        if (empty($post['approved_content_hash']) || empty($post['selected_image_id'])) return false;
        return !hash_equals((string) $post['approved_content_hash'], ContentHash::atLease($post));
    }
}
