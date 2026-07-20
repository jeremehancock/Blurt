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

  // Fade out and remove the flash toast after a few seconds.
  function wireFlash() {
    var flash = document.querySelector('.flash[data-autohide]');
    if (!flash) return;
    setTimeout(function () {
      flash.classList.add('is-leaving');
      var done = false;
      var remove = function () { if (!done) { done = true; flash.remove(); } };
      flash.addEventListener('animationend', remove);
      setTimeout(remove, 700); // fallback if animationend doesn't fire
    }, 3200);
  }

  // Progressive enhancement for reactions: toggle without a page reload.
  function findByEmoji(nodes, emoji) {
    for (var i = 0; i < nodes.length; i++) {
      if (nodes[i].getAttribute('data-emoji') === emoji) return nodes[i];
    }
    return null;
  }

  function makeChip(emoji) {
    var b = document.createElement('button');
    b.type = 'submit';
    b.name = 'emoji';
    b.value = emoji;
    b.className = 'react-chip is-active';
    b.setAttribute('data-emoji', emoji);
    var e = document.createElement('span');
    e.className = 'react-chip__e';
    e.textContent = emoji;
    var n = document.createElement('span');
    n.className = 'react-chip__n';
    n.textContent = '0';
    b.appendChild(e);
    b.appendChild(n);
    return b;
  }

  function applyReaction(form, data) {
    var pick = findByEmoji(form.querySelectorAll('.react-pick'), data.emoji);
    if (pick) pick.classList.toggle('is-active', !!data.reacted);

    var chip = findByEmoji(form.querySelectorAll('.react-chip'), data.emoji);
    if (data.count > 0) {
      if (!chip) {
        chip = makeChip(data.emoji);
        var addEl = form.querySelector('.react-add');
        form.insertBefore(chip, addEl);
      }
      var n = chip.querySelector('.react-chip__n');
      if (n) n.textContent = String(data.count);
      chip.classList.toggle('is-active', !!data.reacted);
      chip.classList.remove('just-reacted');
      void chip.offsetWidth; // restart the pop animation
      chip.classList.add('just-reacted');
    } else if (chip) {
      chip.remove();
    }
  }

  function closePicker(form) {
    var d = form.querySelector('.react-add');
    if (d) d.removeAttribute('open');
  }

  function wireReactions() {
    document.querySelectorAll('form.reactions').forEach(function (form) {
      form.addEventListener('submit', function (e) {
        if (form.getAttribute('data-bypass')) return; // native fallback submit
        var btn = e.submitter;
        if (!btn || btn.name !== 'emoji') return;
        e.preventDefault();

        var fd = new FormData(form);
        fd.set('emoji', btn.value); // the submitter's value isn't auto-included

        fetch(form.action, {
          method: 'POST',
          headers: { 'X-Requested-With': 'fetch' },
          body: fd,
          credentials: 'same-origin'
        }).then(function (r) {
          return r.json();
        }).then(function (data) {
          if (data && data.ok) {
            applyReaction(form, data);
            closePicker(form);
          } else if (data && data.rate) {
            closePicker(form); // silently ignore a rate-limit
          } else {
            throw new Error('reaction failed');
          }
        }).catch(function () {
          // Fall back to a normal form submission so nothing is lost.
          form.setAttribute('data-bypass', '1');
          if (form.requestSubmit) form.requestSubmit(btn);
          else form.submit();
        });
      });
    });
  }

  // Close any open reaction picker when tapping/clicking outside it, or on Esc.
  function wireReactionDismiss() {
    document.addEventListener('click', function (e) {
      document.querySelectorAll('details.react-add[open]').forEach(function (d) {
        if (!d.contains(e.target)) d.removeAttribute('open');
      });
    });
    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') return;
      document.querySelectorAll('details.react-add[open]').forEach(function (d) {
        d.removeAttribute('open');
      });
    });
  }

  function init() {
    wireCounter();
    wireCountdowns();
    wireFlash();
    wireReactions();
    wireReactionDismiss();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
