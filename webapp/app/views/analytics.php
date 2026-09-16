<?php declare(strict_types=1);
if (!defined('GO_BOOT')) { http_response_code(404); exit; }

/**
 * Impressions and shares, and the days nobody could read them.
 *
 * Two charts are authored rather than one scaled: a wide one for desktop and a
 * narrow one that only shows under 720px, because a 900px chart squeezed into a
 * phone puts its x labels on top of each other.
 *
 * Charts::series emits the geometry with generic class names. The series
 * modifier and the grid class are applied here, on fixed literals, because the
 * stylesheet colours the line by series and the helper is series-agnostic.
 */
$goBase  = View::url('analytics' . ($selected === null ? '' : '/' . rawurlencode((string) $selected['post_uid'])));
$goRanges = [30 => 'Last 30 days', 90 => 'Last 90 days', 183 => 'Last 6 months'];

$goChart = static function (string $svg, string $size, string $series): string {
    if ($svg === '') return '';
    return str_replace(
        ['class="go-chart"', 'class="go-chart-line"', 'class="go-chart-grid"'],
        ['class="go-chart go-chart--' . $size . '"', 'class="go-chart-line is-' . $series . '"', 'class="go-chart-grid go-grid"'],
        $svg
    );
};

$goHasReading = false;
foreach ($rows as $goR) {
    if (($goR['impressions'] ?? null) !== null) { $goHasReading = true; break; }
}
?>

<div class="go-page-head">
  <h1 class="go-page-title">Performance</h1>
  <p class="go-page-sub">
    <?php if ($selected === null): ?>
      Every published post, summed by day, <?= View::e(View::date($from)) ?> to <?= View::e(View::date($to)) ?>.
    <?php else: ?>
      <?= View::e((string) $selected['final_title']) ?>, <?= View::e(View::date($from)) ?> to
      <?= View::e(View::date($to)) ?>. <a href="<?= View::e(View::url('analytics') . '?range=' . (int) $range) ?>">Back to every post</a>.
    <?php endif; ?>
  </p>
</div>

<div class="go-toolbar">
  <div class="go-filters">
    <?php foreach ($goRanges as $goDays => $goLabel): ?>
      <a class="go-filter<?= (int) $range === $goDays ? ' is-on' : '' ?>"
         href="<?= View::e($goBase . '?range=' . $goDays) ?>"
         <?= (int) $range === $goDays ? ' aria-current="true"' : '' ?>><?= View::e($goLabel) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<?php if (!$goHasReading): ?>

  <div class="go-empty">
    <p class="go-empty-title">No readings in this window.</p>
    <p class="go-empty-body">A6 collects impressions and shares every morning at 09:00 IST for every post
    from the last six months. Until a post has gone out there is nothing to read, and while the LinkedIn
    app is in dry run A6 records the attempt rather than a number. Neither of those is a zero, and this
    screen will not draw one.</p>
    <a class="go-btn go-btn-secondary" href="<?= View::e(View::url('ops')) ?>">Check A6's last run</a>
  </div>

<?php else: ?>

  <section class="u-stack-8 u-mt-24">
    <h2>Impressions</h2>
    <?= $goChart(Charts::series($rows, 'impressions', 900, 220, ['from' => $from, 'to' => $to,
          'label' => 'Impressions by day, ' . View::date($from) . ' to ' . View::date($to)]), 'wide', 'impressions') ?>
    <?= $goChart(Charts::series($rows, 'impressions', 360, 200, ['from' => $from, 'to' => $to,
          'label' => 'Impressions by day, ' . View::date($from) . ' to ' . View::date($to)]), 'narrow', 'impressions') ?>
    <p class="go-hint"><strong>Shaded bands are days we could not read. They are not zero.</strong>
    The line stops at the edge of a band rather than crossing it, and every reading carries its own dot,
    so a single day between two gaps is still visible.</p>
  </section>

  <section class="u-stack-8 u-mt-24">
    <h2>Shares</h2>
    <?= $goChart(Charts::series($rows, 'shares', 900, 220, ['from' => $from, 'to' => $to,
          'label' => 'Shares by day, ' . View::date($from) . ' to ' . View::date($to)]), 'wide', 'shares') ?>
    <?= $goChart(Charts::series($rows, 'shares', 360, 200, ['from' => $from, 'to' => $to,
          'label' => 'Shares by day, ' . View::date($from) . ' to ' . View::date($to)]), 'narrow', 'shares') ?>
    <p class="go-hint">The shares line is dashed as well as coloured, so the two series stay apart for
    anyone who cannot separate them by colour.</p>
  </section>

  <section class="u-stack-8 u-mt-24">
    <h2>Which day a number belongs to</h2>
    <p class="go-hint">LinkedIn closes its analytics day at 00:00 UTC, which is 05:30 IST. A reading
    collected at 09:00 IST therefore describes a window that ended at 05:30 that morning, not the
    calendar day it is filed under. Nothing here is shifted to hide that.</p>
  </section>

  <section class="u-stack-8 u-mt-24">
    <h2>Days with no reading</h2>
    <?php if ($gaps === []): ?>
      <p class="go-hint">None. Every day in this window has a reading.</p>
    <?php else: ?>
      <ul class="go-gaplist">
        <?php foreach ($gaps as $goGap): ?>
          <li>
            <span class="u-nowrap">
              <?= View::e(View::date($goGap['from'])) ?><?= $goGap['from'] === $goGap['to'] ? '' : ' to ' . View::e(View::date($goGap['to'])) ?>
            </span>
            <span><?= View::e($goGap['reason'] ?? 'No row was written for these days at all.') ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

<?php endif; ?>

<section class="u-stack-8 u-mt-24">
  <h2>Every published post</h2>
  <?php if ($posts === []): ?>
    <div class="go-empty">
      <p class="go-empty-title">Nothing has been published yet, so there is nothing to measure.</p>
      <p class="go-empty-body">The first approved package that A5 takes at 08:00 IST appears here the
      following morning, once A6 has read it.</p>
    </div>
  <?php else: ?>
    <table class="go-perf">
      <caption><?= View::e(View::num(count($posts))) ?> published
      <?= count($posts) === 1 ? 'post' : 'posts' ?>, newest first. Figures are the most recent real
      reading, not the most recent row.</caption>
      <thead>
        <tr>
          <th scope="col">Post</th>
          <th scope="col">Published</th>
          <th scope="col">Last read</th>
          <th scope="col">Impressions</th>
          <th scope="col">Shares</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($posts as $goP): ?>
          <tr>
            <th scope="row">
              <a href="<?= View::e(View::url('analytics/' . rawurlencode((string) $goP['post_uid'])) . '?range=' . (int) $range) ?>"><?= View::e((string) $goP['final_title']) ?></a>
              <?php if (!empty($goP['linkedin_permalink'])): ?>
                <a class="go-source" href="<?= View::e((string) $goP['linkedin_permalink']) ?>" target="_blank" rel="noopener noreferrer">View on LinkedIn<?= View::icon('external') ?></a>
              <?php endif; ?>
            </th>
            <td data-label="Published"><?= View::e(View::date($goP['posted_date_ist'] ?? null)) ?></td>
            <td data-label="Last read">
              <?= $goP['last_metric_date'] === null
                    ? '<span class="u-faint">never read</span>'
                    : View::e(View::date((string) $goP['last_metric_date'])) ?>
            </td>
            <td data-label="Impressions" class="u-num"><?= View::e($goP['impressions'] === null ? '-' : View::num((int) $goP['impressions'])) ?></td>
            <td data-label="Shares" class="u-num"><?= View::e($goP['shares'] === null ? '-' : View::num((int) $goP['shares'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p class="go-hint">A dash is an absence, not a zero. It means A6 has no reading for that post yet.</p>
  <?php endif; ?>
</section>
