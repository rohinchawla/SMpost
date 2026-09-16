<?php declare(strict_types=1);
if (!defined('GO_BOOT')) { http_response_code(404); exit; }

/**
 * Two settings are editable here. The rest are shown and not touched.
 *
 * Everything else in app_settings is a number the agents, the REST contract and
 * the database CHECK constraints all agree on. Raising max_body_words from this
 * screen would leave chk_posts_wordcount refusing the row, so the field does not
 * exist rather than existing and lying.
 */
$goTime = (string) Settings::str('default_post_time_ist', '08:00:00');
$goTime = substr($goTime, 0, 5);
$goCeiling = (int) ($config['approved_queue_ceiling'] ?? 21);
$goReadable = static fn(string $k): string => ucfirst(str_replace('_', ' ', $k));
?>

<div class="go-page-head">
  <h1 class="go-page-title">Settings</h1>
  <p class="go-page-sub">The posting time and the queue ceiling are yours to change. The rest are
  contract numbers that the agents and the database both depend on, so they are shown here rather than
  edited here.</p>
</div>

<form method="post" action="<?= View::e(View::url('settings')) ?>" class="u-stack-16 u-mt-24">
  <?= View::csrf() ?>

  <div class="go-editor-block">
    <h3>When the post goes out</h3>
    <p class="go-field">
      <label class="go-label" for="s-time">Daily posting time, IST</label>
      <input class="go-input" id="s-time" name="default_post_time_ist" type="time" value="<?= View::e($goTime) ?>">
    </p>
    <p class="go-hint">Twenty-four hour clock, Asia/Kolkata. A5 runs once a day and takes the top of the
    queue at this time.</p>
  </div>

  <div class="go-editor-block">
    <h3>How deep the approved queue may get</h3>
    <p class="go-field">
      <label class="go-label" for="s-ceiling">Approved queue ceiling, in days</label>
      <input class="go-input" id="s-ceiling" name="approved_queue_ceiling" type="number" min="1" max="365"
             inputmode="numeric" value="<?= View::e((string) $goCeiling) ?>">
    </p>
    <p class="go-hint">Between 1 and 365. When this many posts are approved and waiting, A1 skips its
    next research run rather than piling up topics nobody will reach.</p>
  </div>

  <p><button type="submit" class="go-btn go-btn-primary">Save</button></p>
</form>

<section class="u-stack-8 u-mt-24">
  <h2>The contract numbers</h2>
  <table class="go-table">
    <caption>What the agents read at the start of every run.</caption>
    <thead>
      <tr><th scope="col">Setting</th><th scope="col">Value</th></tr>
    </thead>
    <tbody>
      <?php foreach ($config as $goKey => $goVal): ?>
        <tr>
          <th scope="row"><?= View::e($goReadable((string) $goKey)) ?></th>
          <td data-label="Value"><?= View::e(is_scalar($goVal) ? (string) $goVal : Canon::encode($goVal)) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>

<section class="u-stack-8 u-mt-24">
  <h2>Everything stored, and who changed it</h2>
  <?php if ($settings === []): ?>
    <div class="go-empty">
      <p class="go-empty-title">app_settings is empty.</p>
      <p class="go-empty-body">The schema seeds every one of these on import, so an empty table means the
      database was created by something other than the shipped schema. The agents will fall back to their
      built-in defaults, which is not the same as agreeing with the database.</p>
    </div>
  <?php else: ?>
    <table class="go-table">
      <thead>
        <tr>
          <th scope="col">Key</th>
          <th scope="col">Stored value</th>
          <th scope="col">Changed</th>
          <th scope="col">By</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($settings as $goS): ?>
          <tr>
            <th scope="row"><?= View::e($goReadable((string) $goS['setting_key'])) ?></th>
            <td data-label="Stored value"><?= View::e((string) $goS['setting_value']) ?></td>
            <td data-label="Changed"><?= View::e(View::dateTime($goS['updated_at'] ?? null)) ?></td>
            <td data-label="By"><?= View::e((string) ($goS['updated_by'] ?? 'the installer')) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>
