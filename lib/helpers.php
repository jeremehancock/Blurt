<?php
/**
 * helpers.php — escaping, ids, IP resolution, session, security headers.
 *
 * NOT web-accessible (lives above the docroot).
 */

declare(strict_types=1);

/**
 * Escape a value for safe HTML output. This is THE primary XSS defense:
 * everything a visitor typed is stored raw and escaped only here, at output.
 */
function h($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

/**
 * Send strict security headers. Called at the top of every entrypoint.
 * All scripts/styles are same-origin files, so the CSP forbids inline
 * execution entirely — that is what makes it actually protective.
 */
function send_security_headers(): void
{
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; "
        . "style-src 'self'; img-src 'self' data:; base-uri 'none'; "
        . "form-action 'self'; frame-ancestors 'none'; object-src 'none'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-Frame-Options: DENY');
    header('Content-Type: text/html; charset=utf-8');
}

/**
 * Best-effort detection of whether the visitor's connection is HTTPS.
 * Behind NPM the PHP-facing connection is plain HTTP, but NPM forwards
 * X-Forwarded-Proto — only trusted when REMOTE_ADDR is a trusted proxy.
 */
function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (remote_addr_is_trusted() && isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        return strtolower(trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_PROTO'])[0])) === 'https';
    }
    return false;
}

/**
 * Start the PHP session with hardened cookie parameters and the themed
 * cookie name. Safe to call more than once.
 */
function start_app_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('blurt_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => is_https(),
    ]);
    session_start();
}

/** Parse TRUSTED_PROXIES into an array of trimmed, non-empty IP strings. */
function trusted_proxies(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    foreach (explode(',', TRUSTED_PROXIES) as $ip) {
        $ip = trim($ip);
        if ($ip !== '') {
            $cache[] = $ip;
        }
    }
    return $cache;
}

/** Is the immediate peer (REMOTE_ADDR) one of our trusted proxies? */
function remote_addr_is_trusted(): bool
{
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    return $remote !== '' && in_array($remote, trusted_proxies(), true);
}

/**
 * Resolve the real client IP.
 *
 * $_SERVER['REMOTE_ADDR'] is the proxy when we sit behind NPM, so we consult
 * X-Forwarded-For — but ONLY when REMOTE_ADDR is a trusted proxy. Otherwise we
 * fall back to REMOTE_ADDR so a client cannot spoof its identity by sending a
 * forged header directly. Used for author_hash and rate limiting.
 */
function resolve_client_ip(): string
{
    $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    if (remote_addr_is_trusted() && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // XFF is a comma-separated chain; the left-most is the original client.
        $parts = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
        foreach ($parts as $candidate) {
            $candidate = trim($candidate);
            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return $candidate;
            }
        }
    }

    if (filter_var($remote, FILTER_VALIDATE_IP) !== false) {
        return $remote;
    }
    return '0.0.0.0';
}

/**
 * The invisible moderation identity: sha256(client_ip + APP_SALT).
 * Never shown to users; only powers rate limiting (posts, reactions, admin
 * login attempts) so we never have to retain a raw IP.
 */
function author_hash_for_ip(string $ip): string
{
    return hash('sha256', $ip . APP_SALT);
}

/** Convenience: author hash for the current request's client. */
function current_author_hash(): string
{
    return author_hash_for_ip(resolve_client_ip());
}

/**
 * Generate a chronologically-sortable blurt id: {unix_ts}-{6 hex chars}.
 * Matches the strict id pattern enforced in storage.php.
 */
function generate_blurt_id(): string
{
    return time() . '-' . bin2hex(random_bytes(3));
}

/** A URL-safe random token (used for CSRF). */
function random_token(): string
{
    return bin2hex(random_bytes(32));
}

/** Send a Location redirect and stop. Relative paths keep us same-origin. */
function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

/**
 * Store a one-shot flash message shown on the next page render.
 * Kept intentionally generic so failures never reveal which rule tripped.
 */
function set_flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

/** Retrieve and clear the flash message, if any. */
function take_flash(): ?array
{
    if (!empty($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/**
 * Render stored (raw) blurt text safely for HTML output:
 *   1. escape everything (primary XSS defense),
 *   2. linkify http/https URLs on the ALREADY-escaped string,
 *   3. convert newlines to <br>.
 *
 * Because we escape first, the matched URL substrings are already
 * attribute-safe, and links carry rel="nofollow noopener noreferrer".
 * Only http/https schemes are linkified — javascript:/data: never match.
 */
function render_blurt_text(string $raw): string
{
    $escaped = h($raw);

    $escaped = preg_replace_callback(
        '#\bhttps?://[^\s<]+#u',
        static function (array $m): string {
            $url = $m[0];
            // Move common trailing punctuation outside the link (leave entity
            // sequences like &amp; intact by not trimming & or ;).
            $trail = '';
            while ($url !== '' && strpos('.,!?)]}\'"', substr($url, -1)) !== false) {
                $trail = substr($url, -1) . $trail;
                $url = substr($url, 0, -1);
            }
            if ($url === '') {
                return $m[0];
            }
            return '<a href="' . $url . '" rel="nofollow noopener noreferrer">'
                . $url . '</a>' . $trail;
        },
        $escaped
    ) ?? $escaped;

    return nl2br($escaped, false);
}

/**
 * Absolute base URL of the site (scheme + host), used for social-share meta
 * tags. Host comes from the request; it's validated and escaped by callers.
 */
function site_base_url(): string
{
    $scheme = is_https() ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    if (!preg_match('/^[A-Za-z0-9.\-:\[\]]{1,255}$/', $host)) {
        $host = 'localhost';
    }
    return $scheme . '://' . $host;
}

/**
 * The site favicon as an inline SVG: a gradient speech bubble with three dots
 * (the same "typing bubble" mark used in the header). Returned as raw SVG.
 */
function favicon_svg(): string
{
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32">'
        . '<defs><linearGradient id="b" x1="0" y1="0" x2="1" y2="1">'
        . '<stop offset="0" stop-color="#7c3aed"/>'
        . '<stop offset="1" stop-color="#db2777"/></linearGradient></defs>'
        . '<path fill="url(#b)" d="M10 4 H22 a4 4 0 0 1 4 4 V16 a4 4 0 0 1 -4 4 '
        . 'H13 L6 25 V8 a4 4 0 0 1 4 -4 Z"/>'
        . '<circle cx="11" cy="12" r="2.3" fill="#fff"/>'
        . '<circle cx="16" cy="12" r="2.3" fill="#fff"/>'
        . '<circle cx="21" cy="12" r="2.3" fill="#fff"/></svg>';
}

/**
 * Render the favicon <link> using an inline (data-URI) SVG. The SVG is
 * URL-encoded, so the `#` in colors/gradient refs is escaped (%23) and won't
 * be mistaken for a data-URI fragment. Allowed by the CSP (img-src data:).
 */
function favicon_link(): string
{
    return '<link rel="icon" type="image/svg+xml" href="data:image/svg+xml,'
        . rawurlencode(favicon_svg()) . '">';
}

/**
 * Render a poster's identity avatar: a colored circle with their handle's
 * initial. The color is applied via a stylesheet class (color_class), NOT an
 * inline style, so the strict no-inline CSP stays intact. The initial is
 * escaped. $variant is one of 'md' (feed) or 'sm' (reply/compose).
 */
function avatar_html(string $name, string $color, string $variant = 'md'): string
{
    if (!valid_hex_color($color)) {
        $color = '#7c3aed';
    }
    $trimmed = trim($name);
    if ($trimmed === '') {
        $initial = '?';
    } elseif (function_exists('mb_substr')) {
        $initial = mb_strtoupper(mb_substr($trimmed, 0, 1, 'UTF-8'), 'UTF-8');
    } else {
        $initial = strtoupper(substr($trimmed, 0, 1));
    }
    $cls = 'avatar avatar--' . ($variant === 'sm' ? 'sm' : 'md')
        . ' ' . color_class($color);
    return '<span class="' . $cls . '" aria-hidden="true">' . h($initial) . '</span>';
}

/**
 * Format a unix timestamp as a compact "time ago" string for the feed.
 * Purely cosmetic.
 */
function time_ago(int $ts): string
{
    $diff = time() - $ts;
    if ($diff < 0) {
        $diff = 0;
    }
    if ($diff < 60) {
        return 'just now';
    }
    if ($diff < 3600) {
        $m = (int) floor($diff / 60);
        return $m . 'm ago';
    }
    if ($diff < 86400) {
        $hr = (int) floor($diff / 3600);
        return $hr . 'h ago';
    }
    if ($diff < 2592000) {
        $d = (int) floor($diff / 86400);
        return $d . 'd ago';
    }
    return date('M j, Y', $ts);
}

/**
 * A short, on-brand label for how long until a blurt vanishes, e.g.
 * "vanishes in 23h" / "vanishes in 8m" / "vanishing…". Reinforces the
 * ephemeral identity. $expiresAt is a unix timestamp (created_at + POST_TTL).
 */
function expiry_label(int $expiresAt): string
{
    $remaining = $expiresAt - time();
    if ($remaining <= 0) {
        return 'vanishing…';
    }
    if ($remaining < 60) {
        return 'vanishes in <1m';
    }
    if ($remaining < 3600) {
        return 'vanishes in ' . (int) floor($remaining / 60) . 'm';
    }
    if ($remaining < 86400) {
        return 'vanishes in ' . (int) floor($remaining / 3600) . 'h';
    }
    return 'vanishes in ' . (int) floor($remaining / 86400) . 'd';
}

/**
 * Bucket a blurt's remaining life into a freshness level 0–4, where 0 is
 * freshly posted and 4 is about to vanish. Drives the "fade as it ages" look
 * (age-N classes) so the feed visibly reflects the ephemeral lifetime.
 */
function freshness_bucket(int $expiresAt, ?int $now = null): int
{
    $now = $now ?? time();
    $remaining = $expiresAt - $now;
    if ($remaining <= 0) {
        return 4;
    }
    $ratio = POST_TTL > 0 ? $remaining / POST_TTL : 1.0;
    if ($ratio >= 0.60) {
        return 0;
    }
    if ($ratio >= 0.40) {
        return 1;
    }
    if ($ratio >= 0.20) {
        return 2;
    }
    if ($ratio >= 0.08) {
        return 3;
    }
    return 4;
}

/** Human phrase for the configured lifetime, e.g. "24 hours" / "90 minutes". */
function ttl_phrase(): string
{
    $ttl = POST_TTL;
    // Prefer hours for anything under two days, so the default 24h reads as
    // "24 hours" (on brand) rather than "1 day".
    if ($ttl % 3600 === 0 && $ttl < 172800) {
        $h = intdiv($ttl, 3600);
        return $h . ' ' . ($h === 1 ? 'hour' : 'hours');
    }
    if ($ttl % 86400 === 0) {
        $d = intdiv($ttl, 86400);
        return $d . ' ' . ($d === 1 ? 'day' : 'days');
    }
    if ($ttl % 3600 === 0) {
        $h = intdiv($ttl, 3600);
        return $h . ' ' . ($h === 1 ? 'hour' : 'hours');
    }
    $m = max(1, (int) round($ttl / 60));
    return $m . ' ' . ($m === 1 ? 'minute' : 'minutes');
}
