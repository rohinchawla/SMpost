/*
 * Golden Opportunities - the approval app.
 *
 * Everything on every screen works with this file deleted. Each mutation is a
 * real form POST, each filter is a real link, each disclosure is a real
 * <details>. What follows only removes round trips: it keeps a counter honest
 * while you type, lights the image you picked before you save, and lets the
 * one-at-a-time topic flow be driven from the keyboard.
 *
 * No dependencies, no build step, no framework. Loaded with defer, so the DOM
 * is parsed before any of this runs.
 */
(function () {
  'use strict';

  var $ = function (sel, root) { return (root || document).querySelector(sel); };
  var $$ = function (sel, root) {
    return Array.prototype.slice.call((root || document).querySelectorAll(sel));
  };

  /* ------------------------------------------------------------------ words
   * A deliberate port of Words::bodyWordCount, which is itself a port of the
   * mock. Hashtag-only lines do not count against the body cap, and the split
   * is on the Unicode-aware \s so a non-breaking space pasted out of a PDF is
   * whitespace here exactly as it is on the server. If these two ever disagree,
   * the server is right and this is the bug.
   */
  function bodyWordCount(body) {
    var kept = String(body == null ? '' : body).split('\n').filter(function (line) {
      return !/^\s*#\S/.test(line.trim());
    });
    var joined = kept.join(' ').trim();
    return joined === '' ? 0 : joined.split(/\s+/).length;
  }

  function firstLine(body) {
    return String(body == null ? '' : body).split('\n')[0].trim();
  }

  /* --------------------------------------------------------- the word cap */
  (function counters() {
    var body = $('textarea[data-word-cap]');
    if (!body) return;

    var cap = parseInt(body.getAttribute('data-word-cap'), 10) || 100;
    var wordBox = $('[data-word-count]');
    var hookBox = $('[data-hook-count]');

    function paint() {
      var n = bodyWordCount(body.value);
      if (wordBox) {
        wordBox.className = 'go-wordcount' + (n > cap ? ' is-over' : (n >= cap - 10 ? ' is-near' : ''));
        wordBox.innerHTML = '';
        var num = document.createElement('span');
        num.className = 'go-num';
        num.textContent = String(n);
        wordBox.appendChild(num);
        wordBox.appendChild(document.createTextNode(' of ' + cap + ' words'));
      }
      if (hookBox) {
        hookBox.innerHTML = '';
        hookBox.appendChild(document.createTextNode('hook '));
        var h = document.createElement('span');
        h.className = 'go-num';
        h.textContent = String(firstLine(body.value).length);
        hookBox.appendChild(h);
        hookBox.appendChild(document.createTextNode(' characters'));
      }
    }

    body.addEventListener('input', paint);
    paint();
  }());

  /* ------------------------------------------------------- hashtag chips
   * The input is the control, because it is the field that posts. The chips
   * are a reading of it, redrawn as it changes.
   */
  (function chips() {
    var source = $('[data-tag-source]');
    var target = $('[data-tag-chips]');
    if (!source || !target) return;

    function paint() {
      var tags = source.value.split(/[\s,]+/).filter(Boolean).map(function (t) {
        return t.charAt(0) === '#' ? t : '#' + t;
      });
      target.innerHTML = '';
      if (tags.length === 0) {
        var none = document.createElement('span');
        none.className = 'go-hint';
        none.textContent = 'None yet.';
        target.appendChild(none);
        return;
      }
      tags.forEach(function (tag) {
        var chip = document.createElement('span');
        chip.className = 'go-chip';
        chip.textContent = tag;
        target.appendChild(chip);
      });
    }

    source.addEventListener('input', paint);
  }());

  /* ---------------------------------------------------- the image picker */
  (function imagePicker() {
    var options = $$('.go-imagepick-option');
    if (options.length === 0) return;

    function paint() {
      options.forEach(function (option) {
        var radio = $('input[type=radio]', option);
        option.classList.toggle('is-selected', !!radio && radio.checked);
      });
    }

    $$('.go-imagepick input[type=radio]').forEach(function (radio) {
      radio.addEventListener('change', paint);
    });
  }());

  /* ------------------------------------------------- unsaved text on approve
   * Approving releases the bytes already stored, not what is in the box. The
   * screen says so in words; this stops a slip that the words did not.
   */
  (function unsavedGuard() {
    var body = $('textarea[data-word-cap]');
    if (!body) return;

    var form = body.form;
    if (!form) return;

    var saved = body.value;
    form.addEventListener('submit', function (event) {
      var action = document.activeElement;
      var verdict = action && action.name === 'action' && action.value === 'approved';
      if (!verdict || body.value === saved) return;
      if (!window.confirm('The text has changed since it was last saved. Approving releases the saved '
          + 'version, not what is on screen. Save first?\n\nOK to approve the saved version, '
          + 'Cancel to go back and save.')) {
        event.preventDefault();
      }
    });
  }());

  /* ------------------------------------------- the keyboard, one topic at a time
   * Only on the single-topic flow, where exactly one verdict control exists and
   * a key press cannot be ambiguous. Never while a field has focus.
   */
  (function verdictKeys() {
    var control = $('.go-one-actions .go-verdict');
    if (!control) return;

    document.addEventListener('keydown', function (event) {
      if (event.metaKey || event.ctrlKey || event.altKey) return;
      var el = document.activeElement;
      if (el && /^(input|textarea|select)$/i.test(el.tagName)) return;
      if (el && el.isContentEditable) return;

      var button = $('[data-key="' + String(event.key).toLowerCase() + '"]', control);
      if (!button) return;
      event.preventDefault();
      button.click();
    });
  }());

  /* ------------------------------------------- close the phone nav on escape */
  (function nav() {
    var menu = $('details.go-topbar-nav');
    if (!menu) return;
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && menu.open) menu.open = false;
    });
  }());
}());
