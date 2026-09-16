<?php declare(strict_types=1);
if (!defined('GO_BOOT')) { http_response_code(404); exit; }

/**
 * The shell every signed-in screen renders inside.
 *
 * The nav is a <details> at every width. Above 720px the stylesheet hides the
 * summary and forces the links open, so the disclosure is inert; under 720px it
 * becomes a real one. That way the collapse needs no JavaScript and no second
 * markup path.
 *
 * The runway count is read here rather than passed in by every screen, because
 * "how many days of publishing are already approved" is the one number the
 * owner wants visible on every page, and Dashboard::runwayDays is the same
 * query A5 reasons about.
 */
$goPath  = Router::normalise(Router::path());
$goHead  = explode('/', trim($goPath, '/'))[0] ?? '';
$goNav   = ['' => 'Today', 'topics' => 'Topics', 'posts' => 'Posts',
            'queue' => 'Queue', 'analytics' => 'Performance', 'ops' => 'Ops'];
$goRunway = Dashboard::runwayDays();

/* Nothing in this app sets a flash yet; the shell renders one when something
   does, rather than a later feature having to touch every view. */
$goFlash = [];
if (!empty($_SESSION['go_flash']) && is_array($_SESSION['go_flash'])) {
    $goFlash = $_SESSION['go_flash'];
    unset($_SESSION['go_flash']);
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= View::e($title ?? 'Golden Opportunities') ?> - Golden Opportunities</title>
<link rel="stylesheet" href="<?= View::e(View::url('assets/app.css')) ?>">
<script defer src="<?= View::e(View::url('assets/app.js')) ?>"></script>
</head>
<body>
<div class="go-app">

  <header class="go-topbar">
    <a class="go-topbar-brand" href="<?= View::e(View::url('')) ?>">
      <span class="ks-mark" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
          <path d="M12 2.5 21.5 12 12 21.5 2.5 12Z"/>
          <path d="M12 8.5 15.5 12 12 15.5 8.5 12Z"/>
        </svg>
      </span>
      <span class="ks-wordmark">Golden Opportunities</span>
    </a>

    <details class="go-topbar-nav">
      <summary aria-label="Menu"><?= View::icon('menu', 20) ?></summary>
      <?php foreach ($goNav as $goSeg => $goLabel): ?>
        <a class="go-topbar-link<?= $goHead === $goSeg ? ' is-current' : '' ?>"
           href="<?= View::e(View::url($goSeg)) ?>"<?= $goHead === $goSeg ? ' aria-current="page"' : '' ?>><?= View::e($goLabel) ?></a>
      <?php endforeach; ?>
    </details>

    <p class="go-status u-hide-sm">
      <?= View::icon('calendar') ?>
      <span class="go-num"><?= View::e(View::num($goRunway)) ?></span>
      <?= $goRunway === 1 ? 'day queued' : 'days queued' ?>
    </p>
  </header>

  <main class="go-main">
    <div class="go-page">
      <?php foreach ($goFlash as $goMsg): ?>
        <?php $goErr = is_array($goMsg) && ($goMsg['level'] ?? '') === 'error'; ?>
        <p class="go-flash<?= $goErr ? ' is-error' : '' ?>" role="<?= $goErr ? 'alert' : 'status' ?>">
          <?= View::icon($goErr ? 'warning' : 'check') ?>
          <span><?= View::e(is_array($goMsg) ? ($goMsg['text'] ?? '') : $goMsg) ?></span>
        </p>
      <?php endforeach; ?>

      <?= $content ?>
    </div>
  </main>

  <hr>
  <footer class="go-page">
    <div class="u-row u-gap-12">
      <span class="u-faint">Signed in as <?= View::e($user['email'] ?? 'unknown') ?></span>
      <a class="go-btn go-btn-quiet go-btn-sm" href="<?= View::e(View::url('settings')) ?>">Settings</a>
      <form method="post" action="<?= View::e(View::url('logout')) ?>">
        <?= View::csrf() ?>
        <button type="submit" class="go-btn go-btn-quiet go-btn-sm">Sign out</button>
      </form>
    </div>
  </footer>

</div>
</body>
</html>
