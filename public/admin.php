<?php
/**
 * admin.php — session-gated moderation area.
 *
 * Login verifies the posted password against ADMIN_PASSWORD_HASH with
 * password_verify(). All mutations are POST + CSRF-protected, and every
 * blurt id is validated through storage.php before any filesystem action.
 *
 * Note: since Blurt already sits behind NPM, you may ALSO (or instead) protect
 * /admin at the proxy with an Access List (Basic Auth). The in-app session auth
 * here is the built-in default so the app works standalone in local dev.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

send_security_headers();
start_app_session();

$notice = null;
$noticeType = 'ok';

// Blurts are ephemeral; keep the admin views in step with the lazy sweep.
maybe_purge_expired();

// ---------------------------------------------------------------------------
// Handle POST actions.
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'login') {
        // Login form has no session yet, but still carries a CSRF token.
        if (!csrf_check($_POST['csrf_token'] ?? null)) {
            $notice = 'Session expired. Please try again.';
            $noticeType = 'error';
        } elseif (admin_login((string) ($_POST['password'] ?? ''))) {
            redirect('admin.php');
        } else {
            $notice = 'Incorrect password.';
            $noticeType = 'error';
        }
    } elseif ($action === 'logout') {
        if (csrf_check($_POST['csrf_token'] ?? null)) {
            admin_logout();
        }
        redirect('admin.php');
    } else {
        // All other actions require an authenticated admin + valid CSRF.
        if (!is_admin() || !csrf_check($_POST['csrf_token'] ?? null)) {
            $notice = 'Not authorized.';
            $noticeType = 'error';
        } else {
            $id = (string) ($_POST['id'] ?? '');
            [$notice, $noticeType] = admin_do_action($action, $id);
        }
    }
}

/**
 * Perform an admin mutation. Returns [message, type].
 * @return array{0:string,1:string}
 */
function admin_do_action(string $action, string $id): array
{
    $found = find_blurt($id); // validates the id + resolves path safely
    if ($found === null) {
        return ['That blurt no longer exists.', 'error'];
    }

    switch ($action) {
        case 'hide':
            if ($found['dir'] !== BLURTS_DIR) {
                return ['That blurt is already hidden.', 'error'];
            }
            $record = read_blurt_file($found['path']) ?? [];
            $record['hidden_by'] = 'admin';
            update_blurt($found['path'], $record);
            $newPath = move_blurt($id, BLURTS_DIR, HIDDEN_DIR);
            if ($newPath === null) {
                return ['Could not hide that blurt.', 'error'];
            }
            update_blurt($newPath, $record);
            return ['Blurt hidden.', 'ok'];

        case 'restore':
            if ($found['dir'] !== HIDDEN_DIR) {
                return ['That blurt is already visible.', 'error'];
            }
            $record = read_blurt_file($found['path']) ?? [];
            // Restore to a clean slate.
            $record['report_count'] = 0;
            $record['reporter_hashes'] = [];
            $record['hidden_by'] = null;
            update_blurt($found['path'], $record);
            $newPath = move_blurt($id, HIDDEN_DIR, BLURTS_DIR);
            if ($newPath === null) {
                return ['Could not restore that blurt.', 'error'];
            }
            update_blurt($newPath, $record);
            return ['Blurt restored.', 'ok'];

        case 'delete':
            if (delete_blurt($id)) {
                return ['Blurt permanently deleted.', 'ok'];
            }
            return ['Could not delete that blurt.', 'error'];

        default:
            return ['Unknown action.', 'error'];
    }
}

/** Render one blurt row in the admin table. */
function admin_row(array $rec, bool $hidden): void
{
    $name = h((string) ($rec['display_name'] ?? 'Anon'));
    $color = (string) ($rec['display_color'] ?? '#666666');
    if (!valid_hex_color($color)) {
        $color = '#666666';
    }
    $id = (string) ($rec['id'] ?? '');
    $created = (int) ($rec['created_at'] ?? 0);
    $reportCount = (int) ($rec['report_count'] ?? 0);
    $hiddenBy = $rec['hidden_by'] ?? null;
    $textHtml = render_blurt_text((string) ($rec['text'] ?? ''));
    $isReply = ($rec['parent_id'] ?? null) !== null;

    echo '<article class="admin-blurt">';
    echo avatar_html($rec['display_name'] ?? '', $color, $isReply ? 'sm' : 'md');
    echo '<div class="blurt__body">';
    echo '<header class="blurt__head">';
    echo '<span class="blurt__name">' . $name . '</span>';
    echo '<span class="blurt__time">' . h(date('Y-m-d H:i', $created)) . '</span>';
    echo '<span class="blurt__expiry">' . h(expiry_label(blurt_expires_at($rec))) . '</span>';
    if ($isReply) {
        echo '<span class="admin-tag">reply</span>';
    }
    if (!$hidden && $reportCount >= 1) {
        echo '<span class="blurt__badge">reported &times;' . (int) $reportCount . '</span>';
    }
    if ($hidden) {
        $reason = $hiddenBy === 'admin' ? 'hidden by admin'
            : ($hiddenBy === 'reports' ? 'auto-hidden (reports)' : 'hidden');
        echo '<span class="admin-tag admin-tag--hidden">' . h($reason)
            . ' &middot; ' . (int) $reportCount . ' reports</span>';
    }
    echo '</header>';

    echo '<div class="blurt__text">' . $textHtml . '</div>';

    echo '<footer class="admin-actions">';
    if ($hidden) {
        admin_action_form('restore', $id, 'Restore', 'btn--primary');
    } else {
        admin_action_form('hide', $id, 'Hide', 'btn--warn');
    }
    admin_action_form('delete', $id, 'Delete', 'btn--danger');
    echo '</footer>';
    echo '</div>'; // .blurt__body
    echo '</article>';
}

/** Render a single CSRF-protected admin action form. */
function admin_action_form(string $action, string $id, string $label, string $btnClass): void
{
    echo '<form method="post" action="admin.php" class="admin-action">';
    echo csrf_fields();
    echo '<input type="hidden" name="action" value="' . h($action) . '">';
    echo '<input type="hidden" name="id" value="' . h($id) . '">';
    echo '<button type="submit" class="btn btn--small ' . h($btnClass) . '">'
        . h($label) . '</button>';
    echo '</form>';
}

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin &middot; <?= h(SITE_TITLE) ?></title>
<?= favicon_link() ?>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="wrap">
  <header class="site-header">
    <h1 class="site-title"><a href="index.php"><?= h(SITE_TITLE) ?></a> <small>admin</small></h1>
  </header>

  <?php if ($notice !== null): ?>
    <div class="flash flash--<?= h($noticeType) ?>" role="status"><?= h($notice) ?></div>
  <?php endif; ?>

  <?php if (!is_admin()): ?>
    <?php if (ADMIN_PASSWORD_HASH === ''): ?>
      <div class="flash flash--error">
        Admin is not configured. Set the <code>ADMIN_PASSWORD_HASH</code>
        environment variable to enable login (see the README).
      </div>
    <?php endif; ?>
    <section class="compose">
      <form method="post" action="admin.php" class="compose__form">
        <?= csrf_fields() ?>
        <input type="hidden" name="action" value="login">
        <label class="sr-only" for="admin-password">Admin password</label>
        <input type="password" id="admin-password" name="password"
          class="compose__text" placeholder="Admin password" autocomplete="current-password" required>
        <div class="compose__bar">
          <span></span>
          <button type="submit" class="btn btn--primary">Log in</button>
        </div>
      </form>
    </section>
    <footer class="site-footer"><a href="index.php">&larr; Back to feed</a></footer>
  <?php else: ?>
    <?php
      $now = time();
      $notExpired = static fn($r) => !blurt_is_expired($r, $now);
      $visible = array_filter(load_all_visible(), $notExpired);
      // Newest first for the admin view.
      usort($visible, static fn($a, $b) => ($b['created_at'] ?? 0) <=> ($a['created_at'] ?? 0));
      $hidden = array_filter(load_all_hidden(), $notExpired);
      usort($hidden, static fn($a, $b) => ($b['created_at'] ?? 0) <=> ($a['created_at'] ?? 0));
      $reportedCount = 0;
      foreach ($visible as $v) {
          if ((int) ($v['report_count'] ?? 0) >= 1) {
              $reportedCount++;
          }
      }
    ?>
    <div class="admin-toolbar">
      <span>Visible: <strong><?= count($visible) ?></strong>
        (reported: <strong><?= (int) $reportedCount ?></strong>) &middot;
        Hidden: <strong><?= count($hidden) ?></strong></span>
      <form method="post" action="admin.php" class="admin-action">
        <?= csrf_fields() ?>
        <input type="hidden" name="action" value="logout">
        <button type="submit" class="btn btn--link">Log out</button>
      </form>
    </div>

    <h2 class="admin-heading">Visible blurts</h2>
    <section class="admin-list">
      <?php if (empty($visible)): ?>
        <p class="empty">No visible blurts.</p>
      <?php else: ?>
        <?php foreach ($visible as $rec): ?>
          <?php admin_row($rec, false); ?>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>

    <h2 class="admin-heading">Hidden blurts</h2>
    <section class="admin-list">
      <?php if (empty($hidden)): ?>
        <p class="empty">No hidden blurts.</p>
      <?php else: ?>
        <?php foreach ($hidden as $rec): ?>
          <?php admin_row($rec, true); ?>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>

    <footer class="site-footer"><a href="index.php">&larr; Back to feed</a></footer>
  <?php endif; ?>
</div>
</body>
</html>
