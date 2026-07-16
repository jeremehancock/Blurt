<?php
/**
 * submit.php — handle a new blurt or a reply (POST only).
 *
 * All checks run server-side. On any failure we redirect back with a single
 * generic, friendly flash message that never reveals which rule tripped.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

send_security_headers();
start_app_session();
ensure_identity();

// Only POST creates blurts.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect('index.php');
}

// Opportunistic ephemeral cleanup (throttled, no cron needed).
maybe_purge_expired();

$genericError = 'Sorry, your blurt couldn\'t be posted. Please try again.';

// --- CSRF + time-trap (one field pair covers both) -------------------------
if (!csrf_check($_POST['csrf_token'] ?? null) || !time_trap_ok($_POST)) {
    set_flash('error', $genericError);
    redirect('index.php');
}

// --- Honeypot --------------------------------------------------------------
if (!honeypot_ok($_POST)) {
    // Silently pretend success to a bot: bounce back with no stored blurt.
    redirect('index.php');
}

// --- Rate limit (keyed on the invisible IP-based author_hash) --------------
$authorHash = current_author_hash();
if (!rate_limit_ok($authorHash)) {
    set_flash('error', 'You\'re posting a bit too fast. Give it a moment.');
    redirect('index.php');
}

// --- Text: normalize, then validate ----------------------------------------
$rawText = (string) ($_POST['text'] ?? '');
$text = normalize_text($rawText);
$textError = validate_post_text($text);
if ($textError !== null) {
    set_flash('error', $genericError);
    redirect('index.php');
}

// --- Reply target validation -----------------------------------------------
$parentId = null;
$rawParent = trim((string) ($_POST['parent_id'] ?? ''));
if ($rawParent !== '') {
    // Must be a valid id AND an existing, visible, top-level blurt.
    $found = find_blurt($rawParent);
    if ($found === null || $found['dir'] !== BLURTS_DIR) {
        set_flash('error', $genericError);
        redirect('index.php');
    }
    $parent = read_blurt_file($found['path']);
    if ($parent === null || ($parent['parent_id'] ?? null) !== null) {
        // Keep replies single-level: cannot reply to a reply.
        set_flash('error', $genericError);
        redirect('index.php');
    }
    if (blurt_is_expired($parent)) {
        // The parent has vanished (or is about to); don't orphan a reply on it.
        set_flash('error', $genericError);
        redirect('index.php');
    }
    $parentId = (string) $parent['id'];
}

// --- Build and persist the record ------------------------------------------
$record = [
    'id' => generate_blurt_id(),
    'parent_id' => $parentId,
    'text' => $text, // stored raw; escaped only at output
    'created_at' => time(),
    'display_name' => current_display_name(),
    'display_color' => current_display_color(),
    'report_count' => 0,
    'reporter_hashes' => [],
    'hidden_by' => null,
    'author_hash' => $authorHash,
];

if (!create_blurt($record)) {
    set_flash('error', $genericError);
    redirect('index.php');
}

// Record the successful post against the rate limit.
rate_limit_record($authorHash);

set_flash('ok', $parentId === null ? 'Blurted!' : 'Reply posted!');
redirect('index.php');
