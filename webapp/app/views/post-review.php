<?php declare(strict_types=1);
if (!defined('GO_BOOT')) { http_response_code(404); exit; }

/**
 * Gate 2. The package as the feed will render it, and the editor beside it.
 *
 * Two things on this screen are load-bearing.
 *
 * The fold. LinkedIn truncates at roughly 140 characters on a phone and hides
 * the rest behind "see more". A preview that does not draw that line flatters
 * the post, so the body is split at character 140 and a dashed rule is drawn
 * across it. The rule is positioned by its own static position - it is placed
 * in the markup exactly where the cut falls - so it is correct with JavaScript
 * switched off.
 *
 * The trace panel. Every numeral in the body, beside the source sentence that
 * carries it. A number that cannot be traced gets the only gold in this
 * application, because an unverifiable number is the one failure the whole
 * pipeline exists to prevent.
 *
 * Everything lives in one form so that approving can carry the chosen image and
 * the date. Approving does not save the text: it releases the version already
 * stored, which is why the decision bar says so in words.
 */
$goUid     = (string) $p['post_uid'];
$goBody    = (string) $p['final_body'];
/* What a refused save was carrying, if that is how we got here. The preview
   keeps showing the stored version - nothing was written - while the editor
   shows the attempt, so the words are not thrown away with the refusal. */
$goDraft   = (string) UiApp::old('final_body', $goBody);
$goAction  = View::url('posts/' . rawurlencode($goUid));
$goLive    = in_array((string) $p['lifecycle_state'], ['publishing', 'posted'], true);
$goPosted  = (string) $p['lifecycle_state'] === 'posted';
$goStatus  = (string) $p['review_status'];
$goMax     = Settings::int('max_body_words', 100);
$goCount   = $goDraft === $goBody ? (int) $p['final_word_count'] : Words::bodyWordCount($goDraft);
$goHookLen = mb_strlen($goDraft === $goBody
    ? (string) $p['final_hook']
    : (string) (explode(chr(10), $goDraft)[0] ?? ''));
$goTags    = Canon::decode((string) $p['final_hashtags'], []);
$goTags    = is_array($goTags) ? $goTags : [];
$goTagText = (string) UiApp::old('hashtags', implode(' ', array_map('strval', $goTags)));

/* LinkedIn's phone truncation. Not a guess dressed as a fact: it is the number
   the house style is written against, and it is stated on screen. */
$goFoldAt   = 140;
$goFoldHead = mb_substr($goBody, 0, $goFoldAt);
$goFoldTail = mb_substr($goBody, $goFoldAt);

$goWordClass = $goCount > $goMax ? ' is-over' : ($goCount >= $goMax - 10 ? ' is-near' : '');
$goVerdicts  = ['pending' => 'Pending', 'approved' => 'Approved', 'hold' => 'Hold', 'rejected' => 'Rejected'];

$goSelected = null;
foreach ($images as $goImg) {
    if ((int) $goImg['id'] === (int) ($p['selected_image_id'] ?? 0)) $goSelected = $goImg;
}
if ($goSelected === null) $goSelected = $images[0] ?? null;

/* The highlight is applied to the ESCAPED quote, so a source sentence can never
   inject markup through this. */
$goMark = static function (string $quote, string $value): string {
    $q = View::e($quote);
    $v = View::e($value);
    if ($v === '') return $q;
    $at = mb_strpos($q, $v);
    if ($at === false) return $q;
    return mb_substr($q, 0, $at) . '<mark class="go-trace-mark">' . $v . '</mark>' . mb_substr($q, $at + mb_strlen($v));
};

$goUntraced = 0;
foreach ($trace as $goRow) { if (empty($goRow['traced'])) $goUntraced++; }
?>

<div class="go-page-head">
  <h1 class="go-page-title"><?= View::e((string) $p['final_title']) ?></h1>
  <p class="go-page-sub">
    <span class="go-progress-note">
      <?= View::e(View::progress($p)) ?><?php if ($goPosted && !empty($p['linkedin_permalink'])): ?>
        &middot; <a href="<?= View::e((string) $p['linkedin_permalink']) ?>" target="_blank" rel="noopener noreferrer">View on LinkedIn<?= View::icon('external') ?></a>
      <?php endif; ?>
    </span>
  </p>
</div>

<div class="go-review">

  <!-- ---------------------------------------------------------- the preview -->
  <section class="go-review-preview">
    <h2>As the feed will render it</h2>

    <div class="go-li u-mt-24">
      <article class="go-li-card">

        <header class="go-li-author">
          <div class="go-li-avatar">
            <svg viewBox="0 0 48 48" width="48" height="48" fill="none" stroke="currentColor"
                 stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M24 9 39 24 24 39 9 24Z"/>
              <path d="M24 17 31 24 24 31 17 24Z"/>
            </svg>
          </div>
          <div>
            <p class="go-li-name">Golden Opportunities Pvt Ltd</p>
            <p class="go-li-sub">Recruitment and executive search &middot; India</p>
            <p class="go-li-sub">
              <?= View::e($goPosted ? View::date($p['posted_date_ist'] ?? null) : View::date($p['scheduled_date_ist'] ?? null)) ?>
              &middot; <?= View::icon('globe', 13) ?><span class="u-sr-only">Public</span>
            </p>
          </div>
        </header>

        <div class="go-li-body"><?= View::e($goFoldHead)
          ?><?php if ($goFoldTail !== ''): ?><span class="go-li-seemore">&hellip;see more</span><span
            class="go-li-fold" aria-hidden="true"><span class="go-li-fold-label">140 characters: the phone fold</span></span><?php endif;
          ?><?= View::e($goFoldTail) ?></div>

        <?php if ($goTags !== []): ?>
          <p class="go-li-tags"><?= View::e(implode(' ', array_map('strval', $goTags))) ?></p>
        <?php endif; ?>

        <?php if ($goSelected !== null): ?>
          <div class="go-li-media">
            <img src="<?= View::e(View::url(ltrim((string) $goSelected['public_url'], '/'))) ?>"
                 alt="<?= View::e((string) ($goSelected['alt_text'] ?? 'Image for this post')) ?>">
          </div>
        <?php endif; ?>

        <!-- Drawn marks from the sprite, and no counts: this post has not been
             seen by anyone, so a like number here would be an invented figure. -->
        <footer class="go-li-actions">
          <span><?= View::icon('check') ?>Like</span>
          <span><?= View::icon('dot') ?>Comment</span>
          <span><?= View::icon('replay') ?>Repost</span>
          <span><?= View::icon('external') ?>Send</span>
        </footer>
      </article>

      <?php if (!empty($p['first_comment_text'])): ?>
        <p class="go-li-comment"><?= View::e((string) $p['first_comment_text']) ?></p>
      <?php else: ?>
        <p class="go-li-comment">Nothing written. The link belongs here, not in the post - a link in the
        body suppresses reach, which is the whole reason this field exists.</p>
      <?php endif; ?>
    </div>

    <p class="go-hint u-mt-24">
      <?php if ($goFoldTail === ''): ?>
        The whole post is <?= View::e(View::num(mb_strlen($goBody))) ?> characters, so nothing is hidden
        behind "see more".
      <?php else: ?>
        A reader on a phone sees only what sits above the dashed rule until they tap "see more". Everything
        that has to land, has to land above it.
      <?php endif; ?>
    </p>
  </section>

  <!-- ----------------------------------------------------------- the editor -->
  <section class="go-review-editor">
    <h2>Edit and decide</h2>

    <form method="post" action="<?= View::e($goAction) ?>">
      <?= View::csrf() ?>
      <input type="hidden" name="post_uid" value="<?= View::e($goUid) ?>">

      <?php if ($goLive): ?>
        <p class="go-flash" role="status">
          <?= View::icon('warning') ?>
          <span><?php if ($goPosted): ?>This post is live on LinkedIn. Editing it here would make the
          audit record a lie, so the fields are read-only.<?php else: ?>A5 has this post in hand and is
          publishing it now. The fields stay read-only until that finishes - an edit mid-publish would
          mean the bytes you approved and the bytes that went out are different.<?php endif; ?></span>
        </p>
      <?php endif; ?>

      <div class="go-editor-block">
        <h3>The post</h3>
        <p class="go-field">
          <label class="go-label" for="e-body">Body</label>
          <textarea class="go-textarea" id="e-body" name="final_body" rows="10"
                    data-word-cap="<?= View::e((string) $goMax) ?>"
                    data-fold="<?= View::e((string) $goFoldAt) ?>"
                    <?= $goLive ? 'disabled' : '' ?>><?= View::e($goDraft) ?></textarea>
        </p>
        <p class="u-row u-gap-12">
          <span class="go-wordcount<?= $goWordClass ?>" data-word-count>
            <span class="go-num"><?= View::e(View::num($goCount)) ?></span> of
            <?= View::e(View::num($goMax)) ?> words
          </span>
          <span class="go-hookcount" data-hook-count>
            hook <span class="go-num"><?= View::e(View::num($goHookLen)) ?></span> characters
          </span>
        </p>
        <p class="go-hint">The cap is a database constraint as well as a house rule, so a body over
        <?= View::e(View::num($goMax)) ?> words is refused rather than trimmed. A link in the body is
        refused too - it belongs in the first comment.</p>
      </div>

      <div class="go-editor-block">
        <h3>Hashtags</h3>
        <p class="go-tags" data-tag-chips>
          <?php if ($goTags === []): ?>
            <span class="go-hint">None yet.</span>
          <?php else: ?>
            <?php foreach ($goTags as $goTag): ?>
              <span class="go-chip"><?= View::e((string) $goTag) ?></span>
            <?php endforeach; ?>
          <?php endif; ?>
        </p>
        <p class="go-field">
          <label class="go-label" for="e-tags">Hashtags, separated by spaces</label>
          <input class="go-input" id="e-tags" name="hashtags" value="<?= View::e($goTagText) ?>"
                 data-tag-source <?= $goLive ? 'disabled' : '' ?>>
        </p>
      </div>

      <div class="go-editor-block">
        <h3>Call to action</h3>
        <p class="go-field">
          <label class="go-label" for="e-cta">What it says</label>
          <input class="go-input" id="e-cta" name="cta_text" maxlength="300"
                 value="<?= View::e((string) UiApp::old('cta_text', $p['final_cta_text'] ?? '')) ?>" <?= $goLive ? 'disabled' : '' ?>>
        </p>
        <p class="go-field">
          <label class="go-label" for="e-cta-target">Where it points</label>
          <input class="go-input" id="e-cta-target" name="cta_target" maxlength="500"
                 value="<?= View::e((string) UiApp::old('cta_target', $p['final_cta_target'] ?? '')) ?>" <?= $goLive ? 'disabled' : '' ?>>
        </p>
      </div>

      <div class="go-editor-block">
        <h3>First comment</h3>
        <p class="go-field">
          <label class="go-label" for="e-comment">Posted by A5 immediately after the post</label>
          <textarea class="go-textarea" id="e-comment" name="first_comment" maxlength="500"
                    <?= $goLive ? 'disabled' : '' ?>><?= View::e((string) UiApp::old('first_comment', $p['first_comment_text'] ?? '')) ?></textarea>
        </p>
        <p class="go-hint">This is where the link goes. It is posted as a separate comment, so the post
        itself carries no URL.</p>
      </div>

      <div class="go-editor-block">
        <h3>Image</h3>
        <?php if ($images === []): ?>
          <p class="go-error"><?= View::icon('warning') ?> No images were generated for this package. It
          cannot be approved until A3 has produced two options.</p>
        <?php else: ?>
          <?php if (count($images) < 2): ?>
            <p class="go-error"><?= View::icon('warning') ?> Only one option exists. A3 should have
            produced two, from two different models.</p>
          <?php endif; ?>
          <div class="go-imagepick">
            <?php foreach ($images as $goImg): ?>
              <?php
                $goOpt = (int) $goImg['option_index'];
                $goIsSel = $goSelected !== null && (int) $goSelected['id'] === (int) $goImg['id'];
                $goPicked = UiApp::old('select_image');
                if ($goPicked !== null) { $goIsSel = (int) $goPicked === $goOpt; }
              ?>
              <label class="go-imagepick-option<?= $goIsSel ? ' is-selected' : '' ?>" for="img-<?= View::e((string) $goOpt) ?>">
                <img src="<?= View::e(View::url(ltrim((string) $goImg['public_url'], '/'))) ?>"
                     alt="<?= View::e((string) ($goImg['alt_text'] ?? 'Image option ' . $goOpt)) ?>">
                <span class="go-hint">
                  <input type="radio" name="select_image" id="img-<?= View::e((string) $goOpt) ?>"
                         value="<?= View::e((string) $goOpt) ?>" <?= $goIsSel ? 'checked' : '' ?>
                         <?= $goLive ? 'disabled' : '' ?>>
                  Option <?= View::e($goOpt === 1 ? 'A' : 'B') ?>
                  &middot; <?= View::e((string) ($goImg['provider_model'] ?? 'unknown model')) ?>
                  &middot; <?= View::e((string) ($goImg['aspect_ratio'] ?? 'aspect not recorded')) ?>
                  <?php if (!empty($goImg['concept_label'])): ?>
                    <br><?= View::e((string) $goImg['concept_label']) ?>
                  <?php endif; ?>
                </span>
              </label>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="go-editor-block">
        <h3>When it goes out</h3>
        <p class="go-field">
          <label class="go-label" for="e-date">Date, IST</label>
          <input class="go-input" id="e-date" name="scheduled_date_ist" type="date"
                 value="<?= View::e((string) UiApp::old('scheduled_date_ist', $p['scheduled_date_ist'] ?? '')) ?>" <?= $goLive ? 'disabled' : '' ?>>
        </p>
        <p class="go-hint">Leave it empty and the package joins the queue in order, oldest approved
        first. A5 publishes one post a day at 08:00 IST.</p>
      </div>

      <!-- --------------------------------------------------------- the trace -->
      <div class="go-editor-block">
        <h3>Numbers and where they come from</h3>
        <?php if ($trace === []): ?>
          <p class="go-trace-verdict is-traced"><?= View::icon('check') ?>No numbers in this post. Nothing to trace.</p>
          <p class="go-hint">A post with no numbers is fine. A post with a number nobody can point at a
          source for is not.</p>
        <?php else: ?>
          <p class="go-hint">Checked against the bytes in the box above, not against what the writer
          submitted - the body is editable here, so the agent's verdict was reached on a different string.</p>
          <div class="go-trace">
            <?php foreach ($trace as $goRow): ?>
              <div class="go-trace-row">
                <p class="go-trace-value">
                  <?= View::e((string) $goRow['value']) ?>
                  <span class="go-hint"><?= View::e((string) $goRow['context']) ?></span>
                </p>
                <p class="go-trace-quote">
                  <?php if (!empty($goRow['traced'])): ?>
                    <?= $goMark((string) ($goRow['quote'] ?? ''), (string) $goRow['value']) ?>
                    <?php if (!empty($goRow['source_url'])): ?>
                      <cite><a href="<?= View::e((string) $goRow['source_url']) ?>" target="_blank" rel="noopener noreferrer">Open the source<?= View::icon('external') ?></a></cite>
                    <?php endif; ?>
                  <?php else: ?>
                    No quoted sentence in this package contains this figure.
                  <?php endif; ?>
                </p>
                <?php if (!empty($goRow['traced'])): ?>
                  <p class="go-trace-verdict is-traced"><span class="go-dot is-approved"></span>Found verbatim in the source</p>
                <?php else: ?>
                  <p class="go-trace-verdict is-untraced ks-tag">NOT IN ANY SOURCE</p>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- ------------------------------------------------------ the decision -->
      <?php if ($stale): ?>
        <p class="go-flash is-error" role="alert">
          <?= View::icon('warning') ?>
          <span><strong>This post was edited after it was approved, so it will not publish.</strong>
          A5 compares the bytes at 08:00 against the bytes you approved and refuses the difference in
          silence. Approving again releases the version on screen now.</span>
        </p>
      <?php endif; ?>

      <?php if ($goUntraced > 0): ?>
        <p class="go-flash is-error" role="alert">
          <?= View::icon('warning') ?>
          <span><strong><?= View::e(View::num($goUntraced)) ?>
          <?= $goUntraced === 1 ? 'number has' : 'numbers have' ?> no source.</strong>
          Either cut the figure or correct it to one the quoted sentence actually carries.</span>
        </p>
      <?php endif; ?>

      <p class="go-field">
        <label class="go-label" for="e-note">Note for the record, optional</label>
        <input class="go-input" id="e-note" name="note" maxlength="500" placeholder="Why you held or rejected it">
      </p>

      <div class="go-decision">
        <?php if (!$goLive): ?>
          <button type="submit" name="action" value="save" class="go-btn go-btn-primary">Save the edits</button>
        <?php endif; ?>

        <div class="go-verdict" role="group" aria-label="Verdict for this package">
          <?php foreach ($goVerdicts as $goKey => $goLabel): ?>
            <?php $goOn = $goStatus === $goKey; ?>
            <button type="submit" name="action" value="<?= View::e($goKey) ?>"
                    class="go-verdict-btn<?= $goOn ? ' is-on' : '' ?>"
                    aria-pressed="<?= $goOn ? 'true' : 'false' ?>"
                    <?= (!$goOn && $goLive) ? 'disabled' : '' ?>>
              <span class="go-dot is-<?= View::e($goKey) ?>"></span><?= View::e($goLabel) ?>
            </button>
          <?php endforeach; ?>
        </div>

        <p class="go-hint u-right">Approving releases the version that is <em>saved</em>, with the image
        selected above. Save first if you have changed the text.</p>
      </div>
    </form>

    <?php if ($history !== []): ?>
      <details class="go-editor-block">
        <summary>What has happened to this package</summary>
        <table class="go-table">
          <thead>
            <tr><th scope="col">When</th><th scope="col">Verdict</th><th scope="col">Who</th><th scope="col">Note</th></tr>
          </thead>
          <tbody>
            <?php foreach ($history as $goH): ?>
              <tr>
                <td data-label="When"><?= View::e(View::dateTime($goH['acted_at'] ?? null)) ?></td>
                <td data-label="Verdict">
                  <span class="go-status">
                    <span class="go-dot is-<?= View::e((string) $goH['to_status']) ?>"></span>
                    <?= View::e($goVerdicts[(string) $goH['to_status']] ?? (string) $goH['to_status']) ?>
                  </span>
                </td>
                <td data-label="Who"><?= View::e((string) $goH['actor_name']) ?></td>
                <td data-label="Note"><?= View::e((string) ($goH['note'] ?? '')) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </details>
    <?php endif; ?>

  </section>
</div>
