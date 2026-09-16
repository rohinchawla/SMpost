<?php declare(strict_types=1);
if (!defined('GO_BOOT')) { http_response_code(404); exit; }

/**
 * JavaScript-compatible string primitives.
 *
 * The mock is JavaScript, where \s, \S and String.trim() are Unicode-aware.
 * PCRE's are ASCII-only. A single non-breaking space - entirely plausible in
 * copy pasted from a PDF or a press release - would otherwise make the word
 * count differ between the two implementations, and the word count is a hard
 * gate that decides whether a post can be saved at all.
 *
 * Do not "simplify" these to trim() and \s.
 */
final class Js
{
    /** Exactly ECMAScript WhiteSpace u LineTerminator. */
    public const WS = '[\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]';

    /** JS String.prototype.trim(). PHP's trim() misses U+00A0 and U+2028. */
    public static function trim(string $s): string
    {
        return preg_replace('/^(?:' . self::WS . ')+|(?:' . self::WS . ')+$/u', '', $s) ?? $s;
    }

    /** Split on runs of JS whitespace, dropping empties, like s.split(/\s+/).filter(Boolean). */
    public static function words(string $s): array
    {
        $t = self::trim($s);
        if ($t === '') return [];
        $parts = preg_split('/(?:' . self::WS . ')+/u', $t) ?: [];
        return array_values(array_filter($parts, static fn($w) => $w !== ''));
    }
}
