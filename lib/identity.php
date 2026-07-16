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
 * Generate a readable accent color as a validated #rrggbb hex string.
 * We pick a random hue at fixed saturation/lightness so every color has decent
 * contrast against the feed background, then convert HSL -> hex.
 */
function generate_accent_color(): string
{
    $h = random_int(0, 359) / 360.0;
    $s = 0.62;
    $l = 0.42;
    [$r, $g, $b] = hsl_to_rgb($h, $s, $l);
    $hex = sprintf('#%02x%02x%02x', $r, $g, $b);
    // Belt-and-suspenders: only ever return a value matching the strict pattern.
    return valid_hex_color($hex) ? $hex : '#4b5563';
}

/** Strict validation of a #rrggbb hex color, used before emitting into markup. */
function valid_hex_color(string $c): bool
{
    return preg_match('/^#[0-9a-fA-F]{6}$/', $c) === 1;
}

/**
 * Convert HSL (each 0..1) to an [r,g,b] byte triple.
 * @return array{0:int,1:int,2:int}
 */
function hsl_to_rgb(float $h, float $s, float $l): array
{
    if ($s == 0.0) {
        $v = (int) round($l * 255);
        return [$v, $v, $v];
    }
    $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
    $p = 2 * $l - $q;
    $r = hue_to_rgb($p, $q, $h + 1 / 3);
    $g = hue_to_rgb($p, $q, $h);
    $b = hue_to_rgb($p, $q, $h - 1 / 3);
    return [
        (int) round($r * 255),
        (int) round($g * 255),
        (int) round($b * 255),
    ];
}

function hue_to_rgb(float $p, float $q, float $t): float
{
    if ($t < 0) {
        $t += 1;
    }
    if ($t > 1) {
        $t -= 1;
    }
    if ($t < 1 / 6) {
        return $p + ($q - $p) * 6 * $t;
    }
    if ($t < 1 / 2) {
        return $q;
    }
    if ($t < 2 / 3) {
        return $p + ($q - $p) * (2 / 3 - $t) * 6;
    }
    return $p;
}
