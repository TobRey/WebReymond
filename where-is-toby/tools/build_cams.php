<?php
/**
 * Erzeugt Kamerabilder (CCTV-Einzelbilder), Dokumente und Sonderbilder.
 * Aufruf: php tools/build_cams.php
 */
declare(strict_types=1);

require_once __DIR__ . '/asset_lib.php';
require_once __DIR__ . '/scene_lib.php';

$root = dirname(__DIR__) . '/src/assets/img/scenes';
@mkdir($root, 0755, true);

$W = 1024;
$H = 576;

/* ============ Ridge Road (Kamera 3) ============ */

function ridgeBase(): string
{
    $body = '<rect width="1024" height="576" fill="#0c1015"/>'
        // Himmel
        . '<rect width="1024" height="230" fill="#111721"/>'
        // Baumreihe
        . '<path d="M0 240 L60 170 L110 205 L160 150 L230 210 L300 160 L360 220 L430 165 L500 215 L570 170 L650 225 L720 175 L800 220 L880 165 L960 215 L1024 180 L1024 300 L0 300Z" fill="#05070a"/>'
        // Strasse
        . '<path d="M300 300 L724 300 L1024 576 L0 576Z" fill="#171b20"/>'
        . '<path d="M300 300 L724 300 L1024 576 L0 576Z" fill="none" stroke="#242a31" stroke-width="3"/>'
        // Mittellinie
        . '<g stroke="#8d8f88" stroke-width="5" opacity=".5">'
        . '<line x1="508" y1="310" x2="508" y2="340"/><line x1="508" y1="370" x2="508" y2="410"/>'
        . '<line x1="508" y1="450" x2="508" y2="510"/><line x1="508" y1="545" x2="508" y2="576"/></g>'
        // Leitpfosten
        . '<g fill="#3a4047"><rect x="270" y="300" width="8" height="40"/><rect x="180" y="330" width="9" height="50"/><rect x="60" y="380" width="11" height="62"/>'
        . '<rect x="744" y="300" width="8" height="40"/><rect x="830" y="330" width="9" height="50"/><rect x="950" y="380" width="11" height="62"/></g>'
        // Strassenlaterne
        . '<radialGradient id="street"><stop offset="0" stop-color="#c9b47a" stop-opacity=".22"/><stop offset="1" stop-color="#c9b47a" stop-opacity="0"/></radialGradient>'
        . '<circle cx="740" cy="300" r="220" fill="url(#street)"/>'
        . '<g fill="#22282e"><rect x="752" y="120" width="8" height="180"/><path d="M752 124 q30 -16 56 0 l0 10 q-28 -12 -56 0z"/></g>';
    return $body;
}

$frames = [
    ['file' => 'cam-ridge-1.svg', 'stamp' => '2024-10-11 22:50:04', 'extra' => ''],
    ['file' => 'cam-ridge-2.svg', 'stamp' => '2024-10-11 22:53:31', 'extra' =>
        // Radfahrer
        '<g transform="translate(470,330) scale(.62)" stroke="#05070a" stroke-width="9" fill="none">'
        . '<circle cx="0" cy="60" r="34"/><circle cx="110" cy="60" r="34"/>'
        . '<path d="M0 60 L48 8 L96 8 L110 60 M48 8 L60 60 L0 60"/></g>'
        . personSilhouette(510, 320, 0.66, '#05070a')
        . '<circle cx="466" cy="336" r="7" fill="#e8e2c6" opacity=".7"/>'],
    ['file' => 'cam-ridge-3.svg', 'stamp' => '2024-10-11 22:58:17', 'extra' =>
        van(420, 300, 1.15, '7KD-418')
        . '<g opacity=".4"><ellipse cx="520" cy="398" rx="150" ry="18" fill="#05070a"/></g>'
        . '<g fill="#e8dfae" opacity=".25"><path d="M600 340 L760 300 L780 360 L610 372Z"/></g>'],
    ['file' => 'cam-ridge-4.svg', 'stamp' => '2024-10-11 23:04:52', 'extra' =>
        '<g opacity=".8"><circle cx="690" cy="316" r="5" fill="#c9484d"/><circle cx="712" cy="316" r="5" fill="#c9484d"/>'
        . '<ellipse cx="700" cy="330" rx="40" ry="8" fill="#3a1416" opacity=".5"/></g>'],
];
foreach ($frames as $frame) {
    $body = ridgeBase() . $frame['extra'] . camOverlay($W, $H, 'CAM 03 · RIDGE RD / RESERVOIR ACCESS', $frame['stamp']);
    write($root . '/' . $frame['file'], svg($W, $H, $body));
}

/* ============ Tankstelle Miller's Gas ============ */

function gasBase(): string
{
    return '<rect width="1024" height="576" fill="#0b0f13"/>'
        . '<rect width="1024" height="200" fill="#10161d"/>'
        // Vordach
        . '<g fill="#161c23" stroke="#222a33" stroke-width="3"><rect x="120" y="120" width="790" height="46"/>'
        . '<rect x="180" y="166" width="16" height="200"/><rect x="820" y="166" width="16" height="200"/></g>'
        . '<rect x="140" y="128" width="750" height="12" fill="#c9b47a" opacity=".18"/>'
        // Zapfsaeulen
        . '<g fill="#1e252c" stroke="#2b333c" stroke-width="3">'
        . '<rect x="320" y="250" width="70" height="120" rx="6"/><rect x="620" y="250" width="70" height="120" rx="6"/></g>'
        . '<rect x="332" y="266" width="46" height="30" fill="#2a3a44"/><rect x="632" y="266" width="46" height="30" fill="#2a3a44"/>'
        // Boden
        . '<rect x="0" y="366" width="1024" height="210" fill="#14181d"/>'
        . '<g stroke="#2b3138" stroke-width="3" opacity=".7"><line x1="0" y1="430" x2="1024" y2="430"/><line x1="0" y1="500" x2="1024" y2="500"/></g>'
        // Shop
        . '<g fill="#12181f" stroke="#1f272f" stroke-width="3"><rect x="60" y="200" width="120" height="170"/></g>'
        . '<rect x="74" y="220" width="92" height="80" fill="#2a3d49" opacity=".55"/>'
        . text(120, 392, "MILLER'S GAS", 12, '#5c6672', 'middle', 'monospace', 2);
}

function sedan(float $x, float $y, float $scale, string $color = '#4a5560'): string
{
    return '<g transform="translate(' . $x . ',' . $y . ') scale(' . $scale . ')">'
        . '<path d="M0 40 q20 -34 60 -36 h70 q40 4 60 36 l14 6 v26 h-218 v-26z" fill="' . $color . '" stroke="#2a3138"/>'
        . '<path d="M28 20 q18 -22 52 -22 h46 q32 2 48 22z" fill="#233039"/>'
        . '<circle cx="46" cy="76" r="16" fill="#14181c"/><circle cx="166" cy="76" r="16" fill="#14181c"/>'
        . '</g>';
}

$gasFrames = [
    ['file' => 'cam-gas-1.svg', 'stamp' => '2024-10-11 22:30:11', 'extra' => ''],
    ['file' => 'cam-gas-2.svg', 'stamp' => '2024-10-11 22:34:48', 'extra' =>
        sedan(420, 300, 1.0, '#586472')
        . personSilhouette(390, 396, 0.86, '#0a0e12')
        . '<g opacity=".35"><ellipse cx="530" cy="392" rx="120" ry="14" fill="#05070a"/></g>'],
    ['file' => 'cam-gas-3.svg', 'stamp' => '2024-10-11 22:39:26', 'extra' =>
        sedan(700, 306, 0.94, '#586472')
        . '<g opacity=".8"><circle cx="700" cy="350" r="5" fill="#c9484d"/><circle cx="716" cy="350" r="5" fill="#c9484d"/></g>'],
];
foreach ($gasFrames as $frame) {
    $body = gasBase() . $frame['extra'] . camOverlay($W, $H, "CAM 01 · MILLER'S GAS FORECOURT", $frame['stamp']);
    write($root . '/' . $frame['file'], svg($W, $H, $body));
}

/* ============ Einfahrt Brennan (private Kamera) ============ */

function drivewayBase(): string
{
    return '<rect width="1024" height="576" fill="#0a0e12"/>'
        . '<rect width="1024" height="210" fill="#0e141c"/>'
        // Haus links
        . '<g fill="#121820" stroke="#1d242c" stroke-width="3"><rect x="0" y="120" width="300" height="300"/>'
        . '<path d="M-20 120 L150 40 L320 120z"/><rect x="60" y="200" width="70" height="80" fill="#1b2a33"/>'
        . '<rect x="190" y="210" width="60" height="70" fill="#0a1015"/></g>'
        . '<rect x="70" y="210" width="50" height="60" fill="#c9b47a" opacity=".17"/>'
        // Garage
        . '<g fill="#101620" stroke="#1d242c" stroke-width="3"><rect x="620" y="150" width="340" height="270"/>'
        . '<rect x="660" y="210" width="260" height="210" fill="#0b1015"/></g>'
        . '<g stroke="#1b232b" stroke-width="3">';
}

function drivewayMid(): string
{
    $out = '';
    for ($y = 220; $y < 420; $y += 24) { $out .= '<line x1="660" y1="' . $y . '" x2="920" y2="' . $y . '"/>'; }
    $out .= '</g>'
        // Einfahrt
        . '<path d="M300 420 L720 420 L1024 576 L120 576Z" fill="#15191e"/>'
        . '<g stroke="#232a31" stroke-width="2" opacity=".7"><line x1="300" y1="470" x2="1024" y2="470"/></g>'
        . '<radialGradient id="porch"><stop offset="0" stop-color="#c9b47a" stop-opacity=".2"/><stop offset="1" stop-color="#c9b47a" stop-opacity="0"/></radialGradient>'
        . '<circle cx="300" cy="300" r="200" fill="url(#porch)"/>';
    return $out;
}

$driveFrames = [
    ['file' => 'cam-garage-1.svg', 'stamp' => '2024-10-11 22:16:38', 'extra' =>
        '<g transform="translate(430,430) scale(.6)" stroke="#05070a" stroke-width="10" fill="none">'
        . '<circle cx="0" cy="60" r="36"/><circle cx="120" cy="60" r="36"/><path d="M0 60 L52 6 L100 6 L120 60 M52 6 L64 60 L0 60"/></g>'
        . personSilhouette(480, 430, 0.7, '#05070a')],
    ['file' => 'cam-garage-2.svg', 'stamp' => '2024-10-11 22:30:02', 'extra' =>
        sedan(560, 400, 1.05, '#3f4a55')
        . '<g fill="#e8dfae" opacity=".3"><path d="M560 450 L360 470 L370 520 L570 490Z"/></g>'],
    ['file' => 'cam-garage-3.svg', 'stamp' => '2024-10-11 23:05:47', 'extra' =>
        sedan(520, 398, 1.05, '#3f4a55')
        // geoeffneter Kofferraum mit Fahrradrad
        . '<g transform="translate(700,392)"><path d="M0 0 q34 -30 70 -10 l0 40 h-70z" fill="#333c46"/>'
        . '<circle cx="40" cy="18" r="22" fill="none" stroke="#2b333c" stroke-width="7"/></g>'
        . personSilhouette(660, 470, 0.82, '#05070a')],
];
foreach ($driveFrames as $frame) {
    $body = drivewayBase() . drivewayMid() . $frame['extra'] . camOverlay($W, $H, 'CAM PRIVAT · 14 MAPLE ST (EINFAHRT)', $frame['stamp']);
    write($root . '/' . $frame['file'], svg($W, $H, $body));
}

/* ============ Pumpstation-Kamera ============ */

function pumpBase(): string
{
    return '<rect width="1024" height="576" fill="#080b0f"/>'
        . '<rect x="0" y="0" width="1024" height="360" fill="#0d1218"/>'
        . '<g fill="#101820" stroke="#1a232b" stroke-width="3"><rect x="240" y="120" width="540" height="300"/>'
        . '<rect x="430" y="210" width="130" height="210" fill="#070a0d"/></g>'
        . '<rect x="0" y="420" width="1024" height="156" fill="#0b0f13"/>'
        . '<g fill="#1a222a"><rect x="560" y="280" width="40" height="30" rx="4"/></g>'
        . '<circle cx="580" cy="295" r="4" fill="#c9484d"/>'
        . text(512, 100, 'PUMPSTATION 4 · WARTUNGSTUER', 13, '#46525f', 'middle', 'monospace', 2);
}

write($root . '/cam-pump-1.svg', svg($W, $H, pumpBase() . camOverlay($W, $H, 'CAM 04 · PUMP STATION DOOR', '2024-10-12 03:55:10')));
write($root . '/cam-pump-2.svg', svg($W, $H, pumpBase()
    . '<rect x="430" y="210" width="130" height="210" fill="#c9a94a" opacity=".2"/>'
    . personSilhouette(500, 420, 1.0, '#04060a')
    . '<circle cx="580" cy="295" r="4" fill="#6fae86"/>'
    . camOverlay($W, $H, 'CAM 04 · PUMP STATION DOOR', '2024-10-12 04:02:33')));

// Horror-Frame: die Kamera zeigt den Ermittlungsraum
$body = '<rect width="1024" height="576" fill="#070a0e"/>'
    . '<rect x="0" y="0" width="1024" height="330" fill="#0b1015"/>'
    . '<g fill="#12171e" stroke="#1c232b" stroke-width="3"><rect x="120" y="150" width="784" height="200"/></g>'
    // Schreibtisch mit Terminal (der Raum des Spielers)
    . '<g><rect x="260" y="330" width="500" height="26" fill="#1b2027"/>'
    . '<rect x="400" y="230" width="220" height="130" rx="6" fill="#0a0f14" stroke="#2a323b" stroke-width="6"/>'
    . '<rect x="412" y="242" width="196" height="106" fill="#0c1218"/>'
    . text(510, 300, 'WHERE IS TOBY?', 13, '#3d6484', 'middle', 'monospace', 2)
    . '<rect x="452" y="366" width="120" height="14" rx="3" fill="#232a33"/></g>'
    // leerer Stuhl
    . '<g fill="#161c23"><rect x="470" y="420" width="100" height="16" rx="6"/><rect x="510" y="436" width="16" height="70"/>'
    . '<path d="M470 506 l-30 30 M570 506 l30 30" stroke="#161c23" stroke-width="8"/></g>'
    . camOverlay($W, $H, 'CAM 04 · ??? ', '2024-10-13 --:--:--', false)
    . text(512, 470, 'DU SITZT NICHT ALLEIN DA.', 20, '#8d2b2e', 'middle', 'monospace', 4);
write($root . '/cam-pump-3.svg', svg($W, $H, $body));

/* ============ Zeitungsarchiv ============ */

$body = '<rect width="1100" height="820" fill="#171410"/>';
$clips = [
    ['x' => 40, 'y' => 40, 'r' => -2.5, 'year' => '2003', 'head' => 'Schuelerin seit Samstag vermisst',
     'lines' => ['MILLBROOK - Die 16-jaehrige Karen', 'Pielmeier wurde nach dem Herbstfest', 'zuletzt an der State Route 12 gesehen.',
                 'Die Polizei bestaetigte Wartungs-', 'arbeiten der Wasserwerke im Bereich', 'Abschnitt 2 in derselben Nacht.']],
    ['x' => 560, 'y' => 90, 'r' => 1.8, 'year' => '2009', 'head' => 'Keine Spur von Danny Oro',
     'lines' => ['MILLBROOK - Der 17-jaehrige Danny Oro', 'verschwand in der Nacht zum Sonntag.', 'Sein Fahrrad wurde an der Ridge Road',
                 'gefunden. Die Strasse war wegen einer', 'Leitungsspuelung (Abschnitt 3) halb-', 'seitig gesperrt.']],
    ['x' => 100, 'y' => 430, 'r' => 2.2, 'year' => '2015', 'head' => 'Suche nach Marisol Vance eingestellt',
     'lines' => ['MILLBROOK - Nach sieben Wochen stellt', 'die Polizei die Suche ein. Ein Zeuge,', 'Lehrer G. Hale, berichtete zunaechst',
                 'von einem weissen Transporter, zog', 'die Aussage aber zurueck. Abschnitt 5', 'war in der Tatnacht gesperrt.']],
    ['x' => 620, 'y' => 470, 'r' => -1.6, 'year' => '1998', 'head' => 'Pumpstation 4 geht vom Netz',
     'lines' => ['MILLBROOK - Die Stadt nimmt Pump-', 'station 4 am Halloway-Stausee aus', 'dem Betrieb. Betriebsleiter Walter',
                 'Doss betont, die Anlage bleibe als', 'Reserve erhalten und werde weiter', 'gewartet.']],
];
foreach ($clips as $clip) {
    $body .= '<g transform="translate(' . $clip['x'] . ',' . $clip['y'] . ') rotate(' . $clip['r'] . ')">'
        . '<rect width="440" height="330" fill="#ddd8c8" stroke="#b8b2a0" stroke-width="2"/>'
        . '<rect x="0" y="0" width="440" height="34" fill="#c9c3b2"/>'
        . text(14, 24, 'MILLBROOK SENTINEL · ' . $clip['year'], 13, '#4a453c', 'start', 'monospace', 2)
        . text(14, 72, $clip['head'], 19, '#221f1a', 'start', 'Georgia, serif', 0, 'bold')
        . '<line x1="14" y1="86" x2="426" y2="86" stroke="#a49e8d"/>';
    foreach ($clip['lines'] as $index => $line) {
        $body .= text(14, 116 + ($index * 26), $line, 13, '#3c3830', 'start', 'monospace', 0);
    }
    $body .= '<rect x="14" y="292" width="412" height="24" fill="#cdc7b6"/>'
        . text(20, 309, 'ARCHIV · MIKROFILM ' . $clip['year'], 11, '#6b6658', 'start', 'monospace', 1)
        . '</g>';
}
$body .= '<rect x="0" y="0" width="1100" height="820" fill="#0d0b08" opacity=".12"/>';
write($root . '/doc-clipping.svg', svg(1100, 820, $body));

/* ============ Morse-Referenzkarte ============ */

$morse = [
    'A' => '.-', 'B' => '-...', 'C' => '-.-.', 'D' => '-..', 'E' => '.', 'F' => '..-.',
    'G' => '--.', 'H' => '....', 'I' => '..', 'J' => '.---', 'K' => '-.-', 'L' => '.-..',
    'M' => '--', 'N' => '-.', 'O' => '---', 'P' => '.--.', 'Q' => '--.-', 'R' => '.-.',
    'S' => '...', 'T' => '-', 'U' => '..-', 'V' => '...-', 'W' => '.--', 'X' => '-..-',
    'Y' => '-.--', 'Z' => '--..', '0' => '-----', '1' => '.----', '2' => '..---',
    '3' => '...--', '4' => '....-', '5' => '.....', '6' => '-....', '7' => '--...',
    '8' => '---..', '9' => '----.',
];
$body = '<rect width="900" height="700" fill="#e7e3d7"/>'
    . '<rect x="0" y="0" width="900" height="70" fill="#1d2630"/>'
    . text(36, 44, 'FBI · TECHNISCHE REFERENZ 7-B · MORSECODE', 18, '#e7e3d7', 'start', 'monospace', 3);
$index = 0;
foreach ($morse as $char => $code) {
    $column = intdiv($index, 12);
    $row = $index % 12;
    $x = 50 + $column * 290;
    $y = 130 + $row * 44;
    $body .= text($x, $y, $char, 20, '#22201c', 'start', 'monospace', 1)
        . text($x + 46, $y, $code, 20, '#8d2b2e', 'start', 'monospace', 4);
    $index++;
}
$body .= text(36, 660, 'Kurzzeichen = 1 Einheit · Langzeichen = 3 Einheiten · Pause zwischen Zeichen = 1 Einheit', 12, '#55504a', 'start', 'monospace', 0);
write($root . '/doc-morse.svg', svg(900, 700, $body));

/* ============ Vermisstenplakat ============ */

$body = '<rect width="760" height="1080" fill="#14181d"/>'
    . '<rect x="0" y="0" width="760" height="16" fill="#8d2b2e"/>'
    . text(380, 70, 'FEDERAL BUREAU OF INVESTIGATION', 12, '#8d97a3', 'middle', 'monospace', 5)
    . text(380, 140, 'MISSING', 62, '#e9e7e1', 'middle', 'Georgia, serif', 8, 'bold')
    . text(380, 174, 'HAVE YOU SEEN THIS PERSON?', 14, '#8d2b2e', 'middle', 'monospace', 4)
    . '<rect x="70" y="210" width="620" height="520" fill="#0c1015" stroke="#2b333c" stroke-width="6"/>'
    . '<image href="toby-portrait-placeholder" x="0" y="0" width="0" height="0"/>'
    // stilisiertes Portrait direkt im Plakat
    . '<g transform="translate(380,470)">'
    . '<ellipse cx="0" cy="-30" rx="150" ry="185" fill="#6f5c4c"/>'
    . '<path d="M-152 -60 c0 -112 68 -160 152 -160 s152 48 152 160 c-30 -56 -84 -84 -152 -84 s-122 28 -152 84z" fill="#2e2119"/>'
    . '<g fill="#10151b"><ellipse cx="-56" cy="-30" rx="17" ry="11"/><ellipse cx="56" cy="-30" rx="17" ry="11"/></g>'
    . '<path d="M0 -14 l-14 48 h32z" fill="#2b221d" opacity=".55"/>'
    . '<path d="M-38 74 q38 18 76 0" stroke="#2b221d" stroke-width="6" fill="none"/>'
    . '<path d="M-150 220 c0 -90 68 -130 150 -130 s150 40 150 130z" fill="#26403a"/></g>'
    . text(380, 790, 'TOBIAS "TOBY" BRENNAN', 30, '#e9e7e1', 'middle', 'Georgia, serif', 2, 'bold');
$rows = [
    ['ALTER', '17 Jahre'],
    ['ZULETZT GESEHEN', '11.10.2024, ca. 22:16 Uhr'],
    ['ORT', '14 Maple St, Millbrook VT'],
    ['GROeSSE', '178 cm, schlank'],
    ['KLEIDUNG', 'dunkelgruene Regenjacke, Jeans'],
];
$y = 840;
foreach ($rows as [$label, $value]) {
    $body .= text(70, $y, $label, 12, '#8d97a3', 'start', 'monospace', 3)
        . text(300, $y, $value, 15, '#d7dbe2', 'start', 'monospace', 0)
        . '<line x1="68" y1="' . ($y + 12) . '" x2="692" y2="' . ($y + 12) . '" stroke="#262c36"/>';
    $y += 40;
}
$body .= '<rect x="0" y="1000" width="760" height="80" fill="#8d2b2e"/>'
    . text(70, 1040, 'HINWEISE: 1-800-CALL-FBI', 20, '#f2eeea', 'start', 'monospace', 2)
    . text(70, 1064, 'FALL WIT-2024-1011 · MILLBROOK FIELD OFFICE', 11, '#f0d6d7', 'start', 'monospace', 2);
write($root . '/poster-toby.svg', svg(760, 1080, $body));

/* ============ Portraitfoto fuer die Akte ============ */

$body = '<rect width="600" height="700" fill="#1a1e24"/>'
    . '<rect x="30" y="30" width="540" height="540" fill="#0c1015"/>'
    . '<g transform="translate(300,330)">'
    . '<ellipse cx="0" cy="-20" rx="145" ry="180" fill="#7a6553"/>'
    . '<path d="M-148 -50 c0 -110 66 -158 148 -158 s148 48 148 158 c-30 -54 -82 -82 -148 -82 s-118 28 -148 82z" fill="#2e2119"/>'
    . '<g fill="#10151b"><ellipse cx="-54" cy="-20" rx="16" ry="10"/><ellipse cx="54" cy="-20" rx="16" ry="10"/></g>'
    . '<path d="M0 -6 l-13 46 h30z" fill="#2b221d" opacity=".5"/>'
    . '<path d="M-36 80 q36 14 72 0" stroke="#2b221d" stroke-width="5" fill="none"/>'
    . '<path d="M-146 214 c0 -86 66 -126 146 -126 s146 40 146 126z" fill="#26403a"/></g>'
    . text(300, 620, 'BRENNAN, TOBIAS', 20, '#d7dbe2', 'middle', 'monospace', 3)
    . text(300, 650, 'AUFNAHME 09/2024 · SCHULFOTO', 11, '#78818f', 'middle', 'monospace', 2)
    . scanlines(600, 700, 0.04, 4);
write($root . '/photo-toby.svg', svg(600, 700, $body));

/* ============ Horror-Gesicht ============ */

$body = '<rect width="1024" height="576" fill="#05070a"/>'
    . '<g transform="translate(512,300)">'
    . '<ellipse cx="0" cy="0" rx="170" ry="215" fill="#20242a"/>'
    . '<ellipse cx="0" cy="-10" rx="150" ry="195" fill="#2b3037"/>'
    . '<g fill="#05070a"><ellipse cx="-58" cy="-40" rx="30" ry="42"/><ellipse cx="58" cy="-40" rx="30" ry="42"/></g>'
    . '<g fill="#8d2b2e" opacity=".55"><circle cx="-58" cy="-34" r="7"/><circle cx="58" cy="-34" r="7"/></g>'
    . '<path d="M-52 96 q52 40 104 0 q-52 14 -104 0z" fill="#05070a"/>'
    . '<path d="M-30 -110 q30 -20 60 0" stroke="#171b20" stroke-width="6" fill="none"/>'
    . '</g>'
    . scanlines(1024, 576, 0.16, 3);
write($root . '/horror-face.svg', svg(1024, 576, $body));

/* ============ Schule / Archiv ============ */

$body = '<rect width="1200" height="800" fill="#141a20"/>'
    . '<rect x="0" y="560" width="1200" height="240" fill="#1a1f25"/>'
    . '<g fill="#101620" stroke="#1d2530" stroke-width="3">';
for ($i = 0; $i < 4; $i++) {
    $x = 80 + $i * 290;
    $body .= '<rect x="' . $x . '" y="160" width="230" height="400"/>';
    for ($shelf = 0; $shelf < 4; $shelf++) {
        $body .= '<rect x="' . ($x + 10) . '" y="' . (190 + $shelf * 95) . '" width="210" height="70" fill="#0c1116"/>';
    }
}
$body .= '</g>'
    . '<g fill="#3a3226">';
for ($i = 0; $i < 26; $i++) {
    $x = 96 + ($i % 8) * 140 + random_int(-6, 6);
    $y = 200 + intdiv($i, 8) * 95;
    $body .= '<rect x="' . $x . '" y="' . $y . '" width="' . random_int(24, 46) . '" height="60" rx="2"/>';
}
$body .= '</g>'
    . '<g fill="#2a2f36"><rect x="420" y="600" width="380" height="24"/><rect x="450" y="624" width="20" height="120"/><rect x="750" y="624" width="20" height="120"/></g>'
    . '<g transform="translate(470,540)"><rect width="260" height="60" fill="#3f3524" stroke="#4d4230"/>'
    . text(130, 38, 'ARCHIV 1998-2016', 15, '#d3c9ac', 'middle', 'monospace', 2) . '</g>'
    . timestampBar(1200, 800, 'MILLBROOK HIGH SCHOOL · ARCHIVRAUM', '13.10.2024 11:20');
write($root . '/photo-school.svg', svg(1200, 800, $body, gradient('wall', '#1a2028', '#0c1014')));

echo "\nKamerabilder und Dokumente fertig.\n";
