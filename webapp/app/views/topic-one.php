<?php declare(strict_types=1);
if (!defined('GO_BOOT')) { http_response_code(404); exit; }

/**
 * Gate 1, one topic at a time. The phone path.
 *
 * Deciding advances: the verdict form's `back` is the next topic's URL, so the
 * redirect that follows the POST lands on the next one. That makes the whole
 * flow work with JavaScript off - the browser's own form submission is the
 * navigation.
 */
$goBase  = View::url('topics/' . rawurlencode((string) $batch['batch_uid']));
$goQuery = static fn(int $i): string => $goBase . '?i=' . $i . ($filter !== '' ? '&status=' . rawurlencode($filter) : '');
$goList  = $goBase . ($filter !== '' ? '?status=' . rawurlencode($filter) : '');
$goCount = count($topics);
$goT     = $topics[$index] ?? null;
$goVerdicts = ['approved' => ['Approved', 'a'], 'hold' => ['Hold', 'h'], 'rejected' => ['Rejected', 'r'], 'pending' => ['Pending', 'p']];
$goWords = static fn(string $s): string => ucfirst(str_replace('_', ' ', $s));
?>

<?php if ($goT === null): ?>

  <div class="go-page-head">
    <h1 class="go-page-title"><?= View::e((string) $batch['title']) ?></h1>
  </div>
  <div class="go-empty">
    <p class="go-empty-title">That is the end of the batch.</p>
    <p class="go-empty-body">All <?= View::e(View::num((int) ($counts['total'] ?? 0))) ?> topics reviewed.
    <?= View::e(View::num((int) ($counts['approved'] ?? 0))) ?> approved,
    <?= View::e(View::num((int) ($counts['rejected'] ?? 0))) ?> rejected,
    <?= View::e(View::num((int) ($counts['onhold'] ?? 0))) ?> on hold. The approved ones are with the
    writer now and come back as packages at gate 2.</p>
    <a class="go-btn go-btn-secondary" href="<?= View::e($goList) ?>">Back to the list</a>
  </div>

<?php else: ?>

  <?php
    $goUid    = (string) $goT['topic_uid'];
    $goStatus = (string) $goT['review_status'];
    $goLocked = in_array((string) $goT['pipeline_state'], ['copy_in_progress', 'copy_done'], true);
    $goRel    = max(0, min(2, (int) $goT['india_relevance']));
    $goEdited = (int) ($goT['was_edited'] ?? 0) === 1;
    $goNext   = $goQuery($index + 1);
    $goPct    = $goCount > 0 ? (int) round(($index + 1) * 100 / $goCount) : 0;
  ?>

  <div class="go-page-head">
    <h1 class="go-page-title"><?= View::e((string) $batch['title']) ?></h1>
    <p class="go-page-sub">One at a time. Deciding moves you to the next topic.
    <a href="<?= View::e($goList) ?>">Back to the full list</a>.</p>
  </div>

  <article class="go-one" id="t-<?= View::e($goUid) ?>">

    <p class="go-batch-progress" style="--go-progress: <?= View::e((string) $goPct) ?>%">
      Topic <?= View::e((string) ($index + 1)) ?> of <?= View::e((string) $goCount) ?>
    </p>

    <div class="u-row">
      <span class="go-topic-pos">Position <?= View::e((string) (int) $goT['position']) ?></span>
      <span class="go-relevance is-<?= View::e((string) $goRel) ?>" role="img"
            aria-label="India relevance <?= View::e((string) $goRel) ?> of 2"><span></span><span></span><span></span></span>
      <span class="u-faint">India relevance <?= View::e((string) $goRel) ?> of 2</span>
    </div>

    <h2 class="go-topic-title"><?= View::e((string) $goT['final_title']) ?></h2>
    <p class="go-topic-line"><?= View::e((string) $goT['final_one_liner']) ?></p>

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
      <p>
        <a class="go-source" href="<?= View::e((string) $goT['source_url']) ?>" target="_blank" rel="noopener noreferrer">
          <?= View::e((string) ($goT['source_domain'] ?? 'source')) ?>
          &middot; <?= View::e(View::date($goT['source_published_on'] ?? null)) ?>
          &middot; Open source<?= View::icon('external') ?>
        </a>
      </p>
    <?php endif; ?>

    <p class="go-topic-meta">
      <span><?= View::e($goWords((string) $goT['theme_tag'])) ?></span>
      <span><?= View::e($goWords((string) $goT['post_type'])) ?></span>
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

    <details class="go-edit">
      <summary>Edit the title or the one-liner</summary>
      <form method="post" action="<?= View::e($goBase) ?>" class="u-stack-8">
        <?= View::csrf() ?>
        <input type="hidden" name="topic_uid" value="<?= View::e($goUid) ?>">
        <input type="hidden" name="back" value="<?= View::e($goQuery($index)) ?>">
        <p class="go-field">
          <label class="go-label" for="one-title">Title</label>
          <input class="go-input" id="one-title" name="final_title" maxlength="300"
                 value="<?= View::e((string) $goT['final_title']) ?>">
        </p>
        <p class="go-field">
          <label class="go-label" for="one-line">One-liner</label>
          <textarea class="go-textarea" id="one-line" name="final_one_liner"
                    maxlength="500"><?= View::e((string) $goT['final_one_liner']) ?></textarea>
        </p>
        <p class="go-hint">What A1 researched is kept untouched beside your edit.</p>
        <p><button type="submit" class="go-btn go-btn-primary go-btn-sm" name="action" value="edit">Save the edit</button></p>
      </form>
    </details>

    <div class="go-one-actions">
      <?php if ($index > 0): ?>
        <a class="go-btn go-btn-quiet" href="<?= View::e($goQuery($index - 1)) ?>">Previous</a>
      <?php endif; ?>

      <form method="post" action="<?= View::e($goBase) ?>" class="go-verdict" aria-label="Verdict">
        <?= View::csrf() ?>
        <input type="hidden" name="topic_uid" value="<?= View::e($goUid) ?>">
        <input type="hidden" name="back" value="<?= View::e($goNext) ?>">
        <?php foreach ($goVerdicts as $goKey => $goSeg): ?>
          <button type="submit" name="action" value="<?= View::e($goKey) ?>"
                  class="go-verdict-btn<?= $goStatus === $goKey ? ' is-on' : '' ?>"
                  data-key="<?= View::e($goSeg[1]) ?>"
                  aria-pressed="<?= $goStatus === $goKey ? 'true' : 'false' ?>">
            <span class="go-dot is-<?= View::e($goKey) ?>"></span><?= View::e($goSeg[0]) ?>
            <span class="go-verdict-key"><?= View::e($goSeg[1]) ?></span>
          </button>
        <?php endforeach; ?>
      </form>

      <a class="go-btn go-btn-quiet u-right" href="<?= View::e($goNext) ?>">Skip for now</a>
    </div>

  </article>

<?php endif; ?>
