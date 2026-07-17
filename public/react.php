<?php
/**
 * react.php — toggle an emoji reaction on a blurt (POST only).
 *
 * A reaction adds (or removes) the visitor's invisible IP-based author_hash
 * to the blurt's reactions map for one whitelisted emoji. Distinct reactors
 * only, so a client can't inflate a count. Works with a plain form POST
 * (redirect back to the blurt) and, when JS is on, answers JSON for a
 * no-reload toggle.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

send_security_headers();
start_app_session();
ensure_identity();

// Does the client want a JSON answer (progressive enhancement)?
$wantsJson = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';

/** Reply with JSON (enhanced) or redirect back to the blurt (plain form). */
function react_respond(bool $wantsJson, array $payload, string $id, string $page): void
{
    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload);
        exit;
    }
    $frag = ($id !== '' ? '#b-' . rawurlencode($id) : '');
    $q = ($page !== '' && $page !== '1') ? ('?page=' . rawurlencode($page)) : '';
    redirect('index.php' . $q . $frag);
}

$id = (string) ($_POST['id'] ?? '');
$page = (string) ($_POST['page'] ?? '1');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST'
    || !csrf_check($_POST['csrf_token'] ?? null)) {
    react_respond($wantsJson, ['ok' => false], $id, $page);
}

maybe_purge_expired();

$emoji = (string) ($_POST['emoji'] ?? '');
if (!is_valid_reaction($emoji)) {
    react_respond($wantsJson, ['ok' => false], $id, $page);
}

// Only react to a visible blurt; validate + resolve the id via storage.php.
$found = find_blurt($id);
if ($found === null || $found['dir'] !== BLURTS_DIR) {
    react_respond($wantsJson, ['ok' => false], $id, $page);
}

$authorHash = current_author_hash();

// Light, dedicated rate limit so reaction spam can't churn the disk.
if (!rate_limit_ok($authorHash, REACT_MAX, 'react')) {
    react_respond($wantsJson, ['ok' => false, 'rate' => true], $id, $page);
}

$record = read_blurt_file($found['path']);
if ($record === null || blurt_is_expired($record)) {
    react_respond($wantsJson, ['ok' => false], $id, $page);
}

$reactions = $record['reactions'] ?? [];
if (!is_array($reactions)) {
    $reactions = [];
}
$list = (isset($reactions[$emoji]) && is_array($reactions[$emoji])) ? $reactions[$emoji] : [];

// Toggle: remove if already reacted, otherwise add. Distinct reactors only.
$pos = array_search($authorHash, $list, true);
if ($pos !== false) {
    array_splice($list, $pos, 1);
    $reacted = false;
} else {
    $list[] = $authorHash;
    $reacted = true;
}

if (empty($list)) {
    unset($reactions[$emoji]);          // keep the file tidy
} else {
    $reactions[$emoji] = array_values($list);
}
$record['reactions'] = $reactions;

update_blurt($found['path'], $record);
rate_limit_record($authorHash, 'react');

react_respond($wantsJson, [
    'ok' => true,
    'emoji' => $emoji,
    'count' => count($list),
    'reacted' => $reacted,
], $id, $page);
