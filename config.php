<?php
/**
 * config.php — central configuration and bootstrap.
 *
 * Reads settings from environment variables with safe defaults.
 * NO SECRETS live in this file; provide them via the environment.
 *
 * This file also wires up the shared library includes so that each
 * entrypoint in public/ only has to `require` this one file.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Paths. Application code and data deliberately live ABOVE the webroot
// (public/) so raw JSON and includes are never web-accessible.
// ---------------------------------------------------------------------------
define('APP_ROOT', __DIR__);
define('LIB_PATH', APP_ROOT . '/lib');
define('DATA_PATH', APP_ROOT . '/data');
define('BLURTS_DIR', DATA_PATH . '/blurts');   // visible blurts
define('HIDDEN_DIR', DATA_PATH . '/hidden');   // hidden blurts
define('RATE_DIR', DATA_PATH . '/rate');       // per-client rate tracking

// ---------------------------------------------------------------------------
// Small helper to read an env var with a default. Checks getenv() and the
// $_SERVER/$_ENV superglobals so it works under CLI server, Apache, php-fpm.
// ---------------------------------------------------------------------------
function env_str(string $key, string $default = ''): string
{
    $v = getenv($key);
    if ($v === false || $v === '') {
        $v = $_SERVER[$key] ?? $_ENV[$key] ?? '';
    }
    return $v === '' ? $default : (string) $v;
}

function env_int(string $key, int $default): int
{
    $v = env_str($key, '');
    return $v === '' ? $default : (int) $v;
}

// ---------------------------------------------------------------------------
// Secrets & deployment settings (from environment only).
// ---------------------------------------------------------------------------

// Display name of the site.
define('SITE_TITLE', env_str('SITE_TITLE', 'Blurt'));

// Optional short tagline under the header. Empty string hides it.
// Ephemerality is core to Blurt's identity, so the default says so.
define('SITE_TAGLINE', env_str('SITE_TAGLINE', 'Anonymous, and gone in 24 hours.'));

// bcrypt/argon hash of the admin password (see README to generate one).
// Empty by default: admin login is effectively disabled until you set this.
define('ADMIN_PASSWORD_HASH', env_str('ADMIN_PASSWORD_HASH', ''));

// Salt mixed into the IP hash. CHANGE THIS in production via the env var.
// The default is intentionally weak and only meant to let local dev run.
define('APP_SALT', env_str('APP_SALT', 'change-me-in-production'));

// Comma-separated list of proxy IPs we trust X-Forwarded-For from
// (e.g. your Nginx Proxy Manager container/host IP). Empty = trust none.
define('TRUSTED_PROXIES', env_str('TRUSTED_PROXIES', ''));

// ---------------------------------------------------------------------------
// Tunable constants. Adjust here (or via env) to taste.
// ---------------------------------------------------------------------------

// Max post length in (Unicode) characters.
define('MAX_POST_LEN', env_int('MAX_POST_LEN', 280));

// Hard cap on raw byte length, independent of character count. Guards against
// multibyte/combining-mark bloat even when the character count looks fine.
define('MAX_POST_BYTES', env_int('MAX_POST_BYTES', 4096));

// Reject submissions that arrive faster than this many seconds after render
// (bot / spam speed-trap).
define('MIN_SUBMIT_SECS', env_int('MIN_SUBMIT_SECS', 2));

// Rate limit: at most RATE_MAX blurts per RATE_WINDOW seconds per client.
define('RATE_MAX', env_int('RATE_MAX', 5));
define('RATE_WINDOW', env_int('RATE_WINDOW', 60));

// Reaction rate limit: at most REACT_MAX reactions per RATE_WINDOW per client
// (its own budget, so reacting never eats into the post limit).
define('REACT_MAX', env_int('REACT_MAX', 30));

// Top-level blurts shown per page in the feed.
define('PER_PAGE', env_int('PER_PAGE', 20));

// Ephemerality: how long (in seconds) a blurt lives before it's removed.
// This is a defining feature of Blurt — every blurt vanishes POST_TTL seconds
// after it was posted (replies included). Default: 24 hours.
define('POST_TTL', env_int('POST_TTL', 86400));

// Minimum seconds between expiry sweeps. Cleanup happens lazily on normal
// requests (no cron needed); throttling avoids scanning on every hit.
define('PURGE_INTERVAL', env_int('PURGE_INTERVAL', 60));

// ---------------------------------------------------------------------------
// Wire up shared library includes. Order matters: helpers first.
// ---------------------------------------------------------------------------
require_once LIB_PATH . '/helpers.php';
require_once LIB_PATH . '/words.php';
require_once LIB_PATH . '/blocklist.php';
require_once LIB_PATH . '/storage.php';
require_once LIB_PATH . '/identity.php';
require_once LIB_PATH . '/moderation.php';
require_once LIB_PATH . '/auth.php';
