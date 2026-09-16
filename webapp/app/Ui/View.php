<?php declare(strict_types=1);
if (!defined('GO_BOOT')) { http_response_code(404); exit; }

/**
 * Template rendering and the small helpers every screen needs.
 *
 * Plain PHP templates. No engine, because a template engine on shared hosting
 * with no build step is a dependency that buys nothing here.
 */
final class View
{
    private static array $shared = [];

    public static function share(string $k, mixed $v): void { self::$shared[$k] = $v; }

    public static function render(string $template, array $vars = []): void
    {
        $vars += self::$shared;
        $vars['title'] ??= 'Golden Opportunities';

        ob_start();
        self::include($template, $vars);
        $content = ob_get_clean();

        if (!empty($vars['bare'])) {
            Http::html(200, $content);
            return;
        }
        $vars['content'] = $content;
        ob_start();
        self::include('layout', $vars);
        Http::html(200, (string) ob_get_clean());
    }

    public static function partial(string $template, array $vars = []): void
    {
        self::include($template, $vars + self::$shared);
    }

    private static function include(string $template, array $vars): void
    {
        $file = GO_APP . '/views/' . $template . '.php';
        if (!is_file($file)) throw new RuntimeException("missing view: {$template}");
        extract($vars, EXTR_SKIP);
        require $file;
    }

    // --- helpers used throughout the templates -----------------------------

    /** Escape. Named `e` because it appears on nearly every line of every view. */
    public static function e(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** A URL relative to wherever the app is mounted. */
    public static function url(string $path = ''): string
    {
        $base = rtrim(str_replace(chr(92), '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
        $path = ltrim($path, '/');
        return ($base === '' ? '' : $base) . '/' . $path;
    }

    public static function csrf(): string
    {
        return '<input type="hidden" name="_csrf" value="' . self::e(UiAuth::csrfToken()) . '">';
    }

    public static function icon(string $name, int $size = 16): string
    {
        return sprintf('<svg class="go-icon" width="%d" height="%d" aria-hidden="true"><use href="%s#i-%s"></use></svg>',
            $size, $size, self::e(self::url('assets/icons.svg')), self::e($name));
    }

    public static function date(?string $ymd): string
    {
        return Dashboard::prettyDate($ymd);
    }

    public static function dateTime(?string $db): string
    {
        if ($db === null || $db === '') return '-';
        $t = strtotime((string) $db . ' UTC');
        if ($t === false) return '-';
        return gmdate('j M, H:i', $t + Ist::OFFSET_SECONDS);
    }

    public static function num(?int $n): string
    {
        return $n === null ? '-' : number_format($n);
    }

    /**
     * The machine's progress, in English.
     *
     * No screen ever prints a raw column value. The owner's verdict and the
     * machine's progress are two different things rendered in two different
     * registers, and this is the second one.
     */
    public static function progress(array $p): string
    {
        return match ($p['lifecycle_state'] ?? '') {
            'draft', 'images_pending' => 'waiting for images',
            'images_ready'            => 'images ready',
            'in_review'               => 'waiting for you',
            'ready'                   => 'ready to publish',
            'scheduled'               => 'scheduled for ' . self::date($p['scheduled_date_ist'] ?? null),
            'publishing'              => 'publishing now',
            'posted'                  => 'published ' . self::date($p['posted_date_ist'] ?? null),
            'failed'                  => 'publish failed: ' . ($p['last_error_code'] ?? 'unknown'),
            'expired'                 => 'expired ' . self::date($p['expires_at_ist'] ?? null),
            'archived'                => 'archived',
            default                   => '',
        };
    }

    public static function topicProgress(array $t): string
    {
        return match ($t['pipeline_state'] ?? '') {
            'awaiting_copy'    => 'waiting for the writer',
            'copy_in_progress' => 'being written',
            'copy_done'        => 'written',
            'discarded'        => 'out of the pipeline',
            default            => '',
        };
    }
}
