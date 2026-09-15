<?php
/**
 * Arbeitsauftraege des Falls "Where is Toby?".
 *
 * Der Fall bleibt frei begehbar - die Auftraege sagen nur, woran gerade zu arbeiten
 * ist und in welchem Bereich. Es wird immer nur das aktuelle Kapitel angezeigt,
 * damit nicht 19 Aufgaben gleichzeitig im Raum stehen.
 *
 * Felder je Auftrag:
 *   chapter / chapter_title  Kapitel, zu dem der Punkt gehoert
 *   title / detail           was zu tun ist und wo man suchen sollte
 *   panel                    Bereich, in den der Knopf "Oeffnen" springt
 *   requires                 ab wann der Punkt sichtbar wird
 *   done                     wann er als erledigt gilt
 */
declare(strict_types=1);

return [
    /* ---------------------------------------------------------------- 1 */
    [
        'id' => 'ob_room',
        'chapter' => 1,
        'chapter_title' => 'Tobys Zimmer',
        'title' => 'Den Tatort ansehen',
        'detail' => 'Oeffne in der Fallakte die Tatortaufnahmen A-5 und A-6. Details lassen sich anklicken und vergroessern.',
        'panel' => 'akte',
        'done' => ['evidence' => ['E02', 'E03', 'E04']],
    ],
    [
        'id' => 'ob_phone',
        'chapter' => 1,
        'chapter_title' => 'Tobys Zimmer',
        'title' => 'Tobys Smartphone entsperren',
        'detail' => 'Die vierstellige PIN steht nicht irgendwo - aber auf dem Schreibtisch wird ein Datum besonders hervorgehoben.',
        'panel' => 'geraete',
        'done' => ['puzzles' => ['pz_phone_pin']],
    ],
    [
        'id' => 'ob_laptop',
        'chapter' => 1,
        'chapter_title' => 'Tobys Zimmer',
        'title' => 'Tobys Laptop anmelden',
        'detail' => 'Der Notizzettel auf dem Schreibtisch nennt die Regel fuer das Passwort, nicht das Passwort selbst. Die Jahreszahl steht auf der Pinnwand.',
        'panel' => 'geraete',
        'done' => ['puzzles' => ['pz_laptop_pw']],
    ],

    /* ---------------------------------------------------------------- 2 */
    [
        'id' => 'ob_oldphone',
        'chapter' => 2,
        'chapter_title' => 'Der Abend',
        'title' => 'Das alte Zweitgeraet entsperren',
        'detail' => 'Asservat 03 ist mit einem Wischmuster gesperrt. Die Streiflichtaufnahme A-7 in der Fallakte zeigt die Fettspur auf dem Glas.',
        'panel' => 'geraete',
        'requires' => ['puzzles' => ['pz_phone_pin']],
        'done' => ['puzzles' => ['pz_oldphone_pattern']],
    ],
    [
        'id' => 'ob_chat',
        'chapter' => 2,
        'chapter_title' => 'Der Abend',
        'title' => 'Den geloeschten Chat wiederherstellen',
        'detail' => 'Im Papierkorb von Tobys Telefon liegen Reste eines geloeschten Verlaufs. Stelle ihn wieder her und pruefe, wessen Aussage er widerlegt.',
        'panel' => 'geraete',
        'requires' => ['devices' => ['dev_toby_phone']],
        'done' => ['puzzles' => ['pz_recover_chat']],
    ],
    [
        'id' => 'ob_home_cams',
        'chapter' => 2,
        'chapter_title' => 'Der Abend',
        'title' => 'Die Aussagen der Eltern pruefen',
        'detail' => 'Beide sagen, sie seien zu Hause gewesen. In der Fallakte liegen die Aufzeichnungen der Einfahrtskamera und der Tankstelle.',
        'panel' => 'akte',
        'requires' => ['puzzles' => ['pz_phone_pin']],
        'done' => ['puzzles' => ['pz_cam_garage', 'pz_cam_gas']],
    ],

    /* ---------------------------------------------------------------- 3 */
    [
        'id' => 'ob_ridge',
        'chapter' => 3,
        'chapter_title' => 'Die Spur nach Norden',
        'title' => 'Die Kamera an der Ridge Road auswerten',
        'detail' => 'Toby faehrt nach Norden. Suche in der Aufzeichnung ein Fahrzeug, das kurz nach ihm in dieselbe Richtung faehrt.',
        'panel' => 'akte',
        'requires' => ['evidence' => ['E06']],
        'done' => ['puzzles' => ['pz_cam_ridge']],
    ],
    [
        'id' => 'ob_plate',
        'chapter' => 3,
        'chapter_title' => 'Die Spur nach Norden',
        'title' => 'Das Kennzeichen bestimmen',
        'detail' => 'Das eingefrorene Bild zeigt es fast vollstaendig. Toby hat sich auf dem Altgeraet schon einmal eines notiert - vergleiche beide.',
        'panel' => 'akte',
        'requires' => ['puzzles' => ['pz_cam_ridge']],
        'done' => ['puzzles' => ['pz_plate']],
    ],
    [
        'id' => 'ob_audio',
        'chapter' => 3,
        'chapter_title' => 'Die Spur nach Norden',
        'title' => 'Tobys letzte Sprachnachricht auswerten',
        'detail' => 'Im Hintergrund sind zwei Geraeusche zu hoeren. Der Player kann langsamer abspielen - das trennt sie.',
        'panel' => 'beweise',
        'requires' => ['devices' => ['dev_toby_phone']],
        'done' => ['puzzles' => ['pz_audio_background']],
    ],

    /* ---------------------------------------------------------------- 4 */
    [
        'id' => 'ob_message',
        'chapter' => 4,
        'chapter_title' => 'Die Nachricht',
        'title' => 'Die Nachricht von 03:14 Uhr pruefen',
        'detail' => 'Sie soll von Toby stammen. Vergleiche sie mit seinen echten Chats - die Auswertung A-12 liegt in der Fallakte.',
        'panel' => 'akte',
        'requires' => ['flags' => ['nachricht_erschienen']],
        'done' => ['puzzles' => ['pz_style_message']],
    ],
    [
        'id' => 'ob_bus',
        'chapter' => 4,
        'chapter_title' => 'Die Nachricht',
        'title' => 'Die Weglauf-These pruefen',
        'detail' => 'Eine Busbuchung nach Montreal soll belegen, dass Toby weggelaufen ist. Suche zwei Dinge, die zur selben Zeit an zwei Orten waren.',
        'panel' => 'akte',
        'requires' => ['flags' => ['nachricht_erschienen']],
        'done' => ['puzzles' => ['pz_bus_contradiction']],
    ],
    [
        'id' => 'ob_reverse',
        'chapter' => 4,
        'chapter_title' => 'Die Nachricht',
        'title' => 'Den Anhang der Nachricht untersuchen',
        'detail' => 'Die Tonspur klingt vorwaerts nach nichts. Der Player kann die Richtung umkehren; die Morse-Referenz liegt in der Fallakte.',
        'panel' => 'beweise',
        'requires' => ['puzzles' => ['pz_recover_chat']],
        'done' => ['puzzles' => ['pz_audio_reverse']],
    ],
    [
        'id' => 'ob_locate',
        'chapter' => 4,
        'chapter_title' => 'Die Nachricht',
        'title' => 'Den Ort auf der Karte eingrenzen',
        'detail' => 'Drei Quellen zeigen auf denselben Bereich: das Hintergrundgeraeusch, das GPS des Zaunfotos und die Funkzelle.',
        'panel' => 'karte',
        'requires' => ['flags' => ['ort_stausee']],
        'done' => ['puzzles' => ['pz_locate_final']],
    ],

    /* ---------------------------------------------------------------- 5 */
    [
        'id' => 'ob_ww_login',
        'chapter' => 5,
        'chapter_title' => 'Die Wasserwerke',
        'title' => 'Den Dienstrechner im Kontrollraum oeffnen',
        'detail' => 'Die Nachschau A-9 zeigt den Arbeitsplatz. Wer sich ein Passwort nicht merken kann, schreibt es auf.',
        'panel' => 'geraete',
        'requires' => ['flags' => ['wasserwerke_im_blick']],
        'done' => ['puzzles' => ['pz_ww_login']],
    ],
    [
        'id' => 'ob_ww_logs',
        'chapter' => 5,
        'chapter_title' => 'Die Wasserwerke',
        'title' => 'Betriebsbuch und Zutrittsprotokoll durchsuchen',
        'detail' => 'Beide lassen sich filtern: das Betriebsbuch nach dem Datum der Tatnacht, das Zutrittsprotokoll nach "Pumpstation 4".',
        'panel' => 'geraete',
        'requires' => ['devices' => ['dev_ww_terminal']],
        'done' => ['puzzles' => ['pz_find_flush', 'pz_keycard']],
    ],
    [
        'id' => 'ob_ww_cam',
        'chapter' => 5,
        'chapter_title' => 'Die Wasserwerke',
        'title' => 'Kamera 4 zum Zeitpunkt des Zutritts pruefen',
        'detail' => 'Das Zutrittsprotokoll nennt die Uhrzeit. Achte im Bild auf das Licht am Kartenleser.',
        'panel' => 'akte',
        'requires' => ['puzzles' => ['pz_keycard']],
        'done' => ['puzzles' => ['pz_cam_pump']],
    ],
    [
        'id' => 'ob_folder',
        'chapter' => 5,
        'chapter_title' => 'Die Wasserwerke',
        'title' => 'Den geschuetzten Ordner oeffnen',
        'detail' => 'Der Dateikopf von "WARTUNG_ALT" nennt ein Stichwort. Die Zeitungsausschnitte A-16 nennen das passende Jahr.',
        'panel' => 'geraete',
        'requires' => ['evidence' => ['E16'], 'devices' => ['dev_ww_terminal']],
        'done' => ['puzzles' => ['pz_doss_folder']],
    ],

    /* ---------------------------------------------------------------- 6 */
    [
        'id' => 'ob_timeline',
        'chapter' => 6,
        'chapter_title' => 'Abschluss',
        'title' => 'Die Zeitleiste der Tatnacht schliessen',
        'detail' => 'Bringe die gesicherten Ereignisse in die richtige Reihenfolge. Alle Uhrzeiten stehen in den Beweisen.',
        'panel' => 'zeit',
        'requires' => ['evidence' => ['E06', 'E11', 'E12', 'E13']],
        'done' => ['puzzles' => ['pz_timeline']],
    ],
    [
        'id' => 'ob_report',
        'chapter' => 6,
        'chapter_title' => 'Abschluss',
        'title' => 'Den Abschlussbericht schreiben',
        'detail' => 'Taeter, Ort, Motiv, Luegner und Zeitleiste. Der Bericht laesst sich vor dem Absenden speichern.',
        'panel' => 'bericht',
        'requires' => ['flags' => ['ort_pumpstation_bekannt']],
        'done' => ['flags' => ['bericht_abgegeben']],
    ],
];
