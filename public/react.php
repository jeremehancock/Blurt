<?php
/**
 * react.php — toggle an emoji reaction on a blurt (POST only).
 *
 * A reaction adds (or removes) the visitor's per-session reactor id in the
 * blurt's reactions map for one whitelisted emoji. Each session is one
 * distinct reactor, so counts can't be inflated and nobody can toggle another
 * visitor's reaction. Rate limiting stays keyed on the IP-based author_hash.
 * Works with a plain form POST (redirect back to the blurt) and, when JS is
 * on, answers JSON for a no-reload toggle.
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

// Rate limiting is an abuse control and stays keyed on the IP-based hash.
$authorHash = current_author_hash();
if (!rate_limit_ok($authorHash, REACT_MAX, 'react')) {
    react_respond($wantsJson, ['ok' => false, 'rate' => true], $id, $page);
}

// Reaction dedupe is keyed on the per-session reactor id, so every visitor is
// a distinct reactor (even sharing an IP) and can only toggle their OWN entry.
$reactorId = current_reactor_id();
if ($reactorId === '') {
    react_respond($wantsJson, ['ok' => false], $id, $page);
}

// Toggle under an exclusive lock so two visitors reacting in the same instant
// can't clobber each other's update (read-modify-write is atomic per blurt).
$reacted = false;
$count = 0;
$updated = modify_blurt(
    $found['path'],
    function (array $record) use ($emoji, $reactorId, &$reacted, &$count): ?array {
        if (blurt_is_expired($record)) {
            return null; // vanished mid-flight; abort without writing
        }
        $reactions = is_array($record['reactions'] ?? null) ? $record['reactions'] : [];
        $list = is_array($reactions[$emoji] ?? null) ? $reactions[$emoji] : [];

        // Toggle only THIS visitor's reaction: remove if present, else add.
        $pos = array_search($reactorId, $list, true);
        if ($pos !== false) {
            array_splice($list, $pos, 1);
            $reacted = false;
        } else {
            $list[] = $reactorId;
            $reacted = true;
        }

        if (empty($list)) {
            unset($reactions[$emoji]);          // keep the file tidy
        } else {
            $reactions[$emoji] = array_values($list);
        }
        $record['reactions'] = $reactions;
        $count = count($list);
        return $record;
    }
);

if ($updated === null) {
    react_respond($wantsJson, ['ok' => false], $id, $page);
}

rate_limit_record($authorHash, 'react');

react_respond($wantsJson, [
    'ok' => true,
    'emoji' => $emoji,
    'count' => $count,
    'reacted' => $reacted,
], $id, $page);
