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

    $classes = 'blurt' . ($isReply ? ' blurt--reply' : '');
    echo '<article class="' . $classes . '">';

    echo '<header class="blurt__head">';
    // display_color is a validated hex; safe to place in the style attribute.
    echo '<span class="blurt__dot" style="background-color:' . h($color) . '"></span>';
    echo '<span class="blurt__name">' . $name . '</span>';
    echo '<span class="blurt__time">' . h(time_ago($created)) . '</span>';
    // Ephemeral countdown. Server renders a static value; app.js keeps it live
    // via the data-expires attribute (both optional — purely informational).
    echo '<span class="blurt__expiry" data-expires="' . (int) $expiresAt . '">'
        . h(expiry_label($expiresAt)) . '</span>';
    if ($reportCount >= 1) {
        // Public badge only — never expose the count.
        echo '<span class="blurt__badge" title="This blurt has been reported">reported</span>';
    }
    echo '</header>';

    echo '<div class="blurt__text">' . $textHtml . '</div>';

    echo '<footer class="blurt__actions">';
    if (!$isReply && $id !== '') {
        // Reply affordance — a plain <details> so it works with JS disabled.
        echo '<details class="reply">';
        echo '<summary class="btn btn--link">Reply</summary>';
        echo '<form class="reply__form" method="post" action="submit.php">';
        echo csrf_fields();
        echo '<input type="hidden" name="parent_id" value="' . h($id) . '">';
        echo hp_field();
        echo '<textarea name="text" class="reply__text" rows="2" maxlength="'
            . (int) MAX_POST_LEN . '" placeholder="Post your reply" required></textarea>';
        echo '<button type="submit" class="btn btn--primary btn--small">Reply</button>';
        echo '</form>';
        echo '</details>';
    }
    if ($id !== '') {
        // Report affordance — a tiny POST form.
        echo '<form class="report__form" method="post" action="report.php">';
        echo csrf_fields();
        echo '<input type="hidden" name="id" value="' . h($id) . '">';
        echo '<button type="submit" class="btn btn--link btn--muted">Report</button>';
        echo '</form>';
    }
    echo '</footer>';

    echo '</article>';
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
<body>
<div class="wrap">
  <header class="site-header">
    <h1 class="site-title"><a href="index.php"><?= h(SITE_TITLE) ?></a></h1>
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
        placeholder="What's happening?" required
        data-maxlen="<?= (int) MAX_POST_LEN ?>"></textarea>
      <div class="compose__bar">
        <span class="compose__identity">
          <span class="blurt__dot" style="background-color:<?= h(current_display_color()) ?>"></span>
          posting as <strong><?= h(current_display_name()) ?></strong>
        </span>
        <span class="compose__count" data-count aria-hidden="true"><?= (int) MAX_POST_LEN ?></span>
        <button type="submit" class="btn btn--primary">Blurt</button>
      </div>
      <p class="compose__note">Every blurt vanishes <?= h(ttl_phrase()) ?> after it's posted.</p>
    </form>
  </section>

  <section class="feed">
    <?php if (empty($pageItems)): ?>
      <p class="empty">Nothing here right now — everything vanishes within <?= h(ttl_phrase()) ?>. Be the first to blurt something.</p>
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
    <a href="admin.php">Admin</a>
  </footer>
</div>
<script src="assets/app.js" defer></script>
</body>
</html>
