<?php declare(strict_types=1);
if (!defined('GO_BOOT')) { http_response_code(404); exit; }

/**
 * The machine room.
 *
 * A run that succeeded and produced nothing is called out in its own words on
 * its own row, because it is the only failure here that arrives wearing a
 * success badge and would otherwise be read past for five weeks.
 */
$goAction = View::url('ops');
$goRunStatus = static fn(?string $s): string => match ((string) $s) {
    'succeeded' => 'finished',
    'partial'   => 'finished with failures',
    'failed'    => 'failed',
    'running'   => 'still running',
    'skipped'   => 'skipped itself',
    'timed_out' => 'timed out',
    default     => 'unknown',
};
$goDlStatus = static fn(?string $s): string => match ((string) $s) {
    'open'     => 'open',
    'retrying' => 'being retried',
    'resolved' => 'resolved',
    'ignored'  => 'ignored',
    default    => 'unknown',
};
$goOpenDl = 0;
foreach ($deadLetters as $goD) { if (in_array((string) $goD['status'], ['open', 'retrying'], true)) $goOpenDl++; }
?>

<div class="go-page-head">
  <h1 class="go-page-title">Ops</h1>
  <p class="go-page-sub">What ran, what did not, what failed permanently, and whether the approvals still
  match the bytes they were given.</p>
</div>

<section class="u-stack-8 u-mt-24">
  <h2>Alerts</h2>
  <?php if ($alerts === []): ?>
    <div class="go-empty">
      <p class="go-empty-title">No alert is firing.</p>
      <p class="go-empty-body">Deliberately few things can fire here. A screen of amber badges trains you
      to ignore all of them, so only a failed run and an empty runway are ever drawn as failures.</p>
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

<section class="u-stack-8 u-mt-24" id="hash">
  <h2>Do the approvals still match the bytes?</h2>
  <?php if ((int) $hash['checked'] === 0): ?>
    <p class="go-hint">Nothing to check: no approved package currently carries a content hash.</p>
  <?php elseif ($hash['mismatched'] === []): ?>
    <p class="go-trace-verdict is-traced">
      <span class="go-dot is-approved"></span>
      All <?= View::e(View::num((int) $hash['checked'])) ?> approved
      <?= (int) $hash['checked'] === 1 ? 'package matches' : 'packages match' ?> what was approved
    </p>
    <p class="go-hint">This runs on every load rather than behind a button, because the state it catches -
    an approved post that will silently refuse to publish at 08:00 - is one nobody would think to go and
    look for.</p>
  <?php else: ?>
    <p class="go-flash is-error" role="alert">
      <?= View::icon('warning') ?>
      <span><strong><?= View::e(View::num(count($hash['mismatched']))) ?> of
      <?= View::e(View::num((int) $hash['checked'])) ?> approved packages were edited after approval.</strong>
      A5 will refuse each one in silence. Open it and approve again to release the current version.</span>
    </p>
    <ul class="go-gaplist">
      <?php foreach ($hash['mismatched'] as $goUid): ?>
        <li><a href="<?= View::e(View::url('posts/' . rawurlencode((string) $goUid))) ?>"><?= View::e((string) $goUid) ?></a></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<section class="u-stack-8 u-mt-24" id="runs">
  <h2>Agent runs</h2>
  <?php if ($runs === []): ?>
    <div class="go-empty">
      <p class="go-empty-title">No agent has ever written a run row.</p>
      <p class="go-empty-body">Every run writes a row, including one that does nothing, so an empty table
      means no agent has reached this app at all. That is a scheduling or a credentials problem, not a
      quiet week.</p>
    </div>
  <?php else: ?>
    <table class="go-runs">
      <caption>The last <?= View::e(View::num(count($runs))) ?> runs, newest first.</caption>
      <thead>
        <tr>
          <th scope="col">Agent</th>
          <th scope="col">Outcome</th>
          <th scope="col">Items</th>
          <th scope="col">Started</th>
          <th scope="col">Log</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($runs as $goR): ?>
          <?php
            $goSilent = (string) $goR['status'] === 'succeeded'
                     && (int) $goR['items_in'] === 0 && (int) $goR['items_ok'] === 0
                     && ($goR['skip_reason'] ?? null) === null;
            $goFailed = in_array((string) $goR['status'], ['failed', 'timed_out', 'partial'], true);
          ?>
          <tr>
            <th scope="row">
              <?= View::e((string) $goR['agent_code']) ?>
              <span class="u-faint"><?= View::e(View::date($goR['business_date_ist'] ?? null)) ?><?= (int) $goR['dry_run'] === 1 ? ' &middot; dry run' : '' ?></span>
            </th>
            <td data-label="Outcome">
              <span class="go-status">
                <span class="go-dot <?= $goFailed || $goSilent ? 'is-rejected' : ((string) $goR['status'] === 'running' ? 'is-hold' : 'is-approved') ?>"></span>
                <?= View::e($goRunStatus($goR['status'] ?? null)) ?>
              </span>
              <?php if ($goSilent): ?>
                <span class="go-error"><?= View::icon('warning') ?> succeeded, no output - a failure wearing a success badge</span>
              <?php endif; ?>
              <?php if (!empty($goR['skip_reason'])): ?>
                <span class="go-hint">reason: <?= View::e(str_replace('_', ' ', (string) $goR['skip_reason'])) ?></span>
              <?php endif; ?>
              <?php if (!empty($goR['error_code'])): ?>
                <span class="go-error"><?= View::icon('warning') ?> <?= View::e((string) $goR['error_code']) ?></span>
              <?php endif; ?>
            </td>
            <td data-label="Items">
              <span class="u-num"><?= View::e(View::num((int) $goR['items_ok'])) ?> done</span>,
              <span class="u-num"><?= View::e(View::num((int) $goR['items_failed'])) ?> failed</span>,
              <span class="u-num"><?= View::e(View::num((int) $goR['items_skipped'])) ?> skipped</span>
              of <span class="u-num"><?= View::e(View::num((int) $goR['items_in'])) ?></span>
              <?php if ((int) $goR['error_events'] > 0): ?>
                <span class="go-error"><?= View::icon('warning') ?> <?= View::e(View::num((int) $goR['error_events'])) ?> error events</span>
              <?php endif; ?>
            </td>
            <td data-label="Started"><?= View::e(View::dateTime($goR['started_at'] ?? null)) ?></td>
            <td data-label="Log">
              <a class="go-btn go-btn-secondary go-btn-sm"
                 href="<?= View::e(View::url('ops/runs/' . rawurlencode((string) $goR['run_uid']))) ?>">Open</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>

<section class="u-stack-16 u-mt-24" id="dead-letters">
  <h2>Dead letters</h2>
  <?php if ($deadLetters === []): ?>
    <div class="go-empty">
      <p class="go-empty-title">Nothing has failed past its retries.</p>
      <p class="go-empty-body">An agent that exhausts its retries writes the whole request here rather
      than dropping it, so a failure is always recoverable by hand. An empty list means every item that
      entered the pipeline came out of it.</p>
    </div>
  <?php else: ?>
    <p class="go-hint"><?= View::e(View::num($goOpenDl)) ?> still open, of
    <?= View::e(View::num(count($deadLetters))) ?>.</p>
    <?php foreach ($deadLetters as $goD): ?>
      <?php $goDUid = (string) $goD['dl_uid']; ?>
      <article class="go-editor-block" id="dl-<?= View::e($goDUid) ?>">
        <h3><?= View::e((string) $goD['agent_code']) ?> failed at <?= View::e(str_replace('_', ' ', (string) $goD['stage'])) ?></h3>
        <dl class="go-dl">
          <dt>Status</dt><dd><?= View::e($goDlStatus($goD['status'] ?? null)) ?></dd>
          <dt>Error</dt><dd><?= View::e((string) $goD['error_code']) ?> - <?= View::e((string) $goD['error_message']) ?></dd>
          <dt>Attempts</dt><dd><?= View::e(View::num((int) $goD['attempts'])) ?></dd>
          <dt>First seen</dt><dd><?= View::e(View::dateTime($goD['first_seen_at'] ?? null)) ?></dd>
          <dt>Last seen</dt><dd><?= View::e(View::dateTime($goD['last_seen_at'] ?? null)) ?></dd>
          <?php if (!empty($goD['entity_uid'])): ?>
            <dt>Entity</dt><dd><?= View::e((string) $goD['entity_type']) ?> <?= View::e((string) $goD['entity_uid']) ?></dd>
          <?php endif; ?>
          <?php if (!empty($goD['resolution_note'])): ?>
            <dt>Note</dt><dd><?= View::e((string) $goD['resolution_note']) ?> - <?= View::e((string) ($goD['resolved_by'] ?? '')) ?></dd>
          <?php endif; ?>
        </dl>

        <details>
          <summary>The payload it failed on</summary>
          <pre class="go-log"><?= View::e((string) $goD['payload_json']) ?></pre>
        </details>

        <?php if (in_array((string) $goD['status'], ['open', 'retrying'], true)): ?>
          <form method="post" action="<?= View::e($goAction) ?>" class="u-row u-gap-8">
            <?= View::csrf() ?>
            <input type="hidden" name="dl_uid" value="<?= View::e($goDUid) ?>">
            <label class="u-sr-only" for="dl-note-<?= View::e($goDUid) ?>">Note</label>
            <input class="go-input" id="dl-note-<?= View::e($goDUid) ?>" name="note" maxlength="500"
                   placeholder="What you did about it">
            <button type="submit" class="go-btn go-btn-secondary go-btn-sm" name="action" value="dl-retry"><?= View::icon('replay') ?>Retry</button>
            <button type="submit" class="go-btn go-btn-secondary go-btn-sm" name="action" value="dl-resolve"><?= View::icon('check') ?>Resolved</button>
            <button type="submit" class="go-btn go-btn-quiet go-btn-sm" name="action" value="dl-ignore"><?= View::icon('cross') ?>Ignore</button>
          </form>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  <?php endif; ?>
</section>

<section class="u-stack-8 u-mt-24" id="keys">
  <h2>API keys</h2>
  <?php if ($keys === []): ?>
    <div class="go-empty">
      <p class="go-empty-title">No keys exist, so no agent can reach this app.</p>
      <p class="go-empty-body">The installer mints one key per agent. Without them every agent request
      returns 401 and the pipeline stops before it starts.</p>
    </div>
  <?php else: ?>
    <table class="go-table">
      <caption>One key per agent, so A5 - the only agent that can write to LinkedIn - can be revoked
      without stopping the other five.</caption>
      <thead>
        <tr>
          <th scope="col">Agent</th>
          <th scope="col">Key</th>
          <th scope="col">State</th>
          <th scope="col">Last used</th>
          <th scope="col"><span class="u-sr-only">Revoke</span></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($keys as $goK): ?>
          <?php $goActive = (int) $goK['is_active'] === 1; ?>
          <tr>
            <th scope="row"><?= View::e((string) $goK['agent_code']) ?> <span class="u-muted"><?= View::e((string) $goK['label']) ?></span></th>
            <td data-label="Key"><?= View::e((string) $goK['key_prefix']) ?>&hellip;</td>
            <td data-label="State">
              <span class="go-status">
                <span class="go-dot <?= $goActive ? 'is-approved' : 'is-rejected' ?>"></span>
                <?= $goActive ? 'active' : 'revoked ' . View::e(View::dateTime($goK['revoked_at'] ?? null)) ?>
              </span>
            </td>
            <td data-label="Last used"><?= View::e($goK['last_used_at'] === null ? 'never' : View::dateTime($goK['last_used_at'])) ?></td>
            <td data-label="Revoke">
              <?php if ($goActive && array_key_exists((string) $goK['agent_code'], ApiKeys::SCOPES)): ?>
                <form method="post" action="<?= View::e($goAction) ?>">
                  <?= View::csrf() ?>
                  <input type="hidden" name="agent_code" value="<?= View::e((string) $goK['agent_code']) ?>">
                  <button type="submit" class="go-btn go-btn-secondary go-btn-sm" name="action" value="revoke-key">Revoke</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p class="go-hint">Revoking is immediate and cannot be undone from here. A new key has to be minted
    before that agent can run again.</p>
  <?php endif; ?>
</section>
