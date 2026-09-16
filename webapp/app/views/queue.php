<?php declare(strict_types=1);
if (!defined('GO_BOOT')) { http_response_code(404); exit; }

/**
 * What publishes next, in A5's own order.
 *
 * The rows come from Dashboard::queue, which is the same function A5 uses to
 * pick tomorrow's post. A queue screen that computed its own order would be
 * worse than no queue screen at all.
 */
$goAction = View::url('queue');
?>

<div class="go-page-head">
  <h1 class="go-page-title">Publishing queue</h1>
  <p class="go-page-sub">A5 takes the top row each morning at 08:00 IST, publishes it, then posts the
  link as the first comment. This is the order it will actually use, not a copy of it.</p>
</div>

<?php if ($queue === []): ?>

  <div class="go-empty">
    <p class="go-empty-title">Nothing is queued, so tomorrow morning nothing goes out.</p>
    <p class="go-empty-body">A package reaches this queue when it is approved at gate 2 and has an image
    selected. If packages are waiting for you, approving one is all this needs.</p>
    <a class="go-btn go-btn-secondary" href="<?= View::e(View::url('posts')) ?>">Review packages</a>
  </div>

<?php else: ?>

  <p class="go-flash" role="status">
    <?= View::icon('calendar') ?>
    <span><strong><?= View::e(View::num($runway)) ?>
    <?= $runway === 1 ? 'day' : 'days' ?> of runway.</strong>
    That is how long the page keeps posting if nothing else is ever approved.</span>
  </p>

  <table class="go-table u-mt-24">
    <caption>Top row first. A failed publish is retried before anything new goes out.</caption>
    <thead>
      <tr>
        <th scope="col">Order</th>
        <th scope="col">Package</th>
        <th scope="col">Why it is here</th>
        <th scope="col">Date</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($queue as $goI => $goQ): ?>
        <?php
          $goUid   = (string) $goQ['post_uid'];
          $goStale = ReviewService::isStale($goQ);
        ?>
        <tr id="q-<?= View::e($goUid) ?>">
          <td data-label="Order" class="u-num"><?= View::e((string) ($goI + 1)) ?></td>
          <th scope="row">
            <a href="<?= View::e(View::url('posts/' . rawurlencode($goUid))) ?>"><?= View::e((string) $goQ['final_title']) ?></a>
            <span class="go-progress-note"><?= View::e(View::progress($goQ)) ?></span>
            <?php if ($goStale): ?>
              <span class="go-error"><?= View::icon('warning') ?> edited after approval, so A5 will refuse it</span>
            <?php endif; ?>
            <?php if (!empty($goQ['last_error_code'])): ?>
              <span class="go-error"><?= View::icon('warning') ?> last attempt failed: <?= View::e((string) $goQ['last_error_code']) ?></span>
            <?php endif; ?>
          </th>
          <td data-label="Why it is here"><?= View::e((string) ($goQ['queue_reason'] ?? '')) ?></td>
          <td data-label="Date">
            <form method="post" action="<?= View::e($goAction) ?>" class="u-row">
              <?= View::csrf() ?>
              <input type="hidden" name="action" value="date">
              <input type="hidden" name="post_uid" value="<?= View::e($goUid) ?>">
              <label class="u-sr-only" for="q-date-<?= View::e($goUid) ?>">Date for <?= View::e((string) $goQ['final_title']) ?></label>
              <input class="go-input" id="q-date-<?= View::e($goUid) ?>" type="date" name="scheduled_date_ist"
                     value="<?= View::e((string) ($goQ['scheduled_date_ist'] ?? '')) ?>">
              <button type="submit" class="go-btn go-btn-secondary go-btn-sm">Move</button>
            </form>
            <?php if (empty($goQ['scheduled_date_ist'])): ?>
              <span class="go-hint">No date. It goes out in turn.</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <p class="go-hint u-mt-24">Moving a date is recorded as an edit, like any other change. A package that
  is already publishing or published cannot be moved, and the app will say so rather than pretend.</p>

<?php endif; ?>
