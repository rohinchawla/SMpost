<?php declare(strict_types=1);
if (!defined('GO_BOOT')) { http_response_code(404); exit; }

/**
 * The hash that proves a post is still what the owner approved.
 *
 * @mock mock-api/server.mjs:950 (written at approval)
 * @mock mock-api/server.mjs:711 (re-checked when A5 takes the publish lease)
 *
 * The mock composes this string in two different places from two different
 * sources: at approval from the in-memory tag array, and at lease time from the
 * raw stored column. On SQLite those bytes are identical, so the mock passes.
 *
 * On MySQL they are not. A JSON column is stored in a normalised binary form and
 * read back as `["#a", "#b"]` - with a space after the comma - where PHP wrote
 * `["#a","#b"]`. Ported naively, every approved post would fail its lease with
 * CONTENT_CHANGED_AFTER_APPROVAL: a silent, total publishing outage that no unit
 * test would catch, because the mock is green.
 *
 * So there is exactly one composition, it is private, and both call sites go
 * through it after decoding. The column's storage format is then irrelevant.
 * Never hash a raw column here.
 */
final class ContentHash
{
    private static function compose(string $finalBody, int $imageId, array $tags): string
    {
        return $finalBody . '|' . $imageId . '|' . Canon::tags($tags);
    }

    /** Gate 2. Called with the same tag array being written in that UPDATE. */
    public static function atApproval(string $finalBody, int $imageId, array $tags): string
    {
        return hash('sha256', self::compose($finalBody, $imageId, $tags));
    }

    /** A5's lease. Decodes the stored column, then composes the identical way. */
    public static function atLease(array $post): string
    {
        $tags = Canon::decode($post['final_hashtags'] ?? null, []) ?: [];
        return hash('sha256', self::compose(
            (string) ($post['final_body'] ?? ''),
            (int) ($post['selected_image_id'] ?? 0),
            is_array($tags) ? $tags : []
        ));
    }

    /**
     * Every approved post whose stored hash no longer matches.
     *
     * The installer runs this and the owner can run it from the ops screen. It
     * is the check that would have caught the bug described above, and an empty
     * result is the only acceptable state before going live.
     */
    public static function selfTest(): array
    {
        $rows = Db::all(
            "SELECT post_uid, final_body, final_hashtags, selected_image_id, approved_content_hash
               FROM posts
              WHERE review_status = 'approved' AND approved_content_hash IS NOT NULL"
        );
        $bad = [];
        foreach ($rows as $r) {
            if (!hash_equals((string) $r['approved_content_hash'], self::atLease($r))) {
                $bad[] = $r['post_uid'];
            }
        }
        return ['checked' => count($rows), 'mismatched' => $bad];
    }
}
