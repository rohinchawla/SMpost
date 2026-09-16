<?php declare(strict_types=1);
/**
 * Preflight probe - one file, no dependencies.
 *
 * Upload this on its own, before you buy a year of hosting and before you
 * install anything, and open it in a browser. It answers the only question that
 * matters at that point: will this host run the Golden Opportunities approval
 * app at all?
 *
 * It needs no database, no config, no session and nothing else from the app. It
 * writes nothing. Three of its checks are things you cannot find out from a
 * hosting feature list, and each of them has ended a deployment before:
 *
 *   - whether .htaccess rewriting is on,
 *   - whether the Authorization header survives to PHP (if it does not, every
 *     single agent call returns 401 on the real host while working perfectly on
 *     your laptop),
 *   - whether a URL path containing a literal colon reaches PHP, because one
 *     route is /api/v1/agent/metrics:bulk-upsert and an aggressive ModSecurity
 *     ruleset answers that with an HTML 403 that no agent can parse.
 *
 * Delete it when you are done. It reveals your PHP configuration to anyone who
 * finds it.
 */

// ---------------------------------------------------------------------------
// Probe endpoints.
//
// The page calls itself over HTTP to see what actually survives the web server.
// These two branches answer those calls in text/plain and stop; they must come
// before any output.
// ---------------------------------------------------------------------------

$probe = (string) ($_GET['probe'] ?? '');

if ($probe === 'auth') {
    // Report all three places the app looks for the header (Router::bearer()).
    $fromServer   = isset($_SERVER['HTTP_AUTHORIZATION']) && $_SERVER['HTTP_AUTHORIZATION'] !== '';
    $fromRedirect = isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) && $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] !== '';
    $fromApache   = false;
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp((string) $k, 'Authorization') === 0 && trim((string) $v) !== '') {
                $fromApache = true;
                break;
            }
        }
    }
    $seen = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    $intact = $seen === 'Bearer go-preflight-probe-token';

    header('Content-Type: text/plain; charset=utf-8');
    echo 'GO-PROBE-AUTH'
       . ' server=' . ($fromServer ? 'yes' : 'no')
       . ' redirect=' . ($fromRedirect ? 'yes' : 'no')
       . ' getallheaders=' . ($fromApache ? 'yes' : 'no')
       . ' intact=' . ($intact ? 'yes' : 'no');
    exit;
}

if ($probe === 'colon') {
    // Reached through PATH_INFO, e.g. preflight.php/go:probe?probe=colon.
    header('Content-Type: text/plain; charset=utf-8');
    echo 'GO-PROBE-COLON path=' . (string) ($_SERVER['PATH_INFO'] ?? '(none)')
       . ' uri=' . (string) ($_SERVER['REQUEST_URI'] ?? '(none)');
    exit;
}

// ---------------------------------------------------------------------------
// Helpers.
// ---------------------------------------------------------------------------

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function pf_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function pf_self_url(): string
{
    $scheme = pf_is_https() ? 'https' : 'http';
    $host   = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '/preflight.php');
    return $scheme . '://' . $host . $script;
}

function pf_base_url(): string
{
    $scheme = pf_is_https() ? 'https' : 'http';
    $host   = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $dir    = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/preflight.php'))), '/');
    return $scheme . '://' . $host . $dir . '/';
}

/** "32M" / "192K" / "-1" -> bytes. -1 means unlimited and stays -1. */
function pf_bytes(string $v): int
{
    $v = trim($v);
    if ($v === '')   return 0;
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

/**
 * A same-origin HTTP request.
 *
 * @return array{ok:bool,status:int,body:string,headers:string,error:string}
 */
function pf_probe(string $url, array $headers = []): array
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
            // Same host, and AutoSSL may not have issued a certificate yet. A
            // TLS complaint here would hide the answer we actually came for.
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_USERAGENT      => 'go-preflight',
        ]);
        $res = curl_exec($ch);
        if ($res === false) {
            $out['error'] = curl_error($ch);
            curl_close($ch);
            return $out;
        }
        $hlen           = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
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
        $out['error'] = 'curl is not installed and allow_url_fopen is off, so this host cannot call itself';
        return $out;
    }
    $out['ok']      = true;
    $out['body']    = $body;
    $out['headers'] = implode("\n", $http_response_header ?? []);
    if (preg_match('~HTTP/\S+\s+(\d{3})~', $out['headers'], $m)) $out['status'] = (int) $m[1];
    return $out;
}

$checks = [];
function pf_add(string $label, string $state, string $detail, string $remedy = ''): void
{
    $GLOBALS['checks'][] = ['label' => $label, 'state' => $state, 'detail' => $detail, 'remedy' => $remedy];
}

// ---------------------------------------------------------------------------
// The checks.
// ---------------------------------------------------------------------------

version_compare(PHP_VERSION, '8.1.0', '>=')
    ? pf_add('PHP version', 'pass', PHP_VERSION . ' (' . PHP_SAPI . ')')
    : pf_add('PHP version', 'fail', PHP_VERSION . ' - the app needs 8.1 or newer',
        'cPanel &rarr; <b>MultiPHP Manager</b> &rarr; tick this domain &rarr; choose <b>PHP 8.1</b> or newer '
        . '&rarr; <b>Apply</b>. If no 8.1+ build is offered, this host cannot run the app.');

foreach ([
    'pdo_mysql' => 'talking to MySQL or MariaDB',
    'openssl'   => 'random_bytes() and HTTPS calls to LinkedIn',
    'mbstring'  => 'lower-casing email addresses and counting body words',
    'json'      => 'every request and response the agents make',
] as $ext => $why) {
    extension_loaded($ext)
        ? pf_add('Extension ' . $ext, 'pass', 'loaded - ' . $why)
        : pf_add('Extension ' . $ext, 'fail', 'missing - needed for ' . $why,
            'cPanel &rarr; <b>Select PHP Version</b> &rarr; <b>Extensions</b> tab &rarr; tick '
            . '<code>' . h($ext) . '</code>. The page saves as you tick; reload this one afterwards.');
}

defined('PASSWORD_ARGON2ID')
    ? pf_add('Argon2id', 'pass', 'available - the owner password will be hashed with it')
    : pf_add('Argon2id', 'warn', 'not compiled into this PHP build',
        'Not a blocker: the installer falls back to bcrypt cost 12, which is sound. To get Argon2id, try a '
        . 'different PHP version in <b>MultiPHP Manager</b>, or ask support for a build made with '
        . '<code>--with-password-argon2</code>.');

$pms = (string) ini_get('post_max_size');
pf_bytes($pms) >= 24 * 1024 * 1024
    ? pf_add('post_max_size', 'pass', $pms . ' (effective)')
    : pf_add('post_max_size', 'fail', $pms . ' (effective) - 24M is the minimum, 32M is what the app ships',
        'A3 uploads an 8&nbsp;MB PNG as base64 inside JSON, which is about 10.7&nbsp;MB on the wire. '
        . 'Put <code>post_max_size = 32M</code> in a <code>.user.ini</code> file in the document root, or set '
        . 'it in cPanel &rarr; <b>Select PHP Version</b> &rarr; <b>Options</b>. Do not use '
        . '<code>php_value</code> in <code>.htaccess</code>: under LSAPI and PHP-FPM, which is what cPanel '
        . 'EA-PHP runs, that is an instant 500.');

$ml  = (string) ini_get('memory_limit');
$mlb = pf_bytes($ml);
($mlb < 0 || $mlb >= 192 * 1024 * 1024)
    ? pf_add('memory_limit', 'pass', $ml . ' (effective)')
    : pf_add('memory_limit', 'fail', $ml . ' (effective) - 192M is the minimum, 256M is what the app ships',
        'The upload body is held in memory twice, once parsed and once re-encoded for the idempotency hash. '
        . 'Set <code>memory_limit = 256M</code> in <code>.user.ini</code>, or in cPanel &rarr; '
        . '<b>Select PHP Version</b> &rarr; <b>Options</b>.');

// --- .htaccess rewriting ---------------------------------------------------

$htaccess = is_file(__DIR__ . '/.htaccess');
$index    = is_file(__DIR__ . '/index.php');
$rwUrl    = pf_base_url() . '__go_preflight_rewrite_' . bin2hex(random_bytes(4));
$rw       = pf_probe($rwUrl);

if (!$htaccess) {
    pf_add('.htaccess rewriting', 'warn', 'there is no .htaccess next to this file, so there is nothing to test',
        'Expected if you uploaded preflight.php on its own. Upload <code>public/.htaccess</code> with the app '
        . 'and run this page again. File Manager hides dotfiles until you turn on '
        . '<b>Settings &rarr; Show Hidden Files (dotfiles)</b>.');
} elseif (!$rw['ok']) {
    pf_add('.htaccess rewriting', 'warn', 'the probe could not run (' . h($rw['error'] ?: 'no response') . ')',
        'This host blocks PHP from calling itself. Open <code>' . h($rwUrl) . '</code> in a browser tab: you '
        . 'should get a page from the app, not an Apache <i>404 Not Found</i>.');
} elseif ($rw['status'] !== 404 && $rw['status'] !== 0) {
    pf_add('.htaccess rewriting', 'pass',
        'a path that is not a real file was handed to index.php (HTTP ' . $rw['status'] . ')');
} elseif (stripos($rw['headers'], 'X-Content-Type-Options') !== false) {
    pf_add('.htaccess rewriting', 'fail',
        'HTTP 404 - .htaccess is being read (its headers came back) but the rewrite did not fire',
        'mod_rewrite is off for this domain. Ask support to enable <code>mod_rewrite</code> and set '
        . '<code>AllowOverride All</code> for this document root. Without it every URL except '
        . '<code>index.php</code> itself is a 404.');
} elseif (!$index) {
    pf_add('.htaccess rewriting', 'warn', 'HTTP 404 - but index.php is not here either, so this is inconclusive',
        'Upload the whole <code>public/</code> folder and run this page again.');
} else {
    pf_add('.htaccess rewriting', 'fail', 'HTTP ' . $rw['status'] . ' - nothing reached index.php',
        'Either <code>AllowOverride</code> is <code>None</code> for this document root, or the subdomain\'s '
        . 'document root does not point at the folder this file is in. Check cPanel &rarr; <b>Domains</b> '
        . '&rarr; the subdomain &rarr; <b>Document Root</b>.');
}

// --- the Authorization header ----------------------------------------------

$auth = pf_probe(pf_self_url() . '?probe=auth', ['Authorization: Bearer go-preflight-probe-token']);

if (!$auth['ok']) {
    pf_add('Authorization header', 'warn', 'the probe could not run (' . h($auth['error'] ?: 'no response') . ')',
        'Test it by hand from your own machine: <code>curl -i -H "Authorization: Bearer test" '
        . h(pf_self_url()) . '?probe=auth</code>. The reply must contain <code>server=yes</code> or '
        . '<code>redirect=yes</code>.');
} elseif (!str_contains($auth['body'], 'GO-PROBE-AUTH')) {
    pf_add('Authorization header', 'fail',
        'HTTP ' . $auth['status'] . ' - the request carrying an Authorization header did not reach PHP at all',
        'Something in front of PHP is rejecting authenticated requests outright, usually ModSecurity. See the '
        . 'colon-path remedy below; the same tool and the same fix apply.');
} else {
    $ok = str_contains($auth['body'], 'server=yes') || str_contains($auth['body'], 'redirect=yes');
    $viaHeaders = str_contains($auth['body'], 'getallheaders=yes');
    if ($ok) {
        pf_add('Authorization header', 'pass', 'reached PHP intact - ' . h(trim(substr($auth['body'], 13))));
    } elseif ($viaHeaders) {
        pf_add('Authorization header', 'warn',
            'stripped from $_SERVER but still visible through getallheaders() - ' . h(trim(substr($auth['body'], 13))),
            'The app reads all three locations, so it will work. To make it robust anyway, keep the '
            . '<code>RewriteCond %{HTTP:Authorization}</code> rule that ships in <code>public/.htaccess</code>.');
    } else {
        pf_add('Authorization header', 'fail',
            'the header was removed before PHP saw it - ' . h(trim(substr($auth['body'], 13))),
            'This is the most common reason a bearer-token API returns 401 for everything on a real host and '
            . 'works fine locally. CGI, FastCGI and LSAPI drop the header. Add this to <code>.htaccess</code> '
            . 'in the document root (it is already in the app\'s own <code>public/.htaccess</code>):'
            . '<pre>RewriteEngine On'
            . "\nRewriteCond %{HTTP:Authorization} ."
            . "\nRewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]</pre>"
            . 'If that is not enough, add <code>SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1</code> '
            . 'and re-run this page.');
    }
}

// --- a literal colon in the path -------------------------------------------

$ctrl  = pf_probe(pf_self_url() . '/gopath?probe=colon');
$colon = pf_probe(pf_self_url() . '/go:path?probe=colon');

$ctrlOk  = $ctrl['ok']  && str_contains($ctrl['body'], 'GO-PROBE-COLON');
$colonOk = $colon['ok'] && str_contains($colon['body'], 'GO-PROBE-COLON');

$modsecRemedy =
    'One route is <code>/api/v1/agent/metrics:bulk-upsert</code>. A ModSecurity rule is refusing the colon '
    . 'and answering with an HTML 403, which A6 cannot parse - it will look like a mysterious failure every '
    . 'morning at 09:00 IST.<br><br>'
    . 'Find the rule: cPanel &rarr; <b>Security</b> &rarr; <b>ModSecurity Tools</b> &rarr; <b>Hit List</b>, '
    . 'and look for the entry for this request. Note its <b>rule ID</b>. Then either turn the rule off for '
    . 'this domain from that same screen, or put this in the <code>.htaccess</code> of the document root, '
    . 'with the real ID in place of the example:'
    . '<pre>&lt;IfModule mod_security2.c&gt;'
    . "\n  # Replace 123456 with the rule ID from the ModSecurity hit list."
    . "\n  SecRuleRemoveById 123456"
    . "\n&lt;/IfModule&gt;</pre>"
    . 'If your host will not allow either, ask support to whitelist that one URL for this domain. Do not '
    . 'rename the route: the agents and the API contract both hard-code it.';

if (!$ctrl['ok'] && !$colon['ok']) {
    pf_add('Colon in the URL path', 'warn', 'the probes could not run',
        'Test it by hand: open <code>' . h(pf_self_url()) . '/go:path?probe=colon</code> in a browser. '
        . 'You should see a line starting <code>GO-PROBE-COLON</code>. A <b>403</b> means ModSecurity. '
        . '<br><br>' . $modsecRemedy);
} elseif ($colonOk) {
    pf_add('Colon in the URL path', 'pass',
        'a path containing a literal colon reached PHP (HTTP ' . $colon['status'] . ')');
} elseif ($colon['status'] === 403 || $colon['status'] === 406) {
    pf_add('Colon in the URL path', 'fail',
        'HTTP ' . $colon['status'] . ' - the colon path was blocked before PHP saw it'
        . ($ctrlOk ? ', while the same path without a colon was allowed' : ''),
        $modsecRemedy);
} elseif (!$ctrlOk) {
    pf_add('Colon in the URL path', 'warn',
        'inconclusive: even the control path without a colon did not reach PHP (HTTP ' . $ctrl['status'] . '), '
        . 'so this host simply does not pass extra path segments to a script',
        'The app does not rely on PATH_INFO - it reads <code>REQUEST_URI</code> - so this may be harmless. '
        . 'Re-run this check after the app is installed by opening '
        . '<code>' . h(pf_base_url()) . 'api/v1/agent/metrics:bulk-upsert</code>: a JSON reply of any kind is '
        . 'good news, an HTML 403 is not.<br><br>' . $modsecRemedy);
} else {
    pf_add('Colon in the URL path', 'fail',
        'HTTP ' . $colon['status'] . ' - the colon path did not reach PHP, the control path did',
        $modsecRemedy);
}

// ---------------------------------------------------------------------------
// Render.
// ---------------------------------------------------------------------------

$fails = count(array_filter($checks, static fn($c) => $c['state'] === 'fail'));
$warns = count(array_filter($checks, static fn($c) => $c['state'] === 'warn'));

$facts = [
    'Server software'   => (string) ($_SERVER['SERVER_SOFTWARE'] ?? 'unknown'),
    'PHP SAPI'          => PHP_SAPI,
    'Request scheme'    => pf_is_https() ? 'https' : 'http (no TLS - run AutoSSL in cPanel)',
    'Document root'     => (string) ($_SERVER['DOCUMENT_ROOT'] ?? 'unknown'),
    'This file'         => __FILE__,
    'PDO drivers'       => class_exists('PDO') ? implode(', ', PDO::getAvailableDrivers()) : 'PDO missing',
    'max_execution_time' => (string) ini_get('max_execution_time') . 's',
    'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
];

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Preflight &middot; Golden Opportunities</title>
<style>
:root{color-scheme:light}
body{font:16px/1.55 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:#f6f7f9;color:#14181f;margin:0}
.wrap{max-width:52rem;margin:0 auto;padding:1.5rem 1rem 4rem}
.card{background:#fff;border:1px solid #dde1e7;border-radius:10px;padding:1.1rem 1.25rem;margin:0 0 1rem}
h1{font-size:1.35rem;margin:.1rem 0 .5rem}
h2{font-size:1.05rem;margin:1.3rem 0 .4rem}
.muted{color:#5b6572}
code,pre{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:.86em}
pre{background:#0f1420;color:#e6edf3;padding:.7rem;border-radius:8px;overflow:auto;white-space:pre-wrap}
table{width:100%;border-collapse:collapse}
td{border-top:1px solid #e7eaee;padding:.5rem .4rem;vertical-align:top}
td.s{width:4.6rem;white-space:nowrap}
.tag{display:inline-block;border-radius:4px;padding:.05rem .4rem;font-size:.74rem;font-weight:700;letter-spacing:.03em;text-transform:uppercase}
.tag.pass{background:#e3f6e8;color:#1c6b33}
.tag.warn{background:#fdf2d8;color:#8a5b00}
.tag.fail{background:#fbe3e0;color:#a32316}
.remedy{color:#404a57;font-size:.9rem;margin-top:.35rem}
.summary{font-size:1.05rem;font-weight:600}
.note{background:#fdf2d8;border:1px solid #f0dca8;border-radius:8px;padding:.6rem .8rem;font-size:.9rem;margin:.9rem 0}
.facts td{font-size:.88rem}
.facts td:first-child{width:12rem;color:#5b6572}
</style>
</head>
<body>
<main class="wrap">

<div class="card">
  <h1>Preflight</h1>
  <p class="muted">Golden Opportunities approval app &middot; <?= h(date('Y-m-d H:i')) ?> server time</p>
  <p class="summary">
    <?php if ($fails === 0 && $warns === 0): ?>
      <span class="tag pass">pass</span> This host can run the app.
    <?php elseif ($fails === 0): ?>
      <span class="tag warn">warn</span> This host can run the app. <?= $warns ?> item<?= $warns === 1 ? '' : 's' ?> worth reading.
    <?php else: ?>
      <span class="tag fail">fail</span> <?= $fails ?> blocking item<?= $fails === 1 ? '' : 's' ?><?= $warns > 0 ? ', ' . $warns . ' warning' . ($warns === 1 ? '' : 's') : '' ?>. Fix these before installing.
    <?php endif; ?>
  </p>
</div>

<div class="card">
  <table>
    <?php foreach ($checks as $c): ?>
      <tr>
        <td class="s"><span class="tag <?= h($c['state']) ?>"><?= h($c['state']) ?></span></td>
        <td>
          <strong><?= h($c['label']) ?></strong><br><?= h($c['detail']) ?>
          <?php if ($c['remedy'] !== '' && $c['state'] !== 'pass'): ?>
            <div class="remedy"><b>Fix:</b> <?= $c['remedy'] ?></div>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>

  <div class="note">
    <b>If you just changed a PHP setting:</b> a <code>.user.ini</code> file is cached by PHP for
    <code>user_ini.cache_ttl</code> seconds, five minutes by default. A value you edited a moment ago will not
    show up above until that expires. Wait five minutes, or re-save the file in File Manager to change its
    timestamp, then reload this page. What you see here is the effective value, read back with
    <code>ini_get()</code> - it is what the app will get.
  </div>
</div>

<div class="card">
  <h2>Host facts</h2>
  <table class="facts">
    <?php foreach ($facts as $k => $v): ?>
      <tr><td><?= h($k) ?></td><td><code><?= h($v) ?></code></td></tr>
    <?php endforeach; ?>
  </table>
</div>

<div class="card">
  <h2>Next</h2>
  <ol>
    <li>Fix anything marked <span class="tag fail">fail</span>, then reload this page.</li>
    <li>Upload the rest of the app and run <code>install.php</code>.</li>
    <li><b>Delete this file.</b> It tells anyone who finds it exactly how this server is configured.</li>
  </ol>
  <p class="muted">Full deployment steps are in <code>docs/DEPLOY.md</code>.</p>
</div>

</main>
</body>
</html>
