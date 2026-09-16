<?php declare(strict_types=1);
/**
 * The only routable file in the application.
 *
 * Everything else lives in app/, which the document root does not reach. If the
 * host will not move the document root, app/.htaccess denies it and every file
 * there also checks GO_BOOT, so a direct request returns 404 rather than the
 * database password.
 */

// Before anything can echo. A stray byte or a BOM in an included file would
// otherwise make every header call fail and corrupt the JSON.
ob_start();

/**
 * PHP's built-in server sends every request through this file, including ones
 * for real files like install.php. Apache does not - its rewrite only fires
 * when the target is missing (RewriteCond REQUEST_FILENAME !-f). Returning
 * false here restores that behaviour locally, so `php -S` behaves the same way
 * the host will. No effect under Apache, LSAPI or FPM.
 */
if (PHP_SAPI === 'cli-server') {
    $requested = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
    $onDisk = __DIR__ . '/' . ltrim($requested, '/');
    if ($onDisk !== __FILE__ && is_file($onDisk)) {
        return false;
    }
}

define('GO_BOOT', true);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$path = Router::normalise(Router::path());

try {
    if (!go_installed()) {
        // Nothing is configured yet. Send a human to the installer and an agent
        // a JSON error it can actually read.
        if (str_starts_with($path, '/api/')) {
            throw new ApiError(503, 'SERVICE_UNAVAILABLE', 'this app has not been installed yet');
        }
        Http::html(503, '<!doctype html><meta charset="utf-8"><title>Not installed</title>'
            . '<body style="font:16px/1.6 system-ui;max-width:42rem;margin:12vh auto;padding:0 1rem">'
            . '<h1 style="font-size:1.5rem">This app has not been installed yet.</h1>'
            . '<p>Open <a href="install.php">install.php</a> to set it up.</p>');
        exit;
    }

    Db::connect(go_setting('db', []));

    if ($path === '/healthz') {
        Http::json(200, ['ok' => true, 'time_ist' => Ist::date(), 'app' => 'go-approval']);
    } elseif (str_starts_with($path, '/media/')) {
        serveMedia(substr($path, strlen('/media/')));
    } elseif (AgentApi::handles($path)) {
        AgentApi::dispatch($path);
    } elseif (class_exists('Harness', false) && Harness::handles($path)) {
        Harness::dispatch($path);
    } else {
        UiApp::dispatch($path);
    }
} catch (Throwable $e) {
    // A UI route that fails an auth check should send the owner to the login
    // page, not a JSON envelope he cannot read.
    if ($e instanceof ApiError && $e->status === 401
        && !str_starts_with($path, '/api/') && !str_starts_with($path, '/__mock/')) {
        Http::redirect('login?next=' . rawurlencode($path));
    } else {
        Http::error($e);
    }
}

// Flush before housekeeping, so the client never waits for it.
if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
if (go_installed()) { Sweeper::maybeRun(); }

/**
 * One URL, three ways to be allowed. The agents keep working unchanged because
 * publishLease hands A5 a signed variant of the same path.
 */
function serveMedia(string $rel): void
{
    $signed = Media::signatureValid($rel, Router::query());
    if (!$signed) {
        $bearer = Router::bearer();
        $viaKey = $bearer !== null && ApiKeys::resolve($bearer) !== null;
        if (!$viaKey && UiAuth::user() === null) {
            throw ApiError::unauthorized('sign in to view this image');
        }
    }
    Media::serve($rel);
}
