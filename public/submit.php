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

$rawText = (string) ($_POST['text'] ?? '');
$isReply = trim((string) ($_POST['parent_id'] ?? '')) !== '';

// Where the visitor came from (replies carry the feed page they were on, so
// they land back where their reply is threaded instead of on page 1).
$backPage = max(1, (int) ($_POST['page'] ?? 1));
$backTo = 'index.php' . ($isReply && $backPage > 1 ? '?page=' . $backPage : '');

/**
 * Bounce back with an error flash. Top-level drafts are kept in the session so
 * a tripped rate limit or size cap never eats what the visitor typed.
 */
function submit_fail(string $message, string $backTo, string $rawText, bool $isReply): void
{
    if (!$isReply && $rawText !== '') {
        $_SESSION['draft'] = $rawText;
    }
    set_flash('error', $message);
    redirect($backTo);
}

// --- CSRF + time-trap (one field pair covers both) -------------------------
if (!csrf_check($_POST['csrf_token'] ?? null) || !time_trap_ok($_POST)) {
    submit_fail($genericError, $backTo, $rawText, $isReply);
}

// --- Honeypot --------------------------------------------------------------
if (!honeypot_ok($_POST)) {
    // Silently pretend success to a bot: bounce back with no stored blurt.
    redirect('index.php');
}

// --- Rate limit (keyed on the invisible IP-based author_hash) --------------
$authorHash = current_author_hash();
if (!rate_limit_ok($authorHash)) {
    submit_fail('You\'re posting a bit too fast. Give it a moment.', $backTo, $rawText, $isReply);
}

// --- Text: normalize, validate, then censor blocklisted words --------------
$text = normalize_text($rawText);
$textError = validate_post_text($text);
if ($textError !== null) {
    submit_fail($textError, $backTo, $rawText, $isReply);
}
// Let the post through, but mask any blocklisted words rather than rejecting.
$text = censor_blocked_terms($text);

// --- Reply target validation -----------------------------------------------
$parentId = null;
$rawParent = trim((string) ($_POST['parent_id'] ?? ''));
if ($rawParent !== '') {
    // Must be a valid id AND an existing, visible, top-level blurt.
    $found = find_blurt($rawParent);
    if ($found === null || $found['dir'] !== BLURTS_DIR) {
        submit_fail($genericError, $backTo, $rawText, $isReply);
    }
    $parent = read_blurt_file($found['path']);
    if ($parent === null || ($parent['parent_id'] ?? null) !== null) {
        // Keep replies single-level: cannot reply to a reply.
        submit_fail($genericError, $backTo, $rawText, $isReply);
    }
    if (blurt_is_expired($parent)) {
        // The parent has vanished (or is about to); don't orphan a reply on it.
        submit_fail($genericError, $backTo, $rawText, $isReply);
    }
    $parentId = (string) $parent['id'];
}

// --- Build and persist the record ------------------------------------------
$record = [
    'id' => '',
    'parent_id' => $parentId,
    'text' => $text, // stored raw; escaped only at output
    'created_at' => time(),
    'display_name' => current_display_name(),
    'display_color' => current_display_color(),
    'reactions' => new stdClass(), // emoji => [reactor_id, …], added on first react
    'hidden_by' => null,
    'author_hash' => $authorHash,
];

// Retry on the (vanishingly rare) same-second id collision instead of failing.
$created = false;
for ($attempt = 0; $attempt < 3 && !$created; $attempt++) {
    $record['id'] = generate_blurt_id();
    $created = create_blurt($record);
}
if (!$created) {
    submit_fail($genericError, $backTo, $rawText, $isReply);
}

// Record the successful post against the rate limit.
rate_limit_record($authorHash);

// The post went through: drop any preserved draft and remember which blurt was
// just posted so the feed can pop it into view. No scroll anchor: the feed
// lands at the top and the new blurt — which sorts to the top — pops in place.
unset($_SESSION['draft']);
$_SESSION['just_posted'] = $record['id'];

set_flash('ok', $parentId === null ? 'Blurted!' : 'Reply posted!');
redirect($backTo);
