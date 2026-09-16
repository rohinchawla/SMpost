<?php declare(strict_types=1);
if (!defined('GO_BOOT')) { http_response_code(404); exit; }

/**
 * The one screen that renders while signed out, so it carries its own document.
 *
 * The error text is deliberately generic and identical for a wrong password, a
 * missing account and a locked one - UiAuth already spends the same time on all
 * three, and a screen that says "no such user" would give the timing defence
 * away in words.
 */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= View::e($title ?? 'Sign in') ?> - Golden Opportunities</title>
<link rel="stylesheet" href="<?= View::e(View::url('assets/app.css')) ?>">
</head>
<body>
<div class="go-app">
  <main class="go-main">
    <div class="go-page u-stack-16">

      <div class="u-row">
        <span class="ks-mark" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 2.5 21.5 12 12 21.5 2.5 12Z"/>
            <path d="M12 8.5 15.5 12 12 15.5 8.5 12Z"/>
          </svg>
        </span>
        <span class="ks-wordmark">Golden Opportunities</span>
      </div>

      <h1>Sign in</h1>

      <?php if (!empty($error)): ?>
        <p class="go-flash is-error" role="alert">
          <?= View::icon('warning') ?>
          <?php if (is_string($error)): ?>
            <span><?= View::e($error) ?></span>
          <?php else: ?>
            <span><strong>That did not work.</strong> Check the email and password and try again. After five
            failures the account locks for a few minutes.</span>
          <?php endif; ?>
        </p>
      <?php endif; ?>

      <form method="post" action="<?= View::e(View::url('login')) ?>" class="u-stack-16">
        <?= View::csrf() ?>
        <input type="hidden" name="next" value="<?= View::e($next ?? '') ?>">

        <p class="go-field">
          <label class="go-label" for="f-email">Email</label>
          <input class="go-input" id="f-email" name="email" type="email" autocomplete="username"
                 required autofocus<?= !empty($error) ? ' aria-invalid="true"' : '' ?>>
        </p>

        <p class="go-field">
          <label class="go-label" for="f-password">Password</label>
          <input class="go-input" id="f-password" name="password" type="password"
                 autocomplete="current-password" required<?= !empty($error) ? ' aria-invalid="true"' : '' ?>>
        </p>

        <p><button type="submit" class="go-btn go-btn-primary">Sign in</button></p>
      </form>

      <p class="go-hint">This is the approval app for the LinkedIn pipeline. Nothing reaches the company
      page without being approved here twice.</p>

    </div>
  </main>
</div>
</body>
</html>
