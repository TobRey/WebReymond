<?php
/**
 * Fall "Toby" - Abschlussbericht, Bewertung, Enden, Startzustand, Wandverbindungen.
 */
declare(strict_types=1);

return [
    'start' => [
        'evidence'  => ['E01'],
        'devices'   => ['dev_fbi_ws', 'dev_home_pc'],
        'flags'     => ['fall_eroeffnet'],
        'locations' => ['loc_home'],
    ],

    'board_links' => [
        [
            'a' => 'E13', 'b' => 'loc_waterworks', 'flag' => 'wasserwerke_im_blick',
            'note' => 'Transporter mit Werksaufschrift und Betriebsgelaende verbunden.',
        ],
        [
            'a' => 'E09', 'b' => 'loc_pump4', 'flag' => 'ort_pumpstation_bekannt',
            'note' => 'Pumpengeraeusch und stillgelegte Pumpstation verbunden.',
        ],
        [
            'a' => 'npc_doss', 'b' => 'E15', 'flag' => 'doss_im_blick',
            'note' => 'Betriebsleiter und nachtraeglich eingetragene Spuelung verbunden.',
        ],
        [
            'a' => 'npc_doss', 'b' => 'E21', 'flag' => 'lantern_verdacht',
            'note' => 'Betriebsleiter und anonymer Kontakt verbunden.',
        ],
        [
            'a' => 'E16', 'b' => 'E15', 'flag' => 'muster_erkannt',
            'note' => 'Altfaelle und Spuelungen verbunden.',
        ],
    ],

    'report' => [
        'culprit_question'  => 'q_culprit',
        'location_question' => 'q_location',
        'liars_question'    => 'q_liars',
        'hints' => [
            'Du hast genug Material. Fasse zusammen, wer wann wo war - und wer das nicht sein konnte.',
            'Pruefe jede Aussage gegen einen Zeitstempel: Tankstelle, Einfahrt, Verkehrskamera, Funkzelle, Zutrittsprotokoll.',
            'Taeter ist die Person, die nachts legal ein Werksfahrzeug fuehren, eine Strasse sperren und eine stillgelegte Anlage oeffnen darf. Der Fundort ist Pumpstation 4.',
        ],
        'weights' => [
            'q_what' => 25, 'q_culprit' => 30, 'q_liars' => 15, 'q_location' => 25,
            'q_motive' => 15, 'q_evidence' => 20, 'q_timeline' => 20,
        ],
        'questions' => [
            [
                'id' => 'q_what', 'type' => 'text', 'required' => true, 'max' => 2000, 'weight' => 25,
                'label' => 'Was ist passiert?',
                'help' => 'Kurzer Ablauf in eigenen Worten: Wie kam Toby an den Tatort, wer hat ihn dorthin gebracht, was geschah danach?',
                'keywords' => ['lantern', 'nordtor', 'pumpstation', 'transporter', 'spuelung', 'doss', 'nachricht', 'schacht'],
            ],
            [
                'id' => 'q_culprit', 'type' => 'choice', 'required' => true, 'weight' => 30,
                'label' => 'Wer ist verantwortlich?',
                'help' => 'Nur eine Person. Eine falsche Anschuldigung hat Folgen.',
                'options' => [
                    ['id' => 'npc_doss', 'label' => 'Walter Doss, Betriebsleiter der Wasserwerke', 'note' => 'Fahrzeug, Karte, Spuelung, Ordner'],
                    ['id' => 'npc_frank', 'label' => 'Frank Brennan, Stiefvater', 'note' => 'Streit, verstecktes Fahrrad, Vorstrafe'],
                    ['id' => 'npc_elias', 'label' => 'Elias Marsh, Freund', 'note' => 'Schichttausch, Einkauf von Kabelbindern'],
                    ['id' => 'npc_hale', 'label' => 'Gregory Hale, Lehrer', 'note' => 'Archivzugang, zurueckgezogene Aussage 2015'],
                    ['id' => 'npc_nora', 'label' => 'Nora Vance, Freundin', 'note' => 'war am Wasserturm, hat gelogen'],
                    ['id' => 'runaway', 'label' => 'Niemand - Toby ist weggelaufen', 'note' => 'Busbuchung, Nachricht um 03:14'],
                ],
            ],
            [
                'id' => 'q_liars', 'type' => 'multi', 'required' => true, 'weight' => 15,
                'label' => 'Wer hat gelogen?',
                'help' => 'Mehrfachauswahl. Gemeint sind bewusst falsche Angaben zur Tatnacht oder zu frueheren Faellen.',
                'options' => [
                    ['id' => 'npc_diane', 'label' => 'Diane Brennan', 'note' => 'Sichtung um 22:10 Uhr'],
                    ['id' => 'npc_frank', 'label' => 'Frank Brennan', 'note' => 'habe geschlafen'],
                    ['id' => 'npc_nora', 'label' => 'Nora Vance', 'note' => 'habe ihn nicht gesehen'],
                    ['id' => 'npc_hale', 'label' => 'Gregory Hale', 'note' => 'nichts mit 2015 zu tun'],
                    ['id' => 'npc_doss', 'label' => 'Walter Doss', 'note' => 'ganze Nacht im Kontrollraum'],
                    ['id' => 'npc_ruth', 'label' => 'Ruth Calloway', 'note' => ''],
                    ['id' => 'npc_elias', 'label' => 'Elias Marsh', 'note' => ''],
                ],
            ],
            [
                'id' => 'q_location', 'type' => 'choice', 'required' => true, 'weight' => 25,
                'label' => 'Wo befindet sich Toby?',
                'help' => 'Der Ort, an dem er festgehalten wird.',
                'options' => [
                    ['id' => 'loc_pump4', 'label' => 'Wartungsschacht unter Pumpstation 4 (Halloway-Stausee)'],
                    ['id' => 'loc_waterworks', 'label' => 'Betriebshof der Wasserwerke'],
                    ['id' => 'loc_watertower', 'label' => 'Alter Wasserturm an der Ridge Road'],
                    ['id' => 'loc_bus', 'label' => 'Unterwegs nach Montreal'],
                    ['id' => 'loc_home', 'label' => 'Im Umfeld des Wohnhauses'],
                    ['id' => 'unknown', 'label' => 'Unbekannt'],
                ],
            ],
            [
                'id' => 'q_motive', 'type' => 'choice', 'required' => true, 'weight' => 15,
                'label' => 'Was war das Motiv?',
                'options' => [
                    ['id' => 'motive_serie', 'label' => 'Toby stand kurz davor, eine seit 2003 laufende Serie aufzudecken - er wurde beseitigt und gleichzeitig als Koeder benutzt, um die Ermittlung auf "Weglaufen" zu lenken.'],
                    ['id' => 'motive_familie', 'label' => 'Familienstreit, der eskaliert ist.'],
                    ['id' => 'motive_geld', 'label' => 'Geldforderung oder Erpressung.'],
                    ['id' => 'motive_zufall', 'label' => 'Zufallstat ohne Zusammenhang.'],
                    ['id' => 'motive_flucht', 'label' => 'Toby wollte verschwinden und hat es geplant.'],
                ],
            ],
            [
                'id' => 'q_evidence', 'type' => 'evidence', 'required' => true, 'weight' => 20,
                'label' => 'Welche Beweise stuetzen die Theorie?',
                'help' => 'Waehle die tragenden Beweise aus deinem Archiv. Unpassende Auswahl kostet Punkte.',
            ],
            [
                'id' => 'q_timeline', 'type' => 'sequence', 'required' => true, 'weight' => 20,
                'label' => 'Welche Zeitleiste ist korrekt?',
                'help' => 'Bringe die Ereignisse der Tatnacht in die richtige Reihenfolge.',
                'options' => [
                    ['id' => 'tl_rad', 'label' => 'Toby verlaesst die Einfahrt mit dem Fahrrad', 'note' => '22:16'],
                    ['id' => 'tl_turm', 'label' => 'Treffen mit Nora am Wasserturm', 'note' => '22:27'],
                    ['id' => 'tl_zaun', 'label' => 'Foto am Zaun der Wasserwerke', 'note' => '22:51'],
                    ['id' => 'tl_van', 'label' => 'Transporter faehrt Richtung Stausee', 'note' => '22:58'],
                    ['id' => 'tl_audio', 'label' => 'Sprachnachricht mit Pumpe und Zughorn', 'note' => '23:12'],
                    ['id' => 'tl_msg', 'label' => '"Hoer auf, mich zu suchen."', 'note' => '03:14'],
                    ['id' => 'tl_card', 'label' => 'Schluesselkarte oeffnet Pumpstation 4', 'note' => '04:02'],
                ],
            ],
        ],
        'correct' => [
            'q_culprit'  => ['npc_doss'],
            'q_liars'    => ['npc_diane', 'npc_frank', 'npc_nora', 'npc_hale', 'npc_doss'],
            'q_location' => ['loc_pump4'],
            'q_motive'   => ['motive_serie'],
            'q_evidence' => ['E08', 'E09', 'E13', 'E14', 'E15', 'E16', 'E17', 'E19', 'E22'],
            'q_timeline' => ['tl_rad', 'tl_turm', 'tl_zaun', 'tl_van', 'tl_audio', 'tl_msg', 'tl_card'],
        ],
    ],

    'scoring' => ['target_minutes' => 25],

    'endings' => [
        [
            'id' => 'ending_rescue', 'title' => 'Lebend gefunden - 04:12 Uhr', 'tone' => 'good',
            'conditions' => ['culprit_correct' => true, 'location_correct' => true, 'min_percent' => 78, 'min_key_evidence' => 6],
            'image' => 'assets/img/scenes/photo-pump-interior.svg',
            'text' => "Das SWAT-Team von Rutland oeffnet die Wartungstuer der Pumpstation 4 um 04:07 Uhr. Im Maschinenraum riecht es nach Chlor und kaltem Beton. Unter dem Boden liegt eine Luke, 2024 erneuert.\n\nToby Brennan sitzt an der Wand des Wartungsschachts, die Haende mit Kabelbindern an ein Rohr gebunden, dehydriert, unterkuehlt, wach. Neben ihm eine Decke, eine Wasserflasche und drei Striche an der Wand - er hat den dritten selbst dazugemalt, damit die Zahl nicht stimmt.\n\nEr fragt als Erstes, ob Nora etwas passiert ist.\n\nWalter Doss wird um 06:20 Uhr im Kontrollraum festgenommen, waehrend er das Betriebsbuch fuehrt. Er wehrt sich nicht. Er bittet darum, vorher die Spuelung abzuschalten - \"sonst haben Sie in drei Tagen braunes Wasser in der Grundschule\".\n\nIm Wartungsschacht sichert die Spurensicherung in den folgenden 48 Stunden die Ueberreste von drei Menschen: Karen Pielmeier (2003), Danny Oro (2009), Marisol Vance (2015). Drei Familien bekommen nach 21, 15 und 9 Jahren eine Antwort.",
            'epilogue' => "Vier Wochen spaeter erscheint in der Schuelerzeitung der Millbrook High School ein Artikel mit dem Titel \"Die Oktober-Linie\". Autor: T. Brennan, 17.\n\nDer letzte Satz lautet: \"Die Akte war nie geschlossen. Sie war nur nicht geoeffnet.\"",
        ],
        [
            'id' => 'ending_late', 'title' => 'Richtig - aber zu langsam', 'tone' => 'tragic',
            'conditions' => ['culprit_correct' => true, 'location_correct' => true, 'max_percent' => 77],
            'image' => 'assets/img/scenes/photo-pump-station.svg',
            'text' => "Der Zugriff erfolgt am Nachmittag des 15.10. - 26 Stunden nach Abgabe Ihres Berichts, weil die Beweislage fuer den Durchsuchungsbeschluss zu duenn war und der Richter zweimal nachgefragt hat.\n\nIm Wartungsschacht der Pumpstation 4 finden die Beamten eine Decke, eine leere Wasserflasche, vier Striche an der Wand und einen frisch gereinigten Boden. Kein Toby.\n\nWalter Doss wird festgenommen. Er schweigt vollstaendig, bis auf einen Satz, den er dem Haftrichter sagt: \"Ich habe alles dokumentiert. Sie haetten nur schneller lesen muessen.\"\n\nDie Ueberreste der Opfer von 2003, 2009 und 2015 werden gefunden. Toby Brennan bleibt vermisst.",
            'epilogue' => "Diane Brennan laesst das Licht in seinem Zimmer nachts an. Nora Vance gibt den USB-Stick nie zurueck.",
        ],
        [
            'id' => 'ending_culprit_only', 'title' => 'Taeter benannt, Ort verfehlt', 'tone' => 'tragic',
            'conditions' => ['culprit_correct' => true, 'location_correct' => false],
            'text' => "Ihr Bericht benennt Walter Doss. Die Durchsuchung konzentriert sich auf den Betriebshof und seine Wohnung: Aktenordner, Festplatten, ein weisser Kastenwagen mit frischem Lack.\n\nDie stillgelegte Pumpstation 4 steht in keinem Antrag. Sie wird erst im Fruehjahr geoeffnet, als ein Angler dem Ordnungsamt meldet, dass am Nordufer nachts eine Pumpe laeuft.\n\nDoss wird wegen der Altfaelle angeklagt. Fuer Toby Brennan kommt der Beschluss vier Monate zu spaet.",
            'epilogue' => "Auf der Rueckseite eines Betriebsbuchs findet ein Sachbearbeiter spaeter eine Bleistiftnotiz in fremder Handschrift: \"2 striche. nicht 3. ich habe geschummelt.\"",
        ],
        [
            'id' => 'ending_wrong_person', 'title' => 'Falsche Festnahme', 'tone' => 'bad',
            'conditions' => ['culprit_correct' => false, 'min_percent' => 35],
            'text' => "Ihr Bericht benennt die falsche Person. Die Staatsanwaltschaft beantragt Haftbefehl, die Presse hat einen Namen, die Stadt hat einen Schuldigen.\n\nFrank Brennan verliert seine Bewaehrung und seine Ehe. Nora Vance wird in der Schule als Luegnerin beschimpft. Gregory Hale kuendigt.\n\nIn der Nacht des 24. Oktober traegt jemand im Betriebsbuch der Millbrook Water Works eine Spuelung in Abschnitt 4 ein. Die Zufahrt ist gesperrt. Es ist Oktober.\n\nDrei Wochen spaeter wird eine Sechzehnjaehrige aus Millbrook als vermisst gemeldet.",
            'epilogue' => "Der Ordner WARTUNG_ALT enthaelt ab diesem Winter ein weiteres Unterverzeichnis.",
        ],
        [
            'id' => 'ending_runaway', 'title' => 'Als Weglaufen geschlossen', 'tone' => 'bad',
            'conditions' => ['culprit_correct' => false, 'max_percent' => 34],
            'text' => "Ihr Bericht folgt der Busbuchung und der Nachricht um 03:14 Uhr. Der Fall wird als Weglaufen eingestuft und an die oertliche Polizei zurueckgegeben. Die Akte wandert ins Archiv, Fach 4, Reihe 12.\n\nDer Koeder hat funktioniert - genau so, wie er gedacht war.\n\nIn der Nacht, in der Ihr Bericht unterschrieben wird, sendet Tobys Account eine letzte Nachricht an Nora Vance. Sie besteht aus vier Zeichen: .--. ....-\n\nNiemand liest sie mehr als eine Signalstoerung.",
            'epilogue' => "Im Oktober des naechsten Jahres wird in Millbrook Abschnitt 6 gespuelt.",
        ],
        [
            'id' => 'ending_open', 'title' => 'Fall bleibt offen', 'tone' => 'bad',
            'conditions' => [],
            'text' => "Der Bericht ist unvollstaendig. Ohne benannte Verantwortliche und ohne Fundort bleibt der Fall in der Zustaendigkeit der oertlichen Polizei. Es gibt keine Durchsuchung, keinen Zugriff, keine Antwort.\n\nDie Akte bleibt offen - so wie 2003, 2009 und 2015.",
            'epilogue' => '',
        ],
    ],

    'solution' => [
        'culprit'  => 'npc_doss',
        'location' => 'loc_pump4',
        'motive'   => 'Toby stand kurz davor, eine seit 2003 laufende Serie von Entfuehrungen aufzudecken. Doss hat ihn als "Lantern" an das Nordtor gelockt, im Wartungsschacht der Pumpstation 4 festgehalten und gleichzeitig als Koeder benutzt: Busbuchung und Nachricht sollten die Ermittlung auf Weglaufen lenken, und Toby sollte ihm sagen, wer sonst noch von dem Muster weiss.',
        'summary'  => "21:47 Streit mit dem Stiefvater. 22:16 Toby faehrt mit dem Rad los. 22:27 Treffen mit Nora am Wasserturm, sie warnt ihn. 22:31 Nora faehrt heim, Toby fahrt weiter nach Norden zum verabredeten Treffen mit \"Lantern\" am Nordtor. 22:41 Doss verlaesst den Betriebshof mit dem Werkstransporter VT 7KD-418. 22:51 Toby fotografiert das Warnschild am Zaun, GPS belegt den Ort. 22:58 der Transporter passiert die Verkehrskamera Richtung Stausee. 23:05 Frank findet Tobys Fahrrad an der Ridge Road und versteckt es. 23:12 Sprachnachricht mit laufender Pumpe und Zughorn. 23:14 letztes Funksignal. 00:58 Doss bucht aus dem Netz der Wasserwerke einen Bus nach Montreal auf Tobys Namen. 03:14 Doss sendet von Tobys Telefon \"Hoer auf, mich zu suchen.\" - Toby hat dem Anhang heimlich eine Morsebotschaft beigelegt (P4). 04:02 Doss oeffnet mit Karte WW-0114 die Pumpstation 4. 05:58 er traegt alle Nachtarbeiten nachtraeglich ins Betriebsbuch ein.",
        'liars'    => ['npc_diane', 'npc_frank', 'npc_nora', 'npc_hale', 'npc_doss'],
    ],
];
