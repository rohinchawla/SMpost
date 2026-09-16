<?php declare(strict_types=1);
if (!defined('GO_BOOT')) { http_response_code(404); exit; }

/**
 * Image bytes on disk.
 *
 * Images live under storage/, outside the document root, and are served by PHP.
 * The mock serves them with no auth at all, which is fine for a laptop and not
 * for a public host: an unapproved draft image should not be guessable by URL.
 *
 * Three ways to be allowed, so nothing in the agents has to change:
 *   1. a signed-in session   - the review screens render <img src="/media/...">
 *   2. a valid bearer key    - an agent fetching the bytes
 *   3. a signed URL          - what publishLease hands to A5
 */
final class Media
{
    /** images/YYYY-MM-DD/<uuid>.<ext> - a shape that cannot express traversal. */
    public const REL_PATTERN = '~^images/\d{4}-\d{2}-\d{2}/[0-9a-fA-F-]{36}\.(png|jpg)$~';

    public static function relativePath(string $imageUid, string $mime): string
    {
        $ext = str_contains(strtolower($mime), 'jpeg') ? 'jpg' : 'png';
        return 'images/' . Ist::date() . '/' . $imageUid . '.' . $ext;
    }

    public static function write(string $rel, string $bytes): string
    {
        $abs = GO_MEDIA . '/' . $rel;
        $dir = dirname($abs);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new ApiError(500, 'INTERNAL', 'could not create the media directory');
        }
        if (@file_put_contents($abs, $bytes) === false) {
            throw new ApiError(500, 'INTERNAL', 'could not write the image file');
        }
        @chmod($abs, 0644);
        return $abs;
    }

    /** Resolve a request path to a real file, or null. Two independent defences. */
    public static function resolve(string $rel): ?string
    {
        if (preg_match(self::REL_PATTERN, $rel) !== 1) return null;
        $root = realpath(GO_MEDIA);
        $file = realpath(GO_MEDIA . '/' . $rel);
        if ($root === false || $file === false) return null;
        if (!str_starts_with($file, $root . DIRECTORY_SEPARATOR)) return null;
        return is_file($file) ? $file : null;
    }

    public static function serve(string $rel): void
    {
        $file = self::resolve($rel);
        if ($file === null) throw ApiError::notFound('media not found');

        // The type comes from the extension whitelist, never from a database
        // column: a poisoned row must not be able to serve text/html.
        $type = str_ends_with($file, '.jpg') ? 'image/jpeg' : 'image/png';

        while (ob_get_level() > 0) { ob_end_clean(); }
        http_response_code(200);
        header('Content-Type: ' . $type);
        header('Content-Length: ' . (string) filesize($file));
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: inline');
        header('Cache-Control: private, max-age=300');
        readfile($file);
    }

    /** A URL A5 can fetch with a plain unauthenticated GET, valid for a window. */
    public static function signedUrl(string $rel, int $ttlSeconds = 7200): string
    {
        $exp = time() + $ttlSeconds;
        $sig = self::sign($rel, $exp);
        return '/media/' . $rel . '?exp=' . $exp . '&sig=' . $sig;
    }

    public static function signatureValid(string $rel, array $query): bool
    {
        $exp = (int) ($query['exp'] ?? 0);
        $sig = (string) ($query['sig'] ?? '');
        if ($exp < time() || $sig === '') return false;
        return hash_equals(self::sign($rel, $exp), $sig);
    }

    private static function sign(string $rel, int $exp): string
    {
        $key = (string) go_setting('app_key', '');
        return rtrim(strtr(base64_encode(hash_hmac('sha256', $rel . '|' . $exp, $key, true)), '+/', '-_'), '=');
    }

    /** Used only by the test harness reset. */
    public static function purgeAll(): void
    {
        $root = GO_MEDIA . '/images';
        if (!is_dir($root)) return;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
    }
}
