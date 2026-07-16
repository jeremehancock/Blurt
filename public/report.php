<?php
/**
 * report.php — handle a report of a blurt (POST only).
 *
 * A report adds the reporter's invisible author_hash to the blurt's
 * reporter_hashes (distinct reporters only). The first distinct report flags
 * the blurt as "reported" (still visible); reaching HIDE_REPORT_THRESHOLD
 * distinct reporters moves it to data/hidden/.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

send_security_headers();
start_app_session();
ensure_identity();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect('index.php');
}

// Opportunistic ephemeral cleanup (throttled, no cron needed).
maybe_purge_expired();

// Generic acknowledgement — we don't reveal whether anything changed.
$thanks = 'Thanks — that blurt has been reported.';

if (!csrf_check($_POST['csrf_token'] ?? null)) {
    set_flash('error', 'Sorry, that didn\'t work. Please try again.');
    redirect('index.php');
}

$id = (string) ($_POST['id'] ?? '');

// Validate the id and resolve the file safely via storage.php.
$found = find_blurt($id);
if ($found === null || $found['dir'] !== BLURTS_DIR) {
    // Unknown or already-hidden blurt: acknowledge generically regardless.
    set_flash('ok', $thanks);
    redirect('index.php');
}

$authorHash = current_author_hash();

// Optional: rate-limit reports the same way blurts are limited, to blunt
// report spam. Reuses the same per-client window.
if (!rate_limit_ok($authorHash)) {
    set_flash('ok', $thanks);
    redirect('index.php');
}

$record = read_blurt_file($found['path']);
if ($record === null || blurt_is_expired($record)) {
    // Nothing (or nothing live) to report; acknowledge generically.
    set_flash('ok', $thanks);
    redirect('index.php');
}

$reporters = $record['reporter_hashes'] ?? [];
if (!is_array($reporters)) {
    $reporters = [];
}

// Distinct reporters only — the same visitor can't report twice.
if (in_array($authorHash, $reporters, true)) {
    set_flash('ok', $thanks);
    redirect('index.php');
}

$reporters[] = $authorHash;
$record['reporter_hashes'] = array_values($reporters);
$record['report_count'] = count($record['reporter_hashes']);

if ($record['report_count'] >= HIDE_REPORT_THRESHOLD) {
    // Auto-hide: record the reason, then move the file into data/hidden/.
    $record['hidden_by'] = 'reports';
    // Persist the updated counts first so the moved file is up to date.
    update_blurt($found['path'], $record);
    $newPath = move_blurt((string) $record['id'], BLURTS_DIR, HIDDEN_DIR);
    if ($newPath !== null) {
        update_blurt($newPath, $record);
    }
} else {
    // Still visible; just flag it as reported.
    update_blurt($found['path'], $record);
}

// Count this report against the reporter's rate window.
rate_limit_record($authorHash);

set_flash('ok', $thanks);
redirect('index.php');
