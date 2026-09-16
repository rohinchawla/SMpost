<?php declare(strict_types=1);
/**
 * The guided installer.
 *
 * This file is deliberately the one place in the application that does NOT go
 * through app/bootstrap.php. Bootstrap installs a JSON exception handler and
 * assumes a config exists; the installer runs when neither is true and has to
 * answer in HTML. So it defines the same constants itself and pulls in only the
 * four classes it actually needs.
 *
 * Its own security is the interesting part. Before the first run there is no
 * database, no user and no session to authenticate against, so the only secret
 * that exists is one the installer writes to disk: storage/install-token.txt.
 * Reading it requires cPanel File Manager, FTP or SSH - i.e. the same access the
 * owner already has and an attacker does not. The attack this closes is real and
 * common: a freshly uploaded folder sits unattended for an hour and someone else
 * runs the installer first, pointing it at their own database and minting
 * themselves the six agent keys.
 *
 * Delete this file once the install finishes. The installer tries to do that
 * itself, and says so loudly if it cannot.
 */

// --------------------------------------------------------------------------
// Boot. No config, no bootstrap.
// --------------------------------------------------------------------------

define('GO_BOOT', true);                       // the guard every app/ file checks
define('GO_ROOT', dirname(__DIR__));
define('GO_APP', GO_ROOT . '/app');
define('GO_STORAGE', GO_ROOT . '/storage');
define('GO_MEDIA', GO_STORAGE . '/media');
define('GO_CONFIG_DIR', GO_ROOT . '/config');
define('GO_CONFIG_FILE', GO_CONFIG_DIR . '/config.php');
define('GO_LOCK_FILE', GO_CONFIG_DIR . '/installed.lock');
define('GO_TOKEN_FILE', GO_STORAGE . '/install-token.txt');
define('GO_ATTEMPTS_FILE', GO_STORAGE . '/install-attempts.json');

define('GO_TOKEN_TTL', 3600);                  // 60 minutes
define('GO_MAX_ATTEMPTS', 5);                  // per IP
define('GO_ATTEMPT_WINDOW', 900);              // per 15 minutes

ini_set('display_errors', '0');                // a trace here could carry the DB password
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
if (is_dir(GO_STORAGE . '/logs')) {
    ini_set('error_log', GO_STORAGE . '/logs/php-error.log');
}
error_reporting(E_ALL);
date_default_timezone_set('UTC');

/**
 * The installed configuration, as the app's own classes expect to find it.
 *
 * bootstrap.php declares these two functions and reads config/config.php. The
 * installer declares them itself and serves the half-built config out of
 * $GLOBALS, so that UiAuth::createUser() hashes the owner's password with the
 * parameters we just benchmarked rather than with the library defaults. Without
 * this the owner's row would be written at 47104/3/1 no matter what step 4 said.
 */
function go_config(): ?array
{
    return $GLOBALS['go_install_cfg'] ?? null;
}

function go_setting(string $key, mixed $default = null): mixed
{
    $cfg = go_config();
    return $cfg[$key] ?? $default;
}

require_once GO_APP . '/Core/ApiError.php';
require_once GO_APP . '/Core/Canon.php';
require_once GO_APP . '/Core/Db.php';
require_once GO_APP . '/Domain/ContentHash.php';
require_once GO_APP . '/Auth/ApiKeys.php';
require_once GO_APP . '/Auth/UiAuth.php';

// --------------------------------------------------------------------------
// Gate 0: already installed.
//
// Checked before the session starts, before anything is read from the request
// and before any database code is reachable. Re-checked at the top of every
// step, because an install can finish in another tab while this one is open.
// --------------------------------------------------------------------------

function go_locked(): bool
{
    clearstatcache(true, GO_LOCK_FILE);
    return is_file(GO_LOCK_FILE);
}

function go_render_locked(): void
{
    http_response_code(410);
    header('Content-Type: text/html; charset=utf-8');
    echo go_head('Already installed');
    echo '<div class="card bad"><h1>Already installed.</h1>'
       . '<p>Delete <code>public/install.php</code>.</p>'
       . '<p class="muted">This installer refuses to run while <code>config/installed.lock</code> exists. '
       . 'It has not opened a session, read your request or touched the database.</p>'
       . '<p><a class="btn" href="./">Go to the app</a></p></div>';
    echo go_foot();
    exit;
}

if (go_locked()) {
    go_render_locked();
}

session_name('go_install');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => go_is_https(),
    'httponly' => true,
    'samesite' => 'Lax',
]);
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_start();

if (!isset($_SESSION['go'])) {
    $_SESSION['go'] = ['done' => [], 'csrf' => bin2hex(random_bytes(32))];
}

// --------------------------------------------------------------------------
// Small helpers.
// --------------------------------------------------------------------------

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Mirrors UiAuth::isHttps(); duplicated so the installer does not depend on a config. */
function go_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function go_base_url(): string
{
    $scheme = go_is_https() ? 'https' : 'http';
    $host   = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $dir    = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/install.php'))), '/');
    return $scheme . '://' . $host . $dir . '/';
}

function &go_state(): array
{
    return $_SESSION['go'];
}

function go_csrf(): string
{
    return (string) $_SESSION['go']['csrf'];
}

function go_check_csrf(): void
{
    $sent = (string) ($_POST['_csrf'] ?? '');
    if ($sent === '' || !hash_equals(go_csrf(), $sent)) {
        go_fail_page('This form expired. Reload install.php and start the step again.');
    }
}

function go_done(int $step): bool
{
    return !empty($_SESSION['go']['done'][$step]);
}

function go_mark_done(int $step): void
{
    $_SESSION['go']['done'][$step] = true;
}

function go_redirect(int $step): void
{
    header('Location: install.php?step=' . $step);
    exit;
}

function go_fail_page(string $msg): void
{
    echo go_head('Installer');
    echo '<div class="card bad"><h1>Cannot continue</h1><p>' . h($msg) . '</p>'
       . '<p><a class="btn" href="install.php">Start again</a></p></div>';
    echo go_foot();
    exit;
}

/** "32M" / "192K" / "1G" / "-1" -> bytes. -1 stays -1, meaning unlimited. */
function go_bytes(string $v): int
{
    $v = trim($v);
    if ($v === '') return 0;
    if ($v === '-1') return -1;
    $unit = strtolower(substr($v, -1));
    $num  = (float) $v;
    return (int) match ($unit) {
        'g' => $num * 1024 * 1024 * 1024,
        'm' => $num * 1024 * 1024,
        'k' => $num * 1024,
        default => $num,
    };
}

// --------------------------------------------------------------------------
// Chrome.
// --------------------------------------------------------------------------

function go_head(string $title): string
{
    $steps = [
        1 => 'Environment', 2 => 'Database', 3 => 'Schema',
        4 => 'Owner', 5 => 'API keys', 6 => 'Finish',
    ];
    $cur = (int) ($GLOBALS['go_current_step'] ?? 0);

    $nav = '';
    if ($cur > 0) {
        $nav = '<ol class="steps">';
        foreach ($steps as $n => $label) {
            $cls = $n === $cur ? 'now' : (go_done($n) ? 'ok' : 'todo');
            $nav .= '<li class="' . $cls . '"><span class="n">' . $n . '</span>' . h($label) . '</li>';
        }
        $nav .= '</ol>';
    }

    return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow">'
        . '<title>' . h($title) . ' &middot; Golden Opportunities</title>'
        . '<link rel="stylesheet" href="assets/app.css">'
        . '<style>' . go_fallback_css() . '</style>'
        . '</head><body class="install"><main class="wrap">'
        . '<header class="top"><strong>Golden Opportunities</strong> <span class="muted">approval app installer</span></header>'
        . $nav;
}

function go_foot(): string
{
    return '<footer class="muted foot">Delete <code>public/install.php</code> when you are finished.</footer>'
         . '</main></body></html>';
}

/**
 * The installer must look right even on a host where assets/app.css has not
 * been uploaded yet, because a broken-looking installer is one the owner stops
 * trusting halfway through. These rules are scoped to body.install so they
 * cannot leak into the app's own stylesheet.
 */
function go_fallback_css(): string
{
    return <<<'CSS'
body.install{font:16px/1.55 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:#f6f7f9;color:#14181f;margin:0}
body.install .wrap{max-width:52rem;margin:0 auto;padding:1.5rem 1rem 4rem}
body.install .top{display:flex;gap:.5rem;align-items:baseline;margin-bottom:1rem}
body.install .muted{color:#5b6572}
body.install .card{background:#fff;border:1px solid #dde1e7;border-radius:10px;padding:1.1rem 1.25rem;margin:0 0 1rem}
body.install .card.bad{border-color:#c0392b}
body.install h1{font-size:1.35rem;margin:.1rem 0 .7rem}
body.install h2{font-size:1.05rem;margin:1.2rem 0 .5rem}
body.install code,body.install pre{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:.875em}
body.install pre{background:#0f1420;color:#e6edf3;padding:.8rem;border-radius:8px;overflow:auto}
body.install label{display:block;margin:.7rem 0 .2rem;font-weight:600;font-size:.92rem}
body.install input[type=text],body.install input[type=password],body.install input[type=email],body.install input[type=number]{width:100%;padding:.5rem .6rem;border:1px solid #c3cad3;border-radius:6px;font:inherit;box-sizing:border-box}
body.install .btn{display:inline-block;background:#14181f;color:#fff;border:0;border-radius:6px;padding:.55rem 1.05rem;font:inherit;cursor:pointer;text-decoration:none;margin-top:.9rem}
body.install .btn.ghost{background:#fff;color:#14181f;border:1px solid #c3cad3}
body.install .btn.small{padding:.25rem .6rem;font-size:.82rem;margin:0}
body.install table.checks{width:100%;border-collapse:collapse;margin-top:.4rem}
body.install table.checks td{border-top:1px solid #e7eaee;padding:.45rem .4rem;vertical-align:top}
body.install table.checks td.s{width:4.6rem;white-space:nowrap}
body.install .tag{display:inline-block;border-radius:4px;padding:.05rem .4rem;font-size:.74rem;font-weight:700;letter-spacing:.03em;text-transform:uppercase}
body.install .tag.pass{background:#e3f6e8;color:#1c6b33}
body.install .tag.warn{background:#fdf2d8;color:#8a5b00}
body.install .tag.fail{background:#fbe3e0;color:#a32316}
body.install .remedy{color:#5b6572;font-size:.88rem;margin-top:.2rem}
body.install ol.steps{display:flex;flex-wrap:wrap;gap:.35rem;list-style:none;padding:0;margin:0 0 1.1rem;font-size:.82rem}
body.install ol.steps li{border:1px solid #dde1e7;background:#fff;border-radius:999px;padding:.2rem .7rem;color:#5b6572}
body.install ol.steps li .n{font-weight:700;margin-right:.35rem}
body.install ol.steps li.now{border-color:#14181f;color:#14181f;font-weight:600}
body.install ol.steps li.ok{background:#e3f6e8;border-color:#bfe4c9;color:#1c6b33}
body.install .keyrow{border:1px solid #dde1e7;border-radius:8px;padding:.6rem .75rem;margin:.5rem 0;background:#fbfcfd}
body.install .keyrow code{display:block;word-break:break-all;margin:.25rem 0 .4rem}
body.install .foot{margin-top:2rem;font-size:.85rem}
body.install .note{background:#fdf2d8;border:1px solid #f0dca8;border-radius:8px;padding:.6rem .8rem;font-size:.9rem;margin:.8rem 0}
CSS;
}

/** One row of the environment table. $state is pass|warn|fail. */
function go_check(string $label, string $state, string $detail, string $remedy = ''): array
{
    return ['label' => $label, 'state' => $state, 'detail' => $detail, 'remedy' => $remedy];
}

function go_render_checks(array $checks): string
{
    $html = '<table class="checks">';
    foreach ($checks as $c) {
        $html .= '<tr><td class="s"><span class="tag ' . h($c['state']) . '">' . h($c['state']) . '</span></td>'
              . '<td><strong>' . h($c['label']) . '</strong><br>' . h($c['detail']);
        if ($c['remedy'] !== '' && $c['state'] !== 'pass') {
            $html .= '<div class="remedy"><b>Fix:</b> ' . $c['remedy'] . '</div>';
        }
        $html .= '</td></tr>';
    }
    return $html . '</table>';
}

// --------------------------------------------------------------------------
// The token gate.
// --------------------------------------------------------------------------

function go_client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/**
 * Per-IP attempt throttle, in a file because there is no database yet.
 *
 * LOCK_EX around the whole read-modify-write, so two parallel guesses cannot
 * each read "0 attempts" and both be allowed.
 */
function go_attempts(bool $record): int
{
    $key = hash('sha256', go_client_ip());
    $fh  = @fopen(GO_ATTEMPTS_FILE, 'c+');
    if ($fh === false) return 0;              // unwritable storage is reported in step 1
    try {
        flock($fh, LOCK_EX);
        $raw  = stream_get_contents($fh) ?: '';
        $data = json_decode($raw, true);
        if (!is_array($data)) $data = [];
        $cut  = time() - GO_ATTEMPT_WINDOW;
        foreach ($data as $k => $list) {
            $data[$k] = array_values(array_filter((array) $list, static fn($t) => (int) $t > $cut));
            if ($data[$k] === []) unset($data[$k]);
        }
        if ($record) $data[$key][] = time();
        $n = count($data[$key] ?? []);
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($data));
        fflush($fh);
        return $n;
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/** 64 hex characters of 32 random bytes. Hex, because the owner reads this in File Manager. */
function go_issue_token(): ?string
{
    $tok = bin2hex(random_bytes(32));
    if (@file_put_contents(GO_TOKEN_FILE, $tok . "\n", LOCK_EX) === false) return null;
    @chmod(GO_TOKEN_FILE, 0600);
    return $tok;
}

function go_token_gate(): void
{
    $s = &go_state();

    // An accepted token expires 60 minutes after it was accepted. The file was
    // deleted on acceptance, so expiry has to issue a fresh one - otherwise a
    // slow install would lock the owner out of his own installer.
    if (!empty($s['token_ok']) && (time() - (int) $s['token_at']) > GO_TOKEN_TTL) {
        unset($s['token_ok'], $s['token_at']);
        $s['token_expired'] = true;
    }
    if (!empty($s['token_ok'])) return;

    $error = '';
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['install_token'])) {
        go_check_csrf();
        $tries = go_attempts(true);
        if ($tries > GO_MAX_ATTEMPTS) {
            http_response_code(429);
            $error = 'Too many attempts from this address. Wait 15 minutes.';
        } else {
            $stored = is_file(GO_TOKEN_FILE) ? trim((string) @file_get_contents(GO_TOKEN_FILE)) : '';
            $given  = trim((string) $_POST['install_token']);
            if ($stored !== '' && strlen($given) === strlen($stored) && hash_equals($stored, $given)) {
                @unlink(GO_TOKEN_FILE);                 // one use only
                session_regenerate_id(true);            // the token is now the session
                $s = &$_SESSION['go'];
                $s['token_ok'] = true;
                $s['token_at'] = time();
                unset($s['token_expired']);
                go_redirect(1);
            }
            $error = 'That token does not match. ' . max(0, GO_MAX_ATTEMPTS - $tries) . ' attempts left.';
        }
    }

    // Issue a token if there is not one waiting to be read.
    clearstatcache(true, GO_TOKEN_FILE);
    $issued = is_file(GO_TOKEN_FILE);
    $writeFailed = false;
    if (!$issued) {
        $writeFailed = go_issue_token() === null;
    }

    $GLOBALS['go_current_step'] = 0;
    echo go_head('Prove filesystem access');
    echo '<div class="card"><h1>Prove you own this server</h1>';

    if (!empty($s['token_expired'])) {
        echo '<div class="note">Your installer session passed the 60-minute limit, so a new token has been '
           . 'written. Paste the new one to carry on. Nothing you entered so far has been lost.</div>';
    }

    if ($writeFailed) {
        echo '<p class="tag fail">fail</p><p>The installer could not write <code>storage/install-token.txt</code>. '
           . 'Set <code>storage/</code> to permissions <code>0755</code> in cPanel File Manager '
           . '(Permissions), owned by your cPanel user, then reload this page.</p>';
    } else {
        echo '<p>There is no database and no account yet, so the only thing that can identify you is access '
           . 'to the files. A one-time token has been written to:</p>'
           . '<pre>storage/install-token.txt</pre>'
           . '<p>Open <b>cPanel &rarr; File Manager</b>, navigate to that file, choose <b>Edit</b> or '
           . '<b>View</b>, and paste the 64-character value below. The file is deleted the moment it is '
           . 'accepted, and it is never served over the web (<code>storage/.htaccess</code> denies it).</p>';

        if ($error !== '') echo '<p class="tag fail">' . h($error) . '</p>';
        if (!go_is_https()) {
            echo '<div class="note">This page is not on HTTPS. The token, and shortly the database password, '
               . 'will cross the network in clear text. Turn on AutoSSL in cPanel first if you possibly can.</div>';
        }

        echo '<form method="post" action="install.php" autocomplete="off">'
           . '<input type="hidden" name="_csrf" value="' . h(go_csrf()) . '">'
           . '<label for="tok">Install token</label>'
           . '<input id="tok" name="install_token" type="text" spellcheck="false" autocapitalize="off" '
           . 'autocomplete="off" required>'
           . '<button class="btn" type="submit">Continue</button></form>';
    }

    echo '</div>';
    echo go_foot();
    exit;
}

// --------------------------------------------------------------------------
// Step 1: environment.
// --------------------------------------------------------------------------

/**
 * Same-origin probe. Used for the rewrite check here and by preflight.php for
 * the header and colon-path checks.
 *
 * @return array{ok:bool,status:int,body:string,headers:string,error:string}
 */
function go_probe(string $url, array $headers = []): array
{
    $out = ['ok' => false, 'status' => 0, 'body' => '', 'headers' => '', 'error' => ''];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            // The target is this same host. A self-signed or not-yet-issued
            // AutoSSL certificate must not turn an infrastructure probe into a
            // TLS lecture, so verification is off for this one request only.
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_USERAGENT      => 'go-installer-probe',
        ]);
        $res = curl_exec($ch);
        if ($res === false) {
            $out['error'] = curl_error($ch);
            curl_close($ch);
            return $out;
        }
        $hlen = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $out['status']  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $out['headers'] = substr($res, 0, $hlen);
        $out['body']    = substr($res, $hlen);
        $out['ok']      = true;
        curl_close($ch);
        return $out;
    }

    $ctx = stream_context_create([
        'http' => ['timeout' => 8, 'ignore_errors' => true, 'follow_location' => 0,
                   'header' => implode("\r\n", $headers)],
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        $out['error'] = 'allow_url_fopen is off or the request was refused';
        return $out;
    }
    $out['ok']      = true;
    $out['body']    = $body;
    $out['headers'] = implode("\n", $http_response_header ?? []);
    if (preg_match('~HTTP/\S+\s+(\d{3})~', $out['headers'], $m)) $out['status'] = (int) $m[1];
    return $out;
}

function go_env_checks(): array
{
    $c = [];

    $c[] = version_compare(PHP_VERSION, '8.1.0', '>=')
        ? go_check('PHP version', 'pass', PHP_VERSION)
        : go_check('PHP version', 'fail', PHP_VERSION . ' - this app uses enum, readonly properties and match',
            'cPanel &rarr; <b>MultiPHP Manager</b>, select this domain, set PHP 8.1 or newer, Apply.');

    foreach (['pdo_mysql' => 'the database driver',
              'openssl'   => 'random_bytes() entropy and HTTPS calls to LinkedIn',
              'mbstring'  => 'mb_strtolower on email addresses and body word counts',
              'json'      => 'every request and response'] as $ext => $why) {
        $c[] = extension_loaded($ext)
            ? go_check('Extension ' . $ext, 'pass', 'loaded - ' . $why)
            : go_check('Extension ' . $ext, 'fail', 'missing - needed for ' . $why,
                'cPanel &rarr; <b>Select PHP Version</b> &rarr; <b>Extensions</b>, tick <code>' . h($ext) . '</code>, save.');
    }

    if (defined('PASSWORD_ARGON2ID')) {
        $c[] = go_check('Argon2id', 'pass', 'available - the owner password will use it');
    } else {
        $c[] = go_check('Argon2id', 'warn',
            'not compiled in; the owner password will fall back to bcrypt cost 12',
            'Optional. bcrypt cost 12 is acceptable. To get Argon2id, ask your host to build PHP '
            . 'with <code>--with-password-argon2</code>, or pick a PHP build that already has it in '
            . '<b>MultiPHP Manager</b>.');
    }

    $pms = (string) ini_get('post_max_size');
    $c[] = go_bytes($pms) >= 24 * 1024 * 1024
        ? go_check('post_max_size', 'pass', $pms . ' (effective)')
        : go_check('post_max_size', 'fail', $pms . ' (effective) - needs 24M or more',
            'A3 posts an 8&nbsp;MB PNG as base64, which is about 10.7&nbsp;MB on the wire. '
            . 'Set <code>post_max_size = 32M</code> in <code>public/.user.ini</code>, or in '
            . 'cPanel &rarr; <b>Select PHP Version</b> &rarr; <b>Options</b>.');

    $ml  = (string) ini_get('memory_limit');
    $mlb = go_bytes($ml);
    $c[] = ($mlb < 0 || $mlb >= 192 * 1024 * 1024)
        ? go_check('memory_limit', 'pass', $ml . ' (effective)')
        : go_check('memory_limit', 'fail', $ml . ' (effective) - needs 192M or more',
            'The upload body is held twice: once parsed, once re-encoded for the idempotency hash. '
            . 'Set <code>memory_limit = 256M</code> in <code>public/.user.ini</code> or in '
            . 'cPanel &rarr; <b>Select PHP Version</b> &rarr; <b>Options</b>.');

    foreach ([GO_MEDIA => 'storage/media', GO_STORAGE . '/logs' => 'storage/logs',
              GO_CONFIG_DIR => 'config'] as $dir => $label) {
        if (!is_dir($dir)) {
            $c[] = go_check($label, 'fail', 'directory is missing',
                'Create <code>' . h($label) . '</code> in cPanel File Manager and set it to <code>0755</code>.');
        } elseif (!is_writable($dir)) {
            $c[] = go_check($label, 'fail', 'exists but is not writable by PHP',
                'File Manager &rarr; select <code>' . h($label) . '</code> &rarr; <b>Permissions</b> &rarr; '
                . '<code>0755</code>, and make sure the owner is your cPanel user.');
        } else {
            $c[] = go_check($label, 'pass', 'writable');
        }
    }

    $c[] = go_is_https()
        ? go_check('HTTPS', 'pass', 'this request arrived over TLS')
        : go_check('HTTPS', 'warn', 'this request is plain HTTP',
            'cPanel &rarr; <b>SSL/TLS Status</b> &rarr; <b>Run AutoSSL</b>. The session cookie cannot be '
            . 'marked Secure without it, and the agents will be sending bearer tokens to this host.');

    // The rewrite probe. A path that is not a real file must reach index.php.
    $url = go_base_url() . '__go_rewrite_probe_' . bin2hex(random_bytes(4));
    $p   = go_probe($url);
    if (!$p['ok']) {
        $c[] = go_check('.htaccess rewrite', 'warn',
            'the probe could not run (' . ($p['error'] ?: 'no response') . ')',
            'Not fatal - the host may block outbound HTTP to itself. Open <code>' . h($url) . '</code> '
            . 'in a browser tab: you should see <i>"This app has not been installed yet"</i>, not an Apache 404.');
    } elseif (str_contains($p['body'], 'has not been installed')) {
        $c[] = go_check('.htaccess rewrite', 'pass', 'a non-file path reached index.php (HTTP ' . $p['status'] . ')');
    } elseif (stripos($p['headers'], 'X-Content-Type-Options') !== false) {
        $c[] = go_check('.htaccess rewrite', 'warn',
            'HTTP ' . $p['status'] . '; .htaccess is being read but the rewrite did not fire',
            'mod_rewrite is probably off for this domain. Ask your host to enable <code>mod_rewrite</code> '
            . 'and <code>AllowOverride All</code> for this document root.');
    } else {
        $c[] = go_check('.htaccess rewrite', 'fail',
            'HTTP ' . $p['status'] . ' - the request never reached index.php',
            'Check that <code>public/.htaccess</code> was uploaded (File Manager hides dotfiles until you '
            . 'tick <b>Show Hidden Files</b>) and that the subdomain document root points at '
            . '<code>.../webapp/public</code>.');
    }

    return $c;
}

function go_step_env(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        go_check_csrf();
        go_mark_done(1);
        go_redirect(2);
    }

    $checks = go_env_checks();
    $fails  = count(array_filter($checks, static fn($c) => $c['state'] === 'fail'));

    echo go_head('Environment');
    echo '<div class="card"><h1>Step 1 &middot; Environment</h1>'
       . '<p class="muted">Everything below is read from the PHP process that is serving this page, '
       . 'so it is what the app will actually get.</p>'
       . go_render_checks($checks)
       . '<div class="note"><b>If you just edited <code>public/.user.ini</code>:</b> PHP caches that file for '
       . '<code>user_ini.cache_ttl</code>, five minutes by default. A value you changed a moment ago may not '
       . 'be in the table above yet. Wait five minutes, or open File Manager and re-save the file to bump its '
       . 'timestamp, then reload this page. Do not put PHP settings in <code>.htaccess</code>: '
       . '<code>php_value</code> is a 500 under LSAPI and PHP-FPM, which is what cPanel EA-PHP runs.</div>';

    if ($fails > 0) {
        echo '<p class="tag fail">' . $fails . ' blocking item' . ($fails === 1 ? '' : 's') . '</p>'
           . '<p>Fix those, then reload. The installer will not continue while any of them fail.</p>'
           . '<a class="btn ghost" href="install.php?step=1">Re-check</a>';
    } else {
        echo '<form method="post" action="install.php?step=1">'
           . '<input type="hidden" name="_csrf" value="' . h(go_csrf()) . '">'
           . '<button class="btn" type="submit">Continue to the database</button></form>'
           . ' <a class="btn ghost" href="install.php?step=1">Re-check</a>';
    }
    echo '</div>' . go_foot();
}

// --------------------------------------------------------------------------
// Step 2: database.
// --------------------------------------------------------------------------

/** @return array{server:string,version:string,ok:bool,why:string} */
function go_server_identity(PDO $pdo): array
{
    $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    $comment = '';
    try { $comment = (string) $pdo->query("SELECT @@version_comment")->fetchColumn(); } catch (Throwable) {}

    $isMaria = stripos($version, 'mariadb') !== false || stripos($comment, 'mariadb') !== false;
    // MariaDB 10.x reports "5.5.5-10.6.12-MariaDB" through some proxies; take the
    // 10.x/11.x number when one is present rather than the 5.5.5 compatibility prefix.
    if ($isMaria && preg_match('~(\d+\.\d+\.\d+)-MariaDB~i', $version, $m)) {
        $num = $m[1];
    } else {
        preg_match('~^(\d+\.\d+\.\d+)~', $version, $m2);
        $num = $m2[1] ?? '0.0.0';
    }

    if ($isMaria) {
        $ok  = version_compare($num, '10.4.0', '>=');
        $why = $ok ? '' : 'MariaDB 10.4 or newer is required (CHECK constraints and utf8mb4 defaults).';
        return ['server' => 'MariaDB ' . $num, 'version' => $version, 'ok' => $ok, 'why' => $why];
    }
    $ok  = version_compare($num, '8.0.0', '>=');
    $why = $ok ? '' : 'MySQL 8.0 or newer is required (JSON columns, CTEs, CHECK constraints).';
    return ['server' => 'MySQL ' . $num, 'version' => $version, 'ok' => $ok, 'why' => $why];
}

/**
 * Prove the grant, do not read it from information_schema: on shared hosting the
 * grant tables are frequently unreadable, and what matters is whether the
 * statement succeeds.
 *
 * @return array{create:bool,insert:bool,trigger:bool,notes:string[]}
 */
function go_probe_privileges(PDO $pdo): array
{
    $t = 'go_install_probe_' . bin2hex(random_bytes(4));
    $r = ['create' => false, 'insert' => false, 'trigger' => false, 'notes' => []];

    try {
        $pdo->exec("CREATE TABLE `{$t}` (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, n INT NOT NULL) ENGINE=InnoDB");
        $r['create'] = true;
    } catch (PDOException $e) {
        $r['notes'][] = 'CREATE: ' . $e->getMessage();
        return $r;
    }

    try {
        $pdo->exec("INSERT INTO `{$t}` (n) VALUES (1)");
        $r['insert'] = true;
    } catch (PDOException $e) {
        $r['notes'][] = 'INSERT: ' . $e->getMessage();
    }

    try {
        $pdo->exec("CREATE TRIGGER `{$t}_trg` BEFORE UPDATE ON `{$t}` FOR EACH ROW SET NEW.n = NEW.n");
        $r['trigger'] = true;
        try { $pdo->exec("DROP TRIGGER `{$t}_trg`"); } catch (PDOException) {}
    } catch (PDOException $e) {
        $r['notes'][] = 'TRIGGER: ' . $e->getMessage();
    }

    try { $pdo->exec("DROP TABLE `{$t}`"); } catch (PDOException $e) {
        $r['notes'][] = 'DROP: ' . $e->getMessage() . ' (remove `' . $t . '` by hand later)';
    }
    return $r;
}

function go_step_db(): void
{
    $s = &go_state();
    $error = '';
    $result = null;

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        go_check_csrf();
        $db = [
            'host' => trim((string) ($_POST['db_host'] ?? '')),
            'port' => (int) ($_POST['db_port'] ?? 3306),
            'name' => trim((string) ($_POST['db_name'] ?? '')),
            'user' => trim((string) ($_POST['db_user'] ?? '')),
            'pass' => (string) ($_POST['db_pass'] ?? ''),
        ];
        $env = ($_POST['env'] ?? 'production') === 'test' ? 'test' : 'production';

        if ($db['host'] === '' || $db['name'] === '' || $db['user'] === '') {
            $error = 'Host, database name and user are all required.';
        } else {
            try {
                // Connected here rather than through Db::connect(), which flattens
                // every PDOException into "the database is unreachable". At this
                // step the owner needs the real message - "Access denied", "Unknown
                // database" and "Connection refused" have three different fixes.
                $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                    $db['host'], $db['port'], $db['name']);
                $pdo = new PDO($dsn, $db['user'], $db['pass'], [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_TIMEOUT            => 8,
                ]);
                $id = go_server_identity($pdo);
                if (!$id['ok']) {
                    $error = $id['server'] . ': ' . $id['why'];
                } else {
                    $priv = go_probe_privileges($pdo);
                    if (!$priv['create'] || !$priv['insert']) {
                        $error = 'Connected to ' . $id['server'] . ', but this user cannot '
                               . (!$priv['create'] ? 'CREATE tables' : 'INSERT rows') . '. '
                               . 'In cPanel &rarr; MySQL Databases &rarr; Add User To Database, grant ALL PRIVILEGES.';
                    } else {
                        $s['db']          = $db;
                        $s['env']         = $env;
                        $s['db_server']   = $id['server'];
                        $s['db_version']  = $id['version'];
                        $s['trigger_ok']  = $priv['trigger'];
                        $s['priv_notes']  = $priv['notes'];
                        go_mark_done(2);
                        go_redirect(3);
                    }
                }
            } catch (PDOException $e) {
                // $e->getMessage() carries the user and host, never the password.
                $error = $e->getMessage();
            }
        }
        // Anything that reaches here failed. The password is deliberately not
        // carried back into the form; it is re-typed.
        $result = $db;
    }

    $prev = $s['db'] ?? ['host' => 'localhost', 'port' => 3306, 'name' => '', 'user' => ''];
    if ($result !== null) $prev = $result;

    echo go_head('Database');
    echo '<div class="card"><h1>Step 2 &middot; Database</h1>'
       . '<p>Create the database and its user first, in cPanel &rarr; <b>MySQL&reg; Databases</b>. cPanel '
       . 'prefixes both names with your account, e.g. <code>gojobs_linkedin</code> and '
       . '<code>gojobs_appuser</code> - type the full prefixed names here. Grant the user '
       . '<b>ALL PRIVILEGES</b> on the database.</p>';

    if ($error !== '') {
        echo '<p class="tag fail">fail</p><pre>' . h($error) . '</pre>';
    }

    echo '<form method="post" action="install.php?step=2" autocomplete="off">'
       . '<input type="hidden" name="_csrf" value="' . h(go_csrf()) . '">'
       . '<label for="dbh">Host</label><input id="dbh" name="db_host" type="text" value="' . h((string) $prev['host']) . '" required>'
       . '<p class="remedy">Almost always <code>localhost</code> on cPanel.</p>'
       . '<label for="dbp">Port</label><input id="dbp" name="db_port" type="number" value="' . h((string) $prev['port']) . '" required>'
       . '<label for="dbn">Database name</label><input id="dbn" name="db_name" type="text" value="' . h((string) $prev['name']) . '" required>'
       . '<label for="dbu">Database user</label><input id="dbu" name="db_user" type="text" value="' . h((string) $prev['user']) . '" required>'
       . '<label for="dbw">Database password</label><input id="dbw" name="db_pass" type="password" autocomplete="new-password">'
       . '<p class="remedy">Never shown again on any screen of this installer, and never written to a log.</p>'
       . '<label>Environment</label>'
       . '<label style="font-weight:400"><input type="radio" name="env" value="production" '
       . (($s['env'] ?? 'production') === 'production' ? 'checked' : '') . '> Production - the real company page</label>'
       . '<label style="font-weight:400"><input type="radio" name="env" value="test" '
       . (($s['env'] ?? '') === 'test' ? 'checked' : '') . '> Test - enables the test harness routes and mints '
       . '<code>go_test_*</code> agent keys</label>'
       . '<button class="btn" type="submit">Connect and check privileges</button></form>';

    echo '<h2>What this step verifies</h2><ul>'
       . '<li><code>VERSION()</code>. <b>MySQL 8.0+ and MariaDB 10.4+ are both fine</b> - most cPanel hosts '
       . 'run MariaDB, and the schema was written to be portable across the two.</li>'
       . '<li>CREATE, INSERT and TRIGGER, by creating a scratch table and trigger and dropping them again. '
       . 'A denied TRIGGER is a warning, not a failure.</li></ul>';
    echo '</div>' . go_foot();
}

// --------------------------------------------------------------------------
// Step 3: schema import.
// --------------------------------------------------------------------------

/**
 * Split a .sql file into statements.
 *
 * A naive explode(';') shatters schema.mysql.sql: it contains
 *
 *     DELIMITER $$
 *     CREATE TRIGGER trg_topics_protect_original ... BEGIN ... ; ... END$$
 *     DELIMITER ;
 *
 * and the semicolons inside the trigger body belong to the body. This scanner
 * tracks the current delimiter, single/double quoted strings (with both \' and
 * '' escapes), backtick identifiers, `--` and `#` line comments and C-style
 * block comments, so those semicolons stay where they are.
 *
 * @return string[]
 */
function go_split_sql(string $sql): array
{
    $out       = [];
    $len       = strlen($sql);
    $delim     = ';';
    $buf       = '';
    $i         = 0;
    $lineStart = true;

    while ($i < $len) {
        $c = $sql[$i];

        // DELIMITER is a client directive, not a statement. It is only
        // meaningful at the start of a line, between statements.
        if ($lineStart && trim($buf) === '' && strncasecmp(substr($sql, $i, 10), 'DELIMITER ', 10) === 0) {
            $eol  = strpos($sql, "\n", $i);
            $line = $eol === false ? substr($sql, $i) : substr($sql, $i, $eol - $i);
            $new  = trim(substr($line, 10));
            if ($new !== '') $delim = $new;
            $i         = $eol === false ? $len : $eol + 1;
            $buf       = '';
            $lineStart = true;
            continue;
        }

        // Line comments. `--` only counts when followed by whitespace, which is
        // MySQL's own rule and keeps expressions like `5--1` intact.
        $isDashComment = $c === '-' && substr($sql, $i, 2) === '--'
            && ($i + 2 >= $len || $sql[$i + 2] === ' ' || $sql[$i + 2] === "\t"
                || $sql[$i + 2] === "\n" || $sql[$i + 2] === "\r");
        if ($c === '#' || $isDashComment) {
            $eol       = strpos($sql, "\n", $i);
            $i         = $eol === false ? $len : $eol + 1;
            $buf      .= "\n";
            $lineStart = true;
            continue;
        }

        // Block comments. A /*! ... */ version-gated block is executable SQL and
        // is kept; an ordinary comment is dropped.
        if ($c === '/' && substr($sql, $i, 2) === '/*') {
            $end = strpos($sql, '*/', $i + 2);
            if ($end === false) { $i = $len; continue; }
            if (($sql[$i + 2] ?? '') === '!') {
                $buf .= substr($sql, $i, $end + 2 - $i);
            }
            $i         = $end + 2;
            $lineStart = false;
            continue;
        }

        // Quoted strings and quoted identifiers.
        if ($c === "'" || $c === '"' || $c === '`') {
            $quote = $c;
            $buf  .= $c;
            $i++;
            while ($i < $len) {
                $q = $sql[$i];
                if ($q === '\\' && $quote !== '`' && $i + 1 < $len) {
                    $buf .= $q . $sql[$i + 1];
                    $i   += 2;
                    continue;
                }
                if ($q === $quote) {
                    if (($sql[$i + 1] ?? '') === $quote) {   // '' or `` escape
                        $buf .= $q . $q;
                        $i   += 2;
                        continue;
                    }
                    $buf .= $q;
                    $i++;
                    break;
                }
                $buf .= $q;
                $i++;
            }
            $lineStart = false;
            continue;
        }

        if ($c === $delim[0] && substr($sql, $i, strlen($delim)) === $delim) {
            $stmt = trim($buf);
            if ($stmt !== '') $out[] = $stmt;
            $buf       = '';
            $i        += strlen($delim);
            $lineStart = false;
            continue;
        }

        $buf      .= $c;
        $lineStart = $c === "\n";
        $i++;
    }

    $tail = trim($buf);
    if ($tail !== '') $out[] = $tail;
    return $out;
}

/** True when a PDOException is "you may not create triggers here". */
function go_is_trigger_denial(PDOException $e): bool
{
    // 1142 no TRIGGER privilege, 1227 access denied (SUPER), 1419 binary logging
    // is on and the user is not SUPER - the last one is routine on shared MariaDB.
    return in_array((int) ($e->errorInfo[1] ?? 0), [1142, 1227, 1419], true);
}

function go_existing_tables(PDO $pdo): array
{
    $rows = $pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')
                ->fetchAll(PDO::FETCH_COLUMN);
    return array_map('strval', $rows ?: []);
}

/** @return array{ran:int,skipped:string[],error:?string,failed:?string} */
function go_import_file(PDO $pdo, string $file): array
{
    $sql = (string) file_get_contents($file);
    $ran = 0;
    $skipped = [];

    foreach (go_split_sql($sql) as $stmt) {
        try {
            $pdo->exec($stmt);
            $ran++;
        } catch (PDOException $e) {
            if (stripos($stmt, 'CREATE TRIGGER') !== false && go_is_trigger_denial($e)) {
                // Recorded, not fatal. The app enforces original_* immutability in
                // code as well; the trigger is the belt, the code is the braces.
                $skipped[] = trim(substr(preg_replace('~\s+~', ' ', $stmt) ?? '', 0, 90));
                continue;
            }
            return ['ran' => $ran, 'skipped' => $skipped,
                    'error' => $e->getMessage(),
                    'failed' => trim(substr(preg_replace('~\s+~', ' ', $stmt) ?? '', 0, 200))];
        }
    }
    return ['ran' => $ran, 'skipped' => $skipped, 'error' => null, 'failed' => null];
}

function go_step_schema(): void
{
    $s = &go_state();
    if (!go_done(2)) go_redirect(2);

    try {
        Db::connect($s['db']);
        $pdo = Db::pdo();
    } catch (Throwable $e) {
        go_fail_page('The database connection that worked a moment ago is failing now. Go back to step 2 '
            . 'and re-enter the details.');
        return;
    }

    $existing = go_existing_tables($pdo);
    $report   = $s['schema_report'] ?? null;

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        go_check_csrf();
        if (($_POST['action'] ?? '') === 'skip') {
            $s['schema_report'] = ['skipped_import' => true];
            go_mark_done(3);
            go_redirect(4);
        }

        $report = ['files' => [], 'skipped_import' => false];
        foreach (['sql/schema.mysql.sql', 'sql/schema.part2.sql'] as $rel) {
            $path = GO_ROOT . '/' . $rel;
            if (!is_file($path)) {
                $report['files'][$rel] = ['ran' => 0, 'skipped' => [], 'error' => 'file not found at ' . $path, 'failed' => null];
                break;
            }
            $res = go_import_file($pdo, $path);
            $report['files'][$rel] = $res;
            if ($res['error'] !== null) break;
        }
        $s['schema_report'] = $report;

        $clean = true;
        foreach ($report['files'] as $r) { if ($r['error'] !== null) $clean = false; }
        if ($clean) {
            go_mark_done(3);
            go_redirect(4);
        }
        $existing = go_existing_tables($pdo);
    }

    echo go_head('Schema');
    echo '<div class="card"><h1>Step 3 &middot; Import the schema</h1>'
       . '<p>Connected to <b>' . h((string) ($s['db_server'] ?? '')) . '</b>, database <code>'
       . h((string) $s['db']['name']) . '</code>.</p>';

    if (empty($s['trigger_ok'])) {
        echo '<div class="note"><b>This user cannot create triggers.</b> The '
           . '<code>trg_topics_protect_original</code> trigger will be skipped, so the database will not '
           . 'refuse an UPDATE that rewrites an <code>original_*</code> column. The application still refuses '
           . 'it in code, and nothing in the app writes those columns twice - but the last line of defence '
           . 'is gone. To get it: cPanel &rarr; <b>MySQL Databases</b> &rarr; Add User To Database &rarr; '
           . 'ALL PRIVILEGES, or ask your host to grant <code>TRIGGER</code>. You may continue without it.</div>';
    }

    if ($report !== null && !empty($report['files'])) {
        echo '<h2>Result</h2>';
        foreach ($report['files'] as $file => $r) {
            if ($r['error'] === null) {
                echo '<p><span class="tag pass">pass</span> <code>' . h($file) . '</code> - '
                   . (int) $r['ran'] . ' statement' . ($r['ran'] === 1 ? '' : 's') . ' executed.</p>';
            } else {
                echo '<p><span class="tag fail">fail</span> <code>' . h($file) . '</code> - stopped after '
                   . (int) $r['ran'] . ' statement' . ($r['ran'] === 1 ? '' : 's') . '.</p>'
                   . '<pre>' . h((string) $r['error']) . "\n\n" . h((string) $r['failed']) . '</pre>';
            }
            foreach ($r['skipped'] as $sk) {
                echo '<p><span class="tag warn">warn</span> skipped (no TRIGGER privilege): <code>'
                   . h($sk) . '</code></p>';
            }
        }
    }

    if ($existing !== []) {
        echo '<div class="note"><b>' . count($existing) . ' table'
           . (count($existing) === 1 ? '' : 's') . ' already exist</b> in this database: <code>'
           . h(implode(', ', array_slice($existing, 0, 12)))
           . (count($existing) > 12 ? ', &hellip;' : '') . '</code>. '
           . 'Importing again will fail on the first <code>CREATE TABLE</code>. Skip the import if this '
           . 'database was already set up; drop the tables first if you want a clean start.</div>';
    }

    echo '<form method="post" action="install.php?step=3">'
       . '<input type="hidden" name="_csrf" value="' . h(go_csrf()) . '">'
       . '<button class="btn" type="submit" name="action" value="import">Import both files</button> '
       . '<button class="btn ghost" type="submit" name="action" value="skip">Skip - the tables are already there</button>'
       . '</form>';
    echo '<p class="remedy">The importer understands <code>DELIMITER $$</code>, quoted strings and '
       . '<code>--</code> comments, so the <code>CREATE TRIGGER</code> block is sent as one statement rather '
       . 'than in pieces.</p>';
    echo '</div>' . go_foot();
}

// --------------------------------------------------------------------------
// Step 4: the owner account.
// --------------------------------------------------------------------------

/**
 * Pick Argon2id parameters for this machine, aiming at ~250ms per hash.
 *
 * 250ms is the usual balance: slow enough that offline guessing is expensive,
 * fast enough that a sign-in feels instant. memory_cost is clamped to 65536 KiB
 * (64 MB) because shared hosting hands PHP a modest memory_limit and an Argon2
 * hash that cannot allocate is a login that fatals, and floored at 19456 KiB,
 * the current OWASP minimum.
 *
 * @return array{memory_cost:int,time_cost:int,threads:int,measured_ms:int}
 */
function go_bench_argon2(): array
{
    $opts   = ['memory_cost' => 19456, 'time_cost' => 2, 'threads' => 1];
    $target = 0.250;
    $sample = 'benchmark-only-never-stored';
    $el     = 0.0;
    $deadline = microtime(true) + 6.0;

    for ($i = 0; $i < 7; $i++) {
        $t0 = microtime(true);
        password_hash($sample, PASSWORD_ARGON2ID, $opts);
        $el = microtime(true) - $t0;

        if ($el >= $target * 0.75 && $el <= $target * 1.5) break;
        if (microtime(true) > $deadline) break;

        if ($el < $target) {
            if ($opts['memory_cost'] < 65536)     $opts['memory_cost'] = min(65536, $opts['memory_cost'] * 2);
            elseif ($opts['time_cost'] < 6)       $opts['time_cost']++;
            else break;
        } else {
            if ($opts['memory_cost'] > 19456)     $opts['memory_cost'] = max(19456, intdiv($opts['memory_cost'], 2));
            elseif ($opts['time_cost'] > 1)       $opts['time_cost']--;
            else break;                            // already at the floor; keep it
        }
    }

    $opts['measured_ms'] = (int) round($el * 1000);
    return $opts;
}

function go_step_owner(): void
{
    $s = &go_state();
    if (!go_done(3)) go_redirect(3);

    try { Db::connect($s['db']); } catch (Throwable) {
        go_fail_page('Lost the database connection. Go back to step 2.');
    }

    $error = '';
    $email = (string) ($s['owner_email'] ?? '');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        go_check_csrf();
        $email = trim((string) ($_POST['email'] ?? ''));
        $p1    = (string) ($_POST['password'] ?? '');
        $p2    = (string) ($_POST['password2'] ?? '');

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $error = 'That does not look like an email address.';
        } elseif (mb_strlen($p1) < 12) {
            $error = 'The password must be at least 12 characters. Length beats cleverness; use four words.';
        } elseif (!hash_equals($p1, $p2)) {
            $error = 'The two passwords do not match.';
        } else {
            try {
                if (defined('PASSWORD_ARGON2ID')) {
                    $argon = go_bench_argon2();
                    // UiAuth::createUser() reads these through go_setting('argon2'),
                    // which this file defines over $GLOBALS. Set it before the call.
                    $GLOBALS['go_install_cfg'] = ['argon2' => $argon];
                    $uid = UiAuth::createUser($email, $p1, 'owner', null);
                    $s['argon2'] = ['memory_cost' => $argon['memory_cost'],
                                    'time_cost'   => $argon['time_cost'],
                                    'threads'     => $argon['threads']];
                    $s['argon_measured_ms'] = $argon['measured_ms'];
                    $s['argon_algo'] = 'argon2id';
                } else {
                    // UiAuth::createUser() names PASSWORD_ARGON2ID directly, so on a
                    // host without it the insert is done here instead. The login path
                    // uses password_verify(), which reads the algorithm out of the
                    // stored hash, so bcrypt rows sign in unchanged.
                    Db::exec('INSERT INTO users (email, password_hash, display_name, role, password_changed_at)
                              VALUES (?,?,?,?,UTC_TIMESTAMP(3))',
                        [mb_strtolower($email), password_hash($p1, PASSWORD_BCRYPT, ['cost' => 12]), null, 'owner']);
                    $uid = Db::lastId();
                    $s['argon2'] = ['memory_cost' => 19456, 'time_cost' => 2, 'threads' => 1];
                    $s['argon_algo'] = 'bcrypt';
                }
                $s['owner_email'] = mb_strtolower($email);
                $s['owner_id']    = $uid;
                go_mark_done(4);
                go_redirect(5);
            } catch (PDOException $e) {
                $error = Db::isDuplicate($e)
                    ? 'An account with that email already exists in this database.'
                    : $e->getMessage();
            }
        }
    }

    echo go_head('Owner account');
    echo '<div class="card"><h1>Step 4 &middot; The owner account</h1>'
       . '<p>This is the account that approves topics at gate 1 and posts at gate 2. There is no password '
       . 'reset by email, so store it in a password manager now.</p>';

    if ($error !== '') echo '<p class="tag fail">fail</p><pre>' . h($error) . '</pre>';

    if (!defined('PASSWORD_ARGON2ID')) {
        echo '<div class="note">Argon2id is not available on this PHP build, so the password will be hashed '
           . 'with <b>bcrypt at cost 12</b>. That is a sound fallback. If Argon2id is enabled later, the app '
           . 'rehashes on the next successful sign-in.</div>';
    } else {
        echo '<p class="remedy">Saving benchmarks Argon2id on this host and picks parameters that take about '
           . '250&nbsp;ms, capped at 64&nbsp;MB of memory. It takes a second or two.</p>';
    }

    echo '<form method="post" action="install.php?step=4" autocomplete="off">'
       . '<input type="hidden" name="_csrf" value="' . h(go_csrf()) . '">'
       . '<label for="em">Email</label><input id="em" name="email" type="email" value="' . h($email) . '" required>'
       . '<label for="pw">Password (12 characters minimum)</label>'
       . '<input id="pw" name="password" type="password" minlength="12" autocomplete="new-password" required>'
       . '<label for="pw2">Password again</label>'
       . '<input id="pw2" name="password2" type="password" minlength="12" autocomplete="new-password" required>'
       . '<button class="btn" type="submit">Create the account</button></form>';
    echo '</div>' . go_foot();
}

// --------------------------------------------------------------------------
// Step 5: the six agent keys.
// --------------------------------------------------------------------------

function go_step_keys(): void
{
    $s = &go_state();
    if (!go_done(4)) go_redirect(4);

    try { Db::connect($s['db']); } catch (Throwable) {
        go_fail_page('Lost the database connection. Go back to step 2.');
    }

    $agents = array_keys(ApiKeys::SCOPES);
    $env    = ($s['env'] ?? 'production') === 'test' ? 'test' : 'prod';
    $note   = '';

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        go_check_csrf();
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'confirm') {
            if (empty($_POST['stored'])) {
                $note = 'Tick the box first. These keys cannot be shown again.';
            } else {
                // The only copy the installer held is now gone. What is left in
                // the database is a prefix and a SHA-256, which cannot be turned
                // back into a key.
                unset($s['keys']);
                go_mark_done(5);
                go_redirect(6);
            }
        } elseif ($action === 'mint' || $action === 'remint') {
            $existing = (int) Db::one('SELECT COUNT(*) FROM api_keys WHERE is_active = 1 AND revoked_at IS NULL');
            if ($existing > 0 && $action === 'mint') {
                $note = 'There are already ' . $existing . ' active keys in this database. Minting again '
                      . 'would leave two live keys per agent. Use "revoke and re-mint" if you no longer '
                      . 'have the originals.';
            } else {
                $keys = [];
                Db::tx(static function () use ($agents, $env, $action, &$keys): void {
                    foreach ($agents as $agent) {
                        if ($action === 'remint') ApiKeys::revoke($agent);
                        $raw = ApiKeys::mint($agent, $env);
                        ApiKeys::store($agent, $raw, 'installer ' . gmdate('Y-m-d'));
                        $keys[$agent] = $raw;
                    }
                });
                $s['keys'] = $keys;
            }
        }
    }

    $keys = $s['keys'] ?? null;

    echo go_head('API keys');
    echo '<div class="card"><h1>Step 5 &middot; The six agent keys</h1>'
       . '<p>One key per agent, so a single agent can be revoked without stopping the other five. That '
       . 'matters most for A5, which is the only agent that can write to LinkedIn.</p>';

    if ($note !== '') echo '<p class="tag warn">' . h($note) . '</p>';

    if ($keys === null) {
        echo '<form method="post" action="install.php?step=5">'
           . '<input type="hidden" name="_csrf" value="' . h(go_csrf()) . '">'
           . '<button class="btn" type="submit" name="action" value="mint">Mint the six keys</button> '
           . '<button class="btn ghost" type="submit" name="action" value="remint">Revoke existing and re-mint</button>'
           . '</form>';
    } else {
        echo '<div class="note"><b>Shown once.</b> Only a 12-character prefix and a SHA-256 hash are stored, '
           . 'so nobody - including this installer - can show them again. Copy them now.</div>';

        $env_block = '';
        foreach ($keys as $agent => $raw) {
            $env_block .= 'GO_API_KEY_' . $agent . '=' . $raw . "\n";
            echo '<div class="keyrow"><strong>' . h($agent) . '</strong> '
               . '<span class="muted">' . h(implode(', ', ApiKeys::SCOPES[$agent])) . '</span>'
               . '<code id="k' . h($agent) . '">GO_API_KEY_' . h($agent) . '=' . h($raw) . '</code>'
               . '<button class="btn small ghost" type="button" data-copy="k' . h($agent) . '">Copy</button></div>';
        }

        echo '<h2>All six, as an .env block</h2>'
           . '<pre id="envblock">' . h(rtrim($env_block)) . '</pre>'
           . '<button class="btn ghost" type="button" data-copy="envblock">Copy all</button> '
           . '<button class="btn ghost" type="button" id="dlenv">Download go-agents.env</button>'
           . '<p class="remedy">These belong on the machine that runs the agents, not on this server.</p>';

        echo '<form method="post" action="install.php?step=5">'
           . '<input type="hidden" name="_csrf" value="' . h(go_csrf()) . '">'
           . '<label style="font-weight:400"><input type="checkbox" name="stored" value="1"> '
           . 'I have stored all six keys somewhere safe.</label>'
           . '<button class="btn" type="submit" name="action" value="confirm">Continue</button></form>';

        echo <<<'JS'
<script>
document.querySelectorAll('[data-copy]').forEach(function (b) {
  b.addEventListener('click', function () {
    var el = document.getElementById(b.getAttribute('data-copy'));
    var text = el.textContent;
    var done = function () { var t = b.textContent; b.textContent = 'Copied'; setTimeout(function(){ b.textContent = t; }, 1200); };
    if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(text).then(done); return; }
    var ta = document.createElement('textarea');
    ta.value = text; document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); done(); } catch (e) { alert('Select the text and copy it manually.'); }
    document.body.removeChild(ta);
  });
});
document.getElementById('dlenv').addEventListener('click', function () {
  // Built in the browser from text already on this page: the keys are not asked
  // for a second time and never travel back to the server.
  var blob = new Blob([document.getElementById('envblock').textContent + '\n'], {type: 'text/plain'});
  var a = document.createElement('a');
  a.href = URL.createObjectURL(blob); a.download = 'go-agents.env';
  document.body.appendChild(a); a.click(); document.body.removeChild(a);
  setTimeout(function(){ URL.revokeObjectURL(a.href); }, 2000);
});
</script>
JS;
    }

    echo '</div>' . go_foot();
}

// --------------------------------------------------------------------------
// Step 6: write the config, lock, self-test, self-destruct.
// --------------------------------------------------------------------------

function go_write_config(array $cfg): array
{
    $header = "<?php\n"
        . "/**\n"
        . " * Written by public/install.php on " . gmdate('Y-m-d H:i:s') . " UTC.\n"
        . " *\n"
        . " * Holds the database password. It is outside the document root, config/.htaccess\n"
        . " * denies it, and it is chmod 0600. Do not copy it into version control.\n"
        . " */\n"
        . "return ";
    $php = $header . var_export($cfg, true) . ";\n";

    if (@file_put_contents(GO_CONFIG_FILE, $php, LOCK_EX) === false) {
        return ['ok' => false, 'perms' => '', 'why' => 'could not write ' . GO_CONFIG_FILE];
    }
    @chmod(GO_CONFIG_FILE, 0600);
    clearstatcache(true, GO_CONFIG_FILE);
    $perms = fileperms(GO_CONFIG_FILE);
    $mode  = $perms === false ? 0 : ($perms & 0777);
    return ['ok' => true, 'perms' => sprintf('%04o', $mode), 'why' => '', 'wide' => ($mode & 0077) !== 0];
}

function go_step_finish(): void
{
    $s = &go_state();
    if (!go_done(5)) go_redirect(5);

    $env = ($s['env'] ?? 'production') === 'test' ? 'test' : 'production';
    $cfg = [
        'db' => [
            'host' => (string) $s['db']['host'],
            'port' => (int) $s['db']['port'],
            'name' => (string) $s['db']['name'],
            'user' => (string) $s['db']['user'],
            'pass' => (string) $s['db']['pass'],
        ],
        'app_key'              => bin2hex(random_bytes(32)),
        'env'                  => $env,
        'test_harness_enabled' => $env === 'test',
        'test_token'           => bin2hex(random_bytes(32)),
        'argon2'               => $s['argon2'] ?? ['memory_cost' => 19456, 'time_cost' => 2, 'threads' => 1],
    ];

    $write = go_write_config($cfg);

    $lockOk = false;
    if ($write['ok']) {
        $lock = json_encode([
            'installed_at' => gmdate('c'),
            'php'          => PHP_VERSION,
            'server'       => (string) ($s['db_server'] ?? ''),
            'env'          => $env,
            'owner'        => (string) ($s['owner_email'] ?? ''),
            'trigger'      => !empty($s['trigger_ok']),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $lockOk = @file_put_contents(GO_LOCK_FILE, $lock . "\n", LOCK_EX) !== false;
    }

    // The self-test that would have caught the JSON-whitespace hashing bug
    // ContentHash documents. On a fresh database it checks zero rows, which is
    // the point: it proves the query runs and the tables are shaped right.
    $self = ['checked' => 0, 'mismatched' => [], 'error' => null];
    try {
        Db::connect($s['db']);
        $self = ContentHash::selfTest() + ['error' => null];
    } catch (Throwable $e) {
        $self['error'] = $e->getMessage();
    }

    $deleted = false;
    if ($write['ok'] && $lockOk) {
        $deleted = @unlink(__FILE__);
        clearstatcache(true, __FILE__);
        if ($deleted && is_file(__FILE__)) $deleted = false;
    }

    // Everything the rest of this page prints is read out of the session first,
    // because the next statement throws the session away: the database password
    // has been written to config.php and has no business outliving this request
    // in a session file on shared hosting.
    $email     = (string) ($s['owner_email'] ?? '');
    $triggerOk = !empty($s['trigger_ok']);
    $_SESSION['go'] = ['done' => $_SESSION['go']['done'], 'csrf' => go_csrf(), 'finished' => true];

    echo go_head('Finished');
    echo '<div class="card">';

    if (!$write['ok']) {
        echo '<h1>Could not finish</h1><p class="tag fail">fail</p>'
           . '<p>' . h((string) $write['why']) . '</p>'
           . '<p>Set <code>config/</code> to <code>0755</code> in File Manager and reload this page.</p>'
           . '</div>' . go_foot();
        return;
    }

    echo '<h1>Installed</h1>';
    echo '<table class="checks">';
    echo '<tr><td class="s"><span class="tag pass">pass</span></td><td><strong>config/config.php</strong><br>'
       . 'written, permissions <code>' . h((string) $write['perms']) . '</code></td></tr>';
    if (!empty($write['wide'])) {
        echo '<tr><td class="s"><span class="tag warn">warn</span></td><td><strong>File permissions</strong><br>'
           . 'The host would not accept <code>0600</code>; <code>config/config.php</code> is '
           . '<code>' . h((string) $write['perms']) . '</code>, which is readable by more than your user. '
           . '<div class="remedy"><b>Fix:</b> File Manager &rarr; <code>config/config.php</code> &rarr; '
           . 'Permissions &rarr; untick everything except Owner Read and Owner Write. If it reverts, your host '
           . 'runs PHP as a shared user - <code>config/.htaccess</code> still denies it over the web.</div>'
           . '</td></tr>';
    }
    echo '<tr><td class="s"><span class="tag ' . ($lockOk ? 'pass' : 'fail') . '">' . ($lockOk ? 'pass' : 'fail')
       . '</span></td><td><strong>config/installed.lock</strong><br>'
       . ($lockOk ? 'written - the app is live and this installer is now refused' : 'could not be written') . '</td></tr>';

    if ($self['error'] !== null) {
        echo '<tr><td class="s"><span class="tag warn">warn</span></td><td><strong>ContentHash::selfTest()</strong><br>'
           . 'did not run: ' . h((string) $self['error']) . '</td></tr>';
    } elseif ($self['mismatched'] === []) {
        echo '<tr><td class="s"><span class="tag pass">pass</span></td><td><strong>ContentHash::selfTest()</strong><br>'
           . (int) $self['checked'] . ' approved post(s) checked, 0 mismatched</td></tr>';
    } else {
        echo '<tr><td class="s"><span class="tag fail">fail</span></td><td><strong>ContentHash::selfTest()</strong><br>'
           . count($self['mismatched']) . ' approved post(s) no longer hash to their approved value: <code>'
           . h(implode(', ', $self['mismatched'])) . '</code>. Do not publish until this is empty.</td></tr>';
    }
    echo '<tr><td class="s"><span class="tag ' . ($triggerOk ? 'pass' : 'warn') . '">'
       . ($triggerOk ? 'pass' : 'warn') . '</span></td>'
       . '<td><strong>original_* immutability trigger</strong><br>'
       . ($triggerOk
            ? 'installed in the database'
            : 'not installed - enforced in application code only') . '</td></tr>';
    echo '</table>';

    if ($deleted) {
        echo '<p><span class="tag pass">pass</span> <code>public/install.php</code> deleted itself.</p>';
    } else {
        echo '<div class="card bad" style="margin-top:1rem"><h2 style="margin-top:0">Delete install.php now</h2>'
           . '<p>The installer could not remove itself. It is locked against re-running, but it should not '
           . 'stay on a public web server.</p>'
           . '<p>cPanel &rarr; <b>File Manager</b> &rarr; <code>webapp/public/install.php</code> &rarr; '
           . '<b>Delete</b>.</p></div>';
    }

    echo '<h2>Before the agents run</h2><ol>'
       . '<li>Sign in at <code>' . h(go_base_url()) . 'login</code> as <code>' . h($email) . '</code>.</li>'
       . '<li>Point the agent host at this base URL: <code>' . h(go_base_url()) . '</code> '
       . '(set <code>GO_API_BASE=' . h(rtrim(go_base_url(), '/')) . '/api/v1</code>).</li>'
       . '<li>Set the six environment variables on the machine that runs the agents: '
       . '<code>GO_API_KEY_A1</code> &hellip; <code>GO_API_KEY_A6</code>, from the .env block in step 5. '
       . 'They do not belong on this server.</li>'
       . '<li>Open <b>Settings</b> in the app and replace <code>linkedin_org_urn</code> - the schema ships it '
       . 'as <code>urn:li:organization:REPLACE_WITH_NUMERIC_ORG_ID</code>.</li>'
       . '<li>Leave <code>linkedin.dryRun</code> in <code>config/pipeline.config.json</code> set to '
       . '<code>true</code> until LinkedIn approves the Community Management API app. A5 and A6 run their '
       . 'full logic in dry-run and publish nothing.</li>'
       . '<li>Confirm <code>' . h(go_base_url()) . 'healthz</code> returns JSON, and run '
       . '<code>public/preflight.php</code> once more if any agent call returns 401.</li>'
       . '</ol>';

    echo '</div>' . go_foot();
}

// --------------------------------------------------------------------------
// Dispatch.
// --------------------------------------------------------------------------

try {
    go_token_gate();

    $step = (int) ($_GET['step'] ?? 1);
    if ($step < 1 || $step > 6) $step = 1;

    // Never let a URL jump past a step that has not been completed.
    for ($n = 2; $n <= 6; $n++) {
        if ($step >= $n && !go_done($n - 1)) { $step = $n - 1; break; }
    }
    $GLOBALS['go_current_step'] = $step;

    if (go_locked()) go_render_locked();      // re-checked at every step

    match ($step) {
        1 => go_step_env(),
        2 => go_step_db(),
        3 => go_step_schema(),
        4 => go_step_owner(),
        5 => go_step_keys(),
        6 => go_step_finish(),
    };
} catch (Throwable $e) {
    error_log('[go-install] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo go_head('Installer error');
    echo '<div class="card bad"><h1>The installer hit an error</h1>'
       . '<pre>' . h($e->getMessage()) . '</pre>'
       . '<p>The full detail is in <code>storage/logs/php-error.log</code>. '
       . 'Nothing has been locked, so you can fix the cause and reload.</p>'
       . '<p><a class="btn" href="install.php">Back to the installer</a></p></div>';
    echo go_foot();
}
