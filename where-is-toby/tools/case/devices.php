<?php
/**
 * Fall "Toby" - Geraete (Handys, Rechner) mit allen Inhalten.
 */
declare(strict_types=1);

return [
    /* =====================================================
     |  Tobys Smartphone (Cloud-Backup)
     ===================================================== */
    [
        'id' => 'dev_toby_phone', 'name' => 'Tobys Smartphone (Cloud-Backup)', 'type' => 'phone',
        'owner' => 'Tobias Brennan', 'evidence_tag' => 'Asservat 01',
        'note' => 'Geraet selbst fehlt. Das Backup vom 11.10., 23:59 Uhr liegt vollstaendig vor.',
        'carrier' => 'GREENLINE · BACKUP', 'clock' => '23:59',
        'lock' => [
            'type' => 'pin', 'puzzle' => 'pz_phone_pin', 'length' => 4,
            'label' => 'Backup verschluesselt', 'user' => 'toby.brennan@greenline',
            'hint' => 'Das Backup ist mit der Geraete-PIN verschluesselt (4 Ziffern). Toby hat in einem Chat verraten, woraus sie besteht - der Kalender in seinem Zimmer nennt das Datum.',
        ],
        'apps' => [
            [
                'id' => 'messages', 'label' => 'Nachrichten', 'type' => 'messages', 'badge' => 2,
                'content' => [
                    'threads' => [
                        [
                            'id' => 'th_nora', 'contact' => 'Nora V.', 'number' => '+1 802 555 0193',
                            'messages' => [
                                ['from' => 'them', 'text' => 'hast du bio gemacht', 'time' => '11.10. 17:22'],
                                ['from' => 'me', 'text' => 'ne. ka wie das gehen soll', 'time' => '11.10. 17:24'],
                                ['from' => 'them', 'text' => 'du bist so ein idiot', 'time' => '11.10. 17:24'],
                                ['from' => 'me', 'text' => 'ey ich hab was. das mit den spuelungen passt', 'time' => '11.10. 19:31'],
                                ['from' => 'them' , 'text' => 'lass das mit dem wasserwerk toby', 'time' => '11.10. 19:44'],
                                ['from' => 'me', 'text' => 'k', 'time' => '11.10. 22:04'],
                                ['from' => 'them', 'text' => '[Nachricht #4473 bis #4478 nicht im Backup - Luecke 22:05 bis 22:31]', 'time' => '11.10. 22:31', 'deleted' => true],
                                ['from' => 'them', 'text' => 'toby?? schreib zurueck', 'time' => '11.10. 23:38'],
                                ['from' => 'them', 'text' => 'bitte', 'time' => '11.10. 23:52'],
                            ],
                        ],
                        [
                            'id' => 'th_mama', 'contact' => 'Mama', 'number' => '+1 802 555 0170',
                            'messages' => [
                                ['from' => 'them', 'text' => 'Ich bin spaeter da. Es ist Essen im Kuehlschrank.', 'time' => '11.10. 21:38'],
                                ['from' => 'me', 'text' => 'wo bist du', 'time' => '11.10. 21:41'],
                                ['from' => 'them', 'text' => 'Termin. Frag nicht.', 'time' => '11.10. 21:42'],
                                ['from' => 'me', 'text' => 'k', 'time' => '11.10. 21:42'],
                            ],
                        ],
                        [
                            'id' => 'th_elias', 'contact' => 'Elias M.', 'number' => '+1 802 555 0166',
                            'messages' => [
                                ['from' => 'me', 'text' => 'kannst du heute mit zum nordtor', 'time' => '11.10. 18:02'],
                                ['from' => 'them', 'text' => 'ne schicht bis 23. hab getauscht mit pike junior', 'time' => '11.10. 18:20'],
                                ['from' => 'them', 'text' => 'geh da nicht alleine hin ey', 'time' => '11.10. 18:21'],
                                ['from' => 'me', 'text' => 'ich bin nicht alleine. lantern ist da', 'time' => '11.10. 18:26'],
                                ['from' => 'them', 'text' => 'wer ist lantern', 'time' => '11.10. 18:40'],
                            ],
                        ],
                        [
                            'id' => 'th_group', 'contact' => 'Familie + Nora (Gruppe)',
                            'requires_flags' => ['nachricht_erschienen'],
                            'messages' => [
                                ['from' => 'them', 'text' => '[Diane] Toby, bitte melde dich. Irgendwie. Bitte.', 'time' => '12.10. 01:12'],
                                ['from' => 'me', 'text' => 'Hoer auf, mich zu suchen.', 'time' => '12.10. 03:14'],
                                ['from' => 'me', 'text' => '[Anhang: audio_0002.wav · 6 s]', 'time' => '12.10. 03:14'],
                                ['from' => 'them', 'text' => '[Nora] das schreibt er nicht. so schreibt er nicht.', 'time' => '12.10. 03:19'],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'id' => 'gallery', 'label' => 'Galerie', 'type' => 'gallery',
                'content' => [
                    'note' => 'Sortiert nach Aufnahmezeit. Die letzten beiden Bilder entstanden nach 22:20 Uhr.',
                    'photos' => [
                        ['media' => 'ph_watertower', 'title' => '22:27 Wasserturm', 'thumb' => 'assets/img/scenes/photo-watertower.svg'],
                        ['media' => 'ph_fence', 'title' => '22:51 Warnschild', 'thumb' => 'assets/img/scenes/photo-fence.svg'],
                        ['media' => 'ph_desk', 'title' => '09.10. Schreibtisch (eigene Aufnahme)', 'thumb' => 'assets/img/scenes/photo-desk.svg'],
                    ],
                ],
            ],
            [
                'id' => 'audio', 'label' => 'Sprachnachrichten', 'type' => 'audio',
                'content' => [
                    'clips' => [
                        ['media' => 'au_voice', 'title' => 'An Nora · 23:12 Uhr', 'meta' => '27 s · abgebrochen'],
                        ['media' => 'au_nora', 'title' => 'Von Nora · 23:41 Uhr', 'meta' => '13 s · Mailbox'],
                        ['media' => 'au_reverse', 'title' => 'audio_0002.wav · Anhang 03:14', 'meta' => '6 s · wiederhergestellt'],
                    ],
                ],
            ],
            [
                'id' => 'browser', 'label' => 'Verlauf', 'type' => 'browser',
                'content' => [
                    'note' => 'Verlauf der letzten 48 Stunden.',
                    'entries' => [
                        ['time' => '10.10. 20:14', 'title' => 'Millbrook Water Works - Betriebsordnung (PDF)', 'url' => 'millbrook-vt.gov/water/ordnung.pdf', 'body' => "Toby hat §4 (Nachtarbeiten) und §7 (Zutritt stillgelegter Anlagen) markiert."],
                        ['time' => '10.10. 21:02', 'title' => 'Gueterstrecke CN-114 Nachtplan', 'url' => 'railfans-vt.org/cn114', 'body' => "Markiert: 23:11 Millbrook Nord -> Rutland."],
                        ['time' => '11.10. 16:40', 'title' => 'Nachtlinie - Entwurf: Die Oktober-Linie', 'url' => 'nachtlinie.blog/entwurf/oktober', 'body' => "Entwurf, unveroeffentlicht:\n\"Drei Faelle, drei Oktober, drei Spuelungen. Wer nachts legal ein Werksfahrzeug fahren und eine Strasse sperren darf, braucht kein Versteck - er hat einen Dienstauftrag.\""],
                        ['time' => '11.10. 19:12', 'title' => 'Wie erkennt man ein gefaelschtes Betriebsbuch', 'url' => 'suche/betriebsbuch+nachtrag'],
                        ['time' => '11.10. 19:35', 'title' => 'Forum Nachtlinie - Nachricht von Lantern', 'url' => 'forum.nachtlinie.blog/pn/lantern'],
                    ],
                ],
            ],
            [
                'id' => 'notes', 'label' => 'Notizen', 'type' => 'notes',
                'content' => [
                    'notes' => [
                        ['title' => 'oktober', 'date' => '09.10.2024', 'text' => "2003 abschnitt 2\n2009 abschnitt 3\n2015 abschnitt 5\n-> immer eine spuelung in derselben nacht\n-> wer gibt spuelungen frei? betriebsleiter\n-> name: doss (seit 96)"],
                        ['title' => 'wenn was passiert', 'date' => '11.10.2024', 'text' => "wenn ich nicht schreibe: nora hat den usb\npasswort laptop steht auf dem zettel am monitor (NL + jahr)\nnicht die polizei hier. die haben 2015 schon nichts gemacht"],
                        ['title' => 'lantern', 'date' => '28.09.2024', 'text' => "kennt betriebsbuecher von 22 jahren\nkennt die sperrzeiten\nwill sich nicht treffen wenn jemand mitkommt\n-> entweder insider oder er ist es selbst"],
                    ],
                ],
            ],
            [
                'id' => 'calls', 'label' => 'Anrufe', 'type' => 'calls',
                'content' => [
                    'note' => 'Der letzte eingehende Anruf kam von einer unterdrueckten Nummer.',
                    'entries' => [
                        ['time' => '11.10. 22:41', 'name' => 'Unbekannt', 'number' => 'unterdrueckt', 'direction' => 'eingehend', 'duration' => '0:38', 'note' => ''],
                        ['time' => '11.10. 20:10', 'name' => 'Nora V.', 'number' => '+1 802 555 0193', 'direction' => 'ausgehend', 'duration' => '6:12'],
                        ['time' => '11.10. 18:44', 'name' => 'Elias M.', 'number' => '+1 802 555 0166', 'direction' => 'eingehend', 'duration' => '2:01'],
                        ['time' => '10.10. 17:03', 'name' => 'Millbrook Water Works', 'number' => '+1 802 555 0140', 'direction' => 'ausgehend', 'duration' => '0:22'],
                    ],
                ],
            ],
            [
                'id' => 'trash', 'label' => 'Geloescht', 'type' => 'deleted',
                'requires_puzzle' => 'pz_recover_chat',
                'locked_hint' => 'Der Papierkorb enthaelt nur Fragmente. Sie muessen zuerst in die richtige Reihenfolge gebracht werden.',
                'content' => [
                    'note' => 'Wiederhergestellt aus dem nicht zugewiesenen Speicherbereich. Zeitstempel aus den Dateikoepfen.',
                    'items' => [
                        ['name' => 'msg_4473.frag', 'size' => '0,4 KB', 'deleted_at' => '11.10. 23:57', 'body' => "22:05 T: kannst du zum wasserturm kommen. 20 min", 'meta' => 'Fragment 1 von 6'],
                        ['name' => 'msg_4474.frag', 'size' => '0,4 KB', 'deleted_at' => '11.10. 23:57', 'body' => "22:07 N: es ist halb elf ey", 'meta' => 'Fragment 2 von 6'],
                        ['name' => 'msg_4475.frag', 'size' => '0,5 KB', 'deleted_at' => '11.10. 23:57', 'body' => "22:08 T: ich hab den typ von der wasserwerkssache. er will mir die protokolle zeigen", 'meta' => 'Fragment 3 von 6'],
                        ['name' => 'msg_4476.frag', 'size' => '0,4 KB', 'deleted_at' => '11.10. 23:57', 'body' => "22:09 N: das ist so eine dumme idee", 'meta' => 'Fragment 4 von 6'],
                        ['name' => 'msg_4477.frag', 'size' => '0,3 KB', 'deleted_at' => '11.10. 23:57', 'body' => "22:26 T: bin da", 'meta' => 'Fragment 5 von 6'],
                        ['name' => 'msg_4478.frag', 'size' => '0,5 KB', 'deleted_at' => '11.10. 23:57', 'body' => "22:31 N: ich muss heim. fahr NICHT alleine dahin", 'meta' => 'Fragment 6 von 6'],
                        ['name' => 'audio_0002.wav', 'size' => '128 KB', 'deleted_at' => '12.10. 03:15', 'media' => 'au_reverse', 'meta' => 'Anhang der Nachricht von 03:14 Uhr'],
                    ],
                ],
            ],
        ],
    ],

    /* =====================================================
     |  Tobys Laptop
     ===================================================== */
    [
        'id' => 'dev_toby_laptop', 'name' => 'Tobys Laptop', 'type' => 'laptop',
        'owner' => 'Tobias Brennan', 'evidence_tag' => 'Asservat 02',
        'note' => 'Forensische Kopie, nur lesend. Ein Benutzerkonto, kein Cloud-Zwang.',
        'os' => 'FORENSIC IMAGE · /dev/sdb1 · read only',
        'lock' => [
            'type' => 'password', 'puzzle' => 'pz_laptop_pw',
            'label' => 'Anmeldung erforderlich', 'user' => 'toby',
            'hint' => 'Ein Zettel am Monitor nennt die Bildungsregel des Passworts. Der Rest steht auf seiner Pinnwand.',
        ],
        'apps' => [
            [
                'id' => 'files', 'label' => 'Dateien', 'type' => 'files',
                'content' => [
                    'root' => 'C:\\Users\\toby',
                    'note' => 'Ordner "nachtlinie" ist der Recherche-Ordner. Zeitstempel beachten.',
                    'tree' => [
                        [
                            'name' => 'nachtlinie', 'type' => 'folder', 'modified' => '11.10.2024 19:36',
                            'children' => [
                                ['name' => 'oktober_linie.md', 'type' => 'file', 'size' => '6 KB', 'modified' => '11.10.2024 16:40',
                                 'body' => "# Die Oktober-Linie (Entwurf 4)\n\n2003 Karen Pielmeier, 16 - Herbstfest - Abschnitt 2 gespuelt\n2009 Danny Oro, 17 - Fahrrad Ridge Road - Abschnitt 3 gespuelt\n2015 Marisol Vance, 18 - Zeuge nennt weissen Transporter - Abschnitt 5 gesperrt\n\nIn allen drei Naechten steht im Betriebsbuch eine Spuelung. Immer in dem Abschnitt,\nin dem die Person verschwand. Immer freigegeben von derselben Unterschrift.\n\nWarum das wichtig ist: Eine Spuelung erlaubt nachts ein Werksfahrzeug im Sperrgebiet\nund haelt gleichzeitig alle anderen fern. Kein Versteck noetig. Ein Dienstauftrag genuegt.\n\nWas mir fehlt: die Betriebsbuecher. Lantern sagt, er hat 22 Jahre.\n\nWenn ich richtig liege, ist der naechste Oktober der von 2024."],
                                ['name' => 'altfaelle.csv', 'type' => 'file', 'size' => '2 KB', 'modified' => '09.10.2024 21:12',
                                 'body' => "jahr;name;alter;ort;abschnitt;quelle\n2003;Karen Pielmeier;16;State Route 12;2;Sentinel 12.10.2003\n2009;Danny Oro;17;Ridge Road;3;Sentinel 19.10.2009\n2015;Marisol Vance;18;Route 12 / Depot;5;Sentinel 24.10.2015\n2024;???;;;4?;"],
                                ['name' => 'archiv_scans', 'type' => 'folder', 'modified' => '09.10.2024 21:30', 'children' => [
                                    ['name' => 'sentinel_2003.jpg', 'type' => 'file', 'size' => '1,2 MB', 'modified' => '09.10.2024 21:22', 'media' => 'doc_clippings'],
                                    ['name' => 'sentinel_2009.jpg', 'type' => 'file', 'size' => '1,1 MB', 'modified' => '09.10.2024 21:24', 'media' => 'doc_clippings'],
                                    ['name' => 'sentinel_2015.jpg', 'type' => 'file', 'size' => '1,4 MB', 'modified' => '09.10.2024 21:26', 'media' => 'doc_clippings'],
                                    ['name' => 'sentinel_1998_pumpstation.jpg', 'type' => 'file', 'size' => '0,9 MB', 'modified' => '09.10.2024 21:28', 'media' => 'doc_clippings'],
                                ]],
                                ['name' => 'forum_lantern.txt', 'type' => 'file', 'size' => '4 KB', 'modified' => '11.10.2024 19:36', 'puzzle' => '',
                                 'body' => "PRIVATNACHRICHTEN - KONTO nachtlinie / KONTAKT lantern\n\n20.09. lantern: ich habe 22 jahre betriebsbuecher. du suchst die spuelungen, richtig?\n20.09. toby:   wer bist du\n20.09. lantern: jemand, der dort arbeitet. mehr nicht.\n28.09. lantern: frag nicht in der verwaltung nach. der betriebsleiter faehrt selbst nachts.\n28.09. toby:   warum hilfst du mir\n28.09. lantern: weil 2015 niemand zugehoert hat.\n09.10. lantern: freitag 23 uhr, nordtor. ich lasse dir die kopien da.\n11.10. toby:   bin um 23 am zaun. wenn du mich verarschst, geht alles online\n11.10. lantern: ich verarsche dich nicht. komm allein, sonst bleibt das tor zu.\n11.10. toby:   bin unterwegs\n\n[letzte Aktivitaet des Kontos lantern: 12.10.2024 03:16]"],
                                ['name' => 'usb_backup', 'type' => 'folder', 'modified' => '11.10.2024 17:10', 'children' => [
                                    ['name' => 'HINWEIS.txt', 'type' => 'file', 'size' => '0,2 KB', 'modified' => '11.10.2024 17:10',
                                     'body' => "Der USB-Stick liegt bei N. Nicht hier. Wer das liest und nicht ich ist:\nfrag Nora nach der Oktober-Linie. Sie weiss, wo er ist."],
                                ]],
                            ],
                        ],
                        [
                            'name' => 'schule', 'type' => 'folder', 'modified' => '10.10.2024 18:00', 'children' => [
                                ['name' => 'bio_referat.docx', 'type' => 'file', 'size' => '340 KB', 'modified' => '08.10.2024 22:41', 'body' => 'Zellatmung. Unfertig. Note voraussichtlich schlecht.'],
                                ['name' => 'zeitung_layout.pdf', 'type' => 'file', 'size' => '2,1 MB', 'modified' => '10.10.2024 18:00', 'body' => 'Layout der Schuelerzeitung, Ausgabe November. Platzhalter fuer einen Artikel mit dem Arbeitstitel "OKTOBER".'],
                            ],
                        ],
                        [
                            'name' => 'papierkorb', 'type' => 'folder', 'modified' => '11.10.2024 19:40', 'children' => [
                                ['name' => 'anzeige_polizei_entwurf.txt', 'type' => 'file', 'size' => '1 KB', 'modified' => '10.10.2024 23:12', 'deleted' => true,
                                 'body' => "Entwurf Anzeige (nicht abgeschickt)\n\nIch moechte Anzeige erstatten gegen den Betriebsleiter der Millbrook Water Works,\nWalter Doss, wegen Verdachts auf Beteiligung an drei Vermisstenfaellen.\nBeweise: Betriebsbuecher 2003, 2009, 2015 (liegen mir noch nicht vor).\n\n[Entwurf verworfen - \"ohne die buecher lachen die mich aus\"]"],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'id' => 'mail', 'label' => 'E-Mail', 'type' => 'mail',
                'content' => [
                    'account' => 'toby.brennan@millbrookhigh.edu',
                    'messages' => [
                        [
                            'id' => 'm1', 'from' => 'g.hale@millbrookhigh.edu', 'to' => 'toby.brennan@millbrookhigh.edu',
                            'subject' => 'Archivzugang', 'date' => '09.10.2024 07:41',
                            'body' => "Toby,\n\nder Archivraum ist am Mittwoch nach der siebten Stunde offen. Kiste 1998-2016 steht\nbereits draussen. Bitte nichts mitnehmen, nur kopieren.\n\nUnd: Sei vorsichtig mit Namen. Ich habe 2015 erlebt, wie schnell so etwas auf einen\nzurueckfaellt.\n\nG. Hale",
                        ],
                        [
                            'id' => 'm2', 'from' => 'no-reply@forum.nachtlinie.blog', 'to' => 'toby.brennan@millbrookhigh.edu',
                            'subject' => 'Neue Privatnachricht von lantern', 'date' => '09.10.2024 20:14',
                            'body' => "Sie haben eine neue Privatnachricht.\n\n\"freitag 23 uhr, nordtor. ich lasse dir die kopien da.\"\n\nAntworten Sie im Forum. Diese Adresse wird nicht gelesen.",
                        ],
                        [
                            'id' => 'm3', 'from' => 'info@millbrook-vt.gov', 'to' => 'toby.brennan@millbrookhigh.edu',
                            'subject' => 'Ihre Anfrage nach Betriebsunterlagen', 'date' => '10.10.2024 09:02',
                            'body' => "Sehr geehrter Herr Brennan,\n\nIhre Anfrage nach Betriebsbuechern der Wasserversorgung (2000-2016) wurde an die\nBetriebsleitung weitergeleitet. Eine Herausgabe an Privatpersonen ist nicht vorgesehen.\n\nMit freundlichen Gruessen\nStadtverwaltung Millbrook",
                        ],
                    ],
                ],
            ],
            [
                'id' => 'browser', 'label' => 'Verlauf', 'type' => 'browser',
                'content' => [
                    'entries' => [
                        ['time' => '11.10. 19:36', 'title' => 'Forum Nachtlinie - PN lantern', 'url' => 'forum.nachtlinie.blog/pn/lantern'],
                        ['time' => '11.10. 16:38', 'title' => 'Entwurf: Die Oktober-Linie', 'url' => 'nachtlinie.blog/wp-admin/post.php?id=88'],
                        ['time' => '10.10. 22:58', 'title' => 'Anzeige erstatten - wie lange dauert das', 'url' => 'suche/anzeige+erstatten'],
                        ['time' => '09.10. 21:10', 'title' => 'Millbrook Sentinel Archiv 1998-2016', 'url' => 'sentinel-archiv.vt.us/1998-2016'],
                        ['time' => '09.10. 20:02', 'title' => 'Wer ist W. Doss? Millbrook Water Works', 'url' => 'suche/walter+doss+millbrook'],
                    ],
                ],
            ],
        ],
    ],

    /* =====================================================
     |  Altes Handy (Mustersperre)
     ===================================================== */
    [
        'id' => 'dev_toby_old_phone', 'name' => 'Altgeraet aus der Schublade', 'type' => 'phone',
        'owner' => 'Tobias Brennan', 'evidence_tag' => 'Asservat 03',
        'note' => 'Aelteres Zweitgeraet ohne SIM. Toby hat es fuer das Forum benutzt.',
        'carrier' => 'KEIN NETZ', 'clock' => '19:48',
        'lock' => [
            'type' => 'pattern', 'puzzle' => 'pz_oldphone_pattern',
            'label' => 'Mustersperre', 'hint' => 'Die Streiflichtaufnahme des Displays (Akte A-7) zeigt die Fettspuren des letzten Musters.',
        ],
        'apps' => [
            [
                'id' => 'messages', 'label' => 'Forum-App', 'type' => 'messages',
                'content' => [
                    'threads' => [
                        [
                            'id' => 'th_lantern', 'contact' => 'lantern (Forum)',
                            'messages' => [
                                ['from' => 'them', 'text' => 'ich habe 22 jahre betriebsbuecher. du suchst die spuelungen, richtig?', 'time' => '20.09. 22:14'],
                                ['from' => 'me', 'text' => 'wer bist du', 'time' => '20.09. 22:20'],
                                ['from' => 'them', 'text' => 'jemand, der dort arbeitet. mehr nicht.', 'time' => '20.09. 22:22'],
                                ['from' => 'them', 'text' => 'frag nicht in der verwaltung nach. der betriebsleiter faehrt selbst nachts.', 'time' => '28.09. 21:03'],
                                ['from' => 'me', 'text' => 'warum hilfst du mir', 'time' => '28.09. 21:06'],
                                ['from' => 'them', 'text' => 'weil 2015 niemand zugehoert hat.', 'time' => '28.09. 21:07'],
                                ['from' => 'them', 'text' => 'freitag 23 uhr, nordtor. ich lasse dir die kopien da.', 'time' => '09.10. 20:14'],
                                ['from' => 'me', 'text' => 'wenn du mich verarschst geht alles online', 'time' => '11.10. 19:30'],
                                ['from' => 'them', 'text' => 'komm allein, sonst bleibt das tor zu.', 'time' => '11.10. 19:35'],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'id' => 'notes', 'label' => 'Notizen', 'type' => 'notes',
                'content' => [
                    'notes' => [
                        ['title' => 'wagen', 'date' => '02.10.2024', 'text' => "weisser kastenwagen, stand 2x nachts an der ridge road\nkennzeichen halb gesehen: 7KD-4??\nauf der tuer: millbrook water works\nfaehrt immer richtung stausee, kommt nicht zurueck"],
                        ['title' => 'nordtor', 'date' => '09.10.2024', 'text' => "nordtor = zufahrt pumpstation 4\nkein licht, keine kamera laut lantern\nfrage: warum weiss er, wo keine kamera ist"],
                    ],
                ],
            ],
        ],
    ],

    /* =====================================================
     |  Auswertungsplatz Kameradaten (FBI)
     ===================================================== */
    [
        'id' => 'dev_fbi_ws', 'name' => 'Auswertungsplatz Kameradaten', 'type' => 'pc',
        'owner' => 'FBI Rutland', 'evidence_tag' => 'Arbeitsplatz 2',
        'note' => 'Alle beschlagnahmten Aufzeichnungen der Tatnacht, synchronisiert auf Ortszeit.',
        'os' => 'FBI MEDIA REVIEW 4.2 · ASSERVATE WIT-2024-1011',
        'apps' => [
            [
                'id' => 'cctv', 'label' => 'Kameras', 'type' => 'cctv', 'badge' => 3,
                'content' => [
                    'videos' => [
                        ['media' => 'vid_ridge', 'title' => 'Ridge Road / Reservoir Access', 'camera' => 'CAM 03', 'meta' => '22:50 - 23:06 · einzige Zufahrt zum Nordufer'],
                        ['media' => 'vid_gas', 'title' => "Miller's Gas - Vorplatz", 'camera' => 'CAM 01', 'meta' => '22:30 - 22:40 · 11,8 km vom Wohnhaus'],
                        ['media' => 'vid_garage', 'title' => 'Einfahrt 14 Maple Street', 'camera' => 'PRIVAT', 'meta' => '22:10 - 23:10 · Kamera der Familie'],
                    ],
                ],
            ],
            [
                'id' => 'logs', 'label' => 'Funkzellen', 'type' => 'logs',
                'content' => [
                    'columns' => ['Zeit', 'Zelle', 'Ereignis', 'Bewertung'],
                    'note' => 'Filtern nach "RESERVOIR" zeigt, dass das Geraet das Gebiet nach 22:53 nicht mehr verlassen hat.',
                    'rows' => [
                        ['11.10. 21:58', 'MAPLE-02', 'Standortmeldung', 'Wohngebiet'],
                        ['11.10. 22:19', 'MAPLE-02', 'Standortmeldung', 'Wohngebiet'],
                        ['11.10. 22:26', 'TOWER-01', 'Datenverkehr (Foto)', 'Wasserturm'],
                        ['11.10. 22:44', 'RIDGE-03', 'Standortmeldung', 'Ridge Road'],
                        ['11.10. 22:53', 'RESERVOIR-01', 'Standortmeldung', 'Stausee/Wasserwerke'],
                        ['11.10. 23:12', 'RESERVOIR-01', 'Sprachnachricht 27 s', 'Stausee/Wasserwerke'],
                        ['11.10. 23:14', 'RESERVOIR-01', 'letzter Kontakt', 'danach kein Signal'],
                        ['12.10. 03:14', 'RESERVOIR-01', 'ausgehende Nachricht 31 Zeichen', 'Widerspruch zur Busbuchung'],
                    ],
                ],
            ],
        ],
    ],

    /* =====================================================
     |  Familien-PC
     ===================================================== */
    [
        'id' => 'dev_home_pc', 'name' => 'Familien-PC (Wohnzimmer Brennan)', 'type' => 'pc',
        'owner' => 'Familie Brennan', 'evidence_tag' => 'Asservat 05',
        'note' => 'Gemeinsames Konto, kein Passwort. Enthaelt Suchverlauf und Mailpostfach der Eltern.',
        'os' => 'FORENSIC IMAGE · BRENNAN-HOME · read only',
        'apps' => [
            [
                'id' => 'browser', 'label' => 'Suchverlauf', 'type' => 'browser',
                'content' => [
                    'note' => 'Die Suchanfragen in der Nacht zum 12.10. stammen aus dem Konto "Frank".',
                    'entries' => [
                        ['time' => '11.10. 23:22', 'title' => 'fahrrad gefunden muss man das melden', 'url' => 'suche/fahrrad+gefunden+melden'],
                        ['time' => '11.10. 23:31', 'title' => 'bewaehrung widerruf wann', 'url' => 'suche/bewaehrung+widerruf', 'body' => "Frank Brennan hat eine Bewaehrungsstrafe von 2019 (Koerperverletzung). Er hatte Angst,\ndass eine Aussage zur Tatnacht seine Bewaehrung kostet."],
                        ['time' => '12.10. 00:04', 'title' => 'vermisstenanzeige ab wann moeglich', 'url' => 'suche/vermisstenanzeige+ab+wann'],
                        ['time' => '12.10. 07:58', 'title' => 'anwalt millbrook strafrecht', 'url' => 'suche/anwalt+millbrook'],
                        ['time' => '11.10. 21:02', 'title' => 'Selbsthilfegruppe Rutland - Freitagstreffen', 'url' => 'rutland-recovery.org/treffen', 'body' => "Aufgerufen vom Konto \"Diane\". Freitagstreffen 22:30 - 23:20 Uhr, Rutland,\n11,8 km ausserhalb von Millbrook. Auf dem Weg dorthin liegt Miller's Gas."],
                    ],
                ],
            ],
            [
                'id' => 'mail', 'label' => 'E-Mail (Eltern)', 'type' => 'mail',
                'content' => [
                    'account' => 'brennan.family@greenline.net',
                    'messages' => [
                        [
                            'id' => 'hm1', 'from' => 'gruppe@rutland-recovery.org', 'to' => 'brennan.family@greenline.net',
                            'subject' => 'Erinnerung: Freitagstreffen 22:30', 'date' => '11.10.2024 08:12',
                            'body' => "Liebe Diane,\n\nkurze Erinnerung an das Freitagstreffen heute, 22:30 Uhr, Gemeindehaus Rutland.\nSchoen, dass du seit acht Wochen dabei bist.\n\nHerzlich\nDie Gruppe",
                        ],
                        [
                            'id' => 'hm2', 'from' => 'service@millers-gas.com', 'to' => 'brennan.family@greenline.net',
                            'subject' => 'Ihr Tankbeleg', 'date' => '11.10.2024 22:36',
                            'body' => "Vielen Dank fuer Ihren Einkauf.\n\nDatum: 11.10.2024, 22:34:52\nStation: Miller's Gas, State Route 12\nSaeule 2, 38,4 Liter, 61,44 USD\nKarte: **** 8123 (D. BRENNAN)\n\nDieser Beleg ist automatisch erzeugt.",
                        ],
                        [
                            'id' => 'hm3', 'from' => 'werkstatt@millbrook-auto.com', 'to' => 'brennan.family@greenline.net',
                            'subject' => 'Kostenvoranschlag Wildschaden', 'date' => '05.10.2024 11:20',
                            'body' => "Kostenvoranschlag: Reinigung Kofferraum und Stossfaengerlackierung nach Wildunfall\n(Reh, gemeldet am 03.10.2024, Route 12).\n\nSumme: 840 USD.\n\nHinweis: Die Blutspuren im Kofferraum stammen laut Ihrer Angabe vom Transport des Tieres.",
                        ],
                    ],
                ],
            ],
            [
                'id' => 'files', 'label' => 'Dateien', 'type' => 'files',
                'content' => [
                    'root' => 'D:\\Familie',
                    'tree' => [
                        ['name' => 'Kamera_Einfahrt', 'type' => 'folder', 'modified' => '12.10.2024 08:30', 'children' => [
                            ['name' => 'HINWEIS.txt', 'type' => 'file', 'size' => '0,2 KB', 'modified' => '12.10.2024 08:30',
                             'body' => "Die Aufzeichnungen der Einfahrtskamera wurden fuer die Auswertung auf den\nFBI-Auswertungsplatz kopiert (Kamera \"Einfahrt 14 Maple Street\")."],
                        ]],
                        ['name' => 'Steuer_2023.pdf', 'type' => 'file', 'size' => '1,8 MB', 'modified' => '14.04.2024 19:02', 'body' => 'Steuerunterlagen. Ohne Bezug zum Fall.'],
                        ['name' => 'Urlaub_Maine', 'type' => 'folder', 'modified' => '02.08.2024 12:00', 'children' => [
                            ['name' => 'strand_01.jpg', 'type' => 'file', 'size' => '2,4 MB', 'modified' => '02.08.2024 12:00', 'body' => 'Familienfoto. Toby steht am Rand und schaut in eine andere Richtung.'],
                        ]],
                    ],
                ],
            ],
        ],
    ],

    /* =====================================================
     |  Dienstrechner Kontrollraum Wasserwerke
     ===================================================== */
    [
        'id' => 'dev_ww_terminal', 'name' => 'Dienstrechner Kontrollraum (Wasserwerke)', 'type' => 'pc',
        'owner' => 'Millbrook Water Works', 'evidence_tag' => 'Asservat 09',
        'note' => 'Freiwillige Nachschau im Betriebshof. Zugriff nur auf den Arbeitsplatzrechner im Kontrollraum.',
        'os' => 'SCADA WORKSTATION · MILLBROOK WW · Benutzer: leitung',
        'requires_flags' => ['wasserwerke_im_blick'],
        'lock' => [
            'type' => 'password', 'puzzle' => 'pz_ww_login',
            'label' => 'Anmeldung Betriebsleitung', 'user' => 'leitung',
            'hint' => 'Auf der Aufnahme des Kontrollraums (Akte A-9) klebt ein Zettel unter der Tastatur.',
        ],
        'apps' => [
            [
                'id' => 'logbook', 'label' => 'Betriebsbuch', 'type' => 'logs',
                'content' => [
                    'columns' => ['Datum', 'Arbeit', 'Abschnitt', 'Zeitraum', 'Freigabe', 'Erfasst um'],
                    'note' => 'Filtern nach "11.10." zeigt die Nacht des Verschwindens. Die Spalte "Erfasst um" verraet, wann der Eintrag getippt wurde.',
                    'puzzle' => 'pz_find_flush',
                    'rows' => [
                        ['11.10.2024', 'Spuelung', 'Abschnitt 4', '22:00 - 02:00', 'W. Doss', '12.10. 05:58:04'],
                        ['11.10.2024', 'Druckpruefung', 'Abschnitt 4', '02:00 - 03:30', 'W. Doss', '12.10. 05:58:09'],
                        ['11.10.2024', 'Zufahrt gesperrt', 'Reservoir Access', '22:00 - 05:00', 'W. Doss', '12.10. 05:58:14'],
                        ['11.10.2024', 'Kontrollgang', 'Pumpstation 4', '04:00 - 04:50', 'W. Doss', '12.10. 05:58:21'],
                        ['08.10.2024', 'Filterwechsel', 'Abschnitt 1', '09:00 - 11:00', 'R. Pike', '08.10. 11:06:40'],
                        ['24.10.2015', 'Spuelung', 'Abschnitt 5', '22:00 - 02:00', 'W. Doss', '25.10. 06:02:11'],
                        ['17.10.2009', 'Spuelung', 'Abschnitt 3', '22:30 - 02:30', 'W. Doss', '18.10. 05:49:03'],
                        ['10.10.2003', 'Spuelung', 'Abschnitt 2', '22:00 - 01:30', 'W. Doss', '11.10. 06:11:52'],
                        ['02.06.2024', 'Spuelung', 'Abschnitt 2', '09:00 - 12:00', 'R. Pike', '02.06. 12:14:02'],
                    ],
                ],
            ],
            [
                'id' => 'access', 'label' => 'Zutrittsprotokoll', 'type' => 'logs',
                'content' => [
                    'columns' => ['Zeit', 'Karte', 'Person', 'Tuer', 'Ergebnis'],
                    'note' => 'Filtern nach "Pumpstation 4" zeigt alle Zutritte zur stillgelegten Anlage.',
                    'puzzle' => 'pz_keycard',
                    'rows' => [
                        ['11.10.2024 21:44', 'WW-0114', 'W. Doss', 'Betriebshof Haupttor', 'gewaehrt'],
                        ['11.10.2024 22:41', 'WW-0114', 'W. Doss', 'Nordtor Zufahrt', 'gewaehrt'],
                        ['12.10.2024 04:02', 'WW-0114', 'W. Doss', 'Pumpstation 4', 'gewaehrt'],
                        ['12.10.2024 04:51', 'WW-0114', 'W. Doss', 'Pumpstation 4', 'verlassen'],
                        ['12.10.2024 05:56', 'WW-0114', 'W. Doss', 'Kontrollraum', 'gewaehrt'],
                        ['13.10.2024 02:06', 'WW-0114', 'W. Doss', 'Pumpstation 4', 'gewaehrt'],
                        ['13.10.2024 02:44', 'WW-0114', 'W. Doss', 'Pumpstation 4', 'verlassen'],
                        ['09.10.2024 08:12', 'WW-0207', 'R. Pike', 'Betriebshof Haupttor', 'gewaehrt'],
                        ['24.10.2015 23:58', 'WW-0114', 'W. Doss', 'Pumpstation 4', 'gewaehrt'],
                        ['17.10.2009 23:41', 'WW-0114', 'W. Doss', 'Pumpstation 4', 'gewaehrt'],
                    ],
                ],
            ],
            [
                'id' => 'cctv', 'label' => 'Kamera 4', 'type' => 'cctv',
                'content' => [
                    'videos' => [
                        ['media' => 'vid_pump', 'title' => 'Pumpstation 4 - Wartungstuer', 'camera' => 'CAM 04', 'meta' => 'im Kontrollraum als "offline" gefuehrt'],
                    ],
                ],
            ],
            [
                'id' => 'files', 'label' => 'Dateien', 'type' => 'files',
                'content' => [
                    'root' => 'S:\\betrieb',
                    'note' => 'Ein Ordner ist zusaetzlich geschuetzt.',
                    'tree' => [
                        ['name' => 'spuelplaene', 'type' => 'folder', 'modified' => '01.10.2024 08:00', 'children' => [
                            ['name' => 'plan_2024.xlsx', 'type' => 'file', 'size' => '88 KB', 'modified' => '01.10.2024 08:00',
                             'body' => "Spuelplan 2024. Abschnitt 4 ist regulaer erst fuer Mai 2025 vorgesehen.\nDie Spuelung vom 11.10.2024 war nicht geplant."],
                        ]],
                        ['name' => 'WARTUNG_ALT', 'type' => 'folder', 'modified' => '13.10.2024 02:11', 'puzzle' => 'pz_doss_folder',
                         'body' => "Dieser Ordner ist mit einem Passwort geschuetzt.\nHinweis im Dateikopf: \"stilllegung\"."],
                        ['name' => 'WARTUNG_ALT_INHALT', 'type' => 'folder', 'modified' => '13.10.2024 02:11', 'requires_puzzle' => 'pz_doss_folder', 'children' => [
                            ['name' => '2003_abschnitt2.pdf', 'type' => 'file', 'size' => '2,1 MB', 'modified' => '11.10.2003 06:12', 'media' => 'doc_folder'],
                            ['name' => '2009_abschnitt3.pdf', 'type' => 'file', 'size' => '1,9 MB', 'modified' => '18.10.2009 05:50', 'media' => 'doc_folder'],
                            ['name' => '2015_abschnitt5.pdf', 'type' => 'file', 'size' => '2,4 MB', 'modified' => '25.10.2015 06:03', 'media' => 'doc_folder'],
                            ['name' => '2024_namensliste.xlsx', 'type' => 'file', 'size' => '64 KB', 'modified' => '02.10.2024 23:41', 'media' => 'doc_folder'],
                            ['name' => 'schacht_2024_002.jpg', 'type' => 'file', 'size' => '3,8 MB', 'modified' => '13.10.2024 02:11', 'media' => 'doc_folder'],
                        ]],
                        ['name' => 'anlagen', 'type' => 'folder', 'modified' => '12.09.2024 10:00', 'children' => [
                            ['name' => 'pumpstation4_grundriss.pdf', 'type' => 'file', 'size' => '1,2 MB', 'modified' => '12.09.2024 10:00',
                             'body' => "Grundriss Pumpstation 4 (Stand 1996).\nErdgeschoss: Maschinenraum, Schaltraum.\nUnter dem Maschinenraum: Wartungsschacht, 4 x 6 m, eigene Belueftung,\nZugang ueber Bodenluke. Kein Mobilfunkempfang (Betonstaerke 60 cm).\n\nHandschriftlicher Zusatz auf dem Plan: \"Luke 2024 erneuert\"."],
                        ]],
                    ],
                ],
            ],
        ],
    ],
];
