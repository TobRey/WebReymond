<?php
/**
 * Fall "Toby" - Stammdaten, Orte, Zeitachse, Medien.
 * Teil des Generators tools/build_case_toby.php
 */
declare(strict_types=1);

return [
    '_schema'       => 3,
    'id'            => 'toby',
    'code'          => 'WIT-2024-1011',
    'title'         => 'Where is Toby?',
    'subtitle'      => 'Vermisstenfall Tobias Brennan',
    'status'        => 'published',
    'order'         => 1,
    'difficulty'    => 'schwer',
    'duration'      => '15-25 Min.',
    'incident_date' => '2024-10-11',
    'report_deadline' => 'Bericht bis 15.10.2024, 08:00 Uhr',
    'location'      => 'Millbrook, Vermont',
    'cover'         => 'assets/img/scenes/case-cover-toby.svg',
    'summary'       => 'Ein 17-Jaehriger verschwindet aus seinem Zimmer. Seine Eltern widersprechen sich, seine Freunde erzaehlen drei Versionen - und auf seinem Rechner liegen Notizen zu drei alten Vermisstenfaellen.',
    'summary_short' => 'Tobias Brennan, 17, verschwindet in der Nacht zum 12.10.2024 aus Millbrook, Vermont.',
    'content_warning' => 'Dieser Fall enthaelt Gewaltdarstellungen, Schilderungen von Toetungsdelikten an Jugendlichen, Blut, Gefangenschaft, psychologischen Horror und beklemmende Szenen. Alle Inhalte sind fiktiv. Ab 18 Jahren.',

    'briefing' => <<<TEXT
EINSATZBEFEHL - FIELD OFFICE ALBANY / RESIDENT AGENCY RUTLAND
FALL WIT-2024-1011 · STUFE: VERMISSTE PERSON, MINDERJAEHRIG

Am Samstag, 12.10.2024, 08:20 Uhr, meldete Diane Brennan ihren Sohn Tobias "Toby"
Brennan (17) als vermisst. Letzte gesicherte Ortung seines Mobiltelefons: 11.10.2024,
23:14 Uhr, Funkzelle "Ridge/Reservoir", drei Kilometer nordoestlich des Wohnhauses.

Die oertliche Polizei fuehrt den Fall als moegliches Weglaufen. Wir uebernehmen, weil
Toby in den Wochen vor seinem Verschwinden in einem oeffentlichen Forum drei alte
Vermisstenfaelle aus Millbrook miteinander in Verbindung gebracht hat: 2003, 2009, 2015.
Alle drei Faelle sind ungeloest. Alle drei Opfer waren zwischen 16 und 18 Jahre alt.

IHR AUFTRAG
1. Sichern Sie die digitale Spur: Mobiltelefon, Rechner, Cloud-Backups, Kameras.
2. Befragen Sie Familie, Freunde, Schule und Nachbarschaft. Achten Sie auf Zeitangaben.
3. Pruefen Sie jede Aussage gegen einen technischen Zeitstempel. Menschen irren sich.
   Manche luegen.
4. Erstellen Sie einen Abschlussbericht: Ablauf, Verantwortliche, Aufenthaltsort, Motiv,
   tragende Beweise.

HINWEIS DER TECHNIK
Alle Geraete liegen als forensische Kopie vor (nur lesend). Simulierte Anmeldemasken
gehoeren zur Auswertungsumgebung. Zugangsdaten muessen aus den Funden abgeleitet werden.

Toby ist seit 34 Stunden verschwunden. In den drei Altfaellen wurde nie eine Leiche
gefunden. Arbeiten Sie schnell.
TEXT,

    'intro' => [
        'title' => 'Nacht zum 12. Oktober',
        'lines' => [
            '23:14 Uhr. Das Handy von Tobias Brennan sendet ein letztes Signal an den Mast an der Ridge Road. Danach nichts.',
            'Seine Mutter sagt, sie habe ihn um 22:10 Uhr in seinem Zimmer gesehen. Sein Stiefvater sagt, er habe geschlafen. Seine beste Freundin sagt, sie habe ihn den ganzen Abend nicht gesehen.',
            'Einer von ihnen luegt. Vielleicht alle drei.',
            'Auf Tobys Pinnwand haengen fuenf Zeitungsartikel, verbunden mit rotem Faden. Drei davon sind Vermisstenanzeigen. Die anderen zwei betreffen das Wasserwerk.',
            'Sie haben Zugriff auf die Asservate, die Kameras und die Personen. Fangen Sie an, Agent.',
        ],
    ],

    'missing_person' => [
        'name'        => 'Tobias "Toby" Brennan',
        'age'         => 17,
        'photo'       => 'assets/img/scenes/photo-toby.svg',
        'last_seen'   => '11.10.2024, ca. 22:16 Uhr (Einfahrt, mit Fahrrad)',
        'height'      => '178 cm, schlank',
        'clothing'    => 'dunkelgruene Regenjacke, Jeans, graue Turnschuhe',
        'traits'      => 'Narbe linke Augenbraue, traegt immer Kopfhoerer um den Hals',
        'description' => 'Schueler der Millbrook High School, 12. Klasse. Schreibt fuer die Schuelerzeitung und betreibt den anonymen Recherche-Blog "Nachtlinie" ueber ungeloeste Faelle der Region. Gilt als ruhig, hartnaeckig, misstrauisch gegenueber Autoritaeten. Kein Hinweis auf Drogen, keine Vorgeschichte von Weglaufen, kein Streit ausserhalb der Familie bekannt.',
    ],

    'map' => [
        'image'  => 'assets/img/scenes/map-millbrook.svg',
        'title'  => 'Millbrook, Vermont',
        'legend' => 'Rote Markierungen: Orte mit Tatbezug. Entfernung Wohnhaus - Tankstelle: 11,8 km (ca. 17 Minuten). Wohnhaus - Wasserturm: 1,4 km (5 Minuten mit dem Fahrrad). Wohnhaus - Pumpstation 4: 3,2 km.',
    ],

    'locations' => [
        [
            'id' => 'loc_home', 'name' => '14 Maple Street', 'type' => 'wohnort',
            'address' => 'Wohnhaus Familie Brennan', 'x' => 0.25, 'y' => 0.70, 'always_visible' => true,
            'description' => 'Einfamilienhaus mit Garage, Geraeteschuppen und privater Einfahrtskamera. Tobys Zimmer liegt im Obergeschoss zur Strasse.',
            'notes' => 'Kein Einbruchsschaden. Fenster von innen geschlossen. Rucksack fehlt, Ladekabel liegt noch am Bett.',
            'evidence' => ['E01', 'E02', 'E12', 'E20'],
        ],
        [
            'id' => 'loc_neighbor', 'name' => '16 Maple Street', 'type' => 'wohnort',
            'address' => 'Ruth Calloway', 'x' => 0.285, 'y' => 0.655, 'always_visible' => true,
            'description' => 'Nachbarhaus. Frau Calloway sitzt abends am Fenster zur Einfahrt.',
            'notes' => 'Hund "Biscuit" bellt zuverlaessig bei Fahrzeugen.',
        ],
        [
            'id' => 'loc_school', 'name' => 'Millbrook High School', 'type' => 'schule',
            'address' => '2 School Lane', 'x' => 0.17, 'y' => 0.375, 'always_visible' => true,
            'description' => 'Schule von Toby und Nora. Im Keller liegt das Zeitungsarchiv 1998-2016, betreut von Gregory Hale.',
            'evidence' => ['E16', 'E23'],
        ],
        [
            'id' => 'loc_watertower', 'name' => 'Alter Wasserturm', 'type' => 'treffpunkt',
            'address' => 'Ende der Ridge Road', 'x' => 0.53, 'y' => 0.625, 'always_visible' => true,
            'description' => 'Treffpunkt der Jugendlichen. 1,4 km vom Wohnhaus, mit dem Fahrrad fuenf Minuten.',
            'evidence' => ['E07'],
        ],
        [
            'id' => 'loc_gas', 'name' => "Miller's Gas", 'type' => 'ort',
            'address' => 'State Route 12, 11,8 km ausserhalb', 'x' => 0.36, 'y' => 0.775, 'always_visible' => true,
            'description' => 'Tankstelle mit Kamera auf dem Vorplatz. 17 Minuten Fahrzeit vom Wohnhaus.',
            'evidence' => ['E11'],
        ],
        [
            'id' => 'loc_ridge', 'name' => 'Ridge Road, Kamera 3', 'type' => 'ort',
            'address' => 'Ridge Road / Reservoir Access', 'x' => 0.58, 'y' => 0.54, 'always_visible' => true,
            'description' => 'Verkehrskamera der Strassenmeisterei an der Abzweigung zum Stausee. Einzige Zufahrt zum Wasserwerksgelaende.',
            'evidence' => ['E13'],
        ],
        [
            'id' => 'loc_rail', 'name' => 'Bahnuebergang CN-114', 'type' => 'infrastruktur',
            'address' => 'Gueterstrecke am Stausee', 'x' => 0.585, 'y' => 0.575, 'always_visible' => true,
            'description' => 'Gueterzuege passieren nur hier, fahrplanmaessig 23:11 und 04:40. Das Signalhorn ist im gesamten Uferbereich zu hoeren.',
        ],
        [
            'id' => 'loc_depot', 'name' => 'Riverside Depot', 'type' => 'arbeit',
            'address' => 'Lagerhalle, Route 12', 'x' => 0.43, 'y' => 0.86, 'always_visible' => true,
            'description' => 'Elias Marsh arbeitet hier Nachtschichten. Zeiterfassung per Chipkarte.',
        ],
        [
            'id' => 'loc_bus', 'name' => 'Busbahnhof Millbrook', 'type' => 'ort',
            'address' => 'Depot Street', 'x' => 0.21, 'y' => 0.80, 'always_visible' => true,
            'description' => 'Ein Fernbus pro Nacht nach Burlington und Montreal, Abfahrt 01:20.',
            'evidence' => ['E18'],
        ],
        [
            'id' => 'loc_waterworks', 'name' => 'Millbrook Water Works', 'type' => 'arbeit',
            'address' => 'Betriebshof, Reservoir Access', 'x' => 0.73, 'y' => 0.81, 'always_visible' => true,
            'description' => 'Verwaltung und Kontrollraum der staedtischen Wasserversorgung. Betriebsleiter: Walter Doss, seit 1996 im Amt.',
            'evidence' => ['E15', 'E19'],
        ],
        [
            'id' => 'loc_pump4', 'name' => 'Pumpstation 4', 'type' => 'tatort',
            'address' => 'Halloway-Stausee, Wartungszufahrt', 'x' => 0.78, 'y' => 0.35,
            'always_visible' => false, 'requires_flags' => ['ort_pumpstation_bekannt'],
            'description' => '1998 stillgelegte Pumpstation am Nordufer. Zugang nur mit Schluesselkarte der Wasserwerke. Unter dem Maschinenraum liegt ein Wartungsschacht mit eigener Belueftung - vom Ufer aus nicht einsehbar.',
            'notes' => 'Der Betonbau daempft Funk vollstaendig. Ein Mobiltelefon hat dort keinen Empfang.',
            'evidence' => ['E22'],
        ],
    ],

    'timeline_truth' => [
        ['time' => '19:40', 'event' => 'Toby schreibt im Forum: "treffe heute Lantern. wenn ich nicht schreibe, war es nicht meine idee."', 'actor' => 'Toby', 'location' => 'loc_home', 'evidence' => ['E21']],
        ['time' => '21:47', 'event' => 'Streit zwischen Toby und Frank Brennan, von Ruth Calloway gehoert (Dauer ca. 9 Minuten)', 'actor' => 'Frank', 'location' => 'loc_home'],
        ['time' => '22:05', 'event' => 'Toby schreibt Nora: "kannst du zum wasserturm kommen. 20 min"', 'actor' => 'Toby', 'evidence' => ['E06']],
        ['time' => '22:10', 'event' => 'Diane behauptet, Toby in seinem Zimmer gesehen zu haben (LUeGE - sie ist bereits unterwegs)', 'actor' => 'Diane', 'evidence' => ['E11']],
        ['time' => '22:16', 'event' => 'Toby verlaesst das Haus mit dem Fahrrad (Einfahrtskamera)', 'actor' => 'Toby', 'location' => 'loc_home', 'evidence' => ['E12']],
        ['time' => '22:27', 'event' => 'Toby und Nora treffen sich am Wasserturm, Selfie mit Zeitstempel', 'actor' => 'Nora', 'location' => 'loc_watertower', 'evidence' => ['E07']],
        ['time' => '22:30', 'event' => 'Frank Brennan verlaesst die Einfahrt mit dem Wagen', 'actor' => 'Frank', 'location' => 'loc_home', 'evidence' => ['E12']],
        ['time' => '22:31', 'event' => 'Nora faehrt nach Hause. Toby faehrt weiter nach Norden.', 'actor' => 'Nora', 'location' => 'loc_watertower', 'evidence' => ['E06']],
        ['time' => '22:34', 'event' => 'Diane Brennan an der Tankstelle Miller\'s Gas, 11,8 km entfernt (Kamera)', 'actor' => 'Diane', 'location' => 'loc_gas', 'evidence' => ['E11']],
        ['time' => '22:51', 'event' => 'Toby fotografiert das Warnschild am Zaun der Wasserwerke (GPS im Bild)', 'actor' => 'Toby', 'location' => 'loc_waterworks', 'evidence' => ['E08']],
        ['time' => '22:58', 'event' => 'Weisser Transporter 7KD-418 (Millbrook Water Works) faehrt Ridge Road Richtung Stausee', 'actor' => 'Doss', 'location' => 'loc_ridge', 'evidence' => ['E13']],
        ['time' => '23:05', 'event' => 'Frank kehrt zurueck, Tobys Fahrrad im Kofferraum (er hat es an der Ridge Road gefunden)', 'actor' => 'Frank', 'location' => 'loc_home', 'evidence' => ['E12', 'E20']],
        ['time' => '23:11', 'event' => 'Gueterzug CN-114 passiert den Bahnuebergang am Stausee (Signalhorn)', 'location' => 'loc_rail'],
        ['time' => '23:12', 'event' => 'Sprachnachricht von Toby an Nora: Pumpengeraeusch, Signalhorn, Abbruch nach 27 Sekunden', 'actor' => 'Toby', 'location' => 'loc_pump4', 'evidence' => ['E09']],
        ['time' => '23:14', 'event' => 'Letztes Signal von Tobys Telefon, Funkzelle Ridge/Reservoir', 'actor' => 'Toby', 'evidence' => ['E01']],
        ['time' => '23:40', 'event' => 'Diane kommt nach Hause', 'actor' => 'Diane', 'location' => 'loc_home'],
        ['time' => '01:20', 'event' => 'Fernbus nach Montreal faehrt ab - ohne Toby (Bordkamera)', 'day_offset' => 1, 'location' => 'loc_bus', 'evidence' => ['E18']],
        ['time' => '03:14', 'event' => '"Hoer auf, mich zu suchen." von Tobys Account, Funkzelle Ridge/Reservoir (getippt von Doss)', 'day_offset' => 1, 'actor' => 'Doss', 'evidence' => ['E17']],
        ['time' => '04:02', 'event' => 'Schluesselkarte WW-0114 (W. Doss) oeffnet Pumpstation 4', 'day_offset' => 1, 'actor' => 'Doss', 'location' => 'loc_pump4', 'evidence' => ['E19']],
        ['time' => '05:58', 'event' => 'Doss traegt die Nachtarbeiten nachtraeglich im Betriebsbuch ein - alle Eintraege in derselben Minute', 'day_offset' => 1, 'actor' => 'Doss', 'evidence' => ['E15']],
        ['time' => '08:20', 'event' => 'Diane Brennan meldet Toby als vermisst', 'day_offset' => 1, 'actor' => 'Diane', 'evidence' => ['E01']],
    ],

    'file_entries' => [
        [
            'code' => 'A-1', 'title' => 'Vermisstenanzeige (Formblatt FD-302)',
            'summary' => 'Meldung der Mutter, 12.10.2024, 08:20 Uhr.',
            'body' => "VERMISSTENANZEIGE - AUSZUG\n\nGemeldete Person: Brennan, Tobias, 17 J.\nMelderin: Brennan, Diane (Mutter)\nZeitpunkt der Meldung: 12.10.2024, 08:20 Uhr\n\nAngabe der Melderin (woertlich):\n\"Ich habe ihn gegen zehn nach zehn noch in seinem Zimmer gesehen, er hatte die\nKopfhoerer auf. Heute morgen war das Bett leer und das Fahrrad weg. Er wuerde\nnie einfach so verschwinden.\"\n\nErgaenzung Stiefvater (Frank Brennan):\n\"Ich habe ab neun geschlafen. Ich habe nichts gehoert.\"\n\nTechnische Feststellung:\n- Letzte Funkzelle: Ridge/Reservoir, 11.10.2024, 23:14 Uhr\n- Rucksack, Portemonnaie und Kopfhoerer fehlen\n- Bankkarte liegt im Zimmer auf dem Schreibtisch\n- Fenster von innen verschlossen, keine Einbruchspuren",
        ],
        [
            'code' => 'A-2', 'title' => 'Vermisstenplakat',
            'summary' => 'Fahndungsplakat, Stand 13.10.2024.',
            'media' => 'doc_poster',
        ],
        [
            'code' => 'A-3', 'title' => 'Altfaelle Millbrook 2003 / 2009 / 2015',
            'summary' => 'Zusammenstellung der ungeloesten Vermisstenfaelle.',
            'requires_flags' => ['laptop_offen'],
            'body' => "ALTFAELLE (AUSZUG AUS DER LANDESDATENBANK)\n\n2003 - Karen Pielmeier, 16, verschwindet nach dem Herbstfest.\n        Letzte Sichtung State Route 12. Nie gefunden.\n2009 - Danny Oro, 17, verschwindet in der Nacht zum Sonntag.\n        Fahrrad an der Ridge Road gefunden. Nie gefunden.\n2015 - Marisol Vance, 18, verschwindet nach der Spaetschicht.\n        Zeuge nennt weissen Transporter, zieht Aussage zurueck. Nie gefunden.\n\nGEMEINSAMKEITEN (Vorbewertung)\n- alle im Oktober\n- alle 16 bis 18 Jahre alt\n- alle in der Naehe der Ridge Road oder Route 12\n- keine Leiche, keine Forderung, keine Spur\n\nANMERKUNG: Marisol Vance ist die Tante von Nora Vance.",
        ],
        [
            'code' => 'A-4', 'title' => 'Technische Auswertung Funkzelle',
            'summary' => 'Standortdaten des Telefons, 11.-12.10.2024.',
            'requires_flags' => ['phone_offen'],
            'body' => "FUNKZELLENAUSWERTUNG (Provider: Greenline Mobile)\n\n21:58  Zelle MAPLE-02      (Wohngebiet)\n22:19  Zelle MAPLE-02\n22:26  Zelle TOWER-01      (Wasserturm)\n22:44  Zelle RIDGE-03\n22:53  Zelle RESERVOIR-01\n23:12  Zelle RESERVOIR-01  (Datenverkehr: Sprachnachricht, 27 s)\n23:14  Zelle RESERVOIR-01  (letzter Kontakt)\n---\n12.10. 03:14  Zelle RESERVOIR-01 (ausgehende Nachricht, 31 Zeichen)\n\nBEWERTUNG: Das Geraet hat das Gebiet RESERVOIR-01 nach 22:53 nicht mehr verlassen.\nEin Aufenthalt in Burlington oder Montreal ist technisch ausgeschlossen.",
        ],
    ],

    'reference' => [
        [
            'title' => 'Morsecode-Referenz 7-B',
            'summary' => 'Fuer die Auswertung von Signaltoenen in Audioaufnahmen.',
            'image' => 'assets/img/scenes/doc-morse.svg',
            'body' => "Kurzzeichen (.) = 1 Einheit, Langzeichen (-) = 3 Einheiten.\nP = .--.   4 = ....-\nTipp: Aufnahmen auch rueckwaerts und mit halber Geschwindigkeit pruefen.",
        ],
        [
            'title' => 'Fahrplan Gueterstrecke CN-114',
            'summary' => 'Zughoerner als Zeitanker in Audioaufnahmen.',
            'body' => "GUeTERSTRECKE CN-114 (Nachtplan, gueltig ab 09/2024)\n\n23:11  Millbrook Nord -> Rutland   (Signalhorn am Uebergang Stausee)\n04:40  Rutland -> Millbrook Nord   (Signalhorn am Uebergang Stausee)\n\nDer Uebergang am Halloway-Stausee ist der einzige Punkt im Stadtgebiet,\nan dem das Horn pflichtgemaess betaetigt wird. Hoerweite bei Nacht: ca. 2 km\nentlang des Nordufers.",
        ],
        [
            'title' => 'Betriebsordnung Wasserwerke (Auszug)',
            'summary' => 'Wann darf nachts gearbeitet werden?',
            'body' => "AUSZUG BETRIEBSORDNUNG MILLBROOK WATER WORKS\n\n§4 Nachtarbeiten\nSpuelungen und Druckpruefungen sind zwischen 22:00 und 05:00 Uhr zulaessig, wenn\nsie im Betriebsbuch eingetragen und vom Betriebsleiter freigegeben sind. Betroffene\nZufahrten sind zu sperren.\n\n§7 Zutritt stillgelegter Anlagen\nDer Zutritt zu stillgelegten Anlagen ist nur mit personalisierter Schluesselkarte\nund Eintrag im Zutrittsprotokoll gestattet.\n\nANMERKUNG DER TECHNIK: Eintraege im Betriebsbuch werden mit Uhrzeit der Eingabe\ngespeichert - nicht mit der Uhrzeit der Arbeit.",
        ],
        [
            'title' => 'Sprachstilprofil T. Brennan',
            'summary' => 'Wie Toby tatsaechlich schreibt (aus 812 Nachrichten).',
            'body' => "SPRACHSTILPROFIL (automatische Auswertung, 812 Nachrichten)\n\n- Satzanfaenge: 97 % klein geschrieben\n- Punkt am Satzende: 4 %\n- \"ok\" wird in 100 % der Faelle als \"k\" geschrieben\n- typische Fuellwoerter: \"ey\", \"halt\", \"ka\" (keine Ahnung)\n- Tippfehlerquote: 6,1 % der Woerter\n- Emojis: nie\n\nAbweichungen von diesem Profil sind ein starker Hinweis darauf, dass eine\nandere Person am Geraet geschrieben hat.",
        ],
    ],
];
