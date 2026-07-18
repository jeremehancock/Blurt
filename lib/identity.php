<?php
/**
 * identity.php — per-session display handle + accent color.
 *
 * NOT web-accessible. This is the *display* identity only: friendly,
 * cookie-based, resettable, and NEVER used for moderation. Moderation keys off
 * the invisible IP-based author_hash (see helpers.php). Keeping these two
 * separate is deliberate: a visitor could dodge every limit by clearing a
 * cookie, so limits must never depend on the session handle.
 */

declare(strict_types=1);

/**
 * Ensure the current session has a display handle + color, generating them on
 * first request. Both are stored in $_SESSION so they persist for the browser
 * session and reset when the cookie is cleared (intended).
 */
function ensure_identity(): void
{
    if (empty($_SESSION['display_name']) || empty($_SESSION['display_color'])) {
        $_SESSION['display_name'] = generate_handle();
        $_SESSION['display_color'] = generate_accent_color();
    }
    // A per-session reactor id: each browser session is one distinct reactor,
    // so reactions are deduped per visitor (NOT per IP). Two people behind the
    // same proxy/IP each get their own id, and one can never toggle another's
    // reaction. Resettable by clearing the cookie, which is fine for reactions.
    if (empty($_SESSION['reactor_id'])) {
        $_SESSION['reactor_id'] = bin2hex(random_bytes(16));
    }
}

/** The current session's reactor id (call ensure_identity() first). */
function current_reactor_id(): string
{
    return (string) ($_SESSION['reactor_id'] ?? '');
}

/** Current session handle (call ensure_identity() first). */
function current_display_name(): string
{
    return (string) ($_SESSION['display_name'] ?? 'Anon');
}

/** Current session accent color (validated hex). */
function current_display_color(): string
{
    $c = (string) ($_SESSION['display_color'] ?? '#666666');
    return valid_hex_color($c) ? $c : '#666666';
}

/** Adjective + Animal + 2 digits, e.g. "SwiftOtter42". */
function generate_handle(): string
{
    $adjectives = handle_adjectives();
    $animals = handle_animals();
    $adj = $adjectives[random_int(0, count($adjectives) - 1)];
    $animal = $animals[random_int(0, count($animals) - 1)];
    $num = random_int(10, 99);
    return $adj . $animal . $num;
}

/**
 * A fixed palette of accent colors. Every entry is a medium-dark hue chosen so
 * white text (the avatar initial) stays legible on it in both light and dark UI.
 *
 * These are the single source of truth: each hex here has a matching CSS class
 * `sw-N` in assets/style.css. Colors are applied via that class (not an inline
 * style) so the strict CSP — which forbids inline styles — stays intact.
 *
 * @return string[]
 */
function identity_palette(): array
{
    // Kept within Blurt's violet→fuchsia brand family (indigo → violet →
    // purple → fuchsia → pink) so avatars stay distinguishable yet harmonize
    // with the theme. Every entry is dark enough for a white initial.
    return [
        '#4338ca', '#4f46e5', '#5b21b6', '#6d28d9', '#7c3aed', '#581c87',
        '#6b21a8', '#7e22ce', '#9333ea', '#86198f', '#a21caf', '#c026d3',
        '#831843', '#9d174d', '#be185d', '#db2777',
    ];
}

/**
 * Pick a random accent color from the palette. Stored on the record as
 * display_color (a validated hex), display-only, never used for moderation.
 */
function generate_accent_color(): string
{
    $palette = identity_palette();
    return $palette[random_int(0, count($palette) - 1)];
}

/**
 * Map a stored display_color to its stylesheet class (`sw-N`). Palette colors
 * resolve to their exact index; any other value (e.g. a legacy hex) is hashed
 * to a stable palette slot so it still renders a consistent color.
 */
function color_class(string $hex): string
{
    $palette = identity_palette();
    $needle = strtolower($hex);
    foreach ($palette as $i => $c) {
        if (strtolower($c) === $needle) {
            return 'sw-' . $i;
        }
    }
    return 'sw-' . (abs(crc32($needle)) % count($palette));
}

/** Strict validation of a #rrggbb hex color, used before emitting into markup. */
function valid_hex_color(string $c): bool
{
    return preg_match('/^#[0-9a-fA-F]{6}$/', $c) === 1;
}
