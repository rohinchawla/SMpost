<?php declare(strict_types=1);
if (!defined('GO_BOOT')) { http_response_code(404); exit; }

/**
 * The 100-word cap, and the link rule.
 *
 * @mock mock-api/server.mjs:68-76 (bodyWordCount)
 * @mock mock-api/server.mjs:506, 640 (the URL test)
 *
 * This is the most-used gate in the system and it must agree with the mock to
 * the word. Two things a reader should check against the JavaScript:
 *
 *  1. The filter tests the TRIMMED line but keeps the ORIGINAL line. The mock
 *     does `.filter((l) => !/^\s*#\S/.test(l.trim()))`, which returns `l`, not
 *     `l.trim()`. Leading indentation therefore survives into the join and is
 *     collapsed by the final split, so it cannot change the count - but the
 *     shape is kept identical so the two can be diffed line by line.
 *  2. JavaScript's \s and \S are Unicode-aware. A non-breaking space pasted from
 *     a PDF is whitespace to the mock and not to a naive PCRE \s, which would
 *     make the same body count differently in the two implementations. Js::WS
 *     spells the ECMAScript set out.
 */
final class Words
{
    /** Hashtag-only lines are excluded: the owner's cap covers the body alone. */
    public static function bodyWordCount(?string $body): int
    {
        $s = $body ?? '';
        $kept = [];
        // The mock splits on '\n' only, never on '\r\n'.
        foreach (explode("\n", $s) as $line) {
            if (self::isHashtagLine($line)) continue;
            $kept[] = $line;
        }
        return count(Js::words(implode(' ', $kept)));
    }

    /** A line whose first non-whitespace character is '#' followed by a non-space. */
    public static function isHashtagLine(string $line): bool
    {
        $pattern = '/^(?:' . Js::WS . ')*#(?!(?:' . Js::WS . ')).' . '/u';
        return preg_match($pattern, Js::trim($line)) === 1;
    }

    /**
     * A URL anywhere in the body.
     *
     * A link in the post body suppresses reach, so the contract refuses one
     * outright and it goes in the first comment instead.
     */
    public static function hasUrl(string $s): bool
    {
        return preg_match('~https?://|www\.~i', $s) === 1;
    }

    /**
     * The exact string sent to LinkedIn: body, a blank line, then the hashtags.
     *
     * @mock server.mjs:729
     *
     * Js::trim, not trim(): A5 hashes this and matches on the hash to decide
     * whether an ambiguously-timed-out post is already public. A trailing
     * non-breaking space that PHP strips and Node does not would make that
     * check fail at the worst possible moment.
     */
    public static function commentary(string $finalBody, array $tags): string
    {
        return Js::trim($finalBody . "\n\n" . implode(' ', $tags));
    }
}
