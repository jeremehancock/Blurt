<?php
/**
 * index.php — the feed + compose form (server-rendered).
 *
 * Works fully without JavaScript: the compose form and every reply/react
 * control are plain POST forms. app.js only layers on small niceties.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

send_security_headers();
start_app_session();
ensure_identity();

$flash = take_flash();

// The blurt just posted by this visitor (if any) — pops into view on load.
$justPosted = (string) ($_SESSION['just_posted'] ?? '');
unset($_SESSION['just_posted']);

// A draft preserved from a failed post (rate limit, size cap, …) so the
// visitor's text is never lost. One-shot: shown once, then cleared.
$draft = (string) ($_SESSION['draft'] ?? '');
unset($_SESSION['draft']);

// Blurts are ephemeral: sweep away anything past its lifetime (lazy, no cron).
maybe_purge_expired();

// ---------------------------------------------------------------------------
// Build the threaded, paginated feed.
// ---------------------------------------------------------------------------
$all = load_all_visible();

$now = time();
$topLevel = [];
$repliesByParent = [];
foreach ($all as $rec) {
    // Never show an expired blurt, even in the gap before the next sweep.
    if (blurt_is_expired($rec, $now)) {
        continue;
    }
    if (($rec['parent_id'] ?? null) === null) {
        $topLevel[] = $rec;
    } else {
        $pid = (string) $rec['parent_id'];
        $repliesByParent[$pid][] = $rec;
    }
}

// Newest top-level first.
usort($topLevel, static fn($a, $b) => ($b['created_at'] ?? 0) <=> ($a['created_at'] ?? 0));

// Replies oldest-first under each parent (Twitter-thread style).
foreach ($repliesByParent as &$replies) {
    usort($replies, static fn($a, $b) => ($a['created_at'] ?? 0) <=> ($b['created_at'] ?? 0));
}
unset($replies);

$total = count($topLevel);
$totalPages = max(1, (int) ceil($total / PER_PAGE));
$page = max(1, (int) ($_GET['page'] ?? 1));
$page = min($page, $totalPages);
$offset = ($page - 1) * PER_PAGE;
$pageItems = array_slice($topLevel, $offset, PER_PAGE);

/**
 * Render a single blurt (top-level or reply).
 */
function render_blurt(array $rec, bool $isReply, string $justPosted = '', int $page = 1): void
{
    $name = h((string) ($rec['display_name'] ?? 'Anon'));
    $color = (string) ($rec['display_color'] ?? '#666666');
    if (!valid_hex_color($color)) {
        $color = '#666666';
    }
    $id = (string) ($rec['id'] ?? '');
    $created = (int) ($rec['created_at'] ?? 0);
    $expiresAt = blurt_expires_at($rec);
    $textHtml = render_blurt_text((string) ($rec['text'] ?? ''));

    // Freshness drives the "fade as it ages" look; data-expires lets app.js
    // keep both the fade and the countdown live.
    $bucket = freshness_bucket($expiresAt);
    $pop = ($id !== '' && $id === $justPosted) ? ' blurt--pop' : '';
    $classes = 'blurt age-' . $bucket . ($isReply ? ' blurt--reply' : '') . $pop;
    $anchor = $id !== '' ? ' id="b-' . h($id) . '"' : '';
    echo '<article class="' . $classes . '"' . $anchor
        . ' data-expires="' . (int) $expiresAt . '">';

    // Left column: the poster's colored initial avatar.
    echo avatar_html($rec['display_name'] ?? '', $color, $isReply ? 'sm' : 'md');

    echo '<div class="blurt__body">';

    echo '<header class="blurt__head">';
    echo '<span class="blurt__name">' . $name . '</span>';
    echo '<span class="blurt__time">' . h(time_ago($created)) . '</span>';
    // Ephemeral countdown chip. app.js keeps the label + urgency fresh.
    $urgent = $bucket >= 4 ? ' is-urgent' : '';
    echo '<span class="blurt__expiry' . $urgent . '">' . icon_clock()
        . '<span class="lbl">' . h(expiry_label($expiresAt)) . '</span></span>';
    echo '</header>';

    echo '<div class="blurt__bubble"><div class="blurt__text">' . $textHtml . '</div></div>';

    echo '<footer class="blurt__actions">';
    if ($id !== '') {
        reaction_bar($id, $rec, $page);
    }
    if (!$isReply && $id !== '') {
        // Reply affordance — a plain <details> so it works with JS disabled.
        echo '<details class="reply">';
        echo '<summary class="act">' . icon_reply() . '<span>Reply</span></summary>';
        echo '<form class="reply__form" method="post" action="submit.php">';
        echo csrf_fields();
        echo '<input type="hidden" name="parent_id" value="' . h($id) . '">';
        // Carry the feed page so the reply redirect lands back on this page.
        echo '<input type="hidden" name="page" value="' . (int) $page . '">';
        echo hp_field();
        echo '<textarea name="text" class="reply__text" rows="2" maxlength="'
            . (int) MAX_POST_LEN . '" placeholder="Post your reply…" required></textarea>';
        echo '<button type="submit" class="btn btn--primary btn--small">Reply</button>';
        echo '</form>';
        echo '</details>';
    }
    echo '</footer>';

    echo '</div>'; // .blurt__body
    echo '</article>';
}

/**
 * Render the emoji reaction bar for a blurt: chips for reactions that already
 * have a tally, plus an "add reaction" picker. One form, many submit buttons —
 * works with plain POST; app.js upgrades it to a no-reload toggle.
 */
function reaction_bar(string $id, array $rec, int $page): void
{
    $reactions = $rec['reactions'] ?? [];
    if (!is_array($reactions)) {
        $reactions = [];
    }
    // "You reacted" highlight keys off the per-session reactor id (matches the
    // dedupe in react.php), so it reflects only this visitor's own reactions.
    $me = current_reactor_id();

    echo '<form class="reactions" method="post" action="react.php" data-id="' . h($id) . '">';
    echo csrf_fields();
    echo '<input type="hidden" name="id" value="' . h($id) . '">';
    echo '<input type="hidden" name="page" value="' . (int) $page . '">';

    // Existing tallies, in whitelist order.
    foreach (reaction_emojis() as $emoji) {
        $list = (isset($reactions[$emoji]) && is_array($reactions[$emoji])) ? $reactions[$emoji] : [];
        $count = count($list);
        if ($count < 1) {
            continue;
        }
        $active = in_array($me, $list, true) ? ' is-active' : '';
        echo '<button type="submit" name="emoji" value="' . h($emoji) . '" '
            . 'class="react-chip' . $active . '" data-emoji="' . h($emoji) . '">'
            . '<span class="react-chip__e">' . h($emoji) . '</span>'
            . '<span class="react-chip__n">' . (int) $count . '</span></button>';
    }

    // Add-reaction picker (a plain <details> so it opens without JS).
    echo '<details class="react-add">';
    echo '<summary class="react-add__btn" title="Add a reaction">' . icon_addreact() . '</summary>';
    echo '<div class="react-picker">';
    foreach (reaction_emojis() as $emoji) {
        $list = (isset($reactions[$emoji]) && is_array($reactions[$emoji])) ? $reactions[$emoji] : [];
        $active = in_array($me, $list, true) ? ' is-active' : '';
        echo '<button type="submit" name="emoji" value="' . h($emoji) . '" '
            . 'class="react-pick' . $active . '" data-emoji="' . h($emoji) . '">'
            . h($emoji) . '</button>';
    }
    echo '</div>';
    echo '</details>';

    echo '</form>';
}

/** Small inline SVG icons (static markup — no CSP concern). */
function icon_reply(): string
{
    return '<svg class="ico" width="15" height="15" viewBox="0 0 24 24" fill="none" '
        . 'stroke="currentColor" stroke-width="2" stroke-linecap="round" '
        . 'stroke-linejoin="round" aria-hidden="true">'
        . '<path d="M9 17l-5-5 5-5"/><path d="M4 12h11a5 5 0 0 1 5 5v1"/></svg>';
}

function icon_addreact(): string
{
    return '<svg class="ico" width="17" height="17" viewBox="0 0 24 24" fill="none" '
        . 'stroke="currentColor" stroke-width="2" stroke-linecap="round" '
        . 'stroke-linejoin="round" aria-hidden="true">'
        . '<path d="M20.9 12.7A9 9 0 1 1 11.3 3.1"/>'
        . '<path d="M8.5 14.5a4 4 0 0 0 6 0"/>'
        . '<path d="M9 9.5h.01M15 9.5h.01"/>'
        . '<path d="M19 3v5M21.5 5.5h-5"/></svg>';
}

function icon_check(): string
{
    return '<svg class="ico" width="16" height="16" viewBox="0 0 24 24" fill="none" '
        . 'stroke="currentColor" stroke-width="2.4" stroke-linecap="round" '
        . 'stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>';
}

function icon_alert(): string
{
    return '<svg class="ico" width="16" height="16" viewBox="0 0 24 24" fill="none" '
        . 'stroke="currentColor" stroke-width="2.2" stroke-linecap="round" '
        . 'stroke-linejoin="round" aria-hidden="true">'
        . '<circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>';
}

function icon_clock(): string
{
    return '<svg class="ico" width="14" height="14" viewBox="0 0 24 24" fill="none" '
        . 'stroke="currentColor" stroke-width="2" stroke-linecap="round" '
        . 'stroke-linejoin="round" aria-hidden="true">'
        . '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>';
}

function icon_send(): string
{
    return '<svg class="ico" width="16" height="16" viewBox="0 0 24 24" fill="none" '
        . 'stroke="currentColor" stroke-width="2" stroke-linecap="round" '
        . 'stroke-linejoin="round" aria-hidden="true">'
        . '<path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4 20-7z"/></svg>';
}

/** The honeypot field: visually hidden, must be left empty by humans. */
function hp_field(): string
{
    return '<div class="hp" aria-hidden="true">'
        . '<label>Website<input type="text" name="website" tabindex="-1" '
        . 'autocomplete="off"></label></div>';
}

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(SITE_TITLE) ?></title>
<?php
  $ogDesc = SITE_TAGLINE !== ''
      ? SITE_TAGLINE
      : 'A tiny anonymous public feed. Post a blurt with no account — everything vanishes in ' . ttl_phrase() . '.';
  $ogUrl = site_base_url() . '/';
  $ogImage = site_base_url() . '/assets/og-image.png';
?>
<meta name="description" content="<?= h($ogDesc) ?>">
<meta name="theme-color" content="#7c3aed">
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= h(SITE_TITLE) ?>">
<meta property="og:title" content="<?= h(SITE_TITLE) ?>">
<meta property="og:description" content="<?= h($ogDesc) ?>">
<meta property="og:url" content="<?= h($ogUrl) ?>">
<meta property="og:image" content="<?= h($ogImage) ?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="<?= h(SITE_TITLE) ?> — <?= h($ogDesc) ?>">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= h(SITE_TITLE) ?>">
<meta name="twitter:description" content="<?= h($ogDesc) ?>">
<meta name="twitter:image" content="<?= h($ogImage) ?>">
<?= favicon_link() ?>
<link rel="stylesheet" href="assets/style.css">
</head>
<body data-ttl="<?= (int) POST_TTL ?>">
<div class="fx" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i></div>
<div class="wrap">
  <header class="site-header">
    <a class="brand" href="index.php" aria-label="<?= h(SITE_TITLE) ?> — home">
      <svg class="brand__logo" viewBox="4 2 24 26" aria-hidden="true">
        <defs><linearGradient id="brandGrad" x1="0" y1="0" x2="1" y2="1">
          <stop class="g0" offset="0"/><stop class="g1" offset="1"/>
        </linearGradient></defs>
        <path fill="url(#brandGrad)" d="M10 4 H22 a4 4 0 0 1 4 4 V16 a4 4 0 0 1 -4 4 H13 L6 25 V8 a4 4 0 0 1 4 -4 Z"/>
        <circle cx="11" cy="12" r="2.4"/><circle cx="16" cy="12" r="2.4"/><circle cx="21" cy="12" r="2.4"/>
      </svg>
      <span class="brand__name"><?= h(SITE_TITLE) ?></span>
    </a>
    <?php if (SITE_TAGLINE !== ''): ?>
      <p class="site-tagline"><?= h(SITE_TAGLINE) ?></p>
    <?php endif; ?>
  </header>

  <?php if ($flash !== null): ?>
    <div class="flash flash--<?= h($flash['type']) ?>" role="status" data-autohide>
      <span class="flash__icon"><?= $flash['type'] === 'ok' ? icon_check() : icon_alert() ?></span>
      <span class="flash__msg"><?= h($flash['message']) ?></span>
    </div>
  <?php endif; ?>

  <section class="compose">
    <form method="post" action="submit.php" class="compose__form">
      <?= csrf_fields() ?>
      <?= hp_field() ?>
      <label class="sr-only" for="compose-text">Write a blurt</label>
      <textarea id="compose-text" name="text" class="compose__text" rows="3"
        maxlength="<?= (int) MAX_POST_LEN ?>"
        placeholder="Blurt something…" required
        data-maxlen="<?= (int) MAX_POST_LEN ?>"><?= h($draft) ?></textarea>
      <div class="compose__bar">
        <span class="compose__identity">
          <?= avatar_html(current_display_name(), current_display_color(), 'sm') ?>
          <span>posting as <strong><?= h(current_display_name()) ?></strong></span>
        </span>
        <span class="compose__count" data-count aria-hidden="true"><?= (int) MAX_POST_LEN ?></span>
        <button type="submit" class="btn btn--primary"><?= icon_send() ?><span>Blurt</span></button>
      </div>
      <p class="compose__note"><?= icon_clock() ?> Every blurt vanishes <?= h(ttl_phrase()) ?> after it's posted.</p>
    </form>
  </section>

  <section class="feed">
    <?php if (empty($pageItems)): ?>
      <div class="empty">
        <span class="empty__bubble" aria-hidden="true"><i></i><i></i><i></i></span>
        <p class="empty__lead">It's quiet in here.</p>
        <p class="empty__sub">Everything vanishes within <?= h(ttl_phrase()) ?> — go ahead, be the first to blurt something.</p>
      </div>
    <?php else: ?>
      <?php foreach ($pageItems as $item): ?>
        <div class="thread">
          <?php render_blurt($item, false, $justPosted, $page); ?>
          <?php
            $pid = (string) ($item['id'] ?? '');
            $replies = $repliesByParent[$pid] ?? [];
          ?>
          <?php if (!empty($replies)): ?>
            <div class="thread__replies">
              <?php foreach ($replies as $reply): ?>
                <?php render_blurt($reply, true, $justPosted, $page); ?>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </section>

  <?php if ($totalPages > 1): ?>
    <nav class="pager" aria-label="Feed pages">
      <?php if ($page > 1): ?>
        <a class="btn btn--link" href="?page=<?= $page - 1 ?>">&larr; Newer</a>
      <?php else: ?>
        <span class="btn btn--link btn--disabled">&larr; Newer</span>
      <?php endif; ?>
      <span class="pager__status">Page <?= (int) $page ?> of <?= (int) $totalPages ?></span>
      <?php if ($page < $totalPages): ?>
        <a class="btn btn--link" href="?page=<?= $page + 1 ?>">Older &rarr;</a>
      <?php else: ?>
        <span class="btn btn--link btn--disabled">Older &rarr;</span>
      <?php endif; ?>
    </nav>
  <?php endif; ?>

  <footer class="site-footer">
    <p class="site-footer__note">No accounts. No history. Everything here is gone in <?= h(ttl_phrase()) ?>.</p>
    <p class="site-footer__by">An AI project by
      <a href="https://jeremehancock.com" target="_blank" rel="noopener noreferrer">Jereme Hancock</a>.</p>
  </footer>
</div>
<script src="assets/app.js" defer></script>
</body>
</html>
