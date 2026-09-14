<?php
/**
 * Erzeugt die Tatort-, Foto- und Kamerabilder des Falls "Toby".
 * Aufruf: php tools/build_scenes.php
 */
declare(strict_types=1);

require_once __DIR__ . '/asset_lib.php';
require_once __DIR__ . '/scene_lib.php';

$root = dirname(__DIR__) . '/src/assets/img/scenes';
@mkdir($root, 0755, true);

/* ---------------- gemeinsame Bausteine ---------------- */

function nightRoom(string $wall1 = '#1a1f25', string $wall2 = '#0b0e12'): string
{
    return gradient('wall', $wall1, $wall2) . gradient('floor', '#15181d', '#080a0c') . gradient('glass', '#1d2b36', '#0a1218');
}

/* ---------------- 1. Tobys Zimmer ---------------- */

$body = '<rect width="1200" height="800" fill="url(#wall)"/>'
    . '<rect x="0" y="600" width="1200" height="200" fill="url(#floor)"/>'
    // Fenster
    . '<g><rect x="760" y="120" width="330" height="290" fill="url(#glass)" stroke="#2a323c" stroke-width="6"/>'
    . '<line x1="925" y1="120" x2="925" y2="410" stroke="#2a323c" stroke-width="6"/>'
    . '<line x1="760" y1="265" x2="1090" y2="265" stroke="#2a323c" stroke-width="6"/>'
    . '<path d="M770 400 q80 -120 160 -60 q60 40 150 30" fill="none" stroke="#0e1620" stroke-width="8" opacity=".8"/></g>'
    // Pinnwand mit Faeden
    . '<g><rect x="90" y="120" width="420" height="300" fill="#2a2318" stroke="#3a3122" stroke-width="8"/>'
    . '<g fill="#d9d5c8">'
    . '<rect x="120" y="150" width="90" height="64" transform="rotate(-3 165 182)"/>'
    . '<rect x="240" y="146" width="88" height="62" transform="rotate(2 284 177)"/>'
    . '<rect x="366" y="156" width="92" height="66" transform="rotate(-2 412 189)"/>'
    . '<rect x="140" y="268" width="96" height="66" transform="rotate(1 188 301)"/>'
    . '<rect x="300" y="280" width="100" height="70" transform="rotate(-4 350 315)"/>'
    . '</g>'
    . '<g stroke="#8d2b2e" stroke-width="2.4" opacity=".9" fill="none">'
    . '<path d="M165 182 L284 177"/><path d="M284 177 L412 189"/><path d="M412 189 L350 315"/><path d="M350 315 L188 301"/><path d="M188 301 L165 182"/>'
    . '</g>'
    . '<g fill="#b9343a">'
    . '<circle cx="165" cy="182" r="5"/><circle cx="284" cy="177" r="5"/><circle cx="412" cy="189" r="5"/><circle cx="350" cy="315" r="5"/><circle cx="188" cy="301" r="5"/>'
    . '</g></g>'
    // Schreibtisch
    . '<g><rect x="110" y="470" width="520" height="24" fill="#241f1a" stroke="#332b23"/>'
    . '<rect x="130" y="494" width="20" height="130" fill="#1d1915"/><rect x="590" y="494" width="20" height="130" fill="#1d1915"/>'
    // Laptop
    . '<g transform="translate(300,372)"><path d="M0 96 h210 l16 8 h-242z" fill="#2a2f36"/><rect x="18" y="0" width="176" height="96" rx="4" fill="#12161b" stroke="#333a42"/>'
    . '<rect x="26" y="8" width="160" height="80" fill="#0b1015"/>'
    . text(106, 44, 'ENTSPERREN', 11, '#3d6484', 'middle', 'monospace', 3)
    . text(106, 62, '_', 14, '#5b8cb5', 'middle', 'monospace', 1) . '</g>'
    // Kalender
    . '<g transform="translate(140,404) rotate(-2)"><rect width="110" height="64" fill="#e6e2d6" stroke="#b3ae9f"/>'
    . text(55, 20, 'SEPTEMBER', 9, '#4a463d', 'middle', 'monospace', 1)
    . text(55, 44, '04.09.', 19, '#8d2b2e', 'middle', 'monospace', 1)
    . text(55, 58, 'N-DAY', 9, '#4a463d', 'middle', 'monospace', 2) . '</g>'
    // Becher, Kabel
    . '<circle cx="560" cy="452" r="18" fill="#2b3138"/>'
    . '<path d="M560 470 q40 30 90 10" fill="none" stroke="#1b1f24" stroke-width="4"/></g>'
    // Bett
    . '<g><rect x="700" y="520" width="440" height="120" rx="8" fill="#20262d"/><rect x="700" y="500" width="130" height="50" rx="8" fill="#2a323b"/></g>'
    . '<rect x="0" y="0" width="1200" height="800" fill="#060a0e" opacity=".22"/>'
    . timestampBar(1200, 800, 'TATORTAUFNAHME 001 · ZIMMER T. BRENNAN', '12.10.2024 09:14');
write($root . '/photo-bedroom.svg', svg(1200, 800, $body, nightRoom()));

/* ---------------- 2. Schreibtisch nah ---------------- */

$body = '<rect width="1200" height="800" fill="url(#wall)"/>'
    . '<rect x="0" y="180" width="1200" height="620" fill="#241f1a"/>'
    . '<g opacity=".25" stroke="#3a322a">';
for ($i = 0; $i < 40; $i++) {
    $body .= '<line x1="0" y1="' . (190 + $i * 16) . '" x2="1200" y2="' . (196 + $i * 16) . '"/>';
}
$body .= '</g>'
    // Kalenderblatt gross
    . '<g transform="translate(120,240) rotate(-1.5)"><rect width="420" height="300" fill="#e8e4d8" stroke="#b7b2a2" stroke-width="3"/>'
    . text(210, 56, 'SEPTEMBER 2024', 22, '#3d3a33', 'middle', 'monospace', 3);
$days = ['01','02','03','04','05','06','07','08','09','10','11','12','13','14'];
foreach ($days as $index => $day) {
    $x = 44 + ($index % 7) * 56;
    $y = 110 + intdiv($index, 7) * 74;
    $body .= text($x, $y, $day, 17, '#4a463d', 'middle', 'monospace', 1);
    if ($day === '04') {
        $body .= '<circle cx="' . $x . '" cy="' . ($y - 6) . '" r="24" fill="none" stroke="#9b2f34" stroke-width="3"/>';
        $body .= text($x + 6, $y + 34, 'N-DAY', 13, '#9b2f34', 'middle', 'monospace', 2);
    }
}
$body .= text(210, 268, 'nicht vergessen!!', 15, '#9b2f34', 'middle', 'cursive', 1) . '</g>'
    // Notizzettel
    . '<g transform="translate(640,300) rotate(3)"><rect width="300" height="220" fill="#d8d3c2" stroke="#b0ab9b"/>'
    . text(20, 46, 'NACHTLINIE', 20, '#2f2c26', 'start', 'monospace', 3)
    . text(20, 80, 'NL + Jahr des', 15, '#4a463d', 'start', 'monospace', 1)
    . text(20, 104, 'ersten Falls', 15, '#4a463d', 'start', 'monospace', 1)
    . text(20, 146, '2003 / 2009 / 2015', 15, '#8d2b2e', 'start', 'monospace', 1)
    . text(20, 178, 'Abschnitt? -> 2,3,5 ...', 14, '#4a463d', 'start', 'monospace', 1) . '</g>'
    // Stift, Kabel, Becher
    . '<rect x="600" y="560" width="220" height="10" rx="5" fill="#1d2127" transform="rotate(-8 700 565)"/>'
    . '<circle cx="1040" cy="480" r="58" fill="#2b3138"/><circle cx="1040" cy="480" r="46" fill="#14181c"/>'
    . timestampBar(1200, 800, 'TATORTAUFNAHME 004 · SCHREIBTISCH', '12.10.2024 09:22');
write($root . '/photo-desk.svg', svg(1200, 800, $body, nightRoom('#20252c', '#0d1115')));

/* ---------------- 3. Wasserturm (Selfie 22:27) ---------------- */

$body = '<rect width="1200" height="800" fill="url(#wall)"/>'
    . '<circle cx="980" cy="150" r="70" fill="#1b232c" opacity=".7"/>'
    . '<g fill="#04060a"><path d="M0 640 L120 520 L200 560 L300 470 L420 570 L520 500 L640 600 L760 520 L900 610 L1040 530 L1200 620 L1200 800 L0 800Z"/></g>'
    // Wasserturm
    . '<g fill="#080c11" stroke="#141a21" stroke-width="3">'
    . '<rect x="700" y="300" width="12" height="330"/><rect x="790" y="300" width="12" height="330"/>'
    . '<path d="M676 300 h150 l-18 -58 h-114z"/><rect x="690" y="196" width="122" height="50" rx="8"/>'
    . '<path d="M676 300 l150 330 M826 300 l-150 330" stroke-width="2" opacity=".6"/></g>'
    . text(751, 228, 'MILLBROOK', 13, '#39424d', 'middle', 'monospace', 2)
    // Zwei Personen, Handylicht
    . '<ellipse cx="420" cy="700" rx="200" ry="60" fill="#121a22" opacity=".8"/>'
    . '<radialGradient id="phoneglow"><stop offset="0" stop-color="#7fa7c6" stop-opacity=".55"/><stop offset="1" stop-color="#7fa7c6" stop-opacity="0"/></radialGradient>'
    . '<circle cx="410" cy="560" r="150" fill="url(#phoneglow)"/>'
    . personSilhouette(360, 700, 1.5, '#0a0e13')
    . personSilhouette(470, 706, 1.42, '#080b10')
    . '<rect x="398" y="548" width="26" height="42" rx="4" fill="#9dc4e0" opacity=".85"/>'
    . '<rect x="0" y="0" width="1200" height="800" fill="#070b10" opacity=".3"/>'
    . timestampBar(1200, 800, 'FOTO AUS BACKUP · N. VANCE', '11.10.2024 22:27');
write($root . '/photo-watertower.svg', svg(1200, 800, $body, nightRoom('#141b24', '#070a0e')));

/* ---------------- 4. Zaun der Wasserwerke (22:51) ---------------- */

$body = '<rect width="1200" height="800" fill="url(#wall)"/>'
    . '<g fill="#04060a"><path d="M0 600 L200 540 L400 580 L600 520 L800 570 L1000 520 L1200 580 L1200 800 L0 800Z"/></g>'
    // Maschendrahtzaun
    . '<g stroke="#2b3440" stroke-width="2" opacity=".85">';
for ($x = 0; $x <= 1200; $x += 26) { $body .= '<line x1="' . $x . '" y1="220" x2="' . ($x + 60) . '" y2="720"/>'; }
for ($x = -300; $x <= 1200; $x += 26) { $body .= '<line x1="' . $x . '" y1="720" x2="' . ($x + 60) . '" y2="220"/>'; }
$body .= '</g>'
    . '<g fill="#161c24" stroke="#232b35"><rect x="100" y="200" width="16" height="540"/><rect x="600" y="200" width="16" height="540"/><rect x="1080" y="200" width="16" height="540"/></g>'
    // Warnschild
    . '<g transform="translate(420,330) rotate(-2)"><rect width="330" height="170" fill="#d9d4c4" stroke="#8f8b7d" stroke-width="3"/>'
    . '<rect x="0" y="0" width="330" height="36" fill="#8d2b2e"/>'
    . text(165, 26, 'ZUTRITT VERBOTEN', 16, '#f0ece1', 'middle', 'monospace', 2)
    . text(165, 74, 'MILLBROOK WATER WORKS', 17, '#37342d', 'middle', 'monospace', 1)
    . text(165, 104, 'PUMPSTATION 4', 21, '#8d2b2e', 'middle', 'monospace', 2)
    . text(165, 134, 'Betriebsgelaende - Video', 12, '#5c584e', 'middle', 'monospace', 1)
    . text(165, 154, 'Notruf 802-555-0140', 12, '#5c584e', 'middle', 'monospace', 1) . '</g>'
    // Rueckleuchten eines Transporters hinter dem Zaun
    . '<g opacity=".9"><rect x="820" y="470" width="150" height="70" rx="6" fill="#0d1116"/>'
    . '<circle cx="840" cy="505" r="9" fill="#c9484d"/><circle cx="950" cy="505" r="9" fill="#c9484d"/>'
    . '<ellipse cx="895" cy="560" rx="120" ry="18" fill="#3a1416" opacity=".5"/></g>'
    . '<rect x="0" y="0" width="1200" height="800" fill="#060a0e" opacity=".34"/>'
    . timestampBar(1200, 800, 'FOTO AUS BACKUP · GERAET T. BRENNAN', '11.10.2024 22:51');
write($root . '/photo-fence.svg', svg(1200, 800, $body, nightRoom('#151c25', '#07090d')));

/* ---------------- 5. Fahrrad im Schuppen ---------------- */

$body = '<rect width="1200" height="800" fill="url(#wall)"/>'
    . '<g opacity=".5" stroke="#2c2620" stroke-width="3">';
for ($y = 0; $y < 800; $y += 60) { $body .= '<line x1="0" y1="' . $y . '" x2="1200" y2="' . ($y + 10) . '"/>'; }
$body .= '</g>'
    . '<rect x="0" y="640" width="1200" height="160" fill="#14181c"/>'
    // Fahrrad
    . '<g transform="translate(240,300)" stroke="#2f3843" stroke-width="10" fill="none">'
    . '<circle cx="120" cy="300" r="110"/><circle cx="560" cy="300" r="110"/>'
    . '<path d="M120 300 L300 120 L470 120 L560 300 M300 120 L360 300 L120 300 M470 120 L520 60"/>'
    . '<path d="M290 100 h110" stroke-width="12"/>'
    . '</g>'
    // weisse Lackspur am Rahmen
    . '<g><path d="M520 380 q60 -40 120 -30" stroke="#d8dade" stroke-width="9" fill="none" opacity=".9"/>'
    . '<circle cx="580" cy="362" r="52" fill="none" stroke="#9b2f34" stroke-width="3" stroke-dasharray="8 6"/>'
    . text(580, 300, 'LACKSPUR', 13, '#c2666a', 'middle', 'monospace', 2) . '</g>'
    . '<g fill="#1a1e24"><rect x="900" y="420" width="240" height="220"/><rect x="930" y="450" width="180" height="40" fill="#22272e"/></g>'
    . timestampBar(1200, 800, 'ASSERVAT 07 · FAHRRAD (GERaeTESCHUPPEN BRENNAN)', '12.10.2024 14:40');
write($root . '/photo-bike.svg', svg(1200, 800, $body, nightRoom('#211c17', '#0c0e11')));

/* ---------------- 6. Kontrollraum Wasserwerke ---------------- */

$body = '<rect width="1200" height="800" fill="url(#wall)"/>'
    . '<rect x="0" y="520" width="1200" height="280" fill="#1c2027"/>'
    // Monitore
    . '<g><rect x="120" y="120" width="420" height="270" rx="6" fill="#0a0f14" stroke="#2c333c" stroke-width="8"/>'
    . '<rect x="140" y="140" width="380" height="230" fill="#0c1218"/>'
    . text(160, 176, 'SCADA · ABSCHNITT 4', 15, '#4e87a8', 'start', 'monospace', 2)
    . text(160, 206, 'SPUELUNG   AKTIV', 14, '#6fae86', 'start', 'monospace', 1)
    . text(160, 232, 'DRUCK      2.4 bar', 14, '#8d97a3', 'start', 'monospace', 1)
    . text(160, 258, 'VENTIL V12 OFFEN', 14, '#8d97a3', 'start', 'monospace', 1)
    . text(160, 300, 'LETZTER EINTRAG 05:58', 14, '#c2666a', 'start', 'monospace', 1)
    . '<g stroke="#2a4d61" opacity=".7">';
for ($i = 0; $i < 6; $i++) { $body .= '<line x1="150" y1="' . (330 + $i * 6) . '" x2="510" y2="' . (330 + $i * 6) . '"/>'; }
$body .= '</g></g>'
    . '<g><rect x="620" y="150" width="380" height="240" rx="6" fill="#0a0f14" stroke="#2c333c" stroke-width="8"/>'
    . '<rect x="640" y="170" width="340" height="200" fill="#0b1015"/>'
    . text(810, 260, 'KAMERA 4 · OFFLINE', 16, '#7e5a5c', 'middle', 'monospace', 2) . '</g>'
    // Tastatur mit Zettel
    . '<g transform="translate(300,560) rotate(-1)"><rect width="520" height="150" rx="8" fill="#22272f" stroke="#2f3640"/>'
    . '<g fill="#171b21">';
for ($row = 0; $row < 4; $row++) {
    for ($col = 0; $col < 13; $col++) {
        $body .= '<rect x="' . (16 + $col * 38) . '" y="' . (16 + $row * 32) . '" width="30" height="24" rx="3"/>';
    }
}
$body .= '</g></g>'
    // Zettel unter der Tastatur (Hotspot-Ziel)
    . '<g transform="translate(700,690) rotate(6)"><rect width="230" height="96" fill="#e3dfd2" stroke="#b6b1a2" stroke-width="2"/>'
    . text(115, 34, 'ZUGANG', 12, '#57534a', 'middle', 'monospace', 3)
    . text(115, 66, 'Halloway98', 24, '#2c2a25', 'middle', 'monospace', 1) . '</g>'
    . '<rect x="0" y="0" width="1200" height="800" fill="#060a0e" opacity=".2"/>'
    . timestampBar(1200, 800, 'DURCHSUCHUNG · KONTROLLRAUM WASSERWERKE', '13.10.2024 08:05');
write($root . '/photo-office.svg', svg(1200, 800, $body, nightRoom('#1d232b', '#0a0d11')));

/* ---------------- 7. Handydisplay mit Wischspuren ---------------- */

$body = '<rect width="900" height="1200" fill="#05070a"/>'
    . '<rect x="40" y="40" width="820" height="1120" rx="60" fill="#0a0d11" stroke="#22272e" stroke-width="10"/>'
    . '<rect x="80" y="110" width="740" height="980" rx="30" fill="#0c1116"/>'
    // Punkteraster
    . '<g fill="none" stroke="#1d242c" stroke-width="4">';
$positions = [];
for ($row = 0; $row < 3; $row++) {
    for ($col = 0; $col < 3; $col++) {
        $x = 250 + $col * 200;
        $y = 400 + $row * 200;
        $positions[($row * 3) + $col + 1] = [$x, $y];
        $body .= '<circle cx="' . $x . '" cy="' . $y . '" r="26"/>';
    }
}
$body .= '</g>'
    // Wischspur 1-4-7-8-9 (L-Form)
    . '<g stroke="#b9c6d2" stroke-width="26" stroke-linecap="round" opacity=".28" fill="none">'
    . '<path d="M250 400 L250 600 L250 800 L450 800 L650 800"/></g>'
    . '<g stroke="#e6edf4" stroke-width="6" stroke-linecap="round" opacity=".2" fill="none">'
    . '<path d="M250 400 L250 600 L250 800 L450 800 L650 800"/></g>'
    . text(450, 250, 'MUSTER ZEICHNEN', 22, '#4d5a68', 'middle', 'monospace', 4)
    . text(450, 1010, 'Fettspuren im Streiflicht', 17, '#5b6876', 'middle', 'monospace', 1)
    . timestampBar(900, 1200, 'ASSERVAT 03 · ALTGERAeT', 'LABORAUFNAHME');
write($root . '/photo-phone-smudge.svg', svg(900, 1200, $body, nightRoom()));

/* ---------------- 8. Pumpstation aussen und innen ---------------- */

$body = '<rect width="1200" height="800" fill="url(#wall)"/>'
    . '<path d="M0 520 q300 -60 600 -20 q300 40 600 -10 L1200 800 L0 800Z" fill="#0a0f14"/>'
    // Wasser
    . '<path d="M0 470 q300 -50 600 -14 q300 36 600 -6 L1200 470 L0 470Z" fill="#0d1a22"/>'
    // Gebaeude
    . '<g fill="#111720" stroke="#1d242d" stroke-width="3">'
    . '<rect x="420" y="330" width="380" height="220"/><path d="M400 330 h420 l-40 -60 h-340z"/>'
    . '<rect x="470" y="410" width="70" height="90" fill="#070a0e"/><rect x="580" y="410" width="70" height="90" fill="#070a0e"/>'
    . '<rect x="690" y="400" width="80" height="150" fill="#0a0e12"/></g>'
    . '<rect x="700" y="418" width="60" height="18" fill="#c9a94a" opacity=".22"/>'
    . text(610, 300, 'PUMPSTATION 4 · STILLGELEGT 1998', 14, '#5a6674', 'middle', 'monospace', 2)
    // Rohre
    . '<g stroke="#1a2129" stroke-width="22" fill="none"><path d="M120 560 h300 M800 560 h280"/></g>'
    . '<g stroke="#212a33" stroke-width="10" fill="none"><path d="M160 520 v80 M1040 520 v80"/></g>'
    . '<rect x="0" y="0" width="1200" height="800" fill="#05080c" opacity=".3"/>'
    . timestampBar(1200, 800, 'OBJEKT · HALLOWAY-STAUSEE, WARTUNGSZUFAHRT', '13.10.2024 04:30');
write($root . '/photo-pump-station.svg', svg(1200, 800, $body, nightRoom('#131a22', '#070a0d')));

$body = '<rect width="1200" height="800" fill="#080b0f"/>'
    // Betonwaende
    . '<g fill="#12161b"><rect x="0" y="0" width="1200" height="520"/></g>'
    . '<g opacity=".4" stroke="#1b2128" stroke-width="2">';
for ($y = 40; $y < 520; $y += 80) { $body .= '<line x1="0" y1="' . $y . '" x2="1200" y2="' . ($y + 6) . '"/>'; }
$body .= '</g>'
    . '<rect x="0" y="520" width="1200" height="280" fill="#0b0f13"/>'
    // Pumpe und Rohre
    . '<g fill="#161c23" stroke="#222a33" stroke-width="4">'
    . '<rect x="120" y="300" width="260" height="220" rx="8"/><circle cx="250" cy="300" r="70"/>'
    . '<rect x="380" y="380" width="520" height="40" rx="8"/><rect x="900" y="260" width="80" height="260"/></g>'
    . '<g stroke="#2a323b" stroke-width="12" fill="none"><path d="M250 230 v-120 M980 260 h180"/></g>'
    // Notlicht
    . '<radialGradient id="lamp"><stop offset="0" stop-color="#c9a94a" stop-opacity=".5"/><stop offset="1" stop-color="#c9a94a" stop-opacity="0"/></radialGradient>'
    . '<circle cx="640" cy="150" r="190" fill="url(#lamp)"/>'
    . '<rect x="620" y="120" width="40" height="14" fill="#3a3222"/>'
    // Matratze, Decke, Wasserflaschen: Hinweis auf Gefangenschaft
    . '<g><rect x="560" y="600" width="300" height="90" rx="6" fill="#20242a"/>'
    . '<path d="M560 640 q80 -30 160 -10 q60 16 140 -6 l0 66 h-300z" fill="#2b3038"/>'
    . '<circle cx="900" cy="660" r="14" fill="#1b2a30"/><circle cx="930" cy="668" r="12" fill="#1b2a30"/></g>'
    // Kratzspuren an der Wand
    . '<g stroke="#5c5248" stroke-width="3" opacity=".8" fill="none">'
    . '<path d="M980 420 v70 M1000 414 v78 M1020 424 v64 M1040 410 v82"/></g>'
    . text(1010, 392, 'STRICHLISTE', 11, '#6d6253', 'middle', 'monospace', 2)
    . '<rect x="0" y="0" width="1200" height="800" fill="#05080b" opacity=".45"/>'
    . timestampBar(1200, 800, 'WARTUNGSSCHACHT · UNTERE EBENE', 'ZUGRIFF 13.10.2024');
write($root . '/photo-pump-interior.svg', svg(1200, 800, $body, nightRoom()));

echo "\nSzenenbilder fertig.\n";
