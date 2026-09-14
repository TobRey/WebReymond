<?php
/**
 * Erzeugt alle SVG-Grafiken des Spiels in einem einheitlichen Stil.
 * Aufruf (lokal, vor dem Ausliefern):  php tools/build_assets.php
 *
 * Die erzeugten Dateien werden mit ausgeliefert - auf dem Server ist
 * kein Build-Schritt noetig.
 */
declare(strict_types=1);

$root = dirname(__DIR__) . '/src/assets/img';
@mkdir($root . '/avatars', 0755, true);
@mkdir($root . '/scenes', 0755, true);
@mkdir($root . '/ui', 0755, true);

require_once __DIR__ . '/asset_lib.php';

/* ---------------------------------------------------------------
 |  Favicon und Cover
 --------------------------------------------------------------- */

write($root . '/ui/favicon.svg', <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="64" height="64">
<rect width="64" height="64" rx="10" fill="#0c0f14"/>
<circle cx="32" cy="32" r="21" fill="none" stroke="#5b8cb5" stroke-width="2"/>
<path d="M32 13c7 6 11 11 11 19 0 9-5 15-11 19-6-4-11-10-11-19 0-8 4-13 11-19z" fill="#16202b" stroke="#3d6484"/>
<text x="32" y="38" font-family="monospace" font-size="15" fill="#a02a2f" text-anchor="middle" letter-spacing="1">T?</text>
</svg>
SVG);

function coverImage(string $title, string $subtitle, string $code): string
{
    $defs = gradient('sky', '#141a22', '#080a0d') . gradient('glow', '#2a3a4a', '#0d1116');
    $body = '<rect width="1280" height="720" fill="url(#sky)"/>'
        // Baumsilhouetten
        . '<g fill="#05070a">'
        . '<path d="M0 560 L60 430 L110 470 L150 360 L210 470 L260 420 L320 520 L380 430 L430 500 L500 400 L560 500 L620 440 L680 540 L740 470 L800 540 L860 450 L920 530 L980 460 L1040 540 L1100 470 L1160 540 L1220 480 L1280 560 L1280 720 L0 720Z"/>'
        . '</g>'
        // Wasserturm
        . '<g fill="#0b1014" stroke="#151c24">'
        . '<rect x="980" y="300" width="14" height="240"/><rect x="1060" y="300" width="14" height="240"/>'
        . '<path d="M960 300 h134 l-16 -52 h-102 z"/><rect x="972" y="206" width="110" height="46" rx="6"/>'
        . '</g>'
        // Nebel
        . '<ellipse cx="640" cy="600" rx="700" ry="120" fill="#0f141a" opacity=".7"/>'
        . '<ellipse cx="400" cy="640" rx="520" ry="90" fill="#121820" opacity=".6"/>'
        // Lichtkegel
        . '<path d="M250 720 L410 300 L470 300 L330 720Z" fill="#2c3d4d" opacity=".12"/>'
        . '<rect x="0" y="0" width="1280" height="720" fill="#0a0e13" opacity=".25"/>'
        . '<rect x="64" y="470" width="6" height="140" fill="#9a2226"/>'
        . text(96, 556, mb_strtoupper($title), 62, '#e9e7e1', 'start', 'Georgia, serif', 3, 'bold')
        . text(98, 594, mb_strtoupper($subtitle), 16, '#8d95a0', 'start', 'monospace', 6)
        . text(98, 626, $code, 13, '#9a2226', 'start', 'monospace', 4)
        . scanlines(1280, 720, 0.05, 4);
    return svg(1280, 720, $body, $defs);
}

write($root . '/scenes/case-cover-toby.svg', coverImage('Where is Toby?', 'FBI Field Investigation', 'WIT-2024-1011'));
write($root . '/scenes/cover-generic.svg', coverImage('Neuer Fall', 'FBI Field Investigation', 'WIT-NEU'));

/* ---------------------------------------------------------------
 |  Portraits
 --------------------------------------------------------------- */

/**
 * Stilisierte Portraitzeichnung (Fahndungsskizzen-Look).
 */
function portrait(array $options): string
{
    $skin = $options['skin'] ?? '#7d6a5c';
    $hair = $options['hair'] ?? '#241c18';
    $bg1 = $options['bg1'] ?? '#1b222b';
    $bg2 = $options['bg2'] ?? '#0d1116';
    $hairStyle = $options['hairStyle'] ?? 'short';
    $glasses = (bool)($options['glasses'] ?? false);
    $beard = (bool)($options['beard'] ?? false);
    $label = $options['label'] ?? '';
    $collar = $options['collar'] ?? '#232b34';
    $age = $options['age'] ?? 30;

    $defs = gradient('bg', $bg1, $bg2) . gradient('skin', $skin, '#3a2f29');
    $body = '<rect width="400" height="400" fill="url(#bg)"/>';
    // Schulter
    $body .= '<path d="M60 400 c0 -80 60 -120 140 -120 s140 40 140 120 z" fill="' . $collar . '"/>';
    // Hals
    $body .= '<rect x="176" y="232" width="48" height="60" fill="' . $skin . '" opacity=".9"/>';
    // Kopf
    $body .= '<ellipse cx="200" cy="170" rx="78" ry="96" fill="url(#skin)"/>';
    // Ohren
    $body .= '<ellipse cx="122" cy="176" rx="12" ry="20" fill="' . $skin . '"/><ellipse cx="278" cy="176" rx="12" ry="20" fill="' . $skin . '"/>';
    // Haare
    $body .= match ($hairStyle) {
        'long'  => '<path d="M120 150 c0 -66 40 -96 80 -96 s80 30 80 96 c0 40 6 90 14 120 l-40 0 c6 -40 0 -80 -12 -104 c-26 18 -58 20 -84 0 c-14 24 -20 64 -14 104 l-38 0 c8 -30 14 -80 14 -120z" fill="' . $hair . '"/>',
        'bun'   => '<path d="M124 156 c0 -62 38 -94 76 -94 s76 32 76 94 c-24 -26 -50 -34 -76 -34 s-52 8 -76 34z" fill="' . $hair . '"/><circle cx="200" cy="58" r="22" fill="' . $hair . '"/>',
        'bald'  => '<path d="M128 150 c8 -46 36 -70 72 -70 s64 24 72 70 c-22 -20 -46 -28 -72 -28 s-50 8 -72 28z" fill="' . $hair . '" opacity=".45"/>',
        'curly' => '<g fill="' . $hair . '"><circle cx="150" cy="104" r="26"/><circle cx="188" cy="86" r="28"/><circle cx="228" cy="94" r="26"/><circle cx="256" cy="120" r="24"/><circle cx="136" cy="136" r="22"/><circle cx="266" cy="150" r="20"/></g>',
        default => '<path d="M124 154 c0 -58 38 -92 76 -92 s76 34 76 92 c-16 -30 -44 -44 -76 -44 s-60 14 -76 44z" fill="' . $hair . '"/>',
    };
    // Augen
    $body .= '<g fill="#10151b"><ellipse cx="170" cy="168" rx="9" ry="6"/><ellipse cx="230" cy="168" rx="9" ry="6"/></g>';
    $body .= '<g stroke="#241c18" stroke-width="3" fill="none"><path d="M156 150 q14 -8 28 -2"/><path d="M216 148 q14 -6 28 2"/></g>';
    // Nase und Mund
    $body .= '<path d="M200 176 l-8 26 h18z" fill="#2b221d" opacity=".55"/>';
    $body .= '<path d="M180 220 q20 ' . ($age > 45 ? '6' : '10') . ' 40 0" stroke="#2b221d" stroke-width="3" fill="none"/>';
    if ($age > 45) {
        $body .= '<g stroke="#2b221d" stroke-width="1.6" opacity=".5" fill="none"><path d="M150 196 q10 8 4 18"/><path d="M250 196 q-10 8 -4 18"/><path d="M168 138 q32 -10 64 0"/></g>';
    }
    if ($beard) {
        $body .= '<path d="M144 188 c4 56 30 82 56 82 s52 -26 56 -82 c-14 34 -40 44 -56 44 s-42 -10 -56 -44z" fill="' . $hair . '" opacity=".85"/>';
    }
    if ($glasses) {
        $body .= '<g stroke="#c9d2dc" stroke-width="3" fill="#0d1116" fill-opacity=".25"><rect x="146" y="152" width="48" height="34" rx="8"/><rect x="206" y="152" width="48" height="34" rx="8"/><path d="M194 168 h12"/><path d="M146 166 l-22 6"/><path d="M254 166 l22 6"/></g>';
    }
    $body .= scanlines(400, 400, 0.05, 4);
    if ($label !== '') {
        $body .= '<rect x="0" y="352" width="400" height="48" fill="#070a0d" opacity=".82"/>'
            . text(20, 382, mb_strtoupper($label), 15, '#9aa4b0', 'start', 'monospace', 2);
    }
    return svg(400, 400, $body, $defs);
}

$people = [
    'toby'   => ['label' => 'T. BRENNAN · 17', 'skin' => '#8b7561', 'hair' => '#2e2119', 'hairStyle' => 'short', 'age' => 17, 'collar' => '#26403a'],
    'diane'  => ['label' => 'D. BRENNAN · 44', 'skin' => '#9b8371', 'hair' => '#3a2a20', 'hairStyle' => 'long', 'age' => 44, 'collar' => '#2a2430'],
    'frank'  => ['label' => 'F. BRENNAN · 49', 'skin' => '#8a705c', 'hair' => '#2a231d', 'hairStyle' => 'short', 'beard' => true, 'age' => 49, 'collar' => '#2b2b28'],
    'nora'   => ['label' => 'N. VANCE · 17', 'skin' => '#a08a76', 'hair' => '#1d1a1a', 'hairStyle' => 'bun', 'age' => 17, 'collar' => '#332430'],
    'elias'  => ['label' => 'E. MARSH · 18', 'skin' => '#6f5a4a', 'hair' => '#171313', 'hairStyle' => 'curly', 'age' => 18, 'collar' => '#24303a'],
    'hale'   => ['label' => 'G. HALE · 52', 'skin' => '#9c8674', 'hair' => '#5c5a57', 'hairStyle' => 'short', 'glasses' => true, 'age' => 52, 'collar' => '#2d2a24'],
    'ruth'   => ['label' => 'R. CALLOWAY · 71', 'skin' => '#a99384', 'hair' => '#b9b6b1', 'hairStyle' => 'bun', 'glasses' => true, 'age' => 71, 'collar' => '#3a3334'],
    'doss'   => ['label' => 'W. DOSS · 61', 'skin' => '#87735f', 'hair' => '#6d6a63', 'hairStyle' => 'bald', 'age' => 61, 'collar' => '#1f2a22'],
    'agent'  => ['label' => 'SPECIAL AGENT', 'skin' => '#8d7967', 'hair' => '#241f1b', 'hairStyle' => 'short', 'age' => 38, 'collar' => '#1c2430'],
    'unknown'=> ['label' => 'UNBEKANNT', 'skin' => '#4a4a4f', 'hair' => '#1a1a1d', 'hairStyle' => 'short', 'age' => 40, 'collar' => '#202225'],
];
foreach ($people as $name => $options) {
    write($root . '/avatars/' . $name . '.svg', portrait($options));
}

/* ---------------------------------------------------------------
 |  Karte von Millbrook
 --------------------------------------------------------------- */

$mapDefs = gradient('land', '#10151b', '#0a0d11') . gradient('water', '#12242f', '#0b161d');
$map = '<rect width="1200" height="800" fill="url(#land)"/>';
// Waldflaechen
$map .= '<g fill="#0d1419" opacity=".9">'
    . '<path d="M0 0 h520 v210 q-160 40 -320 10 T0 190z"/>'
    . '<path d="M760 0 h440 v300 q-200 -40 -300 20 T760 240z"/>'
    . '<path d="M0 560 h300 q80 120 -40 240 H0z"/>'
    . '</g>';
// Stausee
$map .= '<path d="M700 120 q170 -40 300 60 q70 90 -20 190 q-140 90 -300 20 q-90 -100 20 -270z" fill="url(#water)" stroke="#1d3b4a"/>';
$map .= text(880, 240, 'HALLOWAY-STAUSEE', 13, '#3f7590', 'middle', 'monospace', 3);
// Fluss
$map .= '<path d="M700 330 q-60 90 -180 130 q-140 50 -260 190" fill="none" stroke="#16303c" stroke-width="10"/>';
// Bahnlinie
$map .= '<path d="M60 470 q300 -70 620 -40 q240 20 460 120" fill="none" stroke="#2a323c" stroke-width="5" stroke-dasharray="14 8"/>';
$map .= text(300, 452, 'GUETERSTRECKE CN-114', 11, '#4c5866', 'start', 'monospace', 2);
// Strassen
$roads = [
    ['d' => 'M0 620 h1200', 'w' => 9, 'label' => 'STATE ROUTE 12', 'lx' => 120, 'ly' => 612],
    ['d' => 'M300 800 V180', 'w' => 7, 'label' => 'MAPLE STREET', 'lx' => 306, 'ly' => 300],
    ['d' => 'M620 800 q20 -300 120 -420', 'w' => 7, 'label' => 'RIDGE ROAD', 'lx' => 660, 'ly' => 520],
    ['d' => 'M900 690 q-40 -200 40 -330', 'w' => 6, 'label' => 'RESERVOIR ACCESS', 'lx' => 930, 'ly' => 600],
    ['d' => 'M120 300 h420', 'w' => 5, 'label' => 'SCHOOL LANE', 'lx' => 140, 'ly' => 292],
];
foreach ($roads as $road) {
    $map .= '<path d="' . $road['d'] . '" fill="none" stroke="#1c232b" stroke-width="' . ($road['w'] + 6) . '"/>';
    $map .= '<path d="' . $road['d'] . '" fill="none" stroke="#2b3542" stroke-width="' . $road['w'] . '"/>';
    $map .= text($road['lx'], $road['ly'], $road['label'], 11, '#586474', 'start', 'monospace', 2);
}
// Stadtblock
$map .= '<g fill="#141a21" stroke="#1e2731">';
for ($i = 0; $i < 26; $i++) {
    $x = 140 + ($i % 7) * 60 + random_int(-8, 8);
    $y = 500 + intdiv($i, 7) * 54 + random_int(-6, 6);
    $map .= '<rect x="' . $x . '" y="' . $y . '" width="' . random_int(26, 44) . '" height="' . random_int(20, 34) . '" rx="2"/>';
}
$map .= '</g>';
$map .= text(150, 700, 'MILLBROOK · VERMONT · 4.180 EINWOHNER', 12, '#4f5b6a', 'start', 'monospace', 3);
$map .= '<g opacity=".25" stroke="#22303c">';
for ($x = 0; $x <= 1200; $x += 100) { $map .= '<line x1="' . $x . '" y1="0" x2="' . $x . '" y2="800"/>'; }
for ($y = 0; $y <= 800; $y += 100) { $map .= '<line x1="0" y1="' . $y . '" x2="1200" y2="' . $y . '"/>'; }
$map .= '</g>';
$map .= '<g><path d="M1120 80 l0 -46 l-10 14 z" fill="#8f98a4"/>' . text(1120, 104, 'N', 14, '#8f98a4', 'middle', 'monospace') . '</g>';
write($root . '/scenes/map-millbrook.svg', svg(1200, 800, $map, $mapDefs));

echo "\nGrundgrafiken fertig.\n";
