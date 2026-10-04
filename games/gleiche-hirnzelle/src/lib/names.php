<?php
declare(strict_types=1);

/*
 * Zufällige, alberne Spielernamen mit passendem Emoji und Farbe.
 */

if (!defined('GH_APP')) {
    http_response_code(404);
    exit;
}

const GH_NAME_PREFIXES = [
    'Turbo', 'Mega', 'Schwabbel', 'Knusper', 'Disco', 'Quietsch', 'Schlabber', 'Glitzer',
    'Wackel', 'Schnarch', 'Kuschel', 'Flausch', 'Brummel', 'Zappel', 'Schnüffel', 'Kicher',
    'Ninja', 'Gummi', 'Plüsch', 'Wusel', 'Krümel', 'Knautsch', 'Hüpf', 'Grummel', 'Schmatz',
    'Laser', 'Party', 'Raketen', 'Pyjama', 'Sofa', 'Chaos', 'Bling-Bling', 'Wirbel', 'Schnuffel',
    'Kapitän', 'Professor', 'Baron', 'Herzogin', 'Doktor', 'Agent', 'Hobby', 'Nebenberufs',
    'Glücks', 'Mitternachts', 'Vollkorn', 'Extra', 'Super', 'Hyper', 'Mini', 'Zauber',
];

const GH_NAME_NOUNS = [
    ['Kartoffel', '🥔'], ['Toaster', '🍞'], ['Pinguin', '🐧'], ['Waschbär', '🦝'], ['Nudel', '🍝'],
    ['Socke', '🧦'], ['Gurke', '🥒'], ['Lama', '🦙'], ['Würstchen', '🌭'], ['Kaktus', '🌵'],
    ['Brokkoli', '🥦'], ['Dackel', '🐕'], ['Muffin', '🧁'], ['Qualle', '🪼'], ['Pudding', '🍮'],
    ['Hamster', '🐹'], ['Raclette', '🧀'], ['Bratwurst', '🌭'], ['Alpaka', '🦙'], ['Seepferdchen', '🐴'],
    ['Zwiebel', '🧅'], ['Knödel', '🥟'], ['Faultier', '🦥'], ['Einhorn', '🦄'], ['Flamingo', '🦩'],
    ['Rüebli', '🥕'], ['Brezel', '🥨'], ['Avocado', '🥑'], ['Tintenfisch', '🐙'], ['Frosch', '🐸'],
    ['Pfannkuchen', '🥞'], ['Donut', '🍩'], ['Ente', '🦆'], ['Igel', '🦔'], ['Schnecke', '🐌'],
    ['Banane', '🍌'], ['Pizza', '🍕'], ['Otter', '🦦'], ['Taco', '🌮'], ['Gespenst', '👻'],
    ['Roboter', '🤖'], ['Dino', '🦖'], ['Koala', '🐨'], ['Erdbeere', '🍓'], ['Kürbis', '🎃'],
    ['Waffel', '🧇'], ['Krabbe', '🦀'], ['Panda', '🐼'], ['Bagel', '🥯'], ['Wal', '🐳'],
];

const GH_PLAYER_COLORS = ['#ff4d8d', '#3d7bff', '#ffb000', '#14c38e', '#8b5cf6', '#ff6b2c', '#00b8d9', '#e83ef5'];

function gh_random_identity(array $taken = []): array
{
    $takenNames = array_map(fn($p) => $p['name'] ?? '', $taken);
    $takenColors = array_map(fn($p) => $p['color'] ?? '', $taken);
    $takenNouns = array_map(fn($p) => $p['emoji'] ?? '', $taken);
    for ($i = 0; $i < 50; $i++) {
        $prefix = GH_NAME_PREFIXES[random_int(0, count(GH_NAME_PREFIXES) - 1)];
        [$noun, $emoji] = GH_NAME_NOUNS[random_int(0, count(GH_NAME_NOUNS) - 1)];
        $name = $prefix . '-' . $noun;
        if (!in_array($name, $takenNames, true) && !in_array($emoji, $takenNouns, true)) {
            break;
        }
    }
    $colors = array_values(array_diff(GH_PLAYER_COLORS, $takenColors)) ?: GH_PLAYER_COLORS;
    return [
        'name' => $name,
        'emoji' => $emoji,
        'color' => $colors[random_int(0, count($colors) - 1)],
    ];
}
