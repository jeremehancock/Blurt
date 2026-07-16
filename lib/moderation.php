<?php
/**
 * moderation.php — blocklist, honeypot, time-trap, rate limit, normalization.
 *
 * NOT web-accessible. All checks here run server-side regardless of any
 * client-side validation. On any failure the caller shows a single generic,
 * friendly error — we never reveal which rule tripped (especially not which
 * blocked word matched).
 */

declare(strict_types=1);

/**
 * Normalize untrusted text before storage. This handles "nefarious text that
 * isn't code": control chars, invisible/bidi trickery, zalgo, etc.
 *
 * Order: strip nulls/control chars -> strip bidi & zero-width -> NFC ->
 * cap combining-mark runs -> collapse excessive blank lines -> trim edges.
 */
function normalize_text(string $text): string
{
    // Ensure we are working with valid UTF-8; drop anything invalid.
    if (function_exists('mb_convert_encoding')) {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }

    // Strip null bytes and C0/C1 control chars, but KEEP newline (\n) and tab (\t).
    // \x00-\x08, \x0B-\x0C, \x0E-\x1F, \x7F, and C1 range \x80-\x9F.
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;
    $text = preg_replace('/[\x{0080}-\x{009F}]/u', '', $text) ?? $text;

    // Strip Unicode bidirectional-override / isolate chars that can hide or
    // reverse text in the feed: U+202A–U+202E and U+2066–U+2069.
    $text = preg_replace('/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $text) ?? $text;

    // Strip zero-width chars: U+200B–U+200D and U+FEFF (BOM/ZWNBSP).
    $text = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $text) ?? $text;

    // Normalize to Unicode NFC when the intl Normalizer is available.
    if (class_exists('Normalizer')) {
        $normalized = \Normalizer::normalize($text, \Normalizer::FORM_C);
        if (is_string($normalized)) {
            $text = $normalized;
        }
    }

    // Cap runs of combining marks to blunt "zalgo" (limit to 2 in a row).
    $text = preg_replace('/(\p{M}{2})\p{M}+/u', '$1', $text) ?? $text;

    // Normalize line endings and collapse 3+ blank lines down to 2 newlines.
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

    return trim($text);
}

/** Character length using mbstring when available, else a UTF-8 fallback. */
function text_length(string $text): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($text, 'UTF-8');
    }
    return strlen(preg_replace('/[\x80-\xBF]/', '', $text) ?? $text);
}

/**
 * Case-insensitive, word-boundary-aware blocklist match.
 * Returns true if any blocked term appears as a whole word/phrase.
 */
function contains_blocked_term(string $text): bool
{
    foreach (blocklist_terms() as $term) {
        $term = trim($term);
        if ($term === '') {
            continue;
        }
        $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($term, '/') . '(?![\p{L}\p{N}])/iu';
        if (preg_match($pattern, $text) === 1) {
            return true;
        }
    }
    return false;
}

/**
 * Validate normalized post text. Returns null when OK, or a generic error
 * string when it should be rejected. Callers must pass ALREADY-normalized text.
 */
function validate_post_text(string $text): ?string
{
    if ($text === '' || trim($text) === '') {
        return 'Your blurt is empty.';
    }
    if (strlen($text) > MAX_POST_BYTES) {
        return 'That blurt is too long.';
    }
    if (text_length($text) > MAX_POST_LEN) {
        return 'That blurt is too long.';
    }
    if (contains_blocked_term($text)) {
        // Deliberately generic — never reveal which word matched.
        return 'Your blurt couldn\'t be posted.';
    }
    return null;
}

/** Honeypot: the hidden field (default name "website") must be empty. */
function honeypot_ok(array $post, string $field = 'website'): bool
{
    return trim((string) ($post[$field] ?? '')) === '';
}

/**
 * Time-trap: the form carries a render timestamp; reject submissions that
 * arrive faster than MIN_SUBMIT_SECS (too fast to be a human).
 */
function time_trap_ok(array $post, string $field = 'rendered_at'): bool
{
    $rendered = (int) ($post[$field] ?? 0);
    if ($rendered <= 0) {
        return false;
    }
    return (time() - $rendered) >= MIN_SUBMIT_SECS;
}

// ---------------------------------------------------------------------------
// Rate limiting, keyed on the invisible IP-based author_hash (NOT the session
// handle). Each client only ever contends with its own per-client file.
// ---------------------------------------------------------------------------

/** Path to the rate file for a given author hash (hash is [0-9a-f]{64}). */
function rate_file_path(string $authorHash): ?string
{
    if (preg_match('/^[0-9a-f]{64}$/', $authorHash) !== 1) {
        return null;
    }
    return RATE_DIR . '/' . $authorHash . '.json';
}

/**
 * Return true if the client is currently within the limit (i.e. allowed to
 * post). Does not record the attempt; call rate_limit_record() on success.
 */
function rate_limit_ok(string $authorHash): bool
{
    $timestamps = rate_load($authorHash);
    return count($timestamps) < RATE_MAX;
}

/** Record a successful action's timestamp for this client. */
function rate_limit_record(string $authorHash): void
{
    storage_init();
    $path = rate_file_path($authorHash);
    if ($path === null) {
        return;
    }
    $timestamps = rate_load($authorHash);
    $timestamps[] = time();
    $json = json_encode(array_values($timestamps));
    if ($json !== false) {
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
            @rename($tmp, $path);
        }
    }
}

/**
 * Load the client's recent timestamps within the current window, pruning old
 * ones. @return int[]
 */
function rate_load(string $authorHash): array
{
    $path = rate_file_path($authorHash);
    if ($path === null || !is_file($path)) {
        return [];
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return [];
    }
    $cutoff = time() - RATE_WINDOW;
    $recent = [];
    foreach ($data as $ts) {
        $ts = (int) $ts;
        if ($ts >= $cutoff) {
            $recent[] = $ts;
        }
    }
    return $recent;
}
