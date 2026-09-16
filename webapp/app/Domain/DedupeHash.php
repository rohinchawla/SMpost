<?php declare(strict_types=1);
if (!defined('GO_BOOT')) { http_response_code(404); exit; }

/**
 * The topic fingerprint that stops the same idea being proposed twice.
 *
 * @mock mock-api/server.mjs:78-79
 *
 *   sha256(title.toLowerCase()
 *               .replace(/[^a-z0-9 ]/g, '')
 *               .replace(/\s+/g, ' ')
 *               .trim())
 *
 * Order matters: lowercasing happens BEFORE the strip, so an uppercase letter
 * survives as its lowercase form rather than being deleted.
 *
 * This value also backs the unique index uq_topics_batch_dedupe, so it must be
 * stable forever - changing it would silently let duplicates back in.
 */
final class DedupeHash
{
    public static function of(string $title): string
    {
        // mb_strtolower, not strtolower: strtolower is byte-wise and locale
        // dependent, where JavaScript's toLowerCase is Unicode-aware.
        $s = mb_strtolower($title, 'UTF-8');
        $s = preg_replace('/[^a-z0-9 ]/u', '', $s) ?? '';
        $s = preg_replace('/(?:' . Js::WS . ')+/u', ' ', $s) ?? '';
        return hash('sha256', Js::trim($s));
    }
}
