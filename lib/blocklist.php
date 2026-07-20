<?php
/**
 * blocklist.php — starter list of blocked terms.
 *
 * NOT web-accessible. The match in moderation.php is case-insensitive and
 * word-boundary-aware, so entries here should be base words/phrases.
 *
 * This is intentionally a SMALL example list. Expand it to suit your
 * community's moderation needs — add slurs, spam signatures, banned domains,
 * etc. Matching never reveals which term tripped, so keep it as long as you
 * like without leaking it to posters.
 *
 * @return string[]
 */

declare(strict_types=1);

function blocklist_terms(): array
{
    return [
        // --- generic spam signatures (examples) ---
        'buy now',
        'free money',
        'click here',
        'viagra',
        'crypto giveaway',
        'work from home',

        // --- placeholder profanity examples; replace with your real list ---
        'badword1',
        'badword2',

        // Add more terms below. One base term or phrase per line.
    ];
}
