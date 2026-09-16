<?php declare(strict_types=1);
if (!defined('GO_BOOT')) { http_response_code(404); exit; }

/**
 * The human surface.
 *
 * Deliberately separate from AgentApi: this dispatcher is the only one that
 * touches a session, and it refuses any request carrying an Authorization
 * header before it looks at anything else. A leaked agent key therefore cannot
 * be replayed against the approval screens even if the session check would
 * otherwise pass.
 */
final class UiApp
{
    public static function dispatch(string $path): void
    {
        // Login is the one route that must work while signed out.
        if ($path === '/login')  { self::login();  return; }
        if ($path === '/logout') { self::logout(); return; }

        $user = UiAuth::require();
        $seg = array_values(array_filter(explode('/', $path), static fn($s) => $s !== ''));
        $head = $seg[0] ?? '';

        // Anything that changes state is a POST carrying a CSRF token.
        if (Router::method() === 'POST') { UiAuth::requireCsrf(); }

        try {
            match (true) {
                $head === ''          => self::today($user),
                $head === 'topics'    => Screens::topics($user, $seg),
                $head === 'posts'     => Screens::posts($user, $seg),
                $head === 'queue'     => Screens::queue($user),
                $head === 'analytics' => Screens::analytics($user, $seg),
                $head === 'ops'       => Screens::ops($user, $seg),
                $head === 'settings'  => Screens::settings($user),
                $head === 'review'    => self::reviewAlias($seg),
                default               => throw ApiError::notFound('no such page'),
            };
        } catch (ApiError $e) {
            // A refused form post is a human being told no, not an API client
            // reading JSON. Hand the screen back with the reason at the top and
            // the words they typed still in the box. Anything 5xx is a fault,
            // not a verdict, so it keeps falling through to the error page.
            if (Router::method() !== 'POST' || $e->status >= 500) { throw $e; }
            self::refuse($path, $e);
        }
    }

    /**
     * Post/Redirect/Get for a refusal: flash the reason, keep the submitted
     * fields, and send the browser back to the screen it came from. Without
     * this, failing the 100-word cap threw the edit away, which is how people
     * learn to be afraid of the Save button.
     */
    private static function refuse(string $path, ApiError $e): void
    {
        $keep = $_POST;
        unset($keep['_csrf'], $keep['action']);
        // A body is a couple of kilobytes. Anything larger is not an edit worth
        // carrying in a session file.
        if (strlen((string) json_encode($keep)) > 65536) { $keep = []; }

        $_SESSION['go_flash'][] = ['level' => 'error', 'text' => $e->getMessage()];
        $_SESSION['go_form'] = ['path' => $path, 'fields' => $keep];
        // Land on the row that was refused, not the top of a list of thirty-five.
        $anchor = '';
        $tuid = (string) ($_POST['topic_uid'] ?? '');
        if ($tuid !== '' && preg_match('/^[0-9a-f-]{36}$/i', $tuid) === 1) { $anchor = '#t-' . $tuid; }
        Http::redirect(View::url(ltrim($path, '/')) . $anchor);
    }

    /** @var array<string,mixed>|null resolved once per request, from the session */
    private static ?array $old = null;

    /**
     * What a refused post was carrying for this field, or $fallback when the
     * screen was reached any other way. Views call this instead of reading the
     * database column directly, so a refusal re-renders the attempt rather than
     * the last thing that saved cleanly.
     */
    public static function old(string $field, mixed $fallback = null): mixed
    {
        if (self::$old === null) {
            $stash = $_SESSION['go_form'] ?? null;
            unset($_SESSION['go_form']);
            self::$old = (is_array($stash) && ($stash['path'] ?? null) === Router::path())
                ? (array) ($stash['fields'] ?? [])
                : [];
        }
        return array_key_exists($field, self::$old) ? self::$old[$field] : $fallback;
    }

    /** A4 records review_url as /review/posts/{uid}; keep it working. */
    private static function reviewAlias(array $seg): void
    {
        $uid = $seg[2] ?? '';
        Http::redirect(View::url('posts/' . rawurlencode($uid)), 301);
    }

    private static function today(array $user): void
    {
        View::render('today', [
            'title'   => 'Today',
            'user'    => $user,
            'counts'  => Dashboard::counts(),
            'alerts'  => Dashboard::alerts(),
            'queue'   => Dashboard::queue(5),
            'lastRuns' => Db::all("SELECT agent_code, MAX(started_at) last_at,
                                          SUBSTRING_INDEX(GROUP_CONCAT(status ORDER BY id DESC), ',', 1) last_status
                                     FROM agent_runs GROUP BY agent_code ORDER BY agent_code"),
        ]);
    }

    private static function login(): void
    {
        UiAuth::start();
        if (UiAuth::user() !== null) { Http::redirect(View::url('')); return; }

        $error = null;
        if (Router::method() === 'POST') {
            try {
                UiAuth::requireCsrf();
                UiAuth::attempt((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''));
                $next = (string) ($_POST['next'] ?? '');
                Http::redirect(View::url(ltrim($next, '/')));
                return;
            } catch (ApiError $e) {
                // A wrong password stays generic on purpose - the same words and
                // the same time whether or not the account exists. Being rate
                // limited or locked is different: it is not a guess to correct,
                // it is a wait, and the one person who uses this app deserves to
                // be told which one he is in.
                $error = $e->status === 429 ? $e->getMessage() : true;
            }
        }

        View::render('login', [
            'title' => 'Sign in',
            'error' => $error,
            'next'  => (string) (Router::query()['next'] ?? ''),
            'bare'  => true,
        ]);
    }

    private static function logout(): void
    {
        UiAuth::logout();
        Http::redirect(View::url('login'));
    }
}
