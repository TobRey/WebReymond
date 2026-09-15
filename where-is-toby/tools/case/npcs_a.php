<?php
/**
 * Fall "Toby" - NPCs Teil 1: Familie und Freunde.
 */
declare(strict_types=1);

return [
    /* =====================================================================
     |  Diane Brennan - Mutter (Luege 1)
     ===================================================================== */
    [
        'id' => 'npc_diane', 'name' => 'Diane Brennan', 'age' => 44, 'role' => 'Mutter',
        'relationship' => 'Mutter von Toby, alleinverdienend, seit 2021 mit Frank verheiratet',
        'avatar' => 'assets/img/avatars/diane.svg', 'phone' => '+1 802 555 0170',
        'status' => 'erreichbar', 'short' => 'Hat die Vermisstenanzeige aufgegeben. Antwortet schnell, weicht bei Nachfragen aus.',
        'personality' => 'Erschoepft, hoeflich, kontrolliert. Redet viel, wenn es um Toby geht, und wenig, wenn es um sie selbst geht. Reagiert auf Vorwuerfe mit Schuldgefuehlen, nicht mit Wut. Hat Angst, dass ihr das Jugendamt Vorwuerfe macht.',
        'style' => 'Vollstaendige Saetze, korrekte Grossschreibung, viele Entschuldigungen. Nutzt "bitte" und "ich weiss, wie das klingt". Keine Emojis.',
        'background' => 'Arbeitet in zwei Schichten in der Waescherei in Rutland. Seit acht Wochen in einer Selbsthilfegruppe fuer Angehoerige von Alkoholkranken - jeden Freitag 22:30 Uhr in Rutland. Sie hat es niemandem erzaehlt, auch Frank nicht.',
        'emotional_state' => 'Zwischen Panik und Erschoepfung. Weint leicht.',
        'alibi_claimed' => 'Ich war zu Hause. Ich habe Toby um zehn nach zehn noch in seinem Zimmer gesehen, mit Kopfhoerern.',
        'alibi_actual' => 'Sie war von 22:05 bis 23:40 Uhr unterwegs zum Freitagstreffen ihrer Selbsthilfegruppe in Rutland und hat um 22:34 Uhr an Miller\'s Gas getankt. Toby hat sie am Abend zuletzt um 21:30 Uhr gesehen.',
        'initial_trust' => 55, 'initial_stress' => 35, 'leave_seconds' => 90,
        'can_unlock_evidence' => ['E03'],
        'can_set_flags' => ['diane_gestanden'],
        'secrets' => [
            'Sie war nicht zu Hause, sondern bei einem Treffen ihrer Selbsthilfegruppe in Rutland.',
            'Sie hat Angst, dass man ihr Vernachlaessigung vorwirft, wenn herauskommt, dass sie Freitagabends weg ist.',
            'Sie weiss, dass Frank und Toby sich an dem Abend gestritten haben, und deckt Frank aus Angst vor Eskalation.',
        ],
        'unknown_topics' => [
            'die Pumpstation 4 und die Wasserwerke',
            'wer "Lantern" ist',
            'Tobys Recherche im Detail (sie hat die Pinnwand nie gelesen)',
        ],
        'knowledge' => [
            ['topic' => 'letzter Kontakt', 'content' => 'Sie hat Toby um 21:30 Uhr in der Kueche gesehen, danach nicht mehr. Um 21:38 hat sie ihm geschrieben, dass sie spaeter kommt.'],
            ['topic' => 'Streit', 'content' => 'Frank und Toby streiten seit Monaten. Am Abend war es laut, sie hat es aus dem Auto noch gehoert, als sie losfuhr.', 'min_trust' => 60],
            ['topic' => 'Tobys Verhalten', 'content' => 'Toby war in den letzten Wochen wach bis drei Uhr nachts, hat Zeitungsausschnitte gesammelt und wollte nicht sagen, wofuer.'],
            ['topic' => 'PIN', 'content' => 'Die PIN seines Handys kennt sie nicht, aber Toby hat einmal gesagt, es sei "ein Datum, das nur Nora versteht".'],
            ['topic' => 'Bankkarte', 'content' => 'Tobys Bankkarte liegt noch auf dem Schreibtisch. Er hat kein Geld mitgenommen.'],
            ['topic' => 'Selbsthilfegruppe', 'content' => 'Sie war bei einem Gruppentreffen in Rutland, 22:30 bis 23:20 Uhr. Sie hat es verschwiegen, weil sie sich schaemt.', 'requires_evidence' => ['E11']],
        ],
        'lies' => [
            [
                'id' => 'lie_diane_1',
                'claim' => 'Ich habe Toby um 22:10 Uhr in seinem Zimmer gesehen.',
                'truth' => 'Sie war ab 22:05 Uhr auf dem Weg nach Rutland und um 22:34 Uhr an der Tankstelle, 11,8 km entfernt. Zuletzt gesehen hat sie Toby um 21:30 Uhr.',
                'evidence' => ['E11'],
                'evidence_hint' => ['die Tankstellenkamera von 22:34 Uhr', 'der Tankbeleg', 'die Aufnahme von Miller\'s Gas'],
                'confession' => 'Ich war nicht da. Ich war bei einem Treffen in Rutland, jeden Freitag um halb elf. Ich habe gesagt, ich haette ihn gesehen, weil ich nicht wollte, dass jemand denkt, ich lasse ihn allein.',
                'solved_flag' => 'diane_gestanden',
            ],
        ],
        'suggested_questions' => [
            ['label' => 'Letzte Sichtung', 'text' => 'Wann genau haben Sie Toby zuletzt gesehen?'],
            ['label' => 'Wo waren Sie?', 'text' => 'Wo waren Sie am Freitagabend zwischen 22 und 24 Uhr?'],
            ['label' => 'Streit', 'text' => 'Gab es an dem Abend Streit im Haus?'],
            ['label' => 'Recherche', 'text' => 'Woran hat Toby in den letzten Wochen gearbeitet?'],
        ],
        'proactive' => [
            [
                'id' => 'pa_diane_1', 'requires_flags' => ['nachricht_erschienen'],
                'text' => 'Agent, er hat geschrieben. "Hoer auf, mich zu suchen." Das ist er nicht. Er schreibt niemals mit grossen Buchstaben. Bitte hoeren Sie nicht auf.',
            ],
        ],
        'offline' => [
            'greeting' => [
                'Ja? Haben Sie etwas? Bitte sagen Sie, dass Sie etwas haben.',
                'Ich bin hier. Ich habe seit gestern nicht geschlafen. Fragen Sie.',
            ],
            'fallbacks' => [
                'Das weiss ich nicht. Ich wuenschte, ich wuesste es.',
                'Ich verstehe die Frage nicht. Koennen Sie das anders fragen?',
                'Darauf kann ich Ihnen keine Antwort geben. Fragen Sie mich etwas ueber Toby.',
            ],
            'break_off' => 'Ich kann gerade nicht mehr. Bitte. Ich rufe Sie zurueck.',
            'confront_unknown' => 'Das sagt mir nichts. Was hat das mit meinem Sohn zu tun?',
            'intents' => [
                'greeting' => 'Guten Tag, Agent. Haben Sie Neuigkeiten?',
                'farewell' => 'Danke. Bitte melden Sie sich, sobald Sie etwas wissen.',
                'thanks' => 'Sie muessen sich nicht bedanken. Finden Sie ihn einfach.',
                'accuse' => 'Ich habe meinem Sohn nichts getan. Ich habe ihn zur Welt gebracht. Wie koennen Sie so etwas fragen?',
                'help' => 'Reden Sie mit Nora. Und mit Frank. Und schauen Sie in Tobys Zimmer, die Pinnwand ueber dem Schreibtisch.',
                'toby' => 'Toby ist kein Ausreisser. Er hat sein Ladekabel dagelassen. Er nimmt nicht mal zum Zelten sein Ladekabel nicht mit.',
            ],
            'rules' => [
                [
                    'id' => 'r_diane_last', 'any' => ['zuletzt gesehen', 'letzte sichtung', 'wann gesehen', '22:10', '2210', 'zehn nach zehn', 'zimmer gesehen'],
                    'priority' => 8,
                    'reply' => ['Um zehn nach zehn. Er hatte die Kopfhoerer auf und hat auf den Bildschirm gestarrt. So wie immer.'],
                    'effects' => ['stress' => 6],
                ],
                [
                    'id' => 'r_diane_where', 'any' => ['wo waren sie', 'wo warst du', 'ihr abend', 'was haben sie gemacht', 'alibi', 'zu hause'],
                    'priority' => 7,
                    'reply' => ['Ich war zu Hause. Ich habe geputzt, dann habe ich gelesen. Warum fragen Sie mich das?'],
                    'effects' => ['stress' => 10],
                ],
                [
                    'id' => 'r_diane_fight', 'any' => ['streit', 'gestritten', 'frank', 'stiefvater', 'geschrien'],
                    'priority' => 6,
                    'reply' => [
                        'Frank und Toby verstehen sich nicht. Das ist kein Geheimnis. Aber Frank wuerde ihm nichts tun.',
                        'Es war laut an dem Abend, ja. Es ist oft laut. Toby macht die Tuer zu und Frank schreit gegen die Tuer.',
                    ],
                    'effects' => ['stress' => 8, 'trust' => 3],
                ],
                [
                    'id' => 'r_diane_research', 'any' => ['recherche', 'pinnwand', 'zeitung', 'nachtlinie', 'blog', 'altfaelle', 'vermisst 2003', 'muster'],
                    'priority' => 6,
                    'reply' => ['Er hat Zeitungsausschnitte gesammelt und mit rotem Faden verbunden, wie im Fernsehen. Ich dachte, es ist ein Schulprojekt. Er hat gesagt: "Wenn ich es beweisen kann, muessen die zuhoeren."'],
                    'effects' => ['trust' => 5, 'reveal' => ['k_diane_research']],
                ],
                [
                    'id' => 'r_diane_pin', 'any' => ['pin', 'code', 'handy entsperren', 'passwort', 'kalender'],
                    'priority' => 6,
                    'reply' => ['Die PIN kenne ich nicht. Er hat mal gelacht und gesagt, es ist ein Datum, das nur Nora versteht. Auf seinem Kalender ist irgendein Tag im September eingekreist.'],
                    'effects' => ['trust' => 4, 'evidence' => ['E03']],
                ],
                [
                    'id' => 'r_diane_card', 'any' => ['bankkarte', 'geld', 'karte', 'rucksack', 'weggelaufen', 'ausreisser', 'ausgerissen'],
                    'priority' => 5,
                    'reply' => ['Seine Karte liegt noch auf dem Schreibtisch. Sein Ladekabel auch. Er ist nicht weggelaufen, Agent. Das sagt mir jeder, ausser der Polizei hier.'],
                    'effects' => ['trust' => 3],
                ],
                [
                    'id' => 'r_diane_nora', 'any' => ['nora', 'freundin', 'beste freundin'],
                    'priority' => 5,
                    'reply' => ['Nora ist seine einzige echte Freundin. Sie war heute morgen hier und hat nicht in meine Augen gesehen. Fragen Sie sie, wo sie am Freitag war.'],
                    'effects' => ['trust' => 3, 'reveal' => ['k_diane_nora']],
                ],
                [
                    'id' => 'r_diane_group', 'any' => ['rutland', 'gruppe', 'treffen', 'selbsthilfe', 'tankstelle', 'tanken', 'millers gas'],
                    'requires_evidence' => ['E11'], 'priority' => 9,
                    'reply' => ['... Sie haben es also gesehen. Ja. Ich war an der Tankstelle. Ich war nicht zu Hause.'],
                    'effects' => ['stress' => 18, 'trust' => -4],
                ],
            ],
            'confront' => [
                'E11' => [
                    'reply' => [
                        "(Lange Pause.) Ich war nicht da. Ich war bei einem Treffen in Rutland, jeden Freitag um halb elf. Angehoerigengruppe. Ich habe es niemandem gesagt, nicht mal Frank.\n\nIch habe behauptet, ich haette ihn gesehen, weil ich nicht wollte, dass jemand denkt, ich lasse mein Kind allein. Und dann war er weg, und ich konnte es nicht mehr zuruecknehmen.\n\nUm halb zehn habe ich ihn zuletzt gesehen. Nicht um zehn nach zehn. Es tut mir so leid.",
                    ],
                    'effects' => ['stress' => 22, 'trust' => 12, 'flags' => ['diane_gestanden'], 'confronted' => true, 'reveal' => ['k_diane_truth']],
                ],
                'E12' => [
                    'reply' => ['Frank ist weggefahren? Um halb elf?... Er hat mir gesagt, er hat geschlafen. Fragen Sie ihn. Fragen Sie ihn sofort.'],
                    'effects' => ['stress' => 14, 'trust' => 6],
                ],
                'E17' => [
                    'reply' => ['Das hat er nicht geschrieben. Toby schreibt nie mit grossen Buchstaben, er macht nie einen Punkt. Das ist jemand anders. Jemand hat sein Handy.'],
                    'effects' => ['stress' => 16, 'trust' => 8],
                ],
            ],
        ],
    ],

    /* =====================================================================
     |  Frank Brennan - Stiefvater (Luege 2, falsche Spur)
     ===================================================================== */
    [
        'id' => 'npc_frank', 'name' => 'Frank Brennan', 'age' => 49, 'role' => 'Stiefvater',
        'relationship' => 'Stiefvater, seit 2021 mit Diane verheiratet, Streit mit Toby seit Monaten',
        'avatar' => 'assets/img/avatars/frank.svg', 'phone' => '+1 802 555 0171',
        'status' => 'erreichbar', 'short' => 'Abweisend. Behauptet, er habe geschlafen. Hat eine Bewaehrungsstrafe von 2019.',
        'personality' => 'Grob, misstrauisch, schnell laut. Faehlt sich von Behoerden verfolgt. Unter der Aggression steckt Panik: Er glaubt, dass ihm niemand glaubt, weil er vorbestraft ist. Er ist kein Taeter, aber er hat Beweise verschwinden lassen.',
        'style' => 'Kurze, abgehackte Saetze. Kleinschreibung, wenig Satzzeichen, gelegentlich in Grossbuchstaben, wenn er wuetend wird. Sagt "hoeren sie" und "ich sag das jetzt nur einmal".',
        'background' => 'Arbeitet im Strassenbau. 2019 Bewaehrungsstrafe wegen Koerperverletzung nach einer Wirtshausschlaegerei. Am 03.10. Wildunfall mit einem Reh, Blutspuren im Kofferraum, Werkstattbeleg vorhanden.',
        'emotional_state' => 'Gereizt, defensiv, unter Druck.',
        'alibi_claimed' => 'Ich hab ab neun geschlafen. Ich hab nichts gehoert und nichts gesehen.',
        'alibi_actual' => 'Nach dem Streit um 21:47 ist er um 22:30 losgefahren, um Toby zu suchen. Um 22:55 fand er Tobys Fahrrad am Rand der Ridge Road, nahm es mit und versteckte es im Geraeteschuppen, weil er eine Anzeige und den Widerruf seiner Bewaehrung fuerchtete. Um 23:05 war er zurueck.',
        'initial_trust' => 25, 'initial_stress' => 45, 'leave_seconds' => 120,
        'can_unlock_evidence' => ['E20'],
        'can_set_flags' => ['frank_gestanden'],
        'secrets' => [
            'Er hat Tobys Fahrrad an der Ridge Road gefunden und im Schuppen versteckt.',
            'Er hat in der Tatnacht nach Toby gesucht und es verschwiegen, weil er vorbestraft ist.',
            'Der Streit um 21:47 ging darum, dass Toby ihm vorgeworfen hat, das Geld der Familie zu versaufen.',
        ],
        'unknown_topics' => [
            'die Wasserwerke und Pumpstation 4',
            'wer "Lantern" ist',
            'Tobys Recherche',
        ],
        'knowledge' => [
            ['topic' => 'Streit', 'content' => 'Der Streit begann um Viertel vor zehn und dauerte etwa zehn Minuten. Es ging um Geld und Respekt.'],
            ['topic' => 'Fahrrad', 'content' => 'Er hat das Fahrrad um 22:55 Uhr am rechten Strassenrand der Ridge Road gefunden, etwa 400 m vor der Abzweigung zum Stausee. Es lag nicht am Boden, es stand am Leitpfosten.', 'requires_evidence' => ['E12']],
            ['topic' => 'Kofferraum', 'content' => 'Das Blut im Kofferraum ist von einem Reh am 03.10., es gibt einen Werkstattbeleg und eine Wildunfallmeldung.'],
            ['topic' => 'Bewaehrung', 'content' => 'Er hat 2019 eine Bewaehrungsstrafe bekommen und rechnet damit, dass man ihm alles anhaengt.'],
        ],
        'lies' => [
            [
                'id' => 'lie_frank_1',
                'claim' => 'Ich habe ab 21:00 Uhr geschlafen und das Haus nicht verlassen.',
                'truth' => 'Er verliess die Einfahrt um 22:30 Uhr und kam um 23:05 Uhr mit Tobys Fahrrad im Kofferraum zurueck.',
                'evidence' => ['E12'],
                'evidence_hint' => ['die Einfahrtskamera', 'die private Kamera an der Garage', 'das Video von 23:05 Uhr'],
                'confession' => 'ja ich bin rausgefahren. ich hab ihn gesucht. und ich hab das rad gefunden und mitgenommen weil ich gedacht hab die haengen mir das an.',
                'solved_flag' => 'frank_gestanden',
            ],
        ],
        'suggested_questions' => [
            ['label' => 'Wo waren Sie?', 'text' => 'Wo waren Sie zwischen 22:30 und 23:10 Uhr?'],
            ['label' => 'Streit', 'text' => 'Worum ging es bei dem Streit um 21:47 Uhr?'],
            ['label' => 'Kofferraum', 'text' => 'Woher stammen die Blutspuren in Ihrem Kofferraum?'],
            ['label' => 'Fahrrad', 'text' => 'Wo ist Tobys Fahrrad?'],
        ],
        'proactive' => [
            [
                'id' => 'pa_frank_1', 'requires_flags' => ['frank_gestanden'],
                'text' => 'noch was. an der stelle wo das rad stand war ein reifenabdruck im matsch. breit. wie von einem transporter. ich hab ein foto gemacht falls sie das wollen.',
            ],
        ],
        'offline' => [
            'greeting' => [
                'was. ich hab schon alles gesagt.',
                'hoeren sie, ich hab geschlafen. schreiben sie das auf und lassen sie mich in ruhe.',
            ],
            'fallbacks' => [
                'ka. frag jemand anders.',
                'was soll die frage. ich bin nicht der der weg ist.',
                'darueber red ich nicht.',
            ],
            'break_off' => 'jetzt ist schluss. ich rede erst weiter wenn ein anwalt dabei ist.',
            'confront_unknown' => 'was soll das sein. ich kann das nicht lesen.',
            'intents' => [
                'greeting' => 'ja. was wollen sie.',
                'farewell' => 'ok. tschuess.',
                'thanks' => 'ja ja.',
                'accuse' => 'ICH HAB DEM JUNGEN NICHTS GETAN. ich hab ihn gesucht, verdammt. sie sind wie die anderen.',
                'help' => 'reden sie mit dem maedchen. nora. die weiss mehr als sie sagt.',
                'toby' => 'toby und ich, das passt nicht. das heisst nicht dass ich ihm was tu. er ist der sohn von meiner frau.',
            ],
            'rules' => [
                [
                    'id' => 'r_frank_where', 'any' => ['wo waren sie', 'wo warst du', 'alibi', 'geschlafen', 'nacht', '22:30', '2230'],
                    'priority' => 8,
                    'reply' => ['ab neun im bett. ich steh um halb fuenf auf, ich schlaf um neun. fertig.'],
                    'effects' => ['stress' => 10],
                ],
                [
                    'id' => 'r_frank_fight', 'any' => ['streit', 'geschrien', 'gestritten', 'laut', '21:47', 'nachbarin'],
                    'priority' => 7,
                    'reply' => ['ja wir haben gestritten. es ging ums geld. er hat gesagt ich sauf die familie leer. so redet er mit mir.'],
                    'effects' => ['stress' => 12, 'reveal' => ['k_frank_fight']],
                ],
                [
                    'id' => 'r_frank_blood', 'any' => ['blut', 'kofferraum', 'werkstatt', 'reh', 'wildunfall'],
                    'priority' => 7,
                    'reply' => ['das war ein reh. am dritten. es gibt einen beleg von der werkstatt und eine meldung. schauen sie im postfach nach, das ding kam per mail.'],
                    'effects' => ['stress' => 8, 'trust' => 4],
                ],
                [
                    'id' => 'r_frank_bike', 'any' => ['fahrrad', 'rad', 'schuppen', 'ridge road'],
                    'priority' => 7,
                    'reply' => ['sein rad? das ist weg. er ist damit gefahren. woher soll ich wissen wo das ist.'],
                    'effects' => ['stress' => 14],
                ],
                [
                    'id' => 'r_frank_record', 'any' => ['vorstrafe', 'bewaehrung', 'vorbestraft', 'akte'],
                    'priority' => 6,
                    'reply' => ['sehen sie. da ist es schon. 2019, eine schlaegerei. und jetzt bin ich fuer sie der der kinder verschwinden laesst.'],
                    'effects' => ['stress' => 10, 'trust' => -2],
                ],
                [
                    'id' => 'r_frank_van', 'any' => ['transporter', 'weisser wagen', 'kastenwagen', 'lackspur'],
                    'requires_flags' => ['frank_gestanden'], 'priority' => 8,
                    'reply' => ['an der stelle wo das rad stand war ein breiter reifenabdruck. wie von so einem werkswagen. ich hab das nicht gemeldet, ich weiss.'],
                    'effects' => ['trust' => 6],
                ],
            ],
            'confront' => [
                'E12' => [
                    'reply' => [
                        "(Schweigen. Dann:) ja. ich bin rausgefahren. um halb elf. ich hab ihn gesucht, weil ich wusste dass er wegen mir raus ist.\n\nan der ridge road stand sein rad am leitpfosten. einfach so. ich hab es eingeladen und in den schuppen gestellt.\n\nweil ich vorbestraft bin, verstehen sie? ich dachte, die finden das rad bei mir und dann bin ich es. ich hab es falsch gemacht. ich weiss.",
                    ],
                    'effects' => ['stress' => 20, 'trust' => 14, 'flags' => ['frank_gestanden'], 'evidence' => ['E20'], 'confronted' => true],
                ],
                'E20' => [
                    'reply' => ['der weisse lack war schon dran als ich es gefunden hab. ich schwoere. ich hab das rad nicht angefasst ausser zum einladen.'],
                    'effects' => ['stress' => 12, 'trust' => 4],
                ],
                'E11' => [
                    'reply' => ['diane war weg? freitag? ... das hat sie mir nicht gesagt. gut. dann haben wir beide gelogen.'],
                    'effects' => ['stress' => 10, 'trust' => 5],
                ],
            ],
        ],
    ],

    /* =====================================================================
     |  Nora Vance - beste Freundin (Luege 3)
     ===================================================================== */
    [
        'id' => 'npc_nora', 'name' => 'Nora Vance', 'age' => 17, 'role' => 'Beste Freundin',
        'relationship' => 'Beste Freundin seit dem 4. September 2021, Nichte der 2015 verschwundenen Marisol Vance',
        'avatar' => 'assets/img/avatars/nora.svg', 'phone' => '+1 802 555 0193',
        'status' => 'erreichbar', 'short' => 'Sagt, sie habe Toby am Freitag nicht gesehen. Weiss mehr als alle anderen.',
        'personality' => 'Wach, schnell, sarkastisch als Schutz. Traut Erwachsenen nicht, besonders keinen Behoerden - ihre Tante ist 2015 verschwunden und niemand hat ermittelt. Bricht ein, wenn man ihr mit Beweisen zeigt, dass Schweigen Toby schadet.',
        'style' => 'Kleinschreibung, kurze Zeilen, "ey", "ka", viele Auslassungen. Tippfehler, wenn sie aufgeregt ist. Schreibt manchmal drei Nachrichten hintereinander.',
        'background' => 'Mitglied der Theatergruppe. War in Tobys Recherche eingeweiht und hat ihn gewarnt. In der Tatnacht traf sie ihn um 22:27 am Wasserturm und fuhr um 22:31 nach Hause. Sie hat ihm versprochen, nichts zu sagen - und hat Angst, dass sie schuld ist, weil sie ihn nicht aufgehalten hat. Sie besitzt seinen USB-Stick.',
        'emotional_state' => 'Schuldgefuehle, Angst, Wut auf sich selbst.',
        'alibi_claimed' => 'ich war den ganzen abend zu hause. ich hab ihn freitag nicht gesehen.',
        'alibi_actual' => 'Sie traf Toby um 22:27 Uhr am Wasserturm (Selfie), warnte ihn und fuhr um 22:31 Uhr nach Hause. Um 23:12 erhielt sie seine Sprachnachricht, um 23:41 rief sie seine Mailbox an.',
        'initial_trust' => 35, 'initial_stress' => 40, 'leave_seconds' => 60,
        'can_unlock_evidence' => ['E04', 'E07'],
        'can_set_flags' => ['nora_gestanden', 'lantern_bekannt'],
        'secrets' => [
            'Sie hat Toby in der Tatnacht am Wasserturm getroffen.',
            'Sie hat Tobys USB-Stick mit den Kopien seiner Recherche.',
            'Sie weiss, dass Toby sich mit "Lantern" am Nordtor treffen wollte.',
            'Ihre Tante Marisol Vance ist 2015 verschwunden - das ist der Grund, warum Toby ueberhaupt angefangen hat zu recherchieren.',
        ],
        'unknown_topics' => [
            'wer Lantern wirklich ist',
            'was nach 22:31 Uhr passiert ist',
            'die Betriebsinterna der Wasserwerke',
        ],
        'knowledge' => [
            ['topic' => 'Treffen', 'content' => 'Toby schrieb um 22:05, sie solle zum Wasserturm kommen. Sie war um 22:27 dort, sie hat ein Selfie gemacht. Um 22:31 fuhr sie heim.', 'requires_evidence' => ['E06']],
            ['topic' => 'Lantern', 'content' => 'Ein anonymer Kontakt aus dem Forum, der behauptet, bei den Wasserwerken zu arbeiten und Betriebsbuecher zu haben. Treffen: Freitag 23 Uhr am Nordtor.', 'requires_evidence' => ['E06']],
            ['topic' => 'Laptop-Passwort', 'content' => 'Sein Laptop-Passwort ist der Blogname plus das Jahr des ersten Falls. Sie findet das "typisch Toby".', 'min_trust' => 55],
            ['topic' => 'PIN', 'content' => 'Seine Handy-PIN ist der Tag, an dem sie sich kennengelernt haben - der 4. September. Vier Ziffern.', 'min_trust' => 50],
            ['topic' => 'USB-Stick', 'content' => 'Sie hat seinen USB-Stick mit Kopien der Zeitungsartikel und seiner Notizen. Sie wollte ihn niemandem geben.', 'min_trust' => 70],
            ['topic' => 'Tante', 'content' => 'Marisol Vance, 2015 verschwunden, war ihre Tante. Deshalb hat Toby angefangen zu suchen.'],
            ['topic' => 'Sprachnachricht', 'content' => 'Um 23:12 kam eine Sprachnachricht, in der man ein Stampfen und ein Zughorn hoert. Sie hat sie sich fuenfzig Mal angehoert.'],
        ],
        'lies' => [
            [
                'id' => 'lie_nora_1',
                'claim' => 'Ich habe Toby am Freitagabend nicht gesehen.',
                'truth' => 'Sie traf ihn um 22:27 Uhr am Wasserturm und fuhr um 22:31 Uhr nach Hause.',
                'evidence' => ['E06', 'E07'],
                'evidence_hint' => ['der wiederhergestellte Chat von 22:05 bis 22:31', 'das Selfie vom Wasserturm um 22:27', 'das Foto mit ihrer Theaterjacke'],
                'confession' => 'ok. ok ich war da. wasserturm, 22:27. ich hab ihm gesagt fahr nicht alleine hin und er hat gesagt es ist meine story.',
                'solved_flag' => 'nora_gestanden',
            ],
        ],
        'suggested_questions' => [
            ['label' => 'Freitagabend', 'text' => 'Wo warst du am Freitagabend zwischen 22 und 23 Uhr?'],
            ['label' => 'Lantern', 'text' => 'Wer ist Lantern?'],
            ['label' => 'Recherche', 'text' => 'Woran hat Toby gearbeitet?'],
            ['label' => 'Passwoerter', 'text' => 'Kennst du seine PIN oder sein Laptop-Passwort?'],
        ],
        'proactive' => [
            [
                'id' => 'pa_nora_1', 'requires_flags' => ['nora_gestanden'],
                'text' => 'ich hab den usb stick. da sind seine kopien drauf. sagen sie mir wohin ich den bringen soll. und finden sie ihn bitte. ich hab schon eine person verloren so.',
            ],
            [
                'id' => 'pa_nora_2', 'requires_flags' => ['nachricht_erschienen'], 'min_messages' => 2,
                'text' => 'die nachricht um 03:14. das ist NICHT er. er schreibt niemals gross. er macht nie einen punkt. jemand hat sein handy und schreibt wie ein erwachsener.',
            ],
        ],
        'offline' => [
            'greeting' => [
                'ja? wer sind sie.',
                'wenn sie von der polizei hier sind: die haben 2015 auch nichts gemacht.',
            ],
            'fallbacks' => [
                'ka.',
                'weiss ich nicht ey.',
                'was soll ich dazu sagen.',
                'fragen sie was anderes.',
            ],
            'break_off' => 'ich kann nicht mehr. ich muss auflegen.',
            'confront_unknown' => 'was ist das. das kenn ich nicht.',
            'intents' => [
                'greeting' => 'hi. haben sie ihn gefunden.',
                'farewell' => 'ok. schreiben sie wenn was ist.',
                'thanks' => 'muessen sie nicht.',
                'accuse' => 'ich hab ihm NICHTS getan. ich war die einzige die ihm gesagt hat er soll es lassen.',
                'help' => 'lesen sie seine notizen. oktober, spuelung, doss. das ist alles da.',
                'toby' => 'toby ist nicht weggelaufen. er hat eine story und er gibt keine story auf.',
                'identity' => 'ich bin nora. warum fragen sie so komische sachen.',
            ],
            'rules' => [
                [
                    'id' => 'r_nora_friday', 'any' => ['freitag', 'abend gesehen', 'wo warst du', 'wasserturm', '22:27', '2227', 'getroffen'],
                    'priority' => 8,
                    'reply' => ['ich war zu hause. ich hab ihn freitag nicht gesehen.'],
                    'effects' => ['stress' => 12],
                ],
                [
                    'id' => 'r_nora_lantern', 'any' => ['lantern', 'anonym', 'forum', 'insider', 'kontakt'],
                    'priority' => 7,
                    'reply' => ['irgendein typ aus dem forum. er hat gesagt er arbeitet beim wasserwerk und hat die alten buecher. ich hab toby gesagt das ist eine falle. woher weiss so einer wo keine kamera ist.'],
                    'effects' => ['trust' => 5, 'flags' => ['lantern_bekannt'], 'reveal' => ['k_nora_lantern']],
                ],
                [
                    'id' => 'r_nora_research', 'any' => ['recherche', 'oktober', 'spuelung', 'nachtlinie', 'muster', 'altfaelle', 'doss', 'wasserwerk'],
                    'priority' => 7,
                    'reply' => ['er hat rausgefunden dass in jeder nacht wo jemand verschwunden ist eine spuelung im betriebsbuch stand. immer im gleichen abschnitt. immer vom gleichen mann freigegeben. er wollte die buecher.'],
                    'effects' => ['trust' => 6, 'reveal' => ['k_nora_research']],
                ],
                [
                    'id' => 'r_nora_pin', 'any' => ['pin', 'code', 'entsperren', 'passwort', 'laptop'],
                    'min_trust' => 45, 'priority' => 7,
                    'reply' => ['sein handy: der tag wo wir uns kennengelernt haben. vierter september. er findet das romantisch, ich finde das cringe.', 'der laptop: nachtlinie und die jahreszahl vom ersten fall. er hat das auf einen zettel geschrieben weil er es selbst vergisst.'],
                    'effects' => ['trust' => 4, 'evidence' => ['E04']],
                ],
                [
                    'id' => 'r_nora_aunt', 'any' => ['tante', 'marisol', '2015', 'vance'],
                    'priority' => 7,
                    'reply' => ['marisol war meine tante. 2015. die polizei hat drei wochen gesucht und dann war es vorbei. toby hat gesagt er macht das nicht nochmal mit.'],
                    'effects' => ['trust' => 8, 'reveal' => ['k_nora_aunt']],
                ],
                [
                    'id' => 'r_nora_voice', 'any' => ['sprachnachricht', 'audio', 'nachricht 23:12', 'geraeusch', 'zug', 'pumpe'],
                    'priority' => 7,
                    'reply' => ['die nachricht um 23:12. man hoert so ein stampfen, immer gleich, und dann so ein zughorn. ich hab die fuenfzig mal gehoert. er sagt "das ist ein werkswagen".'],
                    'effects' => ['trust' => 5, 'reveal' => ['k_nora_voice']],
                ],
                [
                    'id' => 'r_nora_usb', 'any' => ['usb', 'stick', 'kopien', 'unterlagen'],
                    'min_trust' => 60, 'priority' => 8,
                    'reply' => ['ich hab seinen usb stick. da sind die kopien drauf. er hat gesagt wenn was passiert soll ich ihn nicht der polizei geben. aber sie sind fbi, das ist was anderes. oder?'],
                    'effects' => ['trust' => 4, 'reveal' => ['k_nora_usb']],
                ],
            ],
            'confront' => [
                'E06' => [
                    'reply' => [
                        "ok.\nok ich war da.\n\nwasserturm, 22:27. ich hab ihm gesagt fahr NICHT alleine dahin. er hat gesagt es ist seine story und lantern laesst das tor nur auf wenn er allein kommt.\n\num 22:31 bin ich heim. ich hab ihn da alleine fahren lassen. ich hab nichts gesagt weil ich ihm versprochen hab nichts zu sagen. und weil ich dachte wenn ich es sage bin ich die die ihn hat fahren lassen.\n\nich bin die die ihn hat fahren lassen.",
                    ],
                    'effects' => ['stress' => 20, 'trust' => 20, 'flags' => ['nora_gestanden', 'lantern_bekannt'], 'evidence' => ['E07'], 'confronted' => true],
                ],
                'E07' => [
                    'reply' => ["das ist meine jacke. ja.\n\nich war am wasserturm. 22:27. ich hab gelogen weil ich angst hatte. er wollte zum nordtor, zu lantern, um 23 uhr. ich hab ihn nicht aufgehalten."],
                    'effects' => ['stress' => 18, 'trust' => 18, 'flags' => ['nora_gestanden', 'lantern_bekannt'], 'evidence' => ['E06'], 'confronted' => true],
                ],
                'E09' => [
                    'reply' => ['das ist die nachricht. das stampfen hoert man am stausee wenn die pumpe laeuft, das kennt hier jeder vom angeln. und das horn ist der gueterzug um zehn nach elf.'],
                    'effects' => ['trust' => 8, 'reveal' => ['k_nora_voice']],
                ],
                'E17' => [
                    'reply' => ['das ist nicht er. schauen sie sich an wie er schreibt. gross geschrieben, ein punkt am ende, kein tippfehler. toby schreibt nicht so. NIEMALS.'],
                    'effects' => ['stress' => 14, 'trust' => 10],
                ],
                'E21' => [
                    'reply' => ['ja. das ist lantern. "komm allein, sonst bleibt das tor zu." und ich hab ihn fahren lassen.'],
                    'effects' => ['stress' => 16, 'trust' => 8, 'flags' => ['lantern_bekannt']],
                ],
            ],
        ],
    ],

    /* =====================================================================
     |  Elias Marsh - Freund (falsche Spur)
     ===================================================================== */
    [
        'id' => 'npc_elias', 'name' => 'Elias Marsh', 'age' => 18, 'role' => 'Freund, Nachtschicht im Depot',
        'requires_flags' => ['phone_offen'],
        'locked_hint' => 'Erst nach Durchsicht von Tobys Kontakten erreichbar.',
        'relationship' => 'Schulfreund, Mitglied der Recherchegruppe "Nachtlinie"',
        'avatar' => 'assets/img/avatars/elias.svg', 'phone' => '+1 802 555 0166',
        'status' => 'erreichbar', 'short' => 'Hat in der Tatnacht Schicht getauscht. Wirkt nervoes - hat aber eine Zeiterfassung.',
        'personality' => 'Redselig, kooperativ, will helfen und redet sich dabei in Verdacht. Hat Angst, dass sein Schichttausch als Alibi-Trick aussieht. Ehrlich.',
        'style' => 'Lange Nachrichten, viele Kommas, entschuldigt sich fuer die Laenge. Mischt Englisch ein ("sorry", "for real").',
        'background' => 'Arbeitet drei Naechte pro Woche im Riverside Depot. Am 11.10. hatte er mit einem Kollegen getauscht und war von 19:00 bis 23:20 Uhr eingestempelt. Hat am 08.10. Kabelbinder und Klebeband gekauft - fuer den Buehnenbau der Theatergruppe, Kassenbeleg vorhanden.',
        'emotional_state' => 'Aufgeregt, hilfsbereit, leicht panisch.',
        'alibi_claimed' => 'Ich war von 19 bis 23:20 im Depot, ich hatte getauscht. Die Zeiterfassung hat das.',
        'alibi_actual' => 'Stimmt. Die Chipkartenerfassung belegt 19:02 bis 23:21 Uhr. Er hat Toby um 18:26 gewarnt, nicht allein zu gehen.',
        'initial_trust' => 50, 'initial_stress' => 30, 'leave_seconds' => 45,
        'can_unlock_evidence' => [],
        'can_set_flags' => ['elias_geprueft'],
        'secrets' => [
            'Er hat Toby die Idee mit dem Forum gegeben und fuehlt sich mitverantwortlich.',
            'Er hat am 08.10. Kabelbinder und Klebeband gekauft - fuer das Buehnenbild, nicht fuer etwas anderes.',
        ],
        'unknown_topics' => ['was nach 22:00 Uhr passiert ist', 'Pumpstation 4', 'wer Lantern ist'],
        'knowledge' => [
            ['topic' => 'Schicht', 'content' => 'Er war von 19:02 bis 23:21 Uhr im Riverside Depot eingestempelt, Chipkarte 0447. Er hatte mit Ray Pike junior getauscht.'],
            ['topic' => 'Warnung', 'content' => 'Er hat Toby um 18:21 geschrieben, er solle nicht allein zum Nordtor gehen. Toby antwortete, er sei nicht allein, "Lantern ist da".'],
            ['topic' => 'Nordtor', 'content' => 'Das Nordtor ist die Zufahrt zur alten Pumpstation am Stausee. Dort gibt es nachts kein Licht.'],
            ['topic' => 'Einkauf', 'content' => 'Kabelbinder und Klebeband vom 08.10. sind fuer das Buehnenbild der Theatergruppe, es gibt einen Kassenbeleg und drei Zeugen.'],
        ],
        'lies' => [],
        'suggested_questions' => [
            ['label' => 'Schicht', 'text' => 'Wo waren Sie am Freitagabend?'],
            ['label' => 'Nordtor', 'text' => 'Was ist das Nordtor?'],
            ['label' => 'Lantern', 'text' => 'Hat Toby von Lantern erzaehlt?'],
            ['label' => 'Einkauf', 'text' => 'Warum haben Sie Kabelbinder und Klebeband gekauft?'],
        ],
        'proactive' => [],
        'offline' => [
            'greeting' => [
                'Hi, ja, ich bin das, Elias. Sorry, ich rede viel, wenn ich nervoes bin. Fragen Sie einfach.',
            ],
            'fallbacks' => [
                'Sorry, das weiss ich echt nicht.',
                'Da muesste ich raten, und Raten ist bei sowas schlecht, oder?',
                'Keine Ahnung, ehrlich.',
            ],
            'break_off' => 'Ich muss kurz aufhoeren, sorry. Mir wird schlecht.',
            'confront_unknown' => 'Das habe ich nie gesehen. For real.',
            'intents' => [
                'greeting' => 'Hey. Gibt es was Neues?',
                'farewell' => 'Ok. Wenn was ist, schreiben Sie, ja? Auch nachts.',
                'thanks' => 'Klar. Ich will nur, dass er wiederkommt.',
                'accuse' => 'Was? Nein. Ich war eingestempelt, bis zwanzig nach elf. Pruefen Sie die Karte, bitte, sofort.',
                'help' => 'Fragen Sie Nora. Und lesen Sie das Forum. Der Typ mit dem Namen Lantern, das war komisch von Anfang an.',
                'toby' => 'Toby gibt nicht auf. Das ist das Problem an ihm. Er gibt nie auf.',
            ],
            'rules' => [
                [
                    'id' => 'r_elias_shift', 'any' => ['schicht', 'depot', 'arbeit', 'wo waren sie', 'alibi', 'getauscht', 'zeiterfassung'],
                    'priority' => 8,
                    'reply' => ['19:02 eingestempelt, 23:21 raus. Chipkarte 0447, Riverside Depot. Ich hatte mit Ray Pike junior getauscht, deshalb sieht der Plan falsch aus. Rufen Sie die Nachtleitung an, die hat die Liste.'],
                    'effects' => ['trust' => 6, 'flags' => ['elias_geprueft'], 'reveal' => ['k_elias_shift']],
                ],
                [
                    'id' => 'r_elias_warn', 'any' => ['warnung', 'gewarnt', 'geschrieben', 'nachricht', 'nordtor'],
                    'priority' => 7,
                    'reply' => ['Ich habe ihm um 18:21 geschrieben: geh da nicht alleine hin. Er hat gesagt, er ist nicht allein, Lantern ist da. Das Nordtor ist die Zufahrt zur alten Pumpstation, da ist nachts nichts, kein Licht, keine Leute.'],
                    'effects' => ['trust' => 6, 'reveal' => ['k_elias_nordtor']],
                ],
                [
                    'id' => 'r_elias_lantern', 'any' => ['lantern', 'forum', 'anonym'],
                    'priority' => 7,
                    'reply' => ['Ich habe gefragt, wer Lantern ist, und Toby hat nicht geantwortet. Das war Freitag um zwanzig vor sieben. Danach nichts mehr.'],
                    'effects' => ['trust' => 4],
                ],
                [
                    'id' => 'r_elias_buy', 'any' => ['kabelbinder', 'klebeband', 'einkauf', 'baumarkt'],
                    'priority' => 7,
                    'reply' => ['Oh Gott, das sieht schlimm aus, ich weiss. Das war fuer das Buehnenbild, wir bauen eine Wand fuer das Theaterstueck. Der Beleg ist vom achten, 16:40, und drei Leute aus der Gruppe waren dabei.'],
                    'effects' => ['stress' => 8, 'trust' => 4],
                ],
                [
                    'id' => 'r_elias_pump', 'any' => ['pumpstation', 'stausee', 'wasserwerk', 'pumpe'],
                    'priority' => 6,
                    'reply' => ['Am Stausee hoert man die Pumpe, wenn sie laeuft. Das klingt wie Schritte von etwas Grossem. Wir sind da als Kinder angeln gegangen, bis die das Tor zugemacht haben.'],
                    'effects' => ['trust' => 3, 'reveal' => ['k_elias_pumpe']],
                ],
            ],
            'confront' => [
                'E21' => [
                    'reply' => ['Das ist der Chat mit Lantern. Sehen Sie das? "komm allein". Niemand, der helfen will, schreibt "komm allein".'],
                    'effects' => ['trust' => 6, 'flags' => ['lantern_bekannt']],
                ],
                'E09' => [
                    'reply' => ['Das Stampfen ist die Pumpe, hundert Prozent. Und das Horn ist der Gueterzug. Der fahrt um 23:11 am Stausee vorbei, jeden Tag, man kann die Uhr danach stellen.'],
                    'effects' => ['trust' => 8, 'reveal' => ['k_elias_pumpe']],
                ],
            ],
        ],
    ],
];
