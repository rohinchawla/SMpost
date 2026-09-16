<?php declare(strict_types=1);
if (!defined('GO_BOOT')) { http_response_code(404); exit; }

/**
 * What needs a decision, what is about to go out, and what the machine did.
 *
 * The last-run list names all six agents, including the ones with no row at
 * all. "A6 has never run" is the failure that hides for weeks, and a screen
 * that only lists what exists can never show it.
 */
$goAgents = [
    'A1' => 'Topic scout',
    'A2' => 'Copywriter',
    'A3' => 'Image maker',
    'A4' => 'Packager',
    'A5' => 'Poster',
    'A6' => 'Analyst',
];

$goRunStatus = static fn(?string $s): string => match ((string) $s) {
    'succeeded' => 'finished',
    'partial'   => 'finished with failures',
    'failed'    => 'failed',
    'running'   => 'still running',
    'skipped'   => 'skipped itself',
    'timed_out' => 'timed out',
    default     => 'never run',
};

$goSeen = [];
foreach ($lastRuns as $goRun) { $goSeen[(string) $goRun['agent_code']] = $goRun; }

$goTopics = (int) ($counts['topics_pending'] ?? 0);
$goPosts  = (int) ($counts['posts_waiting'] ?? 0);
$goWait   = [];
if ($goTopics > 0) $goWait[] = $goTopics . ($goTopics === 1 ? ' topic' : ' topics');
if ($goPosts  > 0) $goWait[] = $goPosts  . ($goPosts === 1 ? ' package' : ' packages');
?>

<div class="go-page-head">
  <h1 class="go-page-title">Today</h1>
  <p class="go-page-sub">
    <?php if ($goWait !== []): ?>
      <?= View::e(implode(' and ', $goWait)) ?> waiting for a decision.
      <?= (int) ($counts['runway'] ?? 0) === 0
            ? 'Nothing is approved for tomorrow morning.'
            : View::e((string) (int) $counts['runway']) . ' days of posts are approved and queued.' ?>
    <?php elseif ((int) ($counts['runway'] ?? 0) > 0): ?>
      Nothing is waiting for you. <?= View::e((string) (int) $counts['runway']) ?> days of posts are
      approved and queued, and <?= View::e(View::num((int) ($counts['published'] ?? 0))) ?> have gone out
      so far.
    <?php else: ?>
      Nothing is waiting for you, and nothing is approved. Tomorrow morning there is nothing to publish.
    <?php endif; ?>
  </p>
</div>

<div class="u-stack-24 u-mt-24">

  <section class="u-stack-8">
    <h2>Alerts</h2>
    <?php if ($alerts === []): ?>
      <div class="go-empty">
        <p class="go-empty-title">Nothing is wrong that this app can see.</p>
        <p class="go-empty-body">The checks that run on every load: a run that finished and produced
        nothing, topics waiting more than a week, an empty publishing runway, and an approved post whose
        text changed after it was approved. None of them fired. The last-run table below is the evidence.</p>
      </div>
    <?php else: ?>
      <div class="go-alerts">
        <?php foreach ($alerts as $goAlert): ?>
          <?php $goCritical = ($goAlert['level'] ?? '') === 'error'; ?>
          <div class="go-alert<?= $goCritical ? ' is-critical' : '' ?>">
            <?= View::icon($goCritical ? 'warning' : 'dot') ?>
            <p class="go-alert-text"><?= View::e((string) ($goAlert['text'] ?? '')) ?></p>
            <?php if (!empty($goAlert['action'][1])): ?>
              <a class="go-btn go-btn-secondary go-btn-sm go-alert-action"
                 href="<?= View::e(View::url((string) $goAlert['action'][1])) ?>"><?= View::e((string) $goAlert['action'][0]) ?></a>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <section class="u-stack-8">
    <h2>Next out of the door</h2>
    <div class="go-runway<?= $queue === [] ? ' is-empty' : '' ?>">
      <?php foreach ($queue as $goQ): ?>
        <div>
          <time datetime="<?= View::e((string) ($goQ['scheduled_date_ist'] ?? '')) ?>"><?= View::e(View::date($goQ['scheduled_date_ist'] ?? null)) ?></time>
          <a href="<?= View::e(View::url('posts/' . rawurlencode((string) $goQ['post_uid']))) ?>"><?= View::e((string) $goQ['final_title']) ?></a>
          <span class="u-faint"><?= View::e((string) ($goQ['queue_reason'] ?? '')) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
    <?php if ($queue !== []): ?>
      <p class="go-hint"><a href="<?= View::e(View::url('queue')) ?>">See the whole queue</a>, in the
      order A5 will actually take them.</p>
    <?php endif; ?>
  </section>

  <section class="u-stack-8">
    <h2>What the agents did last</h2>
    <table class="go-table">
      <caption>All six agents, including the ones that have never written a row.</caption>
      <thead>
        <tr><th scope="col">Agent</th><th scope="col">Last run</th><th scope="col">Outcome</th></tr>
      </thead>
      <tbody>
        <?php foreach ($goAgents as $goCode => $goName): ?>
          <?php $goRow = $goSeen[$goCode] ?? null; ?>
          <tr>
            <th scope="row"><?= View::e($goCode) ?> <span class="u-muted"><?= View::e($goName) ?></span></th>
            <td data-label="Last run"><?= View::e($goRow === null ? 'never' : View::dateTime($goRow['last_at'] ?? null)) ?></td>
            <td data-label="Outcome">
              <?php if ($goRow === null): ?>
                <span class="go-status"><span class="go-dot is-hold"></span>never run</span>
              <?php else: ?>
                <span class="go-status">
                  <span class="go-dot <?= in_array((string) $goRow['last_status'], ['failed', 'timed_out'], true) ? 'is-rejected' : 'is-approved' ?>"></span>
                  <?= View::e($goRunStatus($goRow['last_status'] ?? null)) ?>
                </span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p class="go-hint"><a href="<?= View::e(View::url('ops')) ?>">Open the run log</a> for what each one
    wrote.</p>
  </section>

</div>
