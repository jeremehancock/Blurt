/*
 * app.js — optional progressive enhancement for Blurt.
 *
 * Everything here is a nicety. The app works fully with JavaScript disabled:
 * it adds a live character counter to the compose box and keeps each blurt's
 * "vanishes in …" countdown — and its fade-as-it-ages level — fresh. No inline
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

  // Mirror the server's expiry_label() so the countdown reads consistently.
  function expiryLabel(expiresAt) {
    var remaining = expiresAt - Math.floor(Date.now() / 1000);
    if (remaining <= 0) return 'vanishing…';
    if (remaining < 60) return 'vanishes in <1m';
    if (remaining < 3600) return 'vanishes in ' + Math.floor(remaining / 60) + 'm';
    if (remaining < 86400) return 'vanishes in ' + Math.floor(remaining / 3600) + 'h';
    return 'vanishes in ' + Math.floor(remaining / 86400) + 'd';
  }

  // Mirror the server's freshness_bucket(): 0 (fresh) … 4 (about to vanish).
  function freshnessBucket(expiresAt, ttl) {
    var remaining = expiresAt - Math.floor(Date.now() / 1000);
    if (remaining <= 0) return 4;
    var ratio = ttl > 0 ? remaining / ttl : 1;
    if (ratio >= 0.60) return 0;
    if (ratio >= 0.40) return 1;
    if (ratio >= 0.20) return 2;
    if (ratio >= 0.08) return 3;
    return 4;
  }

  function wireCountdowns() {
    var nodes = document.querySelectorAll('.blurt[data-expires]');
    if (!nodes.length) return;

    var ttl = parseInt(document.body.getAttribute('data-ttl'), 10) || 0;

    function tick() {
      nodes.forEach(function (article) {
        var expiresAt = parseInt(article.getAttribute('data-expires'), 10);
        if (!expiresAt) return;

        // Keep the fade level in step with the remaining life.
        var bucket = freshnessBucket(expiresAt, ttl);
        article.className = article.className.replace(/\bage-\d\b/, 'age-' + bucket);

        var chip = article.querySelector('.blurt__expiry');
        if (chip) {
          var lbl = chip.querySelector('.lbl');
          if (lbl) lbl.textContent = expiryLabel(expiresAt);
          chip.classList.toggle('is-urgent', bucket >= 4);
        }
      });
    }

    tick();
    // Refresh every 30s — enough for an hours/minutes countdown and gentle fade.
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
