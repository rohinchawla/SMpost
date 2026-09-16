<?php declare(strict_types=1);
if (!defined('GO_BOOT')) { http_response_code(404); exit; }

/**
 * Gate 1, one level up: the batches A1 has submitted.
 *
 * Only state='submitted' reaches this query, so a batch A1 is still uploading
 * is never offered for review half-written.
 */
?>

<div class="go-page-head">
  <h1 class="go-page-title">Topics</h1>
  <p class="go-page-sub">Gate 1. A1 researches on Wednesday at 05:00 IST and submits a batch here.
  Approving a topic is what releases it to the writer; nothing else does.</p>
</div>

<?php if ($batches === []): ?>
  <div class="go-empty">
    <p class="go-empty-title">No batch is waiting for review.</p>
    <p class="go-empty-body">A1 submits one batch of 30 to 60 topics every Wednesday at 05:00 IST. A batch
    it is still uploading stays hidden until it is complete, so a half-written list is never offered for
    approval. If a Wednesday has passed with nothing here, the run itself is the thing to check.</p>
    <a class="go-btn go-btn-secondary" href="<?= View::e(View::url('ops')) ?>">Open the run log</a>
  </div>
<?php else: ?>
  <table class="go-table u-mt-24">
    <caption><?= View::e(View::num(count($batches))) ?> <?= count($batches) === 1 ? 'batch' : 'batches' ?> submitted for review.</caption>
    <thead>
      <tr>
        <th scope="col">Batch</th>
        <th scope="col">Researched</th>
        <th scope="col" class="u-num">Topics</th>
        <th scope="col" class="u-num">Waiting</th>
        <th scope="col">Decided</th>
        <th scope="col"><span class="u-sr-only">Review</span></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($batches as $goB): ?>
        <?php
          $goPending  = (int) ($goB['pending'] ?? 0);
          $goApproved = (int) ($goB['approved'] ?? 0);
          $goRejected = (int) ($goB['rejected'] ?? 0);
          $goHold     = (int) ($goB['onhold'] ?? 0);
          $goHref     = View::url('topics/' . rawurlencode((string) $goB['batch_uid']));
        ?>
        <tr>
          <th scope="row"><a href="<?= View::e($goHref) ?>"><?= View::e((string) $goB['title']) ?></a></th>
          <td data-label="Researched"><?= View::e(View::date($goB['business_date_ist'] ?? null)) ?></td>
          <td data-label="Topics" class="u-num"><?= View::e(View::num((int) $goB['topic_count'])) ?></td>
          <td data-label="Waiting" class="u-num">
            <?php if ($goPending === 0): ?>
              <span class="u-faint">none</span>
            <?php else: ?>
              <span class="go-num"><?= View::e(View::num($goPending)) ?></span>
            <?php endif; ?>
          </td>
          <td data-label="Decided">
            <span class="go-status"><span class="go-dot is-approved"></span><?= View::e(View::num($goApproved)) ?> approved</span>
            <span class="go-status"><span class="go-dot is-rejected"></span><?= View::e(View::num($goRejected)) ?> rejected</span>
            <span class="go-status"><span class="go-dot is-hold"></span><?= View::e(View::num($goHold)) ?> on hold</span>
          </td>
          <td data-label="Review">
            <a class="go-btn go-btn-secondary go-btn-sm" href="<?= View::e($goHref) ?>">
              <?= $goPending > 0 ? 'Review ' . View::e(View::num($goPending)) : 'Open' ?>
            </a>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
