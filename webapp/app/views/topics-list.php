<?php declare(strict_types=1);
if (!defined('GO_BOOT')) { http_response_code(404); exit; }

/**
 * Gate 1. Thirty to sixty topics, one decision each.
 *
 * Full-width rows separated by a hairline rather than a table, because the
 * evidence for a topic is a long source_quote and it has to be on screen
 * without a click. Approving on the strength of a headline is the exact failure
 * this screen exists to prevent, so the quote is never behind a disclosure.
 *
 * The verdict control IS the status display. There is no badge anywhere on this
 * screen that could drift out of step with it.
 */
$goBase  = View::url('topics/' . rawurlencode((string) $batch['batch_uid']));
$goBack  = $goBase . ($filter !== '' ? '?status=' . rawurlencode($filter) : '');
$goTotal = (int) ($counts['total'] ?? 0);
$goDone  = $goTotal - (int) ($counts['pending'] ?? 0);
$goPct   = $goTotal > 0 ? (int) round($goDone * 100 / $goTotal) : 0;

$goVerdicts = ['pending' => 'Pending', 'approved' => 'Approved', 'hold' => 'Hold', 'rejected' => 'Rejected'];
$goTabs = [
    ''         => 'All ' . $goTotal,
    'pending'  => 'Waiting ' . (int) ($counts['pending'] ?? 0),
    'approved' => 'Approved ' . (int) ($counts['approved'] ?? 0),
    'hold'     => 'Hold ' . (int) ($counts['onhold'] ?? 0),
    'rejected' => 'Rejected ' . (int) ($counts['rejected'] ?? 0),
];
$goWords = static fn(string $s): string => ucfirst(str_replace('_', ' ', $s));

/* If an edit was refused, this is the topic it was for. Only that one row gets
   its fields handed back; the other thirty-four are untouched. */
$goOldUid = (string) (UiApp::old('topic_uid') ?? '');
?>

<div class="go-page-head">
  <h1 class="go-page-title"><?= View::e((string) $batch['title']) ?></h1>
  <p class="go-page-sub">Researched <?= View::e(View::date($batch['business_date_ist'] ?? null)) ?>.
  Approving a topic hands it straight to the writer. Rejecting takes it out of the pipeline. Nothing here
  is published by itself - every post comes back for a second approval.</p>
</div>

<div class="go-toolbar">
  <div class="go-filters">
    <?php foreach ($goTabs as $goKey => $goLabel): ?>
      <a class="go-filter<?= $filter === $goKey ? ' is-on' : '' ?>"
         href="<?= View::e($goBase . ($goKey === '' ? '' : '?status=' . rawurlencode($goKey))) ?>"
         <?= $filter === $goKey ? ' aria-current="true"' : '' ?>><?= View::e($goLabel) ?></a>
    <?php endforeach; ?>
  </div>
  <p class="go-batch-progress u-right" style="--go-progress: <?= View::e((string) $goPct) ?>%">
    <?= View::e(View::num($goDone)) ?> of <?= View::e(View::num($goTotal)) ?> decided
  </p>
  <p class="u-right">
    <a class="go-btn go-btn-secondary go-btn-sm" href="<?= View::e($goBase . '?i=0' . ($filter !== '' ? '&status=' . rawurlencode($filter) : '')) ?>">One at a time</a>
  </p>
</div>

<?php if ($topics === []): ?>

  <div class="go-empty">
    <?php if ($filter !== ''): ?>
      <p class="go-empty-title">Nothing in this batch is <?= View::e(strtolower($goVerdicts[$filter] ?? $filter)) ?>.</p>
      <p class="go-empty-body">Of <?= View::e(View::num($goTotal)) ?> topics,
      <?= View::e(View::num((int) ($counts['approved'] ?? 0))) ?> are approved,
      <?= View::e(View::num((int) ($counts['rejected'] ?? 0))) ?> rejected,
      <?= View::e(View::num((int) ($counts['onhold'] ?? 0))) ?> on hold and
      <?= View::e(View::num((int) ($counts['pending'] ?? 0))) ?> still waiting.</p>
      <a class="go-btn go-btn-secondary" href="<?= View::e($goBase) ?>">Show all <?= View::e(View::num($goTotal)) ?></a>
    <?php else: ?>
      <p class="go-empty-title">A1 submitted this batch with no topics in it.</p>
      <p class="go-empty-body">That is a run that succeeded and produced nothing, which is a failure
      wearing a success badge. The run log will say what it searched and what it rejected.</p>
      <a class="go-btn go-btn-secondary" href="<?= View::e(View::url('ops')) ?>">Open the run log</a>
    <?php endif; ?>
  </div>

<?php else: ?>

  <?php if ((int) ($counts['pending'] ?? 0) === 0 && $filter === ''): ?>
    <p class="go-flash" role="status">
      <?= View::icon('check') ?>
      <span>All <?= View::e(View::num($goTotal)) ?> topics reviewed.
      <?= View::e(View::num((int) ($counts['approved'] ?? 0))) ?> approved,
      <?= View::e(View::num((int) ($counts['rejected'] ?? 0))) ?> rejected,
      <?= View::e(View::num((int) ($counts['onhold'] ?? 0))) ?> on hold. The approved ones are with the
      writer; they come back as packages at <a href="<?= View::e(View::url('posts')) ?>">gate 2</a>.</span>
    </p>
  <?php endif; ?>

  <div class="go-topics">
    <?php foreach ($topics as $goT): ?>
      <?php
        $goUid     = (string) $goT['topic_uid'];
        $goStatus  = (string) $goT['review_status'];
        $goLocked  = in_array((string) $goT['pipeline_state'], ['copy_in_progress', 'copy_done'], true);
        $goRel     = max(0, min(2, (int) $goT['india_relevance']));
        $goEdited  = (int) ($goT['was_edited'] ?? 0) === 1;
      ?>
      <article class="go-topic<?= $goStatus === 'rejected' ? ' is-rejected' : '' ?><?= $goLocked ? ' is-locked' : '' ?>"
               id="t-<?= View::e($goUid) ?>">

        <div class="go-topic-rail">
          <span class="go-topic-pos"><?= View::e((string) (int) $goT['position']) ?></span>
          <span class="go-relevance is-<?= View::e((string) $goRel) ?>" role="img"
                aria-label="India relevance <?= View::e((string) $goRel) ?> of 2"><span></span><span></span><span></span></span>
        </div>

        <div>
          <details class="go-edit"<?= $goOldUid === $goUid ? ' open' : '' ?>>
            <summary><h3 class="go-topic-title"><?= View::e((string) $goT['final_title']) ?></h3></summary>

            <form method="post" action="<?= View::e($goBase) ?>" class="u-stack-8">
              <?= View::csrf() ?>
              <input type="hidden" name="topic_uid" value="<?= View::e($goUid) ?>">
              <input type="hidden" name="back" value="<?= View::e($goBack) ?>">
              <p class="go-field">
                <label class="go-label" for="tt-<?= View::e($goUid) ?>">Title</label>
                <input class="go-input" id="tt-<?= View::e($goUid) ?>" name="final_title" maxlength="300"
                       value="<?= View::e($goOldUid === $goUid
                           ? (string) UiApp::old('final_title', $goT['final_title'])
                           : (string) $goT['final_title']) ?>">
              </p>
              <p class="go-field">
                <label class="go-label" for="to-<?= View::e($goUid) ?>">One-liner</label>
                <textarea class="go-textarea" id="to-<?= View::e($goUid) ?>" name="final_one_liner"
                          maxlength="500"><?= View::e($goOldUid === $goUid
                              ? (string) UiApp::old('final_one_liner', $goT['final_one_liner'])
                              : (string) $goT['final_one_liner']) ?></textarea>
              </p>
              <p class="go-hint">The research A1 recorded is never overwritten. Your edit is stored
              beside it, and the original stays readable below.</p>
              <p><button type="submit" class="go-btn go-btn-primary go-btn-sm" name="action" value="edit">Save the edit</button></p>
            </form>
          </details>

          <p class="go-topic-line u-clamp-2"><?= View::e((string) $goT['final_one_liner']) ?></p>

          <?php if (!empty($goT['source_quote'])): ?>
            <blockquote class="go-quote">
              <?= View::e((string) $goT['source_quote']) ?>
              <?php if (!empty($goT['source_title'])): ?>
                <cite><?= View::e((string) $goT['source_title']) ?></cite>
              <?php endif; ?>
            </blockquote>
          <?php else: ?>
            <p class="go-error"><?= View::icon('warning') ?> No source quote was recorded for this topic.</p>
          <?php endif; ?>

          <?php if (!empty($goT['source_url'])): ?>
            <a class="go-source" href="<?= View::e((string) $goT['source_url']) ?>" target="_blank" rel="noopener noreferrer">
              <?= View::e((string) ($goT['source_domain'] ?? 'source')) ?>
              &middot; <?= View::e(View::date($goT['source_published_on'] ?? null)) ?>
              &middot; Open source<?= View::icon('external') ?>
            </a>
          <?php endif; ?>

          <p class="go-topic-meta">
            <span><?= View::e($goWords((string) $goT['theme_tag'])) ?></span>
            <span><?= View::e($goWords((string) $goT['post_type'])) ?></span>
            <?php if ($goEdited): ?><span>Edited by you</span><?php endif; ?>
          </p>

          <?php if ($goEdited): ?>
            <div class="go-original">
              <?= View::e((string) $goT['original_title']) ?><br>
              <?= View::e((string) $goT['original_one_liner']) ?>
            </div>
          <?php endif; ?>

          <?php if ($goLocked): ?>
            <p class="go-progress-note">The writer already has this one - <?= View::e(View::topicProgress($goT)) ?>.
            A hold here will not take it back; hold the package at gate 2 instead.</p>
          <?php endif; ?>
        </div>

        <form method="post" action="<?= View::e($goBase) ?>" class="go-verdict"
              aria-label="Verdict for topic <?= View::e((string) (int) $goT['position']) ?>">
          <?= View::csrf() ?>
          <input type="hidden" name="topic_uid" value="<?= View::e($goUid) ?>">
          <input type="hidden" name="back" value="<?= View::e($goBack) ?>">
          <?php foreach ($goVerdicts as $goKey => $goLabel): ?>
            <button type="submit" name="action" value="<?= View::e($goKey) ?>"
                    class="go-verdict-btn<?= $goStatus === $goKey ? ' is-on' : '' ?>"
                    aria-pressed="<?= $goStatus === $goKey ? 'true' : 'false' ?>">
              <span class="go-dot is-<?= View::e($goKey) ?>"></span><?= View::e($goLabel) ?>
            </button>
          <?php endforeach; ?>
        </form>

      </article>
    <?php endforeach; ?>
  </div>

<?php endif; ?>
