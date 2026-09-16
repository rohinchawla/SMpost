<?php declare(strict_types=1);
if (!defined('GO_BOOT')) { http_response_code(404); exit; }

/**
 * Loaded once by public/index.php, before anything else happens.
 *
 * The job here is to make it impossible for a PHP diagnostic to reach the
 * response body. A "<b>Warning</b>:" prefixed to a JSON payload breaks every
 * agent at once, and on a host with display_errors=On a stack trace would carry
 * the database password with it.
 */

define('GO_ROOT', dirname(__DIR__));
define('GO_APP', GO_ROOT . '/app');
define('GO_STORAGE', GO_ROOT . '/storage');
define('GO_MEDIA', GO_STORAGE . '/media');
define('GO_CONFIG_FILE', GO_ROOT . '/config/config.php');
define('GO_LOCK_FILE', GO_ROOT . '/config/installed.lock');

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
if (is_dir(GO_STORAGE . '/logs')) {
    ini_set('error_log', GO_STORAGE . '/logs/php-error.log');
}
error_reporting(E_ALL);
// Shortest round-trip float formatting, which is what Node does too. This is
// load-bearing for the idempotency request hash.
ini_set('serialize_precision', '-1');
date_default_timezone_set('UTC');

require_once GO_APP . '/Core/ApiError.php';
require_once GO_APP . '/Core/Js.php';
require_once GO_APP . '/Core/Uuid.php';
require_once GO_APP . '/Core/Dt.php';
require_once GO_APP . '/Core/Ist.php';
require_once GO_APP . '/Core/Canon.php';
require_once GO_APP . '/Core/Http.php';
require_once GO_APP . '/Core/Db.php';
require_once GO_APP . '/Core/Router.php';
require_once GO_APP . '/Domain/Words.php';
require_once GO_APP . '/Domain/DedupeHash.php';
require_once GO_APP . '/Domain/ContentHash.php';
require_once GO_APP . '/Domain/Settings.php';
require_once GO_APP . '/Domain/Idempotency.php';
require_once GO_APP . '/Domain/Media.php';
require_once GO_APP . '/Domain/Sweeper.php';
require_once GO_APP . '/Auth/ApiKeys.php';
require_once GO_APP . '/Auth/AgentAuth.php';
require_once GO_APP . '/Auth/UiAuth.php';
require_once GO_APP . '/Handlers/Views.php';
require_once GO_APP . '/Handlers/Meta.php';
require_once GO_APP . '/Handlers/Runs.php';
require_once GO_APP . '/Handlers/Topics.php';
require_once GO_APP . '/Handlers/Posts.php';
require_once GO_APP . '/Handlers/Images.php';
require_once GO_APP . '/Handlers/Publish.php';
require_once GO_APP . '/Handlers/Metrics.php';
require_once GO_APP . '/Handlers/AgentApi.php';
require_once GO_APP . '/Services/ReviewService.php';
require_once GO_APP . '/Services/Dashboard.php';
/* The one file that is not in a production upload. Its routes are gated on
   env=test plus a token in any case, but a file that is not there cannot be
   misconfigured, so scripts/package-webapp.mjs leaves it out and this load is
   conditional. Everything else here is required outright on purpose: a missing
   handler should be a fatal error, not a 404 nobody understands. */
if (is_file(GO_APP . '/Harness/Harness.php')) { require_once GO_APP . '/Harness/Harness.php'; }
require_once GO_APP . '/Ui/View.php';
require_once GO_APP . '/Ui/Provenance.php';
require_once GO_APP . '/Ui/Charts.php';
require_once GO_APP . '/Ui/Screens.php';
require_once GO_APP . '/Ui/UiApp.php';

/** Every diagnostic becomes an exception, so nothing is ever printed. */
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(static function (Throwable $e): void {
    Http::error($e);
});

/**
 * A fatal or an exhausted memory_limit cannot be caught by the handlers above,
 * and an 8 MB image upload is exactly where that happens. Emit the envelope
 * here instead of letting Apache return an HTML 500 that no agent can parse.
 */
register_shutdown_function(static function (): void {
    $last = error_get_last();
    if ($last === null) return;
    if (!in_array($last['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR], true)) return;
    if (Http::alreadySent() || headers_sent()) return;
    error_log('[go] fatal: ' . $last['message'] . ' @ ' . $last['file'] . ':' . $last['line']);
    Http::error(new ApiError(500, 'INTERNAL', 'internal error'));
});

/** The installed configuration, or null when the app has not been installed. */
function go_config(): ?array
{
    static $cfg = null;
    if ($cfg === null) {
        if (!is_file(GO_CONFIG_FILE)) return null;
        $loaded = require GO_CONFIG_FILE;
        $cfg = is_array($loaded) ? $loaded : null;
    }
    return $cfg;
}

function go_installed(): bool
{
    return is_file(GO_LOCK_FILE) && go_config() !== null;
}

function go_setting(string $key, mixed $default = null): mixed
{
    $cfg = go_config();
    return $cfg[$key] ?? $default;
}

function go_is_test_env(): bool
{
    return go_setting('env') === 'test' && go_setting('test_harness_enabled') === true;
}
