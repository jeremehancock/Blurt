<?php
/**
 * auth.php — admin session login/logout + CSRF helpers.
 *
 * NOT web-accessible. The CSRF token doubles as the time-trap partner: every
 * form carries the token plus a render timestamp, covering both CSRF and
 * bot-speed with one field pair.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// CSRF
// ---------------------------------------------------------------------------

/** Get (or lazily create) the per-session CSRF token. */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = random_token();
    }
    return $_SESSION['csrf_token'];
}

/** Constant-time check of a submitted CSRF token. */
function csrf_check(?string $token): bool
{
    if (!is_string($token) || $token === '' || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals((string) $_SESSION['csrf_token'], $token);
}

/**
 * Render the hidden CSRF field plus the render-timestamp field used by the
 * time-trap. Emitted values are safe (token is hex, timestamp is an int).
 */
function csrf_fields(): string
{
    $token = h(csrf_token());
    $now = time();
    return '<input type="hidden" name="csrf_token" value="' . $token . '">'
        . '<input type="hidden" name="rendered_at" value="' . $now . '">';
}

// ---------------------------------------------------------------------------
// Admin session
// ---------------------------------------------------------------------------

/** Is the current session authenticated as admin? */
function is_admin(): bool
{
    return !empty($_SESSION['is_admin']);
}

/**
 * Attempt an admin login. Verifies the password against ADMIN_PASSWORD_HASH
 * with password_verify(). Returns true on success and marks the session.
 */
function admin_login(string $password): bool
{
    $hash = ADMIN_PASSWORD_HASH;
    if ($hash === '' || $password === '') {
        return false;
    }
    if (password_verify($password, $hash)) {
        // Rotate the session id on privilege change to prevent fixation.
        session_regenerate_id(true);
        $_SESSION['is_admin'] = true;
        return true;
    }
    return false;
}

/** Drop admin privileges for the current session. */
function admin_logout(): void
{
    unset($_SESSION['is_admin']);
    session_regenerate_id(true);
}
