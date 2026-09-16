<?php declare(strict_types=1);
if (!defined('GO_BOOT')) { http_response_code(404); exit; }

/**
 * Every number in a post, beside the source sentence it came from.
 *
 * Rule 2 says a numeral in a post must appear verbatim in a quoted sentence from
 * the source. A4 already checked that. This checks it again, at review time, on
 * the bytes currently in final_body - which is the point: the body is editable on
 * the review screen, so the agent's verdict was reached on a different string.
 * A number typed in by hand five seconds ago has no provenance and must say so.
 *
 * Read-only. It never writes, never repairs, and never guesses a source.
 */
final class Provenance
{
    /**
     * A numeral as a reader sees one: digits, optional thousands separators, an
     * optional decimal tail, an optional percent sign. Deliberately not \d+ -
     * "11.15%" is one claim, not the three numbers 11, 15 and a stray symbol.
     */
    private const NUMERAL = '/\d[\d,]*(?:\.\d+)?%?/';

    /** Roughly one line of a phone screen. Enough to recognise the sentence. */
    private const CONTEXT_CHARS = 40;

    /**
     * One row per numeral in the body.
     *
     * @return list<array{value:string,context:string,quote:?string,source_url:?string,traced:bool}>
     */
    public static function scan(array $post): array
    {
        $body = (string) ($post['final_body'] ?? '');
        if ($body === '') return [];

        $entries = self::entries($post);
        if (preg_match_all(self::NUMERAL, $body, $m, PREG_OFFSET_CAPTURE) === 0) return [];

        $out = [];
        foreach ($m[0] as $hit) {
            [$value, $offset] = $hit;

            // A year is a date, not a claim, and nobody quotes a source to prove
            // that 2025 happened. Only a bare year is dropped: "2,024" and
            // "2024%" are still numbers somebody has to stand behind.
            if (self::isBareYear($value)) continue;

            $entry = self::findEntry($value, $entries);
            $out[] = [
                'value'      => $value,
                'context'    => self::context($body, $offset, strlen($value)),
                'quote'      => $entry === null ? null : (string) ($entry['source_quote'] ?? ''),
                'source_url' => $entry === null ? null : (($entry['source_url'] ?? null) === null ? null : (string) $entry['source_url']),
                'traced'     => $entry !== null,
            ];
        }
        return $out;
    }

    /** How many numbers in this post nobody can point at a source for. */
    public static function untracedCount(array $post): int
    {
        $n = 0;
        foreach (self::scan($post) as $row) {
            if (!$row['traced']) $n++;
        }
        return $n;
    }

    // ------------------------------------------------------------- internals

    /** numbers_used, decoded defensively: a malformed column must not 500 a screen. */
    private static function entries(array $post): array
    {
        $raw = $post['numbers_used'] ?? null;
        $decoded = Canon::decode(is_string($raw) ? $raw : null, []);
        if (!is_array($decoded)) return [];

        $out = [];
        foreach ($decoded as $e) {
            if (is_array($e)) $out[] = $e;
        }
        return $out;
    }

    /** The first entry that backs this numeral, or null. */
    private static function findEntry(string $value, array $entries): ?array
    {
        $want = self::normalise($value);
        if ($want === '') return null;

        foreach ($entries as $e) {
            if (self::normalise((string) ($e['value'] ?? '')) === $want) return $e;
            if (in_array($want, self::numeralsIn((string) ($e['source_quote'] ?? '')), true)) return $e;
        }
        return null;
    }

    /**
     * The numerals a quote actually contains.
     *
     * A plain substring search would trace "11.15" to a quote saying "111.15",
     * and tracing a number to a source that does not carry it is the exact
     * failure this screen exists to catch. So the quote is tokenised the same way
     * the body is, and the comparison is between whole numerals.
     *
     * @return list<string> normalised
     */
    private static function numeralsIn(string $quote): array
    {
        if ($quote === '' || preg_match_all(self::NUMERAL, $quote, $m) === 0) return [];
        return array_map([self::class, 'normalise'], $m[0]);
    }

    /** "11.15%" and "1,115" both reduce to their digits, so formatting never hides a match. */
    private static function normalise(string $v): string
    {
        return str_replace([',', '%', ' '], '', trim($v));
    }

    private static function isBareYear(string $v): bool
    {
        if (preg_match('/^\d{4}$/', $v) !== 1) return false;
        $n = (int) $v;
        return $n >= 1900 && $n <= 2100;
    }

    /**
     * ~40 characters of the sentence the numeral sits in, centred on it.
     *
     * Offsets from preg are byte offsets, so every cut is snapped back to a UTF-8
     * boundary afterwards rather than trusting the body to be ASCII.
     */
    private static function context(string $body, int $at, int $len): string
    {
        $start = 0;
        for ($i = $at - 1; $i >= 0; $i--) {
            if (self::endsSentence($body, $i)) { $start = $i + 1; break; }
        }
        $n = strlen($body);
        $end = $n;
        for ($i = $at + $len; $i < $n; $i++) {
            if (self::endsSentence($body, $i)) { $end = $i + 1; break; }
        }

        $raw  = substr($body, $start, $end - $start);
        $rel  = $at - $start;
        $lead = strlen($raw) - strlen(ltrim($raw));
        $raw  = trim($raw);
        $rel  = max(0, $rel - $lead);

        $width = self::CONTEXT_CHARS;
        if (strlen($raw) <= $width + 4) return $raw;

        $from = (int) max(0, $rel + intdiv($len, 2) - intdiv($width, 2));
        $from = (int) min($from, strlen($raw) - $width);
        $snip = self::utf8Clip(substr($raw, $from, $width));

        return ($from > 0 ? "\u{2026}" : '') . $snip . ($from + $width < strlen($raw) ? "\u{2026}" : '');
    }

    /** A full stop between two digits is a decimal point, not the end of a thought. */
    private static function endsSentence(string $s, int $i): bool
    {
        $c = $s[$i];
        if ($c === "\n") return true;
        if ($c === '!' || $c === '?') return true;
        if ($c !== '.') return false;
        return !(isset($s[$i - 1], $s[$i + 1]) && ctype_digit($s[$i - 1]) && ctype_digit($s[$i + 1]));
    }

    /** Drop a half-character at either end of a byte-sliced window. */
    private static function utf8Clip(string $s): string
    {
        while ($s !== '' && (ord($s[0]) & 0xC0) === 0x80) {
            $s = substr($s, 1);
        }
        $len = strlen($s);
        for ($i = $len - 1; $i >= 0 && $i > $len - 5; $i--) {
            $b = ord($s[$i]);
            if (($b & 0xC0) === 0x80) continue;
            $need = $b >= 0xF0 ? 4 : ($b >= 0xE0 ? 3 : ($b >= 0xC0 ? 2 : 1));
            if ($i + $need > $len) $s = substr($s, 0, $i);
            break;
        }
        return $s;
    }
}
