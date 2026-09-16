<?php declare(strict_types=1);
if (!defined('GO_BOOT')) { http_response_code(404); exit; }

/**
 * Every package, filtered by what the owner is looking for.
 *
 * "Published" is a tab, not a verdict. A post that has gone out keeps the
 * verdict Approved for ever - lifecycle_state is what changed - so the tab
 * filters the lifecycle and the control below each row still reads Approved.
 *
 * The Approved segment is disabled here on purpose. Approving is choosing an
 * image and releasing a specific set of bytes, and neither is visible from a
 * list, so gate 2 is the only place that decision can honestly be taken.
 */
$goBase = View::url('posts');
$goTabLabels = [
    'waiting'   => 'Waiting for you',
    'approved'  => 'Approved',
    'published' => 'Published',
    'hold'      => 'On hold',
    'rejected'  => 'Rejected',
    'all'       => 'All',
];
$goVerdicts = ['pending' => 'Pending', 'approved' => 'Approved', 'hold' => 'Hold', 'rejected' => 'Rejected'];
$goEmpty = [
    'waiting'   => ['Nothing is waiting for you at gate 2.',
                    'A package arrives here once A4 has assembled the post, both image options and the traced sources. Approved topics are what feed it, so if this stays empty the place to look is gate 1.'],
    'approved'  => ['No package is approved and waiting to publish.',
                    'An approved package sits here until A5 takes it at 08:00 IST, then moves to Published. An empty list means tomorrow morning has nothing to send.'],
    'published' => ['Nothing has been published yet.',
                    'A5 publishes one approved post a day at 08:00 IST, then posts the link as the first comment and records the URN. Until the LinkedIn app is approved it runs in dry-run and publishes nothing, which is deliberate.'],
    'hold'      => ['No package is on hold.',
                    'A hold stops tomorrow\'s publish without rejecting the work. It is the right verdict when the post is fine but the timing is not.'],
    'rejected'  => ['No package has been rejected.',
                    'A rejected package stays in the record with its reason. Nothing is deleted here.'],
    'all'       => ['No packages exist yet.',
                    'Approve a topic at gate 1 and the writer starts. The post, its two image options and the traced numbers arrive here as one package.'],
];
?>

<div class="go-page-head">
  <h1 class="go-page-title">Posts</h1>
  <p class="go-page-sub">Gate 2. One package at a time: the post as the feed will render it, both image
  options, and every number traced back to the sentence it came from.</p>
</div>

<div class="go-toolbar">
  <div class="go-filters">
    <?php foreach ($goTabLabels as $goKey => $goLabel): ?>
      <a class="go-filter<?= $filter === $goKey ? ' is-on' : '' ?>"
         href="<?= View::e($goBase . '?status=' . rawurlencode($goKey)) ?>"
         <?= $filter === $goKey ? ' aria-current="true"' : '' ?>>
        <?= View::e($goLabel) ?> <span class="go-num"><?= View::e(View::num((int) ($tabs[$goKey] ?? 0))) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</div>

<?php if ($posts === []): ?>

  <?php $goCopy = $goEmpty[$filter] ?? $goEmpty['all']; ?>
  <div class="go-empty">
    <p class="go-empty-title"><?= View::e($goCopy[0]) ?></p>
    <p class="go-empty-body"><?= View::e($goCopy[1]) ?></p>
    <?php if ($filter !== 'all'): ?>
      <a class="go-btn go-btn-secondary" href="<?= View::e($goBase . '?status=all') ?>">Show all <?= View::e(View::num((int) ($tabs['all'] ?? 0))) ?></a>
    <?php endif; ?>
  </div>

<?php else: ?>

  <table class="go-table u-mt-24">
    <caption><?= View::e(View::num(count($posts))) ?> <?= count($posts) === 1 ? 'package' : 'packages' ?>, most recently touched first.</caption>
    <thead>
      <tr>
        <th scope="col">Package</th>
        <th scope="col">Where it is</th>
        <th scope="col">Your verdict</th>
        <th scope="col">Last touched</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($posts as $goP): ?>
        <?php
          $goUid    = (string) $goP['post_uid'];
          $goStatus = (string) $goP['review_status'];
          $goPosted = (string) $goP['lifecycle_state'] === 'posted';
          $goStale  = ReviewService::isStale($goP);
        ?>
        <tr id="p-<?= View::e($goUid) ?>">
          <th scope="row">
            <a href="<?= View::e($goBase . '/' . rawurlencode($goUid)) ?>"><?= View::e((string) $goP['final_title']) ?></a>
            <span class="go-topic-meta">
              <span><?= View::e(View::num((int) $goP['final_word_count'])) ?> words</span>
              <span><?= View::e(View::num((int) $goP['image_count'])) ?> image options</span>
            </span>
          </th>
          <td data-label="Where it is">
            <span class="go-progress-note">
              <?= View::e(View::progress($goP)) ?><?php if ($goPosted && !empty($goP['linkedin_permalink'])): ?>
                &middot; <a href="<?= View::e((string) $goP['linkedin_permalink']) ?>" target="_blank" rel="noopener noreferrer">View on LinkedIn<?= View::icon('external') ?></a>
              <?php endif; ?>
            </span>
            <?php if ($goStale): ?>
              <span class="go-error"><?= View::icon('warning') ?> edited after approval, so it will not publish</span>
            <?php endif; ?>
          </td>
          <td data-label="Your verdict">
            <form method="post" action="<?= View::e($goBase) ?>" class="go-verdict" aria-label="Verdict for this package">
              <?= View::csrf() ?>
              <input type="hidden" name="post_uid" value="<?= View::e($goUid) ?>">
              <?php foreach ($goVerdicts as $goKey => $goLabel): ?>
                <?php $goOn = $goStatus === $goKey; ?>
                <button type="submit" name="action" value="<?= View::e($goKey) ?>"
                        class="go-verdict-btn<?= $goOn ? ' is-on' : '' ?>"
                        aria-pressed="<?= $goOn ? 'true' : 'false' ?>"
                        <?= (!$goOn && ($goPosted || $goKey === 'approved')) ? 'disabled' : '' ?>>
                  <span class="go-dot is-<?= View::e($goKey) ?>"></span><?= View::e($goLabel) ?>
                </button>
              <?php endforeach; ?>
            </form>
            <span class="go-hint">
              <?= $goPosted
                    ? 'Already on LinkedIn. Nothing here can recall it.'
                    : 'Approving means choosing an image, so it happens with the package open.' ?>
            </span>
          </td>
          <td data-label="Last touched"><?= View::e(View::dateTime($goP['updated_at'] ?? null)) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

<?php endif; ?>
