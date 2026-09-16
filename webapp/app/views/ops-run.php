<?php declare(strict_types=1);
if (!defined('GO_BOOT')) { http_response_code(404); exit; }

/**
 * One run, and everything it said.
 *
 * Mono here is machine output, not a costume: these lines were written by an
 * agent for a person reading a failure at speed.
 */
$goRunStatus = static fn(?string $s): string => match ((string) $s) {
    'succeeded' => 'finished',
    'partial'   => 'finished with failures',
    'failed'    => 'failed',
    'running'   => 'still running',
    'skipped'   => 'skipped itself',
    'timed_out' => 'timed out',
    default     => 'unknown',
};
$goSilent = (string) $run['status'] === 'succeeded'
         && (int) $run['items_in'] === 0 && (int) $run['items_ok'] === 0
         && ($run['skip_reason'] ?? null) === null;
?>

<div class="go-page-head">
  <h1 class="go-page-title"><?= View::e((string) $run['agent_code']) ?>,
  <?= View::e(View::date($run['business_date_ist'] ?? null)) ?></h1>
  <p class="go-page-sub">Attempt <?= View::e((string) (int) $run['attempt']) ?>,
  <?= View::e(str_replace('_', ' ', (string) $run['trigger_type'])) ?> trigger.
  <a href="<?= View::e(View::url('ops')) ?>">Back to all runs</a>.</p>
</div>

<?php if ($goSilent): ?>
  <p class="go-flash is-error" role="alert">
    <?= View::icon('warning') ?>
    <span><strong>This run succeeded and produced nothing.</strong> Nothing came in and nothing went out,
    and it gave no reason for skipping. That is a failure wearing a success badge.</span>
  </p>
<?php endif; ?>

<dl class="go-dl u-mt-24">
  <dt>Outcome</dt>
  <dd>
    <span class="go-status">
      <span class="go-dot <?= in_array((string) $run['status'], ['failed', 'timed_out', 'partial'], true) || $goSilent ? 'is-rejected' : ((string) $run['status'] === 'running' ? 'is-hold' : 'is-approved') ?>"></span>
      <?= View::e($goRunStatus($run['status'] ?? null)) ?>
    </span>
  </dd>
  <dt>Items</dt>
  <dd><?= View::e(View::num((int) $run['items_ok'])) ?> done,
      <?= View::e(View::num((int) $run['items_failed'])) ?> failed,
      <?= View::e(View::num((int) $run['items_skipped'])) ?> skipped, of
      <?= View::e(View::num((int) $run['items_in'])) ?> in</dd>
  <dt>Started</dt><dd><?= View::e(View::dateTime($run['started_at'] ?? null)) ?></dd>
  <dt>Finished</dt><dd><?= View::e(View::dateTime($run['finished_at'] ?? null)) ?></dd>
  <?php if ($run['duration_ms'] !== null): ?>
    <dt>Took</dt><dd><?= View::e(View::num((int) round((int) $run['duration_ms'] / 1000))) ?> seconds</dd>
  <?php endif; ?>
  <dt>Published for real</dt><dd><?= (int) $run['dry_run'] === 1 ? 'no, dry run' : 'yes' ?></dd>
  <?php if (!empty($run['skip_reason'])): ?>
    <dt>Skipped because</dt><dd><?= View::e(str_replace('_', ' ', (string) $run['skip_reason'])) ?></dd>
  <?php endif; ?>
  <?php if (!empty($run['error_code'])): ?>
    <dt>Error</dt><dd><?= View::e((string) $run['error_code']) ?> - <?= View::e((string) ($run['error_message'] ?? '')) ?></dd>
  <?php endif; ?>
  <dt>Run id</dt><dd><?= View::e((string) $run['run_uid']) ?></dd>
</dl>

<section class="u-stack-8 u-mt-24">
  <h2>What it said</h2>
  <?php if ($events === []): ?>
    <div class="go-empty">
      <p class="go-empty-title">This run logged nothing at all.</p>
      <p class="go-empty-body">An agent writes an event for each item it takes and each one it refuses.
      No events means it either died before its first step, or it never started one.</p>
    </div>
  <?php else: ?>
    <div class="go-log">
      <?php foreach ($events as $goE): ?>
        <?php $goLevel = (string) $goE['level']; ?>
        <p class="go-log-line<?= $goLevel === 'error' ? ' is-error' : ($goLevel === 'warn' ? ' is-warn' : '') ?>">
          <time><?= View::e(View::dateTime($goE['created_at'] ?? null)) ?></time>
          <span>
            <?= View::e((string) $goE['event_code']) ?>
            <?php if (!empty($goE['message'])): ?> - <?= View::e((string) $goE['message']) ?><?php endif; ?>
            <?php if (!empty($goE['entity_uid'])): ?> [<?= View::e((string) $goE['entity_type']) ?> <?= View::e((string) $goE['entity_uid']) ?>]<?php endif; ?>
            <?php if (!empty($goE['data_json']) && (string) $goE['data_json'] !== 'null'): ?> <?= View::e((string) $goE['data_json']) ?><?php endif; ?>
          </span>
        </p>
      <?php endforeach; ?>
    </div>
    <p class="go-hint"><?= View::e(View::num(count($events))) ?> events, oldest first. Times are IST.</p>
  <?php endif; ?>
</section>
