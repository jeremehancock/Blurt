/*
 * app.js — optional progressive enhancement for Blurt.
 *
 * Everything here is a nicety. The app works fully with JavaScript disabled:
 * this file only adds a live character counter to the compose box. No inline
 * scripts or handlers are used anywhere, so the strict CSP can forbid inline
 * execution entirely.
 */
(function () {
  'use strict';

  function wireCounter() {
    var textarea = document.getElementById('compose-text');
    if (!textarea) return;
    var counter = document.querySelector('[data-count]');
    if (!counter) return;

    var max = parseInt(textarea.getAttribute('data-maxlen'), 10);
    if (!max || max < 1) return;

    function update() {
      // Count Unicode code points, matching the server's character cap
      // closely enough for a live hint (the server is authoritative).
      var len = Array.from(textarea.value).length;
      var remaining = max - len;
      counter.textContent = String(remaining);
      counter.classList.toggle('is-over', remaining < 0);
    }

    textarea.addEventListener('input', update);
    update();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', wireCounter);
  } else {
    wireCounter();
  }
})();
