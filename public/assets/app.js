/*
 * app.js — optional progressive enhancement for Blurt.
 *
 * Everything here is a nicety. The app works fully with JavaScript disabled:
 * it adds a live character counter to the compose box and keeps each blurt's
 * "vanishes in …" countdown fresh. No inline scripts or handlers are used
 * anywhere, so the strict CSP can forbid inline execution entirely.
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

  // Mirror the server's expiry_label() so the countdown reads consistently.
  function expiryLabel(expiresAt) {
    var remaining = expiresAt - Math.floor(Date.now() / 1000);
    if (remaining <= 0) return 'vanishing…';
    if (remaining < 60) return 'vanishes in <1m';
    if (remaining < 3600) return 'vanishes in ' + Math.floor(remaining / 60) + 'm';
    if (remaining < 86400) return 'vanishes in ' + Math.floor(remaining / 3600) + 'h';
    return 'vanishes in ' + Math.floor(remaining / 86400) + 'd';
  }

  function wireCountdowns() {
    var nodes = document.querySelectorAll('.blurt__expiry[data-expires]');
    if (!nodes.length) return;

    function tick() {
      nodes.forEach(function (node) {
        var expiresAt = parseInt(node.getAttribute('data-expires'), 10);
        if (!expiresAt) return;
        node.textContent = expiryLabel(expiresAt);
      });
    }

    tick();
    // Refresh once a minute — enough for an hours/minutes countdown.
    setInterval(tick, 30000);
  }

  function init() {
    wireCounter();
    wireCountdowns();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
