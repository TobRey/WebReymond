<?php
/**
 * Fall "Toby" - Medien: Fotos, Videos, Audios, Dokumente.
 */
declare(strict_types=1);

return [
    'photos' => [
        [
            'id' => 'ph_bedroom', 'title' => 'Tatortaufnahme 001 - Tobys Zimmer',
            'src' => 'assets/img/scenes/photo-bedroom.svg', 'thumb' => 'assets/img/scenes/photo-bedroom.svg',
            'aspect' => 0.667,
            'caption' => 'Aufnahme der Spurensicherung, 12.10.2024. Fenster von innen verschlossen. Zoomen und Details anklicken.',
            'exif' => [
                'Aufnahmezeit' => '12.10.2024 09:14:22',
                'Geraet'       => 'FBI ERT Canon R6',
                'Blitz'        => 'aus',
                'GPS'          => '43.9012, -72.9431 (14 Maple St)',
            ],
            'hotspots' => [
                [
                    'id' => 'hs_board', 'label' => 'Pinnwand mit rotem Faden', 'x' => 0.25, 'y' => 0.34, 'r' => 0.14,
                    'zoom_min' => 1, 'evidence' => 'E02',
                    'text' => 'Fuenf Zeitungsausschnitte, mit rotem Faden verbunden: drei Vermisstenfaelle (2003, 2009, 2015) und zwei Artikel ueber die Wasserwerke. An einer Nadel klebt ein Zettel: "immer Oktober. immer Spuelung."',
                ],
                [
                    'id' => 'hs_calendar', 'label' => 'Tischkalender', 'x' => 0.155, 'y' => 0.545, 'r' => 0.06,
                    'zoom_min' => 1.5, 'evidence' => 'E03',
                    'text' => 'Auf dem Kalender ist der 4. September rot eingekreist, daneben steht "N-DAY". Darunter: "nicht vergessen!!"',
                ],
                [
                    'id' => 'hs_laptop', 'label' => 'Laptop', 'x' => 0.34, 'y' => 0.52, 'r' => 0.09,
                    'zoom_min' => 1, 'text' => 'Der Laptop ist als Asservat 02 gesichert. Er verlangt ein Passwort. Bildschirmhintergrund: ein Ausschnitt einer Landkarte mit dem Stausee.',
                ],
                [
                    'id' => 'hs_window', 'label' => 'Fenster', 'x' => 0.77, 'y' => 0.33, 'r' => 0.1,
                    'zoom_min' => 1, 'text' => 'Von innen verriegelt. Keine Werkzeugspuren. Wer hier raus wollte, ist durch die Haustuer gegangen.',
                ],
                [
                    'id' => 'hs_cable', 'label' => 'Ladekabel am Bett', 'x' => 0.62, 'y' => 0.66, 'r' => 0.07,
                    'zoom_min' => 2, 'text' => 'Das Ladekabel liegt noch am Bett. Wer freiwillig fuer immer geht, nimmt sein Ladekabel mit.',
                ],
            ],
        ],
        [
            'id' => 'ph_desk', 'title' => 'Tatortaufnahme 004 - Schreibtisch (Nahaufnahme)',
            'src' => 'assets/img/scenes/photo-desk.svg', 'thumb' => 'assets/img/scenes/photo-desk.svg',
            'aspect' => 0.667,
            'caption' => 'Detailaufnahme. Der Kalender und der Notizzettel enthalten die Grundlagen fuer zwei Zugangscodes.',
            'exif' => [
                'Aufnahmezeit' => '12.10.2024 09:22:10',
                'Geraet'       => 'FBI ERT Canon R6',
                'Objektiv'     => '50 mm Makro',
            ],
            'hotspots' => [
                [
                    'id' => 'hs_date', 'label' => 'Rot eingekreister 4. September', 'x' => 0.28, 'y' => 0.36, 'r' => 0.08,
                    'zoom_min' => 1, 'evidence' => 'E03',
                    'text' => '4. September, rot eingekreist, daneben "N-DAY". In Tobys Chats heisst Nora "N". Der Tag, an dem sie sich kennengelernt haben.',
                ],
                [
                    'id' => 'hs_note', 'label' => 'Notizzettel "NACHTLINIE"', 'x' => 0.66, 'y' => 0.5, 'r' => 0.1,
                    'zoom_min' => 1, 'evidence' => 'E04',
                    'text' => 'Handschriftlich: "NACHTLINIE - NL + Jahr des ersten Falls". Darunter: "2003 / 2009 / 2015" und "Abschnitt? -> 2,3,5 ..."',
                ],
            ],
        ],
        [
            'id' => 'ph_watertower', 'title' => 'Wasserturm, 22:27 Uhr',
            'src' => 'assets/img/scenes/photo-watertower.svg', 'thumb' => 'assets/img/scenes/photo-watertower.svg',
            'aspect' => 0.667, 'requires_puzzle' => 'pz_phone_pin',
            'caption' => 'Aus dem Cloud-Backup von Tobys Telefon. Zwei Personen am Wasserturm.',
            'exif' => [
                'Aufnahmezeit' => '11.10.2024 22:27:41',
                'Geraet'       => 'Greenline G7 (T. Brennan)',
                'Blitz'        => 'aus',
                'GPS'          => '43.9114, -72.9302 (Ridge Road, Wasserturm)',
                'Hinweis'      => 'Frontkamera, Selbstaufnahme',
            ],
            'hotspots' => [
                [
                    'id' => 'hs_two', 'label' => 'Zwei Personen', 'x' => 0.35, 'y' => 0.8, 'r' => 0.13,
                    'zoom_min' => 1, 'evidence' => 'E07',
                    'text' => 'Zwei Jugendliche, nebeneinander, im Licht eines Handydisplays. Die rechte Person traegt eine helle Jacke mit dem Aufdruck der Millbrook High School Theatergruppe - Noras Jacke.',
                ],
                [
                    'id' => 'hs_tower', 'label' => 'Wasserturm', 'x' => 0.62, 'y' => 0.45, 'r' => 0.1,
                    'zoom_min' => 1.5, 'text' => 'Der alte Wasserturm am Ende der Ridge Road. 1,4 km vom Wohnhaus.',
                ],
            ],
        ],
        [
            'id' => 'ph_fence', 'title' => 'Warnschild Wasserwerke, 22:51 Uhr',
            'src' => 'assets/img/scenes/photo-fence.svg', 'thumb' => 'assets/img/scenes/photo-fence.svg',
            'aspect' => 0.667, 'requires_puzzle' => 'pz_phone_pin',
            'caption' => 'Letztes Foto auf Tobys Telefon. Die Metadaten sind vollstaendig erhalten.',
            'exif' => [
                'Aufnahmezeit' => '11.10.2024 22:51:08',
                'Geraet'       => 'Greenline G7 (T. Brennan)',
                'Blitz'        => 'aus (Restlicht)',
                'GPS'          => '43.9298, -72.9021',
                'Aufloesung'   => '4032 x 3024',
                'Bewertung'    => 'GPS entspricht dem Betriebsgelaende Millbrook Water Works, Nordseite',
            ],
            'hotspots' => [
                [
                    'id' => 'hs_sign', 'label' => 'Warnschild', 'x' => 0.48, 'y' => 0.52, 'r' => 0.14,
                    'zoom_min' => 1, 'evidence' => 'E08',
                    'text' => '"MILLBROOK WATER WORKS - PUMPSTATION 4 - Zutritt verboten". Toby war um 22:51 am Zaun des Wasserwerksgelaendes.',
                ],
                [
                    'id' => 'hs_taillights', 'label' => 'Rueckleuchten', 'x' => 0.75, 'y' => 0.63, 'r' => 0.09,
                    'zoom_min' => 2, 'text' => 'Hinter dem Zaun stehen die Rueckleuchten eines Fahrzeugs. Zu unscharf fuer ein Kennzeichen - aber jemand war dort, bevor Toby eintraf.',
                ],
            ],
        ],
        [
            'id' => 'ph_bike', 'title' => 'Asservat 07 - Fahrrad',
            'src' => 'assets/img/scenes/photo-bike.svg', 'thumb' => 'assets/img/scenes/photo-bike.svg',
            'aspect' => 0.667, 'requires_flags' => ['frank_gestanden'],
            'caption' => 'Sichergestellt im Geraeteschuppen der Familie Brennan, nachdem Frank Brennan den Fundort genannt hat.',
            'exif' => ['Aufnahmezeit' => '12.10.2024 14:40:02', 'Bearbeiter' => 'ERT Rutland'],
            'hotspots' => [
                [
                    'id' => 'hs_paint', 'label' => 'Weisse Lackspur am Rahmen', 'x' => 0.48, 'y' => 0.46, 'r' => 0.09,
                    'zoom_min' => 1, 'evidence' => 'E20',
                    'text' => 'Weisser Fremdlack auf dem Oberrohr, dazu eine Delle. Laborbefund: Industrielack RAL 9016, wie an Nutzfahrzeugen der staedtischen Betriebe. Das Rad wurde von einem weissen Fahrzeug gerammt oder gestreift.',
                ],
            ],
        ],
        [
            'id' => 'ph_office', 'title' => 'Kontrollraum Wasserwerke',
            'src' => 'assets/img/scenes/photo-office.svg', 'thumb' => 'assets/img/scenes/photo-office.svg',
            'aspect' => 0.667, 'requires_flags' => ['wasserwerke_im_blick'],
            'caption' => 'Aufnahme bei der freiwilligen Nachschau im Betriebshof. Der Bildschirm zeigt die Spuelung von Abschnitt 4.',
            'exif' => ['Aufnahmezeit' => '13.10.2024 08:05:44', 'Ort' => 'Millbrook Water Works, Kontrollraum'],
            'hotspots' => [
                [
                    'id' => 'hs_note_key', 'label' => 'Zettel unter der Tastatur', 'x' => 0.68, 'y' => 0.9, 'r' => 0.1,
                    'zoom_min' => 1.5, 'evidence' => 'E24',
                    'text' => 'Unter der Tastatur klebt ein Zettel: "ZUGANG - Halloway98". Damit laesst sich der Dienstrechner im Kontrollraum entsperren.',
                ],
                [
                    'id' => 'hs_screen', 'label' => 'SCADA-Bildschirm', 'x' => 0.27, 'y' => 0.32, 'r' => 0.13,
                    'zoom_min' => 1, 'text' => 'Spuelung Abschnitt 4, Ventil V12 offen, letzter Eintrag 05:58. Alle Nachtarbeiten der Tatnacht wurden in einer einzigen Minute nachgetragen.',
                ],
                [
                    'id' => 'hs_cam4', 'label' => 'Kamera 4', 'x' => 0.67, 'y' => 0.34, 'r' => 0.1,
                    'zoom_min' => 1, 'text' => 'Kamera 4 (Pumpstation 4) ist als "offline" markiert. Die Aufzeichnung laeuft trotzdem - sie wird nur nicht angezeigt.',
                ],
            ],
        ],
        [
            'id' => 'ph_smudge', 'title' => 'Asservat 03 - Altgeraet, Displayspuren',
            'src' => 'assets/img/scenes/photo-phone-smudge.svg', 'thumb' => 'assets/img/scenes/photo-phone-smudge.svg',
            'aspect' => 1.333,
            'caption' => 'Streiflichtaufnahme des Displays. Fettspuren zeigen das zuletzt gezeichnete Muster.',
            'exif' => ['Aufnahmezeit' => '12.10.2024 16:12:00', 'Verfahren' => 'Streiflicht, 45 Grad'],
            'hotspots' => [
                [
                    'id' => 'hs_pattern', 'label' => 'Wischspur', 'x' => 0.5, 'y' => 0.5, 'r' => 0.28,
                    'zoom_min' => 1, 'evidence' => 'E25',
                    'text' => 'Die Spur laeuft von oben links gerade nach unten und dann nach rechts - eine L-Form ueber die Punkte 1, 4, 7, 8, 9.',
                ],
            ],
        ],
        [
            'id' => 'ph_pump_ext', 'title' => 'Pumpstation 4 - Aussenaufnahme',
            'src' => 'assets/img/scenes/photo-pump-station.svg', 'thumb' => 'assets/img/scenes/photo-pump-station.svg',
            'aspect' => 0.667, 'requires_flags' => ['ort_pumpstation_bekannt'],
            'caption' => 'Nordufer des Halloway-Stausees. Stillgelegt 1998, Zugang nur mit Schluesselkarte.',
            'exif' => ['Aufnahmezeit' => '13.10.2024 04:30:12', 'GPS' => '43.9331, -72.8988'],
            'hotspots' => [
                [
                    'id' => 'hs_door', 'label' => 'Wartungstuer', 'x' => 0.61, 'y' => 0.6, 'r' => 0.08,
                    'zoom_min' => 1, 'text' => 'Die Tuer ist neu gestrichen und hat ein modernes Kartenlesegeraet - an einer Anlage, die seit 26 Jahren "stillgelegt" ist.',
                ],
                [
                    'id' => 'hs_vent', 'label' => 'Belueftungsrohr', 'x' => 0.13, 'y' => 0.7, 'r' => 0.07,
                    'zoom_min' => 1.5, 'text' => 'Ein Belueftungsrohr fuehrt in den unteren Wartungsschacht. Es ist frei geraeumt. Warum belueftet man einen stillgelegten Keller?',
                ],
            ],
        ],
        [
            'id' => 'ph_school', 'title' => 'Zeitungsarchiv der Schule',
            'src' => 'assets/img/scenes/photo-school.svg', 'thumb' => 'assets/img/scenes/photo-school.svg',
            'aspect' => 0.667,
            'caption' => 'Archivraum im Keller der Millbrook High School. Hier hat Toby recherchiert.',
            'exif' => ['Aufnahmezeit' => '13.10.2024 11:20:31'],
            'hotspots' => [
                [
                    'id' => 'hs_box', 'label' => 'Kiste 1998-2016', 'x' => 0.5, 'y' => 0.68, 'r' => 0.12,
                    'zoom_min' => 1, 'text' => 'Die Archivkiste ist herausgezogen. Im Ausleihzettel steht dreimal derselbe Name: T. Brennan, 12.09., 26.09., 09.10.2024 - freigegeben von G. Hale.',
                ],
            ],
        ],
    ],

    'videos' => [
        [
            'id' => 'vid_ridge', 'title' => 'Verkehrskamera Ridge Road (22:50 - 23:06)',
            'camera' => 'CAM 03 · RIDGE RD / RESERVOIR ACCESS', 'date' => '11.10.2024',
            'start_clock' => '22:50:00', 'duration' => 960, 'puzzle' => 'pz_cam_ridge',
            'help' => 'Die einzige Zufahrt zum Stausee. Fahre die Aufzeichnung durch und melde den Zeitpunkt, an dem ein Fahrzeug Richtung Stausee faehrt.',
            'frames' => [
                ['t' => 0, 'src' => 'assets/img/scenes/cam-ridge-1.svg', 'note' => 'Leere Strasse, Regen hat aufgehoert.'],
                ['t' => 211, 'src' => 'assets/img/scenes/cam-ridge-2.svg', 'note' => '22:53:31 - Ein Radfahrer faehrt Richtung Norden. Helle Jacke, Kopfhoerer.'],
                ['t' => 497, 'src' => 'assets/img/scenes/cam-ridge-3.svg', 'note' => '22:58:17 - Weisser Kastenwagen mit Aufschrift und lesbarem Kennzeichen faehrt Richtung Stausee.'],
                ['t' => 892, 'src' => 'assets/img/scenes/cam-ridge-4.svg', 'note' => '23:04:52 - Nur Rueckleuchten in der Ferne. Kein Fahrzeug kommt zurueck.'],
            ],
            'markers' => [
                ['t' => 211, 'label' => 'Radfahrer', 'hidden' => false],
                ['t' => 497, 'label' => 'Fahrzeug', 'hidden' => true],
            ],
        ],
        [
            'id' => 'vid_gas', 'title' => "Tankstellenkamera Miller's Gas (22:30 - 22:40)",
            'camera' => "CAM 01 · MILLER'S GAS FORECOURT", 'date' => '11.10.2024',
            'start_clock' => '22:30:00', 'duration' => 600, 'puzzle' => 'pz_cam_gas',
            'help' => 'Aufzeichnung des Vorplatzes. Melde den Zeitpunkt, an dem eine Person tankt, die zu dieser Zeit laut Aussage zu Hause war.',
            'frames' => [
                ['t' => 0, 'src' => 'assets/img/scenes/cam-gas-1.svg', 'note' => 'Leerer Vorplatz.'],
                ['t' => 288, 'src' => 'assets/img/scenes/cam-gas-2.svg', 'note' => '22:34:48 - Ein dunkelblauer Kombi an Saeule 2. Eine Frau in heller Jacke tankt. Kennzeichen VT 3RF-902 (Halterin: D. Brennan).'],
                ['t' => 566, 'src' => 'assets/img/scenes/cam-gas-3.svg', 'note' => '22:39:26 - Der Kombi verlaesst den Vorplatz Richtung Stadt.'],
            ],
            'markers' => [['t' => 288, 'label' => 'Tankvorgang', 'hidden' => true]],
        ],
        [
            'id' => 'vid_garage', 'title' => 'Einfahrtskamera Brennan (22:10 - 23:10)',
            'camera' => 'CAM PRIVAT · 14 MAPLE ST', 'date' => '11.10.2024',
            'start_clock' => '22:10:00', 'duration' => 3600, 'puzzle' => 'pz_cam_garage',
            'help' => 'Private Kamera der Familie. Drei Bewegungen in einer Stunde. Melde den Zeitpunkt, an dem der Wagen zurueckkommt.',
            'frames' => [
                ['t' => 0, 'src' => 'assets/img/scenes/cam-garage-1.svg', 'note' => 'Ruhige Einfahrt. Licht im Erdgeschoss aus.'],
                ['t' => 398, 'src' => 'assets/img/scenes/cam-garage-1.svg', 'note' => '22:16:38 - Toby schiebt sein Fahrrad aus dem Schuppen und faehrt Richtung Ridge Road. Rucksack auf dem Ruecken.'],
                ['t' => 1202, 'src' => 'assets/img/scenes/cam-garage-2.svg', 'note' => '22:30:02 - Der Wagen von Frank Brennan verlaesst die Einfahrt.'],
                ['t' => 3347, 'src' => 'assets/img/scenes/cam-garage-3.svg', 'note' => '23:05:47 - Der Wagen kommt zurueck. Der Kofferraum ist geoeffnet, darin ein Fahrradreifen. Eine Person traegt etwas in den Schuppen.'],
            ],
            'markers' => [
                ['t' => 398, 'label' => 'Toby faehrt los', 'hidden' => false],
                ['t' => 1202, 'label' => 'Wagen faehrt weg', 'hidden' => false],
                ['t' => 3347, 'label' => 'Rueckkehr', 'hidden' => true],
            ],
        ],
        [
            'id' => 'vid_pump', 'title' => 'Kamera 4 - Pumpstation 4 (03:55 - 04:12)',
            'camera' => 'CAM 04 · PUMP STATION DOOR', 'date' => '12.10.2024',
            'start_clock' => '03:55:00', 'duration' => 1020, 'requires_flags' => ['ww_terminal_offen'],
            'puzzle' => 'pz_cam_pump',
            'help' => 'Diese Kamera ist im Kontrollraum als "offline" markiert - die Aufzeichnung lief trotzdem. Melde den Zeitpunkt, an dem die Tuer geoeffnet wird.',
            'frames' => [
                ['t' => 0, 'src' => 'assets/img/scenes/cam-pump-1.svg', 'note' => 'Geschlossene Wartungstuer. Rotes Licht am Kartenleser.'],
                ['t' => 453, 'src' => 'assets/img/scenes/cam-pump-2.svg', 'note' => '04:02:33 - Das Licht am Leser wird gruen. Eine Person oeffnet die Tuer und traegt einen Kanister und eine Decke hinein.'],
                ['t' => 840, 'src' => 'assets/img/scenes/cam-pump-3.svg', 'note' => 'Bildstoerung. Dieses Bild gehoert nicht zu dieser Kamera.'],
            ],
            'markers' => [['t' => 453, 'label' => 'Tuer', 'hidden' => true]],
        ],
    ],

    'audios' => [
        [
            'id' => 'au_voice', 'title' => 'Sprachnachricht an Nora, 23:12 Uhr',
            'track' => 'voice_toby', 'meta' => '11.10.2024 23:12:04 · 27 Sekunden · abgebrochen',
            'puzzle' => 'pz_audio_background', 'voice_pitch' => 0.92,
            'note' => 'Die Stimme ist gedaempft, als waere das Telefon in einer Tasche. Im Hintergrund zwei deutliche Geraeusche. Tempo und Rueckwaertswiedergabe helfen beim Heraushoeren.',
            'transcript' => [
                ['t' => 1.2, 'text' => 'nora ... ka ob du das hoerst'],
                ['t' => 4.0, 'text' => '(Rauschen, Schritte auf Kies)'],
                ['t' => 7.5, 'text' => 'ich bin am zaun ... da ist jemand, der hat aufgeschlossen'],
                ['t' => 12.0, 'text' => '(tiefes, regelmaessiges Stampfen, etwa alle 0,9 Sekunden)'],
                ['t' => 15.5, 'text' => 'das ist nicht der hausmeister ey, das ist ein werkswagen'],
                ['t' => 19.0, 'text' => '(zwei lange Signaltoene, tief, aus der Ferne)'],
                ['t' => 21.5, 'text' => 'wenn ich nicht zurueckschreib, dann sag denen von der nachtlinie'],
                ['t' => 25.0, 'text' => 'warte, er ... (Knacken)'],
                ['t' => 27.4, 'text' => '(Aufnahme endet)'],
            ],
            'markers' => [
                ['t' => 12.0, 'label' => 'Stampfen', 'color' => '#b98a35'],
                ['t' => 19.0, 'label' => 'Signal', 'color' => '#a02a2f'],
                ['t' => 27.4, 'label' => 'Abbruch', 'color' => '#a02a2f'],
            ],
        ],
        [
            'id' => 'au_reverse', 'title' => 'Unbenannte Audiodatei aus dem Papierkorb',
            'track' => 'morse_reverse', 'meta' => 'Anhang der Nachricht vom 12.10.2024, 03:14 Uhr · 6 Sekunden',
            'puzzle' => 'pz_audio_reverse', 'requires_puzzle' => 'pz_recover_chat',
            'note' => 'Diese Datei hing an der Nachricht "Hoer auf, mich zu suchen." Vorwaerts klingt sie wie Rauschen. Rueckwaerts sind regelmaessige Toene zu hoeren - vergleiche sie mit der Morsecode-Referenz in der Fallakte.',
            'transcript' => [
                ['t' => 0.0, 'text' => '(Rauschen)'],
                ['t' => 1.4, 'text' => '(Toene, unregelmaessige Laengen - rueckwaerts abspielen)'],
                ['t' => 4.6, 'text' => '(Rauschen)'],
            ],
            'markers' => [['t' => 1.4, 'label' => 'Signalfolge', 'color' => '#a02a2f']],
        ],
        [
            'id' => 'au_nora', 'title' => 'Mailbox-Nachricht von Nora, 23:41 Uhr',
            'track' => 'voice_nora', 'meta' => '11.10.2024 23:41 · 13 Sekunden', 'voice_pitch' => 1.18,
            'requires_puzzle' => 'pz_phone_pin',
            'note' => 'Noras Stimme, sehr schnell gesprochen, im Hintergrund ein Fernseher.',
            'transcript' => [
                ['t' => 0.6, 'text' => 'Toby, das ist nicht witzig. Ruf zurueck.'],
                ['t' => 4.0, 'text' => 'Ich hab deine Nachricht gehoert, da war so ein Klopfen.'],
                ['t' => 8.2, 'text' => 'Wenn du da oben bist, fahr weg. Bitte. Ich sag nichts, ich sag es niemandem.'],
            ],
        ],
        [
            'id' => 'au_call', 'title' => 'Eingehender Anruf ohne Nummer',
            'track' => 'call_unknown', 'meta' => 'Asservatentelefon, Mitschnitt · 9 Sekunden',
            'requires_flags' => ['doss_konfrontiert'],
            'note' => 'Der Anruf erreichte das Asservatentelefon in der Auswertung. Es hat keine SIM-Karte.',
            'transcript' => [
                ['t' => 0.5, 'text' => '(Freizeichen, dann Leitungsrauschen)'],
                ['t' => 4.5, 'text' => '(Atmen. Dann, sehr leise, der Name des Agenten.)'],
            ],
        ],
    ],

    'documents' => [
        [
            'id' => 'doc_poster', 'title' => 'Vermisstenplakat Tobias Brennan',
            'src' => 'assets/img/scenes/poster-toby.svg',
            'heading' => 'Fahndungsplakat',
            'body' => "Verteilt an alle Dienststellen im Bezirk sowie an Tankstellen entlang der Route 12.\nHinweise an das Millbrook Field Office.",
        ],
        [
            'id' => 'doc_clippings', 'title' => 'Zeitungsarchiv - vier Ausschnitte',
            'src' => 'assets/img/scenes/doc-clipping.svg',
            'heading' => 'Millbrook Sentinel, Archivkopien',
            'body' => "2003 - Karen Pielmeier (16), vermisst nach dem Herbstfest. Wartungsarbeiten Abschnitt 2.\n2009 - Danny Oro (17), Fahrrad an der Ridge Road gefunden. Spuelung Abschnitt 3.\n2015 - Marisol Vance (18), Zeuge nennt weissen Transporter und zieht die Aussage zurueck. Abschnitt 5 gesperrt.\n1998 - Pumpstation 4 wird stillgelegt. Betriebsleiter Walter Doss betont, die Anlage werde weiter gewartet.\n\nToby hat in seiner Kopie drei Woerter unterstrichen: \"Spuelung\", \"gesperrt\", \"Doss\".",
        ],
        [
            'id' => 'doc_registry', 'title' => 'Halterabfrage VT 7KD-418',
            'heading' => 'Kraftfahrzeugregister Vermont - Auszug',
            'body' => "KENNZEICHEN: VT 7KD-418\nFAHRZEUG:    Ford E-350 Kastenwagen, weiss (RAL 9016)\nHALTER:      City of Millbrook - Water Works Department\nSTANDORT:    Betriebshof Reservoir Access\nNUTZUNG:     Bereitschaftsfahrzeug, Schluesselausgabe ueber Betriebsleitung\n\nZUGEWIESENE FAHRER (Nachtbereitschaft Oktober 2024):\n  W. Doss (Betriebsleiter)  - Hauptnutzer\n  R. Pike (Techniker)       - Urlaub 05.10. - 20.10.2024\n\nBEWERTUNG: In der Tatnacht war das Fahrzeug ausschliesslich W. Doss zugeordnet.",
        ],
        [
            'id' => 'doc_bus', 'title' => 'Fernbusbuchung Millbrook - Montreal',
            'heading' => 'Buchungsbestaetigung (Kopie des Anbieters)',
            'body' => "BUCHUNG #NL-4471822\nStrecke:   Millbrook -> Burlington -> Montreal\nAbfahrt:   12.10.2024, 01:20 Uhr\nName:      T. BRENNAN\nBezahlung: Debitkarte **** 4417, Online, 12.10.2024 00:58 Uhr\nIP-Adresse der Buchung: 68.14.22.107 (Kabelanschluss Reservoir Access 4 - Wasserwerke)\nEinstieg:  nicht erfolgt (Bordkamera, Fahrerliste)\n\nWIDERSPRUCH DER TECHNIK:\nDie verwendete Debitkarte **** 4417 lag zum Zeitpunkt der Buchung nachweislich auf\ndem Schreibtisch im Kinderzimmer (Tatortaufnahme 004, 09:22 Uhr, unveraendert).\nDie Buchung wurde aus dem Netz der Wasserwerke ausgefuehrt.",
        ],
        [
            'id' => 'doc_message', 'title' => 'Nachricht "Hoer auf, mich zu suchen."',
            'heading' => 'Technische Auswertung der Nachricht vom 12.10.2024, 03:14 Uhr',
            'body' => "EMPFAENGER:   Nora Vance, Diane Brennan (Gruppenchat)\nABSENDER:     Account T. Brennan (Greenline Messenger)\nZEIT:         12.10.2024 03:14:22\nFUNKZELLE:    RESERVOIR-01\nINHALT:       \"Hoer auf, mich zu suchen.\"\nANHANG:       audio_0002.wav (6 s), ohne Vorschaubild\n\nSTILVERGLEICH (812 Referenznachrichten):\n  Grossbuchstabe am Satzanfang      -> Toby: 3 %   diese Nachricht: ja\n  Punkt am Satzende                 -> Toby: 4 %   diese Nachricht: ja\n  \"ok\" als \"k\"                      -> Toby: 100 % diese Nachricht: entfaellt\n  Tippfehler                        -> Toby: 6,1 % diese Nachricht: 0 %\n\nBEWERTUNG: Wahrscheinlichkeit, dass diese Nachricht von T. Brennan selbst getippt\nwurde: sehr gering. Die Nachricht wurde vom Geraet, aber nicht von seiner Hand gesendet.",
        ],
        [
            'id' => 'doc_folder', 'title' => 'Ordner "WARTUNG_ALT" (Dienstrechner Kontrollraum)',
            'heading' => 'Inhalt des passwortgeschuetzten Ordners',
            'requires_puzzle' => 'pz_doss_folder',
            'body' => "Der Ordner enthaelt 47 Dateien, sortiert nach Jahren: 2003, 2009, 2015, 2024.\n\nJe Jahr:\n  - ein Scan des Betriebsbuchs mit der jeweiligen Spuelung (Abschnitt 2, 3, 5, 4)\n  - eine Liste mit Namen, Alter, Schulweg und Arbeitszeiten von Jugendlichen\n  - Fotos aus dem unteren Wartungsschacht der Pumpstation 4\n\nDie Fotos von 2003, 2009 und 2015 zeigen jeweils eine Person im Schacht.\nDrei Namen, die nie gefunden wurden. Der Ordner 2024 enthaelt bisher zwei Dateien:\neine Namensliste mit einem eingekreisten Eintrag (T. Brennan) und ein Foto,\nauf dem eine Decke, eine Wasserflasche und eine Strichliste an der Wand zu sehen sind.\nDie Strichliste zeigt zwei Striche.\n\nAUFNAHMEZEIT DER LETZTEN DATEI: 13.10.2024, 02:11 Uhr.\nToby war zu diesem Zeitpunkt noch am Leben.",
        ],
    ],
];
