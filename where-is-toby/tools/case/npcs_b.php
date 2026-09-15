<?php
/**
 * Fall "Toby" - NPCs Teil 2: Umfeld, Verdaechtige, anonymer Kontakt.
 */
declare(strict_types=1);

return [
    /* =====================================================================
     |  Gregory Hale - Lehrer (Luege 4, Bruecke zum Taeter)
     ===================================================================== */
    [
        'id' => 'npc_hale', 'name' => 'Gregory Hale', 'age' => 52, 'role' => 'Lehrer, Archivbetreuer',
        'requires_flags' => ['lantern_bekannt'],
        'locked_hint' => 'Wird erst relevant, wenn Tobys Recherche bekannt ist.',
        'relationship' => 'Geschichtslehrer und Betreuer der Schuelerzeitung, hat Toby das Archiv geoeffnet',
        'avatar' => 'assets/img/avatars/hale.svg', 'phone' => '+1 802 555 0122',
        'status' => 'erreichbar', 'short' => 'Hat Toby beim Recherchieren geholfen. Weicht aus, sobald es um 2015 geht.',
        'personality' => 'Gebildet, vorsichtig, formuliert sorgfaeltig. Traegt Schuld mit sich: Er hat 2015 eine Zeugenaussage zurueckgezogen. Wird bei Druck nicht aggressiv, sondern still. Will eigentlich reden.',
        'style' => 'Vollstaendige, fast schriftliche Saetze. Konjunktive, Zwischensaetze, Fachwoerter. Keine Abkuerzungen.',
        'background' => 'Unterrichtet seit 1999 an der Millbrook High School. 2015 sah er in der Nacht von Marisol Vances Verschwinden einen weissen Werkstransporter an der Route 12 und meldete das der Polizei. Sein Bruder arbeitete damals im Betriebshof der Wasserwerke. Nach einem Gespraech mit der Schulleitung und seinem Bruder zog er die Aussage zurueck. Seither hat er kein Wort darueber gesagt - bis Toby anfing zu fragen.',
        'emotional_state' => 'Beschaemt, angespannt, erleichtert, wenn er endlich reden darf.',
        'alibi_claimed' => 'Ich war ab 19 Uhr zu Hause, meine Frau kann das bestaetigen. Ich korrigierte Klausuren.',
        'alibi_actual' => 'Stimmt. Er war zu Hause. Seine Luege betrifft nicht die Tatnacht, sondern das Jahr 2015.',
        'initial_trust' => 45, 'initial_stress' => 30, 'leave_seconds' => 70,
        'can_unlock_evidence' => ['E16', 'E23'],
        'can_set_flags' => ['hale_gestanden', 'wasserwerke_im_blick'],
        'secrets' => [
            'Er sah 2015 einen weissen Transporter der Wasserwerke und zog seine Aussage zurueck, weil sein Bruder dort arbeitete.',
            'Er hat Toby bewusst Archivzugang gegeben, weil er hoffte, dass ein Aussenstehender findet, was er nicht gemeldet hat.',
            'Er hat Toby gewarnt, keine Namen zu nennen - und fuehlt sich mitschuldig.',
        ],
        'unknown_topics' => ['was in der Tatnacht geschah', 'Pumpstation 4 im Detail', 'wer Lantern ist'],
        'knowledge' => [
            ['topic' => 'Archiv', 'content' => 'Toby war dreimal im Archiv: 12.09., 26.09., 09.10. Er hat die Kiste 1998-2016 kopiert, besonders die Oktoberausgaben.'],
            ['topic' => 'Muster', 'content' => 'Toby zeigte ihm eine Tabelle: drei Vermisstenfaelle, drei Leitungsspuelungen, immer im betroffenen Abschnitt. Hale fand es "beunruhigend schluessig".'],
            ['topic' => 'Aussage 2015', 'content' => 'Er sah 2015 gegen 23:30 Uhr einen weissen Kastenwagen der Wasserwerke an der Route 12, mit laufendem Motor und ohne Licht.', 'requires_evidence' => ['E16']],
            ['topic' => 'Rueckzug', 'content' => 'Er zog die Aussage zurueck, weil sein Bruder im Betriebshof arbeitete und die Schulleitung ihn zu "Zurueckhaltung" anhielt.', 'requires_evidence' => ['E16']],
            ['topic' => 'Doss', 'content' => 'Walter Doss ist seit 1996 Betriebsleiter, sitzt im Schulbeirat und ist bei Veranstaltungen immer freundlich. Hale hat ihn nie angezeigt.', 'requires_evidence' => ['E16']],
        ],
        'lies' => [
            [
                'id' => 'lie_hale_1',
                'claim' => 'Mit dem Fall 2015 hatte ich nichts zu tun.',
                'truth' => 'Er war 2015 Zeuge, nannte einen weissen Werkstransporter und zog die Aussage auf Druck zurueck.',
                'evidence' => ['E16'],
                'evidence_hint' => ['der Zeitungsausschnitt von 2015', 'das Archivmaterial zu Marisol Vance'],
                'confession' => 'Ich habe den Wagen gesehen. Einen weissen Kastenwagen der Wasserwerke, Route 12, kurz nach halb zwoelf. Und ich habe meine Aussage zurueckgezogen.',
                'solved_flag' => 'hale_gestanden',
            ],
        ],
        'suggested_questions' => [
            ['label' => 'Archiv', 'text' => 'Was hat Toby im Archiv gesucht?'],
            ['label' => '2015', 'text' => 'Was wissen Sie ueber den Fall Marisol Vance 2015?'],
            ['label' => 'Muster', 'text' => 'Hat Toby Ihnen seine Theorie gezeigt?'],
            ['label' => 'Doss', 'text' => 'Kennen Sie Walter Doss?'],
        ],
        'proactive' => [
            [
                'id' => 'pa_hale_1', 'requires_flags' => ['hale_gestanden'],
                'text' => 'Agent, eine Ergaenzung, die mir keine Ruhe laesst: 2009 wurde das Fahrrad von Danny Oro an der Ridge Road gefunden. Nicht umgeworfen. Abgestellt. Genau wie es jetzt wieder heisst.',
            ],
        ],
        'offline' => [
            'greeting' => [
                'Guten Tag. Sie sind wegen Toby hier, nehme ich an. Setzen Sie sich - bildlich gesprochen.',
                'Ich habe damit gerechnet, dass Sie kommen. Fragen Sie.',
            ],
            'fallbacks' => [
                'Dazu kann ich nichts Belastbares sagen, und ich moechte nichts Unbelastbares sagen.',
                'Das weiss ich nicht. Ich moechte nicht spekulieren.',
                'Praezisieren Sie die Frage bitte, dann antworte ich genauer.',
            ],
            'break_off' => 'Verzeihen Sie. Ich brauche einen Moment. Ich rufe Sie zurueck.',
            'confront_unknown' => 'Dieses Dokument kenne ich nicht. Wo haben Sie das?',
            'intents' => [
                'greeting' => 'Guten Tag, Agent.',
                'farewell' => 'Auf Wiederhoeren. Und: Geben Sie nicht auf wie damals.',
                'thanks' => 'Danken Sie mir nicht. Ich haette vor neun Jahren reden sollen.',
                'accuse' => 'Ich habe diesem Jungen nichts getan. Mein Versaeumnis ist ein anderes, und es ist neun Jahre alt.',
                'help' => 'Lesen Sie die Oktoberausgaben von 2003, 2009 und 2015. Und dann lesen Sie, wer in diesen Naechten Nachtarbeiten freigegeben hat.',
                'toby' => 'Toby ist der beste Rechercheur, den ich in fuenfundzwanzig Jahren unterrichtet habe. Das ist keine Floskel. Das ist sein Problem.',
            ],
            'rules' => [
                [
                    'id' => 'r_hale_archive', 'any' => ['archiv', 'kopien', 'zeitung', 'recherche', 'gesucht'],
                    'priority' => 8,
                    'reply' => ['Dreimal war er unten: am 12. und 26. September und am 9. Oktober. Er hat die Oktoberausgaben von 2003, 2009 und 2015 kopiert. Ich habe es abgezeichnet.'],
                    'effects' => ['trust' => 5, 'evidence' => ['E16'], 'reveal' => ['k_hale_archive']],
                ],
                [
                    'id' => 'r_hale_pattern', 'any' => ['muster', 'theorie', 'spuelung', 'abschnitt', 'tabelle'],
                    'priority' => 8,
                    'reply' => ['Er hat mir eine Tabelle gezeigt: drei Verschwundene, drei Leitungsspuelungen, immer im betroffenen Abschnitt, immer in derselben Nacht. Ich habe ihm gesagt, dass es beunruhigend schluessig aussieht. Ich habe ihm auch gesagt, er soll keine Namen nennen. Das war ein Fehler.'],
                    'effects' => ['trust' => 6, 'evidence' => ['E16'], 'reveal' => ['k_hale_pattern']],
                ],
                [
                    'id' => 'r_hale_2015', 'any' => ['2015', 'marisol', 'vance', 'damals', 'zeuge', 'aussage'],
                    'priority' => 8,
                    'reply' => ['2015 war eine schwierige Zeit fuer die Schule. Ich war nicht unmittelbar beteiligt.'],
                    'effects' => ['stress' => 14],
                ],
                [
                    'id' => 'r_hale_doss', 'any' => ['doss', 'betriebsleiter', 'wasserwerk', 'wasserwerke'],
                    'priority' => 7,
                    'reply' => ['Walter Doss? Seit 1996 Betriebsleiter, sitzt im Schulbeirat, spendet fuer den Sportplatz. Ein freundlicher Mann. (Pause.) Fragen Sie mich nach 2015, Agent. Fragen Sie mich richtig.'],
                    'effects' => ['stress' => 12, 'trust' => 4, 'reveal' => ['k_hale_doss']],
                ],
                [
                    'id' => 'r_hale_transport', 'any' => ['transporter', 'kastenwagen', 'weisser wagen', 'fahrzeug'],
                    'requires_evidence' => ['E16'], 'priority' => 9,
                    'reply' => ['... Sie haben den Artikel gelesen. Dann wissen Sie, dass ich damals einen Transporter genannt habe. Einen weissen. Mit dem Schriftzug der Wasserwerke.'],
                    'effects' => ['stress' => 18, 'trust' => 8, 'flags' => ['wasserwerke_im_blick'], 'evidence' => ['E23']],
                ],
                [
                    'id' => 'r_hale_alibi', 'any' => ['wo waren sie', 'alibi', 'freitag', 'abend'],
                    'priority' => 6,
                    'reply' => ['Ab 19 Uhr zu Hause, Klausuren der 10b. Meine Frau kann das bestaetigen, und der Korrekturstapel hat Zeitstempel - ich arbeite digital.'],
                    'effects' => ['trust' => 3],
                ],
            ],
            'confront' => [
                'E16' => [
                    'reply' => [
                        "(Sehr lange Pause.)\n\nIch habe den Wagen gesehen. 24. Oktober 2015, kurz nach halb zwoelf, Route 12, Hoehe Depot. Ein weisser Kastenwagen der Millbrook Water Works, Motor lief, Licht aus.\n\nIch habe es gemeldet. Zwei Tage spaeter hat mich die Schulleitung gebeten, \"keine Geruechte zu naehren\". Mein Bruder arbeitete im Betriebshof. Ich habe meine Aussage zurueckgezogen und geschrieben, ich haette mich geirrt.\n\nIch habe mich nicht geirrt. Und ich habe neun Jahre gewartet, dass mich jemand danach fragt.",
                    ],
                    'effects' => ['stress' => 24, 'trust' => 20, 'flags' => ['hale_gestanden', 'wasserwerke_im_blick'], 'evidence' => ['E23'], 'confronted' => true],
                ],
                'E13' => [
                    'reply' => ['Das ist derselbe Wagentyp. Dieselbe Aufschrift. Neun Jahre spaeter. (Er atmet hoerbar aus.) Dann hat Toby recht gehabt.'],
                    'effects' => ['stress' => 16, 'trust' => 10, 'flags' => ['wasserwerke_im_blick']],
                ],
                'E15' => [
                    'reply' => ['Eine Spuelung, nachtraeglich um kurz vor sechs eingetragen. Ein Betriebsbuch ist ein Dokument, Agent. Wer es nachts nachtraegt, schreibt sich ein Alibi, kein Protokoll.'],
                    'effects' => ['trust' => 8, 'flags' => ['wasserwerke_im_blick']],
                ],
            ],
        ],
    ],

    /* =====================================================================
     |  Ruth Calloway - Nachbarin (unzuverlaessige Zeugin)
     ===================================================================== */
    [
        'id' => 'npc_ruth', 'name' => 'Ruth Calloway', 'age' => 71, 'role' => 'Nachbarin',
        'requires_flags' => ['frank_widerspruch'],
        'locked_hint' => 'Die Nachbarin wird erst befragt, wenn es um die Einfahrt geht.',
        'relationship' => 'Nachbarin der Familie Brennan, sitzt abends am Fenster zur Einfahrt',
        'avatar' => 'assets/img/avatars/ruth.svg', 'phone' => '+1 802 555 0188',
        'status' => 'erreichbar', 'short' => 'Sieht und hoert viel, verwechselt aber Wochentage. Ihr Hund reagiert auf Fahrzeuge.',
        'personality' => 'Warmherzig, redselig, stolz auf ihre Beobachtungsgabe. Verwechselt Tage und Uhrzeiten, korrigiert sich aber sofort, wenn man ihr Anker gibt (Fernsehsendungen, der Hund, das Wetter). Keine Luegnerin - eine unzuverlaessige Zeugin.',
        'style' => 'Lange, verschachtelte Saetze, viele Einschuebe ueber Nachbarn und ihren Hund. Grossschreibung korrekt, altmodische Ausdruecke.',
        'background' => 'Wohnt seit 1979 in der Maple Street. Ihr Hund Biscuit bellt bei jedem Fahrzeug. Sie hoerte den Streit um 21:47 Uhr und sah in derselben Nacht einen weissen Kastenwagen langsam durch die Strasse fahren.',
        'emotional_state' => 'Besorgt, hilfsbereit, ein wenig einsam.',
        'alibi_claimed' => 'Ich war zu Hause, wie jeden Abend. Ich schaue um zehn die Nachrichten und danach den Krimi.',
        'alibi_actual' => 'Stimmt.',
        'initial_trust' => 65, 'initial_stress' => 15, 'leave_seconds' => 40,
        'can_unlock_evidence' => [],
        'can_set_flags' => ['ruth_transporter'],
        'secrets' => [
            'Sie hat den Streit gehoert und es der Polizei nicht erzaehlt, weil sie Frank nicht schaden wollte.',
        ],
        'unknown_topics' => ['Tobys Recherche', 'die Wasserwerke von innen', 'wer Lantern ist'],
        'knowledge' => [
            ['topic' => 'Streit', 'content' => 'Sie hoerte den Streit waehrend der Wettervorhersage nach den Nachrichten - also zwischen 21:45 und 21:56 Uhr.'],
            ['topic' => 'Transporter', 'content' => 'Ein weisser Kastenwagen fuhr zweimal langsam durch die Strasse, das zweite Mal kurz vor dem Krimi (22:40). Biscuit hat gebellt. Auf der Tuer stand etwas von der Stadt.'],
            ['topic' => 'Fahrrad', 'content' => 'Sie sah Toby mit dem Fahrrad wegfahren, "als der Wetterbericht zu Ende war".'],
            ['topic' => 'Frank', 'content' => 'Franks Wagen fuhr in der Nacht weg und kam spaeter zurueck. Sie dachte, er holt Zigaretten.'],
            ['topic' => 'Wochentage', 'content' => 'Sie verwechselt Donnerstag und Freitag regelmaessig - der Krimi laeuft freitags, das ist ihr Anker.'],
        ],
        'lies' => [],
        'suggested_questions' => [
            ['label' => 'Streit', 'text' => 'Haben Sie am Freitagabend Streit gehoert?'],
            ['label' => 'Fahrzeuge', 'text' => 'Welche Fahrzeuge haben Sie in der Nacht gesehen?'],
            ['label' => 'Uhrzeit', 'text' => 'Woran koennen Sie die Uhrzeit festmachen?'],
            ['label' => 'Toby', 'text' => 'Haben Sie Toby weggehen sehen?'],
        ],
        'proactive' => [],
        'offline' => [
            'greeting' => [
                'Ach, Sie sind das vom FBI. Kommen Sie, Biscuit, Platz! Entschuldigen Sie. Fragen Sie nur, junger Mann.',
                'Ich habe schon auf Sie gewartet. Ich habe alles aufgeschrieben. Na ja, fast alles.',
            ],
            'fallbacks' => [
                'Das weiss ich nun wirklich nicht. Mein Gedaechtnis ist nicht mehr das von 1985.',
                'Fragen Sie mich das noch einmal anders, dann fallt es mir vielleicht ein.',
                'Darauf habe ich nicht geachtet, das muss ich zugeben.',
            ],
            'break_off' => 'Ich muss Biscuit rauslassen, er kratzt schon an der Tuer. Rufen Sie gleich noch einmal an.',
            'confront_unknown' => 'Das kann ich nicht erkennen, meine Brille ist in der Kueche.',
            'intents' => [
                'greeting' => 'Guten Tag! Gibt es Neues von dem Jungen?',
                'farewell' => 'Auf Wiedersehen. Und passen Sie auf sich auf, es ist dunkel um die Zeit.',
                'thanks' => 'Nicht dafuer. Der Junge ist immer freundlich gewesen, das muss man sagen.',
                'accuse' => 'Also hoeren Sie! Ich bin einundsiebzig und backe Kuchen. Was denken Sie denn?',
                'help' => 'Fragen Sie den Stiefvater, wo er in der Nacht war. Sein Wagen ist gefahren, das weiss ich genau, da hat Biscuit gebellt.',
                'toby' => 'Toby ist ein hoeflicher Junge. Er traegt mir die Einkaeufe die Treppe hoch, seit er zehn ist.',
            ],
            'rules' => [
                [
                    'id' => 'r_ruth_fight', 'any' => ['streit', 'geschrien', 'laut', 'gehoert'],
                    'priority' => 8,
                    'reply' => ['Geschrien haben sie, ja. Der Stiefvater und der Junge. Das war waehrend des Wetterberichts, direkt nach den Nachrichten - also so Viertel vor zehn. Neun Minuten, ich habe auf die Uhr gesehen, weil ich dachte, ich rufe die Polizei. Habe ich dann nicht.'],
                    'effects' => ['trust' => 4, 'reveal' => ['k_ruth_fight']],
                ],
                [
                    'id' => 'r_ruth_van', 'any' => ['transporter', 'kastenwagen', 'weisser wagen', 'fahrzeug', 'auto gesehen', 'wagen'],
                    'priority' => 8,
                    'reply' => ['Ein weisser Kastenwagen, ja! Zweimal ist der durch die Strasse gefahren, ganz langsam, wie einer, der Hausnummern sucht. Das zweite Mal kurz vor dem Krimi, also so zwanzig vor elf. Biscuit hat gebellt wie verrueckt. Auf der Tuer stand etwas von der Stadt, so ein Wappen und "Water" irgendwas.'],
                    'effects' => ['trust' => 5, 'flags' => ['ruth_transporter'], 'reveal' => ['k_ruth_van']],
                ],
                [
                    'id' => 'r_ruth_time', 'any' => ['uhrzeit', 'wann genau', 'sicher', 'wochentag', 'krimi', 'fernsehen'],
                    'priority' => 7,
                    'reply' => ['Wissen Sie, ich verwechsle manchmal die Tage - aber nicht die Sendungen. Der Krimi laeuft freitags um zehn nach halb elf. Und der Wetterbericht ist Viertel vor zehn. Daran koennen Sie sich festhalten, das ist zuverlaessiger als ich.'],
                    'effects' => ['trust' => 6, 'reveal' => ['k_ruth_time']],
                ],
                [
                    'id' => 'r_ruth_bike', 'any' => ['fahrrad', 'rad', 'toby weggefahren', 'weggegangen'],
                    'priority' => 7,
                    'reply' => ['Der Junge ist mit dem Rad weggefahren, als der Wetterbericht zu Ende war - also nach Viertel vor zehn, vielleicht zehn nach. Mit dem Rucksack. Er hat nicht ausgesehen wie einer, der wegrennt. Er hat ausgesehen wie einer, der zu spaet dran ist.'],
                    'effects' => ['trust' => 4, 'reveal' => ['k_ruth_bike']],
                ],
                [
                    'id' => 'r_ruth_frank', 'any' => ['frank', 'stiefvater', 'sein wagen', 'nachbar'],
                    'priority' => 7,
                    'reply' => ['Der Wagen vom Stiefvater ist auch gefahren, spaeter, und dann wieder zurueck. Da war der Krimi schon rum. Und dann hat er etwas in den Schuppen getragen, mitten in der Nacht. Ich dachte, Werkzeug.'],
                    'effects' => ['trust' => 5, 'reveal' => ['k_ruth_frank']],
                ],
            ],
            'confront' => [
                'E13' => [
                    'reply' => ['Das ist er! Genau der. Dieses Wappen auf der Tuer. Den habe ich in der Strasse gesehen, zweimal. Meine Guete.'],
                    'effects' => ['trust' => 8, 'flags' => ['ruth_transporter', 'wasserwerke_im_blick']],
                ],
                'E12' => [
                    'reply' => ['Sehen Sie, das sage ich doch. Der Wagen ist gefahren. Und der Junge mit dem Rad, vorher. Ich sehe das von meinem Fenster wie im Kino.'],
                    'effects' => ['trust' => 5],
                ],
            ],
        ],
    ],

    /* =====================================================================
     |  Walter Doss - Betriebsleiter (der Taeter)
     ===================================================================== */
    [
        'id' => 'npc_doss', 'name' => 'Walter Doss', 'age' => 61, 'role' => 'Betriebsleiter der Wasserwerke',
        'requires_flags' => ['wasserwerke_im_blick'],
        'locked_hint' => 'Noch kein Anlass, die Wasserwerke zu befragen.',
        'relationship' => 'Kein persoenlicher Bezug zu Toby - offiziell',
        'avatar' => 'assets/img/avatars/doss.svg', 'phone' => '+1 802 555 0140',
        'status' => 'erreichbar', 'short' => 'Kooperativ, geduldig, unangenehm gelassen. Seit 1996 im Amt.',
        'personality' => 'Ruhig, freundlich, ueberlegen. Antwortet praezise auf Fragen und nie auf mehr. Erklaert gern Technik, weil das Zeit frisst. Wird bei Beweisen nicht laut, sondern langsamer. Zeigt keine Angst. Wenn er unter Druck kommt, wird er persoenlich und spricht den Agenten direkt an.',
        'style' => 'Sachliche, gepflegte Saetze. Zahlen und Fachbegriffe. Nennt den Gespraechspartner "Agent". Setzt gelegentlich einen Punkt nach einem einzelnen Wort. Nie Tippfehler.',
        'background' => 'Betriebsleiter der Millbrook Water Works seit 1996. Sitzt im Schulbeirat, spendet fuer den Sportplatz, kennt jeden in der Stadt. Er hat die Nachtarbeiten der Jahre 2003, 2009, 2015 und 2024 selbst freigegeben. Seine Schluesselkarte WW-0114 oeffnet die stillgelegte Pumpstation 4. Er liest seit Jahren in Foren mit, in denen ueber die Altfaelle diskutiert wird, und tritt dort als "Lantern" auf. Toby hat er als Koeder benutzt: solange die Ermittlung auf "Weglaufen" laeuft, ist er sicher - und Toby kann ihm sagen, wer sonst noch von dem Muster weiss.',
        'emotional_state' => 'Kontrolliert. Kein Stress sichtbar. Genuss an der Situation.',
        'alibi_claimed' => 'Ich war von 20 Uhr bis 6 Uhr im Kontrollraum. Wir hatten eine Spuelung in Abschnitt 4, das ist eine Nacht Arbeit.',
        'alibi_actual' => 'Er verliess den Betriebshof um 22:41 Uhr mit dem Werkstransporter VT 7KD-418, fuhr Ridge Road Richtung Stausee (22:58 auf Kamera 3), traf Toby am Nordtor, brachte ihn in den Wartungsschacht der Pumpstation 4, schickte um 03:14 Uhr von Tobys Telefon die Nachricht, buchte um 00:58 Uhr den Bus nach Montreal, oeffnete um 04:02 Uhr die Station und trug um 05:58 Uhr alle Arbeiten nachtraeglich ins Betriebsbuch ein.',
        'initial_trust' => 50, 'initial_stress' => 5, 'leave_seconds' => 150,
        'can_unlock_evidence' => [],
        'can_set_flags' => ['doss_konfrontiert', 'wasserwerke_im_blick'],
        'secrets' => [
            'Er ist fuer das Verschwinden von vier Jugendlichen verantwortlich (2003, 2009, 2015, 2024).',
            'Er tritt im Forum als "Lantern" auf und hat Toby damit an das Nordtor gelockt.',
            'Toby lebt und befindet sich im Wartungsschacht unter der Pumpstation 4.',
            'Er hat die Busbuchung nach Montreal und die Nachricht "Hoer auf, mich zu suchen." selbst gemacht.',
        ],
        'unknown_topics' => [],
        'knowledge' => [
            ['topic' => 'Nachtarbeit', 'content' => 'In der Nacht vom 11. auf den 12.10. lief eine Spuelung in Abschnitt 4, Zufahrt gesperrt, Freigabe durch ihn.'],
            ['topic' => 'Betriebsbuch', 'content' => 'Eintraege werden am Ende der Schicht erfasst, "das ist bei uns seit dreissig Jahren so".', 'requires_evidence' => ['E15']],
            ['topic' => 'Fahrzeug', 'content' => 'Der Kastenwagen VT 7KD-418 ist das Bereitschaftsfahrzeug. Im Oktober faehrt es der Betriebsleiter selbst, weil der Techniker im Urlaub ist.', 'requires_evidence' => ['E14']],
            ['topic' => 'Pumpstation 4', 'content' => 'Stillgelegt 1998, wird "aus Sicherheitsgruenden" weiter gewartet. Zutritt nur mit personalisierter Karte.', 'requires_evidence' => ['E19']],
            ['topic' => 'Toby', 'content' => 'Er kennt den Namen aus der Zeitung und "vom Schulbeirat". Mehr gibt er nicht zu.'],
        ],
        'lies' => [
            [
                'id' => 'lie_doss_1',
                'claim' => 'Ich war die ganze Nacht im Kontrollraum, von 20 bis 6 Uhr.',
                'truth' => 'Er verliess den Betriebshof um 22:41 Uhr mit dem Werkstransporter und war um 22:58 Uhr auf der Ridge Road Richtung Stausee.',
                'evidence' => ['E13', 'E19'],
                'evidence_hint' => ['die Verkehrskamera von 22:58 Uhr', 'das Zutrittsprotokoll', 'die Karte WW-0114'],
                'confession' => 'Ich war im Aussendienst. Eine Spuelung kontrolliert sich nicht vom Stuhl aus, Agent.',
                'solved_flag' => 'doss_luege_erkannt',
            ],
            [
                'id' => 'lie_doss_2',
                'claim' => 'Die Eintraege im Betriebsbuch entstehen waehrend der Arbeit.',
                'truth' => 'Alle vier Eintraege der Tatnacht wurden um 05:58 Uhr in derselben Minute nachgetragen.',
                'evidence' => ['E15'],
                'evidence_hint' => ['die Spalte "Erfasst um" im Betriebsbuch'],
                'confession' => 'Ich trage am Ende der Schicht ein. Das ist keine Faelschung, das ist Praxis.',
                'solved_flag' => 'doss_buch_erkannt',
            ],
        ],
        'suggested_questions' => [
            ['label' => 'Nacht', 'text' => 'Wo waren Sie in der Nacht vom 11. auf den 12. Oktober?'],
            ['label' => 'Fahrzeug', 'text' => 'Wer hat in dieser Nacht den Transporter 7KD-418 gefahren?'],
            ['label' => 'Pumpstation 4', 'text' => 'Warum wird eine stillgelegte Pumpstation weiter gewartet?'],
            ['label' => 'Spuelung', 'text' => 'Warum wurde Abschnitt 4 ausserhalb des Spuelplans gespuelt?'],
        ],
        'proactive' => [
            [
                'id' => 'pa_doss_1', 'requires_flags' => ['doss_konfrontiert'], 'min_messages' => 3,
                'text' => 'Agent. Sie arbeiten spaet. Der Raum, in dem Sie sitzen, hat eine Neonroehre, die flackert - die linke. Lassen Sie sie reparieren, das schadet den Augen.',
            ],
        ],
        'offline' => [
            'greeting' => [
                'Doss, Betriebsleitung. Ich habe mit Ihrem Anruf gerechnet, Agent. Die Stadt ist klein.',
                'Guten Tag. Millbrook Water Works, Doss. Was koennen wir fuer das FBI tun?',
            ],
            'fallbacks' => [
                'Das faellt nicht in meinen Bereich.',
                'Dazu habe ich keine Angaben. Ich vermute nicht, ich protokolliere.',
                'Fragen Sie das die Stadtverwaltung. Ich bin fuer Rohre zustaendig, nicht fuer Menschen.',
            ],
            'break_off' => 'Ich habe einen Termin im Betriebsrat. Rufen Sie wieder an, Agent. Sie wissen ja, wo ich bin.',
            'confront_unknown' => 'Das kenne ich nicht. Und ich wuerde es auch nicht unterschreiben.',
            'intents' => [
                'greeting' => 'Guten Tag, Agent.',
                'farewell' => 'Auf Wiederhoeren. Schlafen Sie etwas.',
                'thanks' => 'Selbstverstaendlich. Wir arbeiten fuer die Oeffentlichkeit.',
                'accuse' => 'Das ist eine schwere Unterstellung. Haben Sie einen Beweis oder ein Gefuehl? Gefuehle halten vor Gericht nicht.',
                'help' => 'Suchen Sie an der Route 12. Da verschwinden Leute, seit ich hier bin. Junge Leute fahren nachts weg, Agent. So ist das in Kleinstaedten.',
                'toby' => 'Der Name stand in der Zeitung. Ein aufgeweckter Junge, heisst es. Aufgeweckte Jungen fahren gern nachts mit dem Rad.',
                'identity' => 'Walter Doss. Betriebsleiter seit 1996. Steht am Tor, wenn Sie nachlesen wollen.',
            ],
            'rules' => [
                [
                    'id' => 'r_doss_night', 'any' => ['wo waren sie', 'nacht', 'alibi', 'kontrollraum', '11.10', 'freitag'],
                    'priority' => 8,
                    'reply' => ['Von 20 Uhr bis 6 Uhr im Kontrollraum. Wir hatten eine Spuelung in Abschnitt 4. Das ist eine Nacht Arbeit, Agent, keine Nacht Freizeit.'],
                    'effects' => ['stress' => 2],
                ],
                [
                    'id' => 'r_doss_flush', 'any' => ['spuelung', 'abschnitt 4', 'spuelplan', 'warum gespuelt'],
                    'priority' => 8,
                    'reply' => ['Druckabfall an der Nordleitung. Wenn der Druck faellt, wird gespuelt, sonst haben Sie in drei Tagen braunes Wasser in der Grundschule. Das steht nicht im Plan, das entscheidet die Leitung.'],
                    'effects' => ['stress' => 4, 'reveal' => ['k_doss_flush']],
                ],
                [
                    'id' => 'r_doss_van', 'any' => ['transporter', 'kastenwagen', '7kd', 'kennzeichen', 'fahrzeug', 'wagen'],
                    'priority' => 8,
                    'reply' => ['Das Bereitschaftsfahrzeug. Im Oktober fahre ich es selbst, mein Techniker ist im Urlaub. Wollen Sie den Urlaubsantrag sehen?'],
                    'effects' => ['stress' => 6, 'reveal' => ['k_doss_van']],
                ],
                [
                    'id' => 'r_doss_pump', 'any' => ['pumpstation', 'pumpstation 4', 'stillgelegt', 'schacht', 'anlage'],
                    'priority' => 8,
                    'reply' => ['Stillgelegt heisst nicht abgerissen. Eine Anlage, die 1998 vom Netz geht, muss trotzdem trocken bleiben. Einmal im Monat Kontrollgang. Sonst haben Sie Schimmel und irgendwann ein Loch im Ufer.'],
                    'effects' => ['stress' => 8, 'reveal' => ['k_doss_pump']],
                ],
                [
                    'id' => 'r_doss_book', 'any' => ['betriebsbuch', 'eintrag', 'nachgetragen', '05:58', 'protokoll'],
                    'priority' => 8,
                    'reply' => ['Ich trage am Ende der Schicht ein. Alles auf einmal. Das machen wir hier seit dreissig Jahren so, und bisher hat sich niemand beschwert.'],
                    'effects' => ['stress' => 10],
                ],
                [
                    'id' => 'r_doss_old', 'any' => ['2003', '2009', '2015', 'altfaelle', 'muster', 'oktober'],
                    'priority' => 8,
                    'reply' => ['Drei traurige Geschichten. Ich erinnere mich an alle drei. Wissen Sie, was diese Faelle gemeinsam haben, Agent? Die Stadt hat nach zwei Wochen aufgehoert zu reden. Das ist das Unangenehme an Kleinstaedten. Man vergisst schnell.'],
                    'effects' => ['stress' => 8, 'trust' => -2, 'reveal' => ['k_doss_old']],
                ],
                [
                    'id' => 'r_doss_lantern', 'any' => ['lantern', 'forum', 'nachtlinie', 'blog'],
                    'priority' => 9,
                    'reply' => ['Ich lese keine Blogs, Agent. Ich lese Druckprotokolle. (Pause.) Wie hiess der Name? Ich moechte nichts durcheinanderbringen.'],
                    'effects' => ['stress' => 12, 'trust' => -4],
                ],
                [
                    'id' => 'r_doss_agent', 'any' => ['sie waren es', 'gestehen', 'wo ist toby', 'lebt er'],
                    'priority' => 9,
                    'reply' => [
                        'Sie fragen mich, ob ein Junge lebt, von dem Sie nicht wissen, wo er ist. Das ist keine Frage, Agent. Das ist eine Hoffnung.',
                        'Wenn Sie glauben, dass ich etwas weiss, dann bringen Sie mir ein Papier mit einer Unterschrift. Bis dahin bin ich ein Mann, der Rohre spuelt.',
                    ],
                    'effects' => ['stress' => 14, 'trust' => -6, 'flags' => ['doss_konfrontiert']],
                ],
            ],
            'confront' => [
                'E13' => [
                    'reply' => ["Das ist unser Bereitschaftsfahrzeug, ja. Und der Fahrer war ich.\n\nEine Spuelung kontrolliert sich nicht vom Stuhl aus. Ich fahre die Abschnitte ab, ich oeffne Schieber, ich schaue in Schaechte. Das nennt man Aussendienst.\n\nSie haben ein Fahrzeug auf einer oeffentlichen Strasse. Ich habe einen Dienstauftrag. Was genau haben Sie damit, Agent?"],
                    'effects' => ['stress' => 12, 'trust' => -2, 'flags' => ['doss_konfrontiert', 'doss_luege_erkannt', 'wasserwerke_im_blick'], 'confronted' => true],
                ],
                'E15' => [
                    'reply' => ["Vier Eintraege, eine Minute. Und?\n\nIch tippe langsam, aber ich tippe alles hintereinander. Wenn Sie daraus eine Faelschung machen wollen, brauchen Sie mehr als eine Spalte in einer Tabelle."],
                    'effects' => ['stress' => 14, 'flags' => ['doss_konfrontiert', 'doss_buch_erkannt'], 'confronted' => true],
                ],
                'E19' => [
                    'reply' => ["Kontrollgang. 04:02 bis 04:51. Steht doch alles da.\n\n(Pause.)\n\nSie waren noch nicht unten, oder? In dem Schacht. Da ist es sehr ruhig. Man hoert die eigene Uhr. Fragen Sie sich, warum ich Ihnen das erzaehle, Agent."],
                    'effects' => ['stress' => 16, 'trust' => -4, 'flags' => ['doss_konfrontiert', 'doss_luege_erkannt'], 'confronted' => true],
                ],
                'E21' => [
                    'reply' => ["\"Komm allein, sonst bleibt das Tor zu.\"\n\nHm. Das schreibt jemand, der nicht gesehen werden will. Oder jemand, der weiss, dass ein Siebzehnjaehriger genau dann kommt, wenn man ihm sagt, er soll allein kommen.\n\nSie halten mich fuer diesen Jemand. Das ist Ihre Aufgabe, ich verstehe das."],
                    'effects' => ['stress' => 18, 'trust' => -6, 'flags' => ['doss_konfrontiert'], 'confronted' => true],
                ],
                'E22' => [
                    'reply' => ["(Sehr lange Pause. Dann, ruhig:)\n\nSie haben den Ordner geoeffnet.\n\nDann wissen Sie jetzt, dass ich ordentlich arbeite. Ich fuehre Buch, ueber alles. Das haben Sie und ich gemeinsam, Agent.\n\nZwei Striche. Sie sollten sich beeilen. Der dritte Strich ist immer der letzte."],
                    'effects' => ['stress' => 22, 'trust' => -10, 'flags' => ['doss_konfrontiert', 'doss_gestellt'], 'confronted' => true, 'leave' => 240],
                ],
                'E10' => [
                    'reply' => ["Toene auf einer Datei. Rueckwaerts. (Pause.) Sie sind gruendlich.\n\nDer Junge war auch gruendlich. Das ist selten in diesem Alter."],
                    'effects' => ['stress' => 20, 'trust' => -6, 'flags' => ['doss_konfrontiert'], 'confronted' => true],
                ],
                'E17' => [
                    'reply' => ['Ein Junge schreibt seiner Mutter, sie soll aufhoeren zu suchen. Das ist traurig, aber es kommt vor. Warum zeigen Sie das mir?'],
                    'effects' => ['stress' => 10, 'flags' => ['doss_konfrontiert'], 'confronted' => true],
                ],
            ],
        ],
    ],

    /* =====================================================================
     |  "Lantern" - anonymer Kontakt (in Wahrheit Doss)
     ===================================================================== */
    [
        'id' => 'npc_lantern', 'name' => 'lantern (anonym)', 'age' => 0, 'role' => 'Anonymer Forenkontakt',
        'relationship' => 'Hat Toby wochenlang Informationen versprochen',
        'avatar' => 'assets/img/avatars/unknown.svg', 'phone' => 'forum.nachtlinie.blog',
        'status' => 'online', 'short' => 'Schreibt aus Tobys Forum zurueck. Antwortet nur nachts.',
        'requires_flags' => ['lantern_bekannt'], 'show_locked' => true,
        'locked_hint' => 'Der Kontakt ist nur ueber Tobys Forenkonto erreichbar. Zuerst muessen Sie erfahren, wer "Lantern" ist.',
        'personality' => 'Geduldig, hoeflich, unangenehm nah. Antwortet in kurzen Zeilen. Gibt niemals zu, wer er ist, spielt aber mit dem Wissen, dass der Agent es vermutet. Verweist auf Dinge, die er nicht wissen kann.',
        'style' => 'Kleinschreibung, sehr kurze Zeilen, keine Emojis, gelegentlich ein einzelnes Wort als Satz. Korrekte Rechtschreibung.',
        'background' => 'Ein anonymes Forenkonto, angelegt 2019. Aktiv nur nachts. Hat Toby Betriebsbuecher versprochen und ihn an das Nordtor gelockt. Das Konto war zuletzt am 12.10.2024 um 03:16 Uhr aktiv - zwei Minuten nach der Nachricht "Hoer auf, mich zu suchen." Es gehoert Walter Doss.',
        'emotional_state' => 'Ruhig. Amuesiert.',
        'alibi_claimed' => 'ich arbeite dort. mehr sage ich nicht.',
        'alibi_actual' => 'Das Konto gehoert Walter Doss. Die letzte Aktivitaet war um 03:16 Uhr aus der Funkzelle Reservoir.',
        'initial_trust' => 30, 'initial_stress' => 0, 'leave_seconds' => 300,
        'can_unlock_evidence' => [],
        'can_set_flags' => ['lantern_kontakt_aktiv', 'ort_pumpstation_bekannt'],
        'secrets' => [
            'Er ist Walter Doss.',
            'Er hat Toby an das Nordtor gelockt.',
            'Er weiss, dass Toby noch lebt.',
        ],
        'unknown_topics' => [],
        'knowledge' => [
            ['topic' => 'Treffen', 'content' => 'Das Treffen war fuer Freitag 23 Uhr am Nordtor verabredet.'],
            ['topic' => 'Betriebsbuecher', 'content' => 'Er behauptet, 22 Jahre Betriebsbuecher zu haben.'],
        ],
        'lies' => [
            [
                'id' => 'lie_lantern_1',
                'claim' => 'ich bin nur jemand, der dort arbeitet.',
                'truth' => 'Das Konto gehoert dem Betriebsleiter Walter Doss. Letzte Aktivitaet 12.10., 03:16 Uhr, Funkzelle Reservoir - zwei Minuten nach der Nachricht von Tobys Account.',
                'evidence' => ['E17', 'E19'],
                'evidence_hint' => ['die Aktivitaet des Kontos um 03:16 Uhr', 'das Zutrittsprotokoll'],
                'confession' => 'du bist naeher, als der junge war. das sage ich dir als kompliment.',
                'solved_flag' => 'lantern_entlarvt',
            ],
        ],
        'suggested_questions' => [
            ['label' => 'Wer bist du?', 'text' => 'Wer bist du?'],
            ['label' => 'Treffen', 'text' => 'Was ist am Freitag am Nordtor passiert?'],
            ['label' => 'Toby', 'text' => 'Lebt Toby?'],
            ['label' => 'Beweis', 'text' => 'Beweise mir, dass du die Betriebsbuecher hast.'],
        ],
        'proactive' => [
            [
                'id' => 'pa_lantern_1', 'requires_flags' => ['lantern_bekannt'],
                'text' => "du benutzt seinen account.\n\ndas ist nicht hoeflich, agent.",
            ],
            [
                'id' => 'pa_lantern_2', 'requires_flags' => ['doss_konfrontiert'], 'min_messages' => 2,
                'text' => "du hast heute mit einem betriebsleiter telefoniert.\n\nund jetzt schreibst du mir.\n\nwas glaubst du, wie klein diese stadt ist.",
            ],
        ],
        'offline' => [
            'greeting' => [
                "du bist nicht toby.\n\nsein rechtschreibfehler fehlt.",
                "spaet, agent.\n\nsag was du wissen willst.",
            ],
            'fallbacks' => [
                'nein.',
                "das gehoert nicht hierher.\n\nfrag anders.",
                'ich antworte nur auf fragen, die eine antwort verdienen.',
            ],
            'break_off' => "genug fuer heute.\n\nschlaf du zuerst.",
            'confront_unknown' => 'das sagt mir nichts.',
            'intents' => [
                'greeting' => 'hallo, agent.',
                'farewell' => "geh nicht zu spaet nach hause.",
                'thanks' => 'gern.',
                'accuse' => "du wirfst mir etwas vor, was du nicht beweisen kannst.\n\ndas hat der junge auch gemacht.",
                'help' => "such nicht nach ihm.\n\nsuch nach dem ort, an dem niemand hinschaut, obwohl er jeden monat kontrolliert wird.",
                'toby' => "er war hoeflich.\n\ner hat gefragt, statt zu behaupten. das gefiel mir.",
                'identity' => "ich bin jemand, der nachts arbeitet.\n\nmehr brauchst du nicht.",
                'alibi' => 'ich muss dir kein alibi geben. ich bin kein name.',
            ],
            'rules' => [
                [
                    'id' => 'r_lantern_who', 'any' => ['wer bist du', 'name', 'echter name', 'doss', 'betriebsleiter'],
                    'priority' => 9,
                    'reply' => [
                        "namen sind das, was ihr braucht, um aufzuhoeren zu denken.\n\nich bin jemand, der dort arbeitet.",
                        "wenn ich \"doss\" schreibe, hast du einen namen und keinen beweis.\n\nwas soll dir das bringen.",
                    ],
                    'effects' => ['trust' => -2],
                ],
                [
                    'id' => 'r_lantern_meeting', 'any' => ['nordtor', 'treffen', 'freitag', 'was ist passiert', '23 uhr'],
                    'priority' => 9,
                    'reply' => [
                        "das tor war offen.\n\ner ist reingekommen. das war seine entscheidung.",
                        "ich habe ihm gesagt: komm allein.\n\nsiebzehnjaehrige kommen immer allein, wenn man das sagt.",
                    ],
                    'effects' => ['stress' => 2, 'trust' => -2, 'reveal' => ['k_lantern_meeting']],
                ],
                [
                    'id' => 'r_lantern_alive', 'any' => ['lebt', 'lebt er', 'ist er tot', 'wo ist er', 'wo ist toby'],
                    'priority' => 9,
                    'reply' => [
                        "er atmet.\n\nfuer heute reicht dir das.",
                        "du fragst falsch.\n\nfrag: wie lange noch.",
                    ],
                    'effects' => ['stress' => 4, 'flags' => ['lantern_kontakt_aktiv']],
                ],
                [
                    'id' => 'r_lantern_books', 'any' => ['betriebsbuch', 'buecher', 'protokolle', 'beweis', 'beweise mir'],
                    'priority' => 8,
                    'reply' => [
                        "22 jahre.\n\n2003 abschnitt 2. 2009 abschnitt 3. 2015 abschnitt 5. 2024 abschnitt 4.\n\ndas steht in keiner zeitung, agent.",
                    ],
                    'effects' => ['stress' => 6, 'trust' => 2, 'reveal' => ['k_lantern_books']],
                ],
                [
                    'id' => 'r_lantern_place', 'any' => ['pumpstation', 'schacht', 'stausee', 'wo suchen', 'ort'],
                    'priority' => 9,
                    'reply' => [
                        "ein ort, der seit 26 jahren nicht benutzt wird und jeden monat kontrolliert wird.\n\nueberleg, wie viele davon es in einer kleinstadt gibt.",
                    ],
                    'effects' => ['stress' => 6, 'flags' => ['ort_pumpstation_bekannt'], 'reveal' => ['k_lantern_place']],
                ],
                [
                    'id' => 'r_lantern_agent', 'any' => ['ich finde dich', 'wir kriegen dich', 'polizei', 'festnahme', 'verhaftet'],
                    'priority' => 8,
                    'reply' => [
                        "ihr habt neun jahre gebraucht, um die letzte akte zu schliessen.\n\nich habe zeit.",
                        "du sitzt in einem raum mit zwei bildschirmen.\n\nder rechte ist heller. stell ihn dunkler, dann siehst du besser.",
                    ],
                    'effects' => ['stress' => 4, 'trust' => -4],
                ],
            ],
            'confront' => [
                'E17' => [
                    'reply' => ["03:14 nachricht.\n\n03:16 mein konto online.\n\ndu hast recht: das ist ungeschickt.\n\nich bin muede geworden, agent. das passiert, wenn man 22 jahre allein arbeitet."],
                    'effects' => ['stress' => 10, 'trust' => -4, 'flags' => ['lantern_entlarvt'], 'confronted' => true],
                ],
                'E19' => [
                    'reply' => ["eine karte, eine tuer, eine uhrzeit.\n\nund jetzt frag dich, wer dir das protokoll gezeigt hat: niemand. du hast es selbst gefunden.\n\nder junge hat auch selbst gefunden. deshalb ist er unten."],
                    'effects' => ['stress' => 12, 'flags' => ['lantern_entlarvt', 'ort_pumpstation_bekannt'], 'confronted' => true],
                ],
                'E22' => [
                    'reply' => ["du hast die bilder gesehen.\n\ndann weisst du, dass ich niemanden anluege. auch dich nicht.\n\nzwei striche. beeil dich."],
                    'effects' => ['stress' => 16, 'flags' => ['lantern_entlarvt', 'ort_pumpstation_bekannt'], 'confronted' => true, 'leave' => 300],
                ],
                'E10' => [
                    'reply' => ["der anhang.\n\nhab ich nicht geprueft.\n\nclever, der junge. das gebe ich zu."],
                    'effects' => ['stress' => 14, 'flags' => ['ort_pumpstation_bekannt'], 'confronted' => true],
                ],
            ],
        ],
    ],
];
