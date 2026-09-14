<?php
/**
 * Fall "Toby" - Horror-Ereignisse.
 * Selten, fallabhaengig, mit Abstand. Jumpscares nur vereinzelt und abschaltbar.
 */
declare(strict_types=1);

return [
    [
        'id' => 'hr_lamp', 'type' => 'ui', 'intensity' => 'mild', 'priority' => 1, 'once' => true,
        'trigger' => ['event' => 'open_panel', 'match' => 'akte', 'chance' => 0.55, 'cooldown' => 30],
        'payload' => [
            'title' => 'Systemmeldung',
            'text' => 'Die Deckenleuchte ueber dem Arbeitsplatz flackert. Der Hausmeister hat sie letzte Woche getauscht.',
            'effect' => 'flicker', 'duration' => 2200,
        ],
    ],
    [
        'id' => 'hr_plate', 'type' => 'ui', 'intensity' => 'normal', 'priority' => 5, 'once' => true,
        'trigger' => ['event' => 'solve', 'match' => 'pz_plate', 'ignore_cooldown' => true],
        'payload' => [
            'title' => 'Bildauswertung',
            'text' => 'Im eingefrorenen Bild bewegt sich etwas hinter der Windschutzscheibe. Beim zweiten Durchlauf ist die Stelle dunkel.',
            'effect' => 'glitch', 'duration' => 2800, 'sound' => 'static_burst',
        ],
    ],
    [
        'id' => 'hr_message', 'type' => 'message', 'intensity' => 'normal', 'priority' => 9, 'once' => true,
        'trigger' => ['event' => 'solve', 'min_solved' => 4, 'chance' => 1.0, 'ignore_cooldown' => true],
        'payload' => [
            'title' => 'Neue Nachricht von TOBY BRENNAN (03:14)',
            'text' => 'Hoer auf, mich zu suchen.',
            'sender' => 'Toby Brennan · Account online',
            'effect' => 'flicker', 'duration' => 4200, 'sound' => 'ui_alert',
        ],
        'sets_flags' => ['nachricht_erschienen'],
    ],
    [
        'id' => 'hr_reverse', 'type' => 'audio', 'intensity' => 'intense', 'priority' => 8, 'once' => true,
        'trigger' => ['event' => 'solve', 'match' => 'pz_audio_reverse', 'ignore_cooldown' => true],
        'payload' => [
            'title' => 'Rueckwaertswiedergabe',
            'text' => 'Zwischen den Morsezeichen liegt etwas, das kein Signal ist. Eine Stimme, sehr leise, ruckwaerts: P U M P E  V I E R.',
            'effect' => 'glitch', 'duration' => 5000, 'sound' => 'whisper', 'reverse' => 'pumpe vier',
        ],
    ],
    [
        'id' => 'hr_folder', 'type' => 'file', 'intensity' => 'intense', 'priority' => 9, 'once' => true,
        'trigger' => ['event' => 'solve', 'match' => 'pz_doss_folder', 'ignore_cooldown' => true],
        'payload' => [
            'title' => 'WARTUNG_ALT · 47 Dateien',
            'text' => 'Die Ordner sind nach Jahren sortiert. In jedem liegt ein Foto aus demselben Betonraum. Vier Jahre, vier Gesichter. Das letzte Foto ist von heute Nacht, 02:11 Uhr.',
            'effect' => 'blackout', 'duration' => 5200, 'sound' => 'sting_low',
        ],
    ],
    [
        'id' => 'hr_cam_room', 'type' => 'ui', 'intensity' => 'intense', 'priority' => 9, 'once' => true,
        'trigger' => ['event' => 'solve', 'match' => 'pz_cam_pump', 'ignore_cooldown' => true],
        'payload' => [
            'title' => 'CAM 04 · 04:09:12',
            'text' => '{agent}: Die Kamera zeigt einen Raum mit zwei Bildschirmen und einem leeren Stuhl. Der rechte Bildschirm ist heller als der linke. Auf dem linken laeuft diese Akte.',
            'effect' => 'glitch', 'duration' => 4600, 'image' => 'assets/img/scenes/cam-pump-3.svg', 'sound' => 'static_burst',
        ],
    ],
    [
        'id' => 'hr_trash_extra', 'type' => 'file', 'intensity' => 'normal', 'priority' => 4, 'once' => true,
        'trigger' => ['event' => 'open_app', 'match' => 'dev_toby_phone:trash', 'chance' => 0.8, 'cooldown' => 60],
        'payload' => [
            'title' => 'Papierkorb',
            'text' => 'Fuer einen Moment steht eine Datei mehr in der Liste: schacht_2024_003.jpg, 02:11 Uhr. Beim Aktualisieren ist sie nicht mehr da.',
            'effect' => 'flicker', 'duration' => 3000,
        ],
    ],
    [
        'id' => 'hr_account_online', 'type' => 'npc', 'intensity' => 'normal', 'priority' => 6, 'once' => true,
        'trigger' => ['event' => 'open_panel', 'match' => 'personen', 'requires_flags' => ['nachricht_erschienen'], 'chance' => 0.6, 'cooldown' => 90],
        'payload' => [
            'title' => 'Statusmeldung',
            'text' => 'TOBY BRENNAN ist online. tippt ...   tippt ...   offline.',
            'effect' => 'flicker', 'duration' => 3600, 'sound' => 'ui_alert',
        ],
    ],
    [
        'id' => 'hr_call', 'type' => 'call', 'intensity' => 'normal', 'priority' => 6, 'once' => true,
        'trigger' => ['event' => 'open_panel', 'match' => 'beweise', 'requires_flags' => ['doss_konfrontiert'], 'chance' => 0.6, 'cooldown' => 90],
        'payload' => [
            'title' => 'Eingehender Anruf · keine Nummer',
            'text' => 'Das Asservatentelefon auf dem Tisch klingelt. Es hat keine SIM-Karte. Nach neun Sekunden ist Ruhe.',
            'effect' => 'flicker', 'duration' => 4000, 'sound' => 'call_unknown',
        ],
    ],
    [
        'id' => 'hr_agent_name', 'type' => 'system', 'intensity' => 'intense', 'priority' => 7, 'once' => true,
        'trigger' => ['event' => 'chat', 'min_solved' => 7, 'chance' => 0.5, 'cooldown' => 120],
        'payload' => [
            'title' => 'Terminal',
            'text' => 'In der Kopfzeile der Akte steht fuer einen Augenblick nicht die Fallnummer, sondern: {agent} (zuhoerend).',
            'effect' => 'glitch', 'duration' => 3400,
        ],
    ],
    [
        'id' => 'hr_photo_figure', 'type' => 'photo', 'intensity' => 'normal', 'priority' => 5, 'once' => true,
        'trigger' => ['event' => 'open_app', 'match' => 'dev_toby_phone:gallery', 'min_evidence' => 6, 'chance' => 0.55, 'cooldown' => 70],
        'payload' => [
            'title' => 'Galerie',
            'text' => 'Auf dem Bild vom Wasserturm stehen drei Personen. Beim zweiten Oeffnen sind es wieder zwei.',
            'effect' => 'flicker', 'duration' => 3200,
        ],
    ],
    [
        'id' => 'hr_board', 'type' => 'ui', 'intensity' => 'normal', 'priority' => 4, 'once' => true,
        'trigger' => ['event' => 'open_panel', 'match' => 'wand', 'min_evidence' => 8, 'chance' => 0.45, 'cooldown' => 80],
        'payload' => [
            'title' => 'Ermittlungswand',
            'text' => 'Zwei Karten sind mit einem Faden verbunden, den Sie nicht gezogen haben: Pumpstation 4 und 2015.',
            'effect' => 'flicker', 'duration' => 3000,
        ],
    ],
    [
        'id' => 'hr_other_case', 'type' => 'system', 'intensity' => 'normal', 'priority' => 5, 'once' => true,
        'trigger' => ['event' => 'open_panel', 'match' => 'beweise', 'min_evidence' => 11, 'chance' => 0.5, 'cooldown' => 100],
        'payload' => [
            'title' => 'Asservatenliste',
            'text' => 'Zwischen Ihren Beweisen steht ein Eintrag aus einem Fall, den Sie nicht bearbeiten: E-07 · Halskette · M. VANCE · 2015 · Fundort: Wartungsschacht.',
            'effect' => 'glitch', 'duration' => 3800,
        ],
    ],
    [
        'id' => 'hr_face', 'type' => 'ui', 'intensity' => 'intense', 'priority' => 8, 'once' => true,
        'trigger' => ['event' => 'solve', 'match' => 'pz_locate_final', 'chance' => 0.85, 'ignore_cooldown' => true],
        'payload' => [
            'title' => 'Kartendienst',
            'text' => 'Das Satellitenbild der Pumpstation laedt nach. Fuer einen Moment ist es kein Satellitenbild.',
            'effect' => 'glitch', 'duration' => 1600, 'image' => 'assets/img/scenes/horror-face.svg',
            'sound' => 'sting_low', 'jumpscare' => true,
        ],
    ],
    [
        'id' => 'hr_finish', 'type' => 'system', 'intensity' => 'normal', 'priority' => 3, 'once' => true,
        'trigger' => ['event' => 'finish', 'chance' => 1.0, 'ignore_cooldown' => true],
        'payload' => [
            'title' => 'Aktenverwaltung',
            'text' => 'Die Akte wird geschlossen. Im Protokoll erscheint eine letzte Zeile, die niemand geschrieben hat: naechster oktober.',
            'effect' => 'flicker', 'duration' => 4200, 'sound' => 'heartbeat',
        ],
    ],
];
