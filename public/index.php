<?php
/**
 * index.php — the feed + compose form (server-rendered).
 *
 * Works fully without JavaScript: the compose form and every reply/report
 * control are plain POST forms. app.js only layers on small niceties.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

send_security_headers();
start_app_session();
ensure_identity();

$flash = take_flash();

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
function render_blurt(array $rec, bool $isReply): void
{
    $name = h((string) ($rec['display_name'] ?? 'Anon'));
    $color = (string) ($rec['display_color'] ?? '#666666');
    if (!valid_hex_color($color)) {
        $color = '#666666';
    }
    $id = (string) ($rec['id'] ?? '');
    $created = (int) ($rec['created_at'] ?? 0);
    $expiresAt = blurt_expires_at($rec);
    $reportCount = (int) ($rec['report_count'] ?? 0);
    $textHtml = render_blurt_text((string) ($rec['text'] ?? ''));

    // Freshness drives the "fade as it ages" look; data-expires lets app.js
    // keep both the fade and the countdown live.
    $bucket = freshness_bucket($expiresAt);
    $classes = 'blurt age-' . $bucket . ($isReply ? ' blurt--reply' : '');
    echo '<article class="' . $classes . '" data-expires="' . (int) $expiresAt . '">';

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
    if ($reportCount >= 1) {
        // Public badge only — never expose the count.
        echo '<span class="blurt__badge" title="This blurt has been reported">reported</span>';
    }
    echo '</header>';

    echo '<div class="blurt__bubble"><div class="blurt__text">' . $textHtml . '</div></div>';

    echo '<footer class="blurt__actions">';
    if (!$isReply && $id !== '') {
        // Reply affordance — a plain <details> so it works with JS disabled.
        echo '<details class="reply">';
        echo '<summary class="act">' . icon_reply() . '<span>Reply</span></summary>';
        echo '<form class="reply__form" method="post" action="submit.php">';
        echo csrf_fields();
        echo '<input type="hidden" name="parent_id" value="' . h($id) . '">';
        echo hp_field();
        echo '<textarea name="text" class="reply__text" rows="2" maxlength="'
            . (int) MAX_POST_LEN . '" placeholder="Post your reply…" required></textarea>';
        echo '<button type="submit" class="btn btn--primary btn--small">Reply</button>';
        echo '</form>';
        echo '</details>';
    }
    if ($id !== '') {
        // Report affordance — a tiny POST form.
        echo '<form class="report__form" method="post" action="report.php">';
        echo csrf_fields();
        echo '<input type="hidden" name="id" value="' . h($id) . '">';
        echo '<button type="submit" class="act">' . icon_flag() . '<span>Report</span></button>';
        echo '</form>';
    }
    echo '</footer>';

    echo '</div>'; // .blurt__body
    echo '</article>';
}

/** Small inline SVG icons (static markup — no CSP concern). */
function icon_reply(): string
{
    return '<svg class="ico" width="15" height="15" viewBox="0 0 24 24" fill="none" '
        . 'stroke="currentColor" stroke-width="2" stroke-linecap="round" '
        . 'stroke-linejoin="round" aria-hidden="true">'
        . '<path d="M9 17l-5-5 5-5"/><path d="M4 12h11a5 5 0 0 1 5 5v1"/></svg>';
}

function icon_flag(): string
{
    return '<svg class="ico" width="15" height="15" viewBox="0 0 24 24" fill="none" '
        . 'stroke="currentColor" stroke-width="2" stroke-linecap="round" '
        . 'stroke-linejoin="round" aria-hidden="true">'
        . '<path d="M4 21V4"/><path d="M4 4h12l-1.6 4L16 12H4"/></svg>';
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
<link rel="stylesheet" href="assets/style.css">
</head>
<body data-ttl="<?= (int) POST_TTL ?>">
<div class="wrap">
  <header class="site-header">
    <a class="brand" href="index.php" aria-label="<?= h(SITE_TITLE) ?> — home">
      <span class="brand__bubble" aria-hidden="true"><i></i><i></i><i></i></span>
      <span class="brand__name"><?= h(SITE_TITLE) ?></span>
    </a>
    <?php if (SITE_TAGLINE !== ''): ?>
      <p class="site-tagline"><?= h(SITE_TAGLINE) ?></p>
    <?php endif; ?>
  </header>

  <?php if ($flash !== null): ?>
    <div class="flash flash--<?= h($flash['type']) ?>" role="status">
      <?= h($flash['message']) ?>
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
        data-maxlen="<?= (int) MAX_POST_LEN ?>"></textarea>
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
          <?php render_blurt($item, false); ?>
          <?php
            $pid = (string) ($item['id'] ?? '');
            $replies = $repliesByParent[$pid] ?? [];
          ?>
          <?php if (!empty($replies)): ?>
            <div class="thread__replies">
              <?php foreach ($replies as $reply): ?>
                <?php render_blurt($reply, true); ?>
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
    <p>No accounts. No history. Everything here is gone in <?= h(ttl_phrase()) ?>.</p>
  </footer>
</div>
<script src="assets/app.js" defer></script>
</body>
</html>
