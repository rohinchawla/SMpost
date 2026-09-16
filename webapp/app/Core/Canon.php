<?php declare(strict_types=1);
if (!defined('GO_BOOT')) { http_response_code(404); exit; }

/**
 * Canonical JSON encoding.
 *
 * Two flags matter and both are chosen to match Node's JSON.stringify:
 *   JSON_UNESCAPED_SLASHES - PHP writes "http:\/\/" by default and Node does
 *     not. Almost every A1 body carries a source_url, so this one is not
 *     cosmetic.
 *   JSON_UNESCAPED_UNICODE - PHP escapes non-ASCII to \uXXXX and Node does not.
 *
 * JSON_PRESERVE_ZERO_FRACTION is deliberately NOT set: without it json_encode(1.0)
 * gives "1", which is what Node produces too.
 */
final class Canon
{
    public const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    public static function encode(mixed $v): string
    {
        return json_encode($v, self::FLAGS);
    }

    /**
     * The canonical form of a hashtag list.
     *
     * array_values() is explicit rather than assumed: this string is hashed into
     * approved_content_hash, and a re-keyed array would silently become a JSON
     * object and change the hash.
     */
    public static function tags(array $tags): string
    {
        return json_encode(array_values($tags), self::FLAGS);
    }

    /** Decode, returning $default rather than throwing on malformed stored JSON. */
    public static function decode(?string $json, mixed $default = null): mixed
    {
        if ($json === null || $json === '') return $default;
        try { return json_decode($json, true, 512, JSON_THROW_ON_ERROR); }
        catch (JsonException) { return $default; }
    }
}
