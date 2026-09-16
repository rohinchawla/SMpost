<?php declare(strict_types=1);
if (!defined('GO_BOOT')) { http_response_code(404); exit; }

/**
 * Authentication for the human screens.
 *
 * Password only, by the owner's decision. That makes the surrounding controls
 * matter more than they otherwise would: Argon2id, per-IP and per-account rate
 * limiting, lockout, session fixation defence, and a session that lives in the
 * database rather than in a shared-hosting /tmp.
 *
 * The users table carries an unused totp_secret column, so switching a second
 * factor on later is a login-controller change and not a migration.
 */
final class UiAuth
{
    private const IDLE_SECONDS     = 3600;      // 60 minutes
    private const ABSOLUTE_SECONDS = 43200;     // 12 hours
    private const IP_MAX_FAILS     = 10;        // per 15 minutes
    private const ACCOUNT_MAX_FAILS = 5;        // then lock

    private static ?array $user = null;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        require_once GO_APP . '/Auth/DbSessionHandler.php';
        session_set_save_handler(new DbSessionHandler(), true);
        session_name('gosid');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');   // reject an attacker-supplied SID
        ini_set('session.use_only_cookies', '1');
        ini_set('session.sid_length', '64');
        session_start();
    }

    public static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['SERVER_PORT'] ?? '') === '443'
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }

    public static function attempt(string $email, string $password): array
    {
        $ip = self::ipBin();
        $emailHash = hash('sha256', mb_strtolower(trim($email)));

        if (self::recentFailures('ip', $ip) >= self::IP_MAX_FAILS) {
            throw new ApiError(429, 'RATE_LIMITED', 'Too many attempts. Wait 15 minutes and try again.');
        }

        $user = Db::row('SELECT * FROM users WHERE email = ? AND is_active = 1', [mb_strtolower(trim($email))]);

        if ($user !== null && $user['locked_until'] !== null && strtotime((string) $user['locked_until'] . ' UTC') > time()) {
            self::record($ip, $emailHash, false);
            throw new ApiError(429, 'RATE_LIMITED', 'This account is locked for a few minutes after repeated failures.');
        }

        // Always spend the cost of a verify, so a missing account and a wrong
        // password take the same time and return the same words.
        $hash = $user['password_hash'] ?? '$argon2id$v=19$m=47104,t=3,p=1$Y29uc3RhbnR0aW1l$0000000000000000000000000000000000000000000';
        $ok = password_verify($password, (string) $hash) && $user !== null;

        self::record($ip, $emailHash, $ok);

        if (!$ok) {
            if ($user !== null) self::countFailure((int) $user['id']);
            throw ApiError::unauthorized('That email and password do not match.');
        }

        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_ARGON2ID, self::argonOptions())) {
            Db::exec('UPDATE users SET password_hash = ? WHERE id = ?',
                [password_hash($password, PASSWORD_ARGON2ID, self::argonOptions()), (int) $user['id']]);
        }

        Db::exec('UPDATE users SET failed_attempts = 0, locked_until = NULL, last_login_at = UTC_TIMESTAMP(3) WHERE id = ?',
            [(int) $user['id']]);

        self::start();
        session_regenerate_id(true);               // fixation defence
        $_SESSION['uid']       = (int) $user['id'];
        $_SESSION['role']      = (string) $user['role'];
        $_SESSION['email']     = (string) $user['email'];
        $_SESSION['started']   = time();
        $_SESSION['seen']      = time();
        $_SESSION['csrf']      = bin2hex(random_bytes(32));
        $_SESSION['ua']        = self::uaHash();

        return $user;
    }

    public static function argonOptions(): array
    {
        $cfg = go_setting('argon2', []);
        return [
            'memory_cost' => (int) ($cfg['memory_cost'] ?? 47104),
            'time_cost'   => (int) ($cfg['time_cost'] ?? 3),
            'threads'     => (int) ($cfg['threads'] ?? 1),
        ];
    }

    /** The signed-in user, or null. */
    public static function user(): ?array
    {
        if (self::$user !== null) return self::$user;
        self::start();
        if (empty($_SESSION['uid'])) return null;

        $now = time();
        if (($now - (int) ($_SESSION['seen'] ?? 0)) > self::IDLE_SECONDS
            || ($now - (int) ($_SESSION['started'] ?? 0)) > self::ABSOLUTE_SECONDS) {
            self::logout();
            return null;
        }
        // Bound to the user agent, not the IP: Indian mobile networks rotate
        // addresses constantly and IP binding would just sign the owner out all day.
        if (($_SESSION['ua'] ?? '') !== self::uaHash()) {
            self::logout();
            return null;
        }

        $_SESSION['seen'] = $now;
        $u = Db::row('SELECT * FROM users WHERE id = ? AND is_active = 1', [(int) $_SESSION['uid']]);
        if ($u === null) { self::logout(); return null; }
        return self::$user = $u;
    }

    /**
     * Guard for every UI route.
     *
     * A leaked agent key must not be replayable against the human screens, so
     * the presence of an Authorization header is refused outright before the
     * session is even consulted.
     */
    public static function require(): array
    {
        if (Router::hasAuthorizationHeader()) {
            throw ApiError::forbidden('this URL is for the review screens; agents use /api/v1/agent/');
        }
        $u = self::user();
        if ($u === null) throw ApiError::unauthorized('sign in to continue');
        return $u;
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        self::$user = null;
    }

    public static function csrfToken(): string
    {
        self::start();
        if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
        return (string) $_SESSION['csrf'];
    }

    /** SameSite=Lax alone does not stop a top-level POST, so every mutation carries a token. */
    public static function requireCsrf(): void
    {
        self::start();
        $sent = (string) ($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if ($sent === '' || !hash_equals((string) ($_SESSION['csrf'] ?? ''), $sent)) {
            throw ApiError::forbidden('this form expired; reload the page and try again');
        }
    }

    public static function createUser(string $email, string $password, string $role = 'owner', ?string $name = null): int
    {
        Db::exec('INSERT INTO users (email, password_hash, display_name, role, password_changed_at)
                  VALUES (?,?,?,?,UTC_TIMESTAMP(3))',
            [mb_strtolower(trim($email)), password_hash($password, PASSWORD_ARGON2ID, self::argonOptions()), $name, $role]);
        return Db::lastId();
    }

    // --- internals ---------------------------------------------------------

    private static function uaHash(): string
    {
        return hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    }

    private static function ipBin(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $packed = @inet_pton($ip);
        return $packed === false ? inet_pton('0.0.0.0') : $packed;
    }

    private static function record(string $ip, string $emailHash, bool $ok): void
    {
        Db::exec('INSERT INTO login_attempts (ip, email_hash, ok, attempted_at) VALUES (?,?,?,UTC_TIMESTAMP(3))',
            [$ip, $emailHash, $ok ? 1 : 0]);
    }

    private static function recentFailures(string $by, string $value): int
    {
        $col = $by === 'ip' ? 'ip' : 'email_hash';
        return (int) Db::one(
            "SELECT COUNT(*) FROM login_attempts
              WHERE {$col} = ? AND ok = 0 AND attempted_at > UTC_TIMESTAMP(3) - INTERVAL 15 MINUTE",
            [$value]
        );
    }

    /** Five consecutive failures locks the account for fifteen minutes. */
    private static function countFailure(int $userId): void
    {
        Db::exec('UPDATE users SET failed_attempts = failed_attempts + 1 WHERE id = ?', [$userId]);
        $n = (int) Db::one('SELECT failed_attempts FROM users WHERE id = ?', [$userId]);
        if ($n >= self::ACCOUNT_MAX_FAILS) {
            Db::exec('UPDATE users SET locked_until = UTC_TIMESTAMP(3) + INTERVAL 15 MINUTE, failed_attempts = 0 WHERE id = ?',
                [$userId]);
        }
    }
}
