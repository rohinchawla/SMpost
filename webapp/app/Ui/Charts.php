<?php declare(strict_types=1);
if (!defined('GO_BOOT')) { http_response_code(404); exit; }

/**
 * Inline SVG, rendered in PHP. No chart library, no JavaScript.
 *
 * On shared hosting a charting bundle is 200 KB of dependency for six lines of
 * geometry, and it would put the most load-bearing honesty rule in this app
 * behind a minified third party.
 *
 * That rule: a day with no reading is a GAP, not a zero. post_metrics_daily
 * stores a metric the API did not return as NULL for exactly this reason, and a
 * chart that joins across the hole - or plots it at zero, or carries the last
 * value forward - invents data. Rule 1. So a missing day is drawn as a visible
 * band of absence, the line stops at its edge, and every reading gets its own
 * dot so a single point between two gaps is not rendered as nothing at all.
 */
final class Charts
{
    private const PAD_L = 44;   // room for the y labels
    private const PAD_R = 8;
    private const PAD_T = 10;
    private const PAD_B = 20;   // room for the x ticks
    private const GRIDLINES = 4;
    private const MAX_DAYS = 400;

    /**
     * One metric over a date range.
     *
     * $rows are post_metrics_daily rows carrying metric_date_ist and $metric,
     * already ordered by date. $opts takes:
     *   from  YYYY-MM-DD, the range start. Defaults to the earliest row.
     *   to    YYYY-MM-DD, the range end.   Defaults to today, IST.
     *   label text for aria-label.
     *
     * Returns '' when the range is empty, so a view can show its own empty state.
     */
    public static function series(array $rows, string $metric, int $w, int $h, array $opts = []): string
    {
        $byDate = self::byDate($rows);
        $from = (string) ($opts['from'] ?? (array_key_first($byDate) ?? Ist::date()));
        $to   = (string) ($opts['to']   ?? Ist::date());
        $days = self::days($from, $to);
        if ($days === []) return '';

        $n     = count($days);
        $plotW = (float) max(1, $w - self::PAD_L - self::PAD_R);
        $plotH = (float) max(1, $h - self::PAD_T - self::PAD_B);
        $step  = $n > 1 ? $plotW / ($n - 1) : 0.0;

        // null means no reading. A row that exists with a NULL metric is exactly
        // as absent as no row at all, and is treated the same way.
        $vals = [];
        $max  = 0.0;
        foreach ($days as $i => $d) {
            $row = $byDate[$d] ?? null;
            $v = ($row !== null && array_key_exists($metric, $row) && $row[$metric] !== null)
                ? (float) $row[$metric] : null;
            $vals[$i] = $v;
            if ($v !== null && $v > $max) $max = $v;
        }

        $top = self::niceStep($max / (self::GRIDLINES - 1)) * (self::GRIDLINES - 1);
        if ($top <= 0.0) $top = (float) (self::GRIDLINES - 1);

        $x = static fn(int $i): float => $n > 1
            ? self::PAD_L + ($i * $step)
            : self::PAD_L + ($plotW / 2);
        $y = static fn(float $v): float => self::PAD_T + $plotH - (($v / $top) * $plotH);

        $svg = [];

        // --- gridlines and y labels ---------------------------------------
        $grid = [];
        $ylab = [];
        for ($g = 0; $g < self::GRIDLINES; $g++) {
            $v  = $top * $g / (self::GRIDLINES - 1);
            $gy = $y($v);
            $grid[] = sprintf('<line x1="%s" y1="%s" x2="%s" y2="%s"/>',
                self::co(self::PAD_L), self::co($gy), self::co(self::PAD_L + $plotW), self::co($gy));
            $ylab[] = sprintf('<text x="%s" y="%s" font-size="11" text-anchor="end">%s</text>',
                self::co(self::PAD_L - 6), self::co($gy + 4), View::e(self::tick($v, $top)));
        }
        $svg[] = '<g class="go-chart-grid" shape-rendering="crispEdges">' . implode('', $grid) . '</g>';
        $svg[] = '<g class="go-chart-ylabels">' . implode('', $ylab) . '</g>';

        // --- the absences, behind everything ------------------------------
        $gaps = [];
        foreach (self::runs($vals, true) as [$a, $b]) {
            // Half a step either side, so a single missing day is a band you can
            // see rather than a break you have to infer.
            $x0 = max(self::PAD_L, $x($a) - ($step / 2));
            $x1 = min(self::PAD_L + $plotW, $x($b) + ($step / 2));
            if ($x1 - $x0 < 1.0) $x1 = $x0 + 1.0;

            $reason = self::reasonIn($byDate, $days, $a, $b);
            $title  = 'No reading ' . Dashboard::prettyDate($days[$a])
                    . ($a === $b ? '' : ' to ' . Dashboard::prettyDate($days[$b]))
                    . ($reason === null ? '' : ': ' . $reason);

            $gaps[] = sprintf('<rect class="go-chart-gap" x="%s" y="%s" width="%s" height="%s"><title>%s</title></rect>',
                self::co($x0), self::co(self::PAD_T), self::co($x1 - $x0), self::co($plotH), View::e($title));
        }
        if ($gaps !== []) $svg[] = '<g class="go-chart-gaps">' . implode('', $gaps) . '</g>';

        // --- the line, one M per contiguous run ---------------------------
        $d = [];
        foreach (self::runs($vals, false) as [$a, $b]) {
            $run = [];
            for ($i = $a; $i <= $b; $i++) {
                $run[] = ($i === $a ? 'M' : 'L') . self::co($x($i)) . ' ' . self::co($y((float) $vals[$i]));
            }
            $d[] = implode(' ', $run);
        }
        if ($d !== []) {
            $svg[] = '<path class="go-chart-line" fill="none" d="' . View::e(implode(' ', $d)) . '"/>';
        }

        // --- a dot at every reading ---------------------------------------
        $pts = [];
        foreach ($vals as $i => $v) {
            if ($v === null) continue;
            $pts[] = sprintf('<circle class="go-chart-point" cx="%s" cy="%s" r="2.5"><title>%s</title></circle>',
                self::co($x($i)), self::co($y($v)),
                View::e(Dashboard::prettyDate($days[$i]) . ': ' . self::tick($v, $top)));
        }
        if ($pts !== []) $svg[] = '<g class="go-chart-points">' . implode('', $pts) . '</g>';

        // --- x ticks, anchored on the most recent day ---------------------
        $xlab = [];
        for ($i = $n - 1; $i >= 0; $i -= self::tickEvery($n)) {
            $xlab[] = sprintf('<text x="%s" y="%s" font-size="11" text-anchor="middle">%s</text>',
                self::co($x($i)), self::co((float) $h - 5), View::e(Dashboard::prettyDate($days[$i])));
        }
        $svg[] = '<g class="go-chart-xlabels">' . implode('', $xlab) . '</g>';

        $label = (string) ($opts['label'] ?? ($metric . ', ' . Dashboard::prettyDate($from) . ' to ' . Dashboard::prettyDate($to)));

        return sprintf(
            '<svg class="go-chart" viewBox="0 0 %d %d" width="%d" height="%d" role="img" aria-label="%s">%s</svg>',
            $w, $h, $w, $h, View::e($label), implode('', $svg)
        );
    }

    /**
     * The absences, in text, so the reasons can be read out beneath the chart.
     *
     * A date is a gap when no row exists for it, or when the row carries a
     * gap_reason - A6 writes that row precisely to say "we looked and could not
     * read it", and the two are the same absence to a reader.
     *
     * @return list<array{from:string,to:string,reason:?string}>
     */
    public static function gapList(array $rows, string $from, string $to): array
    {
        $byDate = self::byDate($rows);
        $out = [];
        $run = null;

        foreach (self::days($from, $to) as $d) {
            $row    = $byDate[$d] ?? null;
            $reason = self::reason($row);
            if ($row === null || $reason !== null) {
                if ($run === null) {
                    $run = ['from' => $d, 'to' => $d, 'reason' => $reason];
                } else {
                    $run['to'] = $d;
                    $run['reason'] ??= $reason;
                }
            } elseif ($run !== null) {
                $out[] = $run;
                $run = null;
            }
        }
        if ($run !== null) $out[] = $run;

        return $out;
    }

    // ------------------------------------------------------------- internals

    /** @return list<string> every IST date from $from to $to inclusive */
    private static function days(string $from, string $to): array
    {
        $a = strtotime($from . ' UTC');
        $b = strtotime($to . ' UTC');
        if ($a === false || $b === false || $b < $a) return [];

        $out = [];
        for ($t = $a; $t <= $b && count($out) < self::MAX_DAYS; $t += 86400) {
            $out[] = gmdate('Y-m-d', $t);
        }
        return $out;
    }

    private static function byDate(array $rows): array
    {
        $map = [];
        foreach ($rows as $r) {
            $d = (string) ($r['metric_date_ist'] ?? '');
            if ($d !== '') $map[$d] = $r;
        }
        return $map;
    }

    private static function reason(?array $row): ?string
    {
        $r = $row === null ? null : ($row['gap_reason'] ?? null);
        return ($r === null || (string) $r === '') ? null : (string) $r;
    }

    /** The first reason recorded anywhere inside a gap run. */
    private static function reasonIn(array $byDate, array $days, int $a, int $b): ?string
    {
        for ($i = $a; $i <= $b; $i++) {
            $r = self::reason($byDate[$days[$i]] ?? null);
            if ($r !== null) return $r;
        }
        return null;
    }

    /**
     * Contiguous index runs, either of nulls ($missing) or of readings.
     *
     * @return list<array{0:int,1:int}>
     */
    private static function runs(array $vals, bool $missing): array
    {
        $out = [];
        $start = null;
        foreach ($vals as $i => $v) {
            $hit = $missing ? ($v === null) : ($v !== null);
            if ($hit) {
                if ($start === null) $start = $i;
            } elseif ($start !== null) {
                $out[] = [$start, $i - 1];
                $start = null;
            }
        }
        if ($start !== null) $out[] = [$start, array_key_last($vals)];
        return $out;
    }

    /** 1, 2 or 5 times a power of ten, so the axis reads in round numbers. */
    private static function niceStep(float $raw): float
    {
        if ($raw <= 0.0) return 1.0;
        $mag = 10 ** floor(log10($raw));
        $n   = $raw / $mag;
        $mult = $n <= 1 ? 1 : ($n <= 2 ? 2 : ($n <= 5 ? 5 : 10));
        return $mult * $mag;
    }

    /** engagement_rate needs decimals; impressions never do. */
    private static function tick(float $v, float $top): string
    {
        if ($top < 10.0) return rtrim(rtrim(number_format($v, 2), '0'), '.');
        return number_format($v);
    }

    /** Roughly weekly, and never so many ticks that they overprint. */
    private static function tickEvery(int $n): int
    {
        return max(7, (int) ceil($n / 12 / 7) * 7);
    }

    /** A coordinate, short. sprintf keeps the locale out of it; %f would not. */
    private static function co(float $v): string
    {
        $s = sprintf('%.2f', $v);
        return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
    }
}
