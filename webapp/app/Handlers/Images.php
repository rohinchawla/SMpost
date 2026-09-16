<?php declare(strict_types=1);
if (!defined('GO_BOOT')) { http_response_code(404); exit; }

/**
 * A3's two image options, in and back out again.
 *
 * @mock mock-api/server.mjs:571-621
 *
 * Base64 in JSON rather than multipart: it is trivial on both sides, and the
 * server re-hashes the bytes it actually received. A truncated download from
 * Higgsfield is caught here rather than surfacing as a grey box in the owner's
 * preview a week later.
 *
 * Nothing the client claims about the bytes is stored. The hash and the length
 * written to post_images are the ones this process computed; `sha256` in the
 * request body is only ever used as a value to disagree with.
 */
final class Images
{
    /** The only two extensions that exist on disk, and their one true type. */
    private const TYPES = ['png' => 'image/png', 'jpg' => 'image/jpeg'];

    /** @mock server.mjs:571-616 (uploadImage) */
    public static function uploadImage(array $ctx): array
    {
        $b = $ctx['body'];

        $p = Db::row('SELECT * FROM posts WHERE post_uid = ?', [$ctx['params']['postUid'] ?? '']);
        if ($p === null) throw ApiError::notFound('post not found');
        $r = Views::requireRun($b['run_uid'] ?? null);

        // Number(), not (int): Node rejects "1.5" here and a bare cast would
        // accept it as 1 and write the wrong option slot.
        $n = is_numeric($b['option_index'] ?? null) ? (float) $b['option_index'] : NAN;
        if ($n !== 1.0 && $n !== 2.0) throw ApiError::validation('option_index must be 1 or 2');
        $idx = (int) $n;

        // The replay check precedes every content check on purpose: A3 retrying
        // an upload whose response it never saw must not be told its own bytes
        // are wrong.
        $dup = Db::row('SELECT * FROM post_images WHERE image_uid = ?', [$b['image_uid'] ?? '']);
        if ($dup !== null) return [200, ['data' => Views::image($dup), 'replayed' => true]];

        if (empty($b['data_base64']) || !is_string($b['data_base64'])) {
            throw ApiError::validation('data_base64 is required');
        }
        // Non-strict decode: Node's Buffer.from(s, 'base64') skips characters
        // outside the alphabet rather than failing, and so must this.
        $bytes = (string) base64_decode($b['data_base64'], false);
        $actual = hash('sha256', $bytes);

        // Hash before length, matching the mock. A download truncated to nothing
        // should report the mismatch it is, not the emptiness that follows from it.
        if (!empty($b['sha256']) && !hash_equals($actual, (string) $b['sha256'])) {
            throw ApiError::unprocessable('SHA256_MISMATCH',
                'received bytes do not match the declared sha256; the download was corrupt',
                ['declared' => $b['sha256'], 'actual' => $actual, 'bytes' => strlen($bytes)]);
        }
        if ($bytes === '') throw ApiError::validation('image is zero bytes');

        $mime = isset($b['mime_type']) && is_string($b['mime_type']) ? $b['mime_type'] : 'image/png';
        $ext = str_contains($mime, 'jpeg') ? 'jpg' : 'png';
        $rel = 'images/' . Ist::date() . '/' . $b['image_uid'] . '.' . $ext;

        // Written before the transaction opens. The filesystem is not
        // transactional, so one of the two failure shapes has to be chosen: an
        // orphan file that the sweeper collects, or a row pointing at bytes that
        // were rolled away. The orphan is the harmless one.
        self::writeBytes($rel, $bytes);

        return Db::tx(static function () use ($b, $p, $r, $idx, $rel, $bytes, $actual, $mime) {
            try {
                Db::exec(
                    'INSERT INTO post_images (
                       image_uid, post_id, option_index, generated_by_run_id,
                       provider, provider_model, provider_job_id, prompt_text, negative_prompt, seed, aspect_ratio,
                       source_url, source_url_fetched_at, storage_path, public_url, mime_type,
                       width_px, height_px, bytes, sha256, alt_text, concept_label, ocr_text, status, verified_at, created_at)
                     VALUES (?,?,?,?, ?,?,?,?,?,?,?, ?,?,?,?,?, ?,?,?,?,?,?,?,?,?,?)',
                    [
                        $b['image_uid'], (int) $p['id'], $idx, (int) $r['id'],
                        $b['provider'] ?? 'higgsfield', $b['provider_model'] ?? null, $b['provider_job_id'] ?? null,
                        $b['prompt_text'] ?? '', $b['negative_prompt'] ?? null,
                        isset($b['seed']) ? (int) $b['seed'] : null, $b['aspect_ratio'] ?? null,
                        $b['source_url'] ?? null, Dt::nowDb(), $rel, '/media/' . $rel, $mime,
                        isset($b['width_px']) ? (int) $b['width_px'] : null,
                        isset($b['height_px']) ? (int) $b['height_px'] : null,
                        strlen($bytes), $actual,
                        $b['alt_text'] ?? null, $b['concept_label'] ?? null, $b['ocr_text'] ?? null,
                        'stored', Dt::nowDb(), Dt::nowDb(),
                    ]
                );
            } catch (PDOException $e) {
                if (!Db::isDuplicate($e)) throw $e;
                // uq_images_post_option is the arbiter. Two A3 workers racing on
                // the same image_uid land here; the loser replays the winner's row.
                $again = Db::row('SELECT * FROM post_images WHERE image_uid = ?', [$b['image_uid']]);
                if ($again !== null) return [200, ['data' => Views::image($again), 'replayed' => true]];
                // A different image already holds this option slot. Not retryable:
                // A3 must pick the other slot or start a new post, not try again.
                $held = Db::row('SELECT image_uid FROM post_images WHERE post_id = ? AND option_index = ?',
                    [(int) $p['id'], $idx]);
                throw ApiError::validation("option_index {$idx} is already taken by another image",
                    ['existing_image_uid' => $held['image_uid'] ?? null]);
            }

            $count = (int) Db::one('SELECT COUNT(*) FROM post_images WHERE post_id = ?', [(int) $p['id']]);

            // >= 1, not >= 2: a single-image post is degraded, not unsubmittable.
            // A4 flags it; the owner decides. Conditional on the state so a second
            // upload cannot drag an already-submitted post back to images_ready.
            if ($count >= 1) {
                Db::exec(
                    "UPDATE posts SET lifecycle_state='images_ready', image_run_id=?, updated_at=?
                      WHERE id=? AND lifecycle_state='images_pending'",
                    [(int) $r['id'], Dt::nowDb(), (int) $p['id']]
                );
            }

            $row = Db::row('SELECT * FROM post_images WHERE image_uid = ?', [$b['image_uid']]);
            return [201, ['data' => Views::image($row) + ['image_count' => $count]]];
        });
    }

    /**
     * GET /media/<relative path>.
     *
     * Access control belongs to the caller. This does two things only: prove the
     * path cannot escape GO_MEDIA, and put the bytes on the wire.
     *
     * The order is deliberate. The whitelist runs first because it is a grammar
     * that simply cannot express `..`, a null byte or an absolute path - there is
     * nothing to sanitise, only to match. realpath() then runs as the second
     * line, because a symlink planted inside the media directory would satisfy
     * the grammar and still resolve outside it.
     */
    public static function serve(string $path): void
    {
        if (preg_match('~^images/\d{4}-\d{2}-\d{2}/[0-9a-fA-F-]{36}\.(png|jpg)$~', $path, $m) !== 1) {
            Http::error(ApiError::notFound('media not found'));
            return;
        }

        $base = realpath(GO_MEDIA);
        $real = $base === false ? false : realpath($base . '/' . $path);
        // Separators are normalised because realpath() returns backslashes on
        // Windows and forward slashes everywhere else, and the prefix test has to
        // hold on both.
        $baseN = str_replace('\\', '/', (string) $base);
        $realN = str_replace('\\', '/', (string) $real);
        if ($real === false || !str_starts_with($realN, $baseN . '/') || !is_file($real)) {
            // A missing file gets the same envelope as every other 404, so an
            // agent parsing the response never has to special-case this route.
            Http::error(ApiError::notFound('media not found'));
            return;
        }

        // From the extension whitelist, never from post_images.mime_type: a
        // stored type is client-supplied data, and serving text/html out of the
        // media directory would be a stored XSS on our own origin.
        $type = self::TYPES[strtolower($m[1])];
        $size = filesize($real);

        while (ob_get_level() > 0) { ob_end_clean(); }
        http_response_code(200);
        header('Content-Type: ' . $type);
        header('Content-Length: ' . ($size === false ? 0 : $size));
        header('X-Content-Type-Options: nosniff');
        // private: these are unpublished drafts until the owner approves them,
        // so no shared proxy may keep a copy.
        header('Cache-Control: private, max-age=300');
        readfile($real);
    }

    /** Create the dated folder and drop the bytes in it, or fail loudly. */
    private static function writeBytes(string $rel, string $bytes): void
    {
        $full = GO_MEDIA . '/' . $rel;
        $dir = dirname($full);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new ApiError(500, 'INTERNAL', 'could not create the media directory');
        }
        if (file_put_contents($full, $bytes) !== strlen($bytes)) {
            throw new ApiError(500, 'INTERNAL', 'could not write the image to storage');
        }
    }
}
