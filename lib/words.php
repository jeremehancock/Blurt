<?php
/**
 * words.php — adjective / animal wordlists for per-session handle generation.
 *
 * NOT web-accessible. Handles are cosmetic ("SwiftOtter42"), so collisions are
 * harmless and there is no need to over-engineer uniqueness. Expand freely.
 */

declare(strict_types=1);

/** @return string[] Adjectives, all CamelCase-friendly single words. */
function handle_adjectives(): array
{
    return [
        'Swift', 'Brave', 'Quiet', 'Sunny', 'Clever', 'Jolly', 'Nimble',
        'Cosmic', 'Mellow', 'Fuzzy', 'Lucky', 'Witty', 'Bold', 'Gentle',
        'Snappy', 'Breezy', 'Chirpy', 'Dapper', 'Frosty', 'Golden',
        'Hidden', 'Merry', 'Plucky', 'Rustic', 'Silver', 'Spry',
        'Velvet', 'Wild', 'Zesty', 'Amber', 'Crimson', 'Dusty',
        'Electric', 'Feral', 'Groovy', 'Humble', 'Iron', 'Jade',
        'Keen', 'Lunar', 'Misty', 'Noble', 'Opal', 'Prickly',
    ];
}

/** @return string[] Animals, all CamelCase-friendly single words. */
function handle_animals(): array
{
    return [
        'Otter', 'Falcon', 'Badger', 'Panda', 'Lynx', 'Heron', 'Gecko',
        'Marmot', 'Raven', 'Bison', 'Corgi', 'Ferret', 'Koala', 'Lemur',
        'Moose', 'Newt', 'Ocelot', 'Puffin', 'Quokka', 'Robin',
        'Salmon', 'Tapir', 'Urchin', 'Viper', 'Walrus', 'Yak',
        'Zebra', 'Wombat', 'Toucan', 'Stoat', 'Sparrow', 'Seal',
        'Owl', 'Narwhal', 'Mantis', 'Llama', 'Kestrel', 'Jackal',
        'Iguana', 'Hedgehog', 'Gopher', 'Finch', 'Elk', 'Dingo',
    ];
}
