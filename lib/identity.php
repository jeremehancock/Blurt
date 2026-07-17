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
    return [
        '#b91c1c', '#c2410c', '#b45309', '#a16207', '#4d7c0f', '#15803d',
        '#047857', '#0f766e', '#0e7490', '#0369a1', '#1d4ed8', '#4338ca',
        '#6d28d9', '#7e22ce', '#a21caf', '#be185d', '#be123c', '#db2777',
        '#7c3aed', '#2563eb', '#059669', '#374151',
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
