<?php

/**
 * Die zwanzig Designstudien.
 *
 * Jede hat eine eigene Farbwelt, einen eigenen Aufbau und eine eigene
 * Schriftstimmung. Zwanzigmal dasselbe Gerüst in anderen Farben wäre
 * keine Vielfalt, sondern zwanzigmal dieselbe Seite.
 *
 * Felder:
 *   slug        Adresse und Ordnername
 *   name        wie die Firma heisst
 *   branche     steht als Überzeile auf der Studie
 *   stil        welcher Aufbau gerendert wird (siehe render-studie.php)
 *   bg/flaeche  Grund und Kartenfläche
 *   akzent      die Hauptfarbe – steht auch in der Referenzkarte
 *   zweit       eine zweite Farbe für Verläufe und Details
 *   text/leise  Schriftfarben
 *   schrift     'sans' | 'serif' | 'mono' | 'rund' | 'schmal'
 *   titel/lead  der Text im Aufmacher
 *   punkte      drei Stichworte weiter unten
 */

declare(strict_types=1);

function studien(): array
{
    return [

        // 1 ────────────────────────────────────────── Nordlicht Fotografie
        [
            'slug' => 'nordlicht-studio',
            'name' => 'Nordlicht Studio',
            'branche' => 'Fotografie',
            'stil' => 'aurora',
            'bg' => '#070b18', 'flaeche' => '#0d1428',
            'akzent' => '#5eead4', 'zweit' => '#818cf8',
            'text' => '#eef2ff', 'leise' => '#94a3b8',
            'schrift' => 'serif',
            'titel' => 'Licht, das bleibt',
            'lead' => 'Portrait- und Landschaftsfotografie aus dem Berner Oberland. '
                . 'Wir fotografieren dort, wo das Licht am ehrlichsten ist.',
            'punkte' => ['Portrait', 'Landschaft', 'Hochzeit'],
            'untertitel' => 'Fotostudio mit Bildstrecke über die volle Breite',
            'text_de' => "Ein Fotostudio lebt von seinen Bildern – also treten sie hier ganz nach "
                . "vorne. Der Aufmacher zeigt eine Aufnahme über die volle Breite, darunter läuft "
                . "die Bildstrecke in ruhigem Takt weiter.\n\n"
                . "Die dunkle Fläche ist kein Selbstzweck: Vor Schwarz wirken Farben kräftiger, "
                . "und genau darum geht es bei Fotografie.",
            'text_en' => "A photo studio lives by its images, so here they come first. The opening "
                . "shows one photograph across the full width, with the series continuing below at "
                . "a calm pace.\n\nThe dark surface is not decoration: colours read stronger "
                . "against black, and that is the whole point in photography.",
            'tags' => ['Designstudie', 'Fotografie', 'Galerie', 'Dunkel'],
        ],

        // 2 ────────────────────────────────────────────── Vertikal Klettern
        [
            'slug' => 'vertikal-kletterhalle',
            'name' => 'Vertikal',
            'branche' => 'Kletterhalle',
            'stil' => 'brutal',
            'bg' => '#e7e5e4', 'flaeche' => '#fafaf9',
            'akzent' => '#ea580c', 'zweit' => '#1c1917',
            'text' => '#1c1917', 'leise' => '#57534e',
            'schrift' => 'mono',
            'titel' => 'Sechzehn Meter senkrecht',
            'lead' => 'Boulderhalle und Seilkletterzone in Winterthur. '
                . 'Offen ab sieben, auch wenn draussen alles grau ist.',
            'punkte' => ['Bouldern', 'Kurse', 'Tageskarte'],
            'untertitel' => 'Kletterhalle mit Kursplan und Tarifen',
            'text_de' => "Harte Kanten, klare Blöcke, nichts Weiches: Das Aussehen kommt von der "
                . "Sache selbst. Wer klettern will, sucht Öffnungszeiten, Kurse und Preise – die "
                . "stehen deshalb gross und ohne Umweg da.\n\n"
                . "Der Kursplan lässt sich pflegen, ohne jemanden zu fragen.",
            'text_en' => "Hard edges, clear blocks, nothing soft: the look comes from the subject "
                . "itself. People who want to climb are looking for opening hours, courses and "
                . "prices, so those are large and immediate.\n\n"
                . "The course schedule can be maintained without asking anyone.",
            'tags' => ['Designstudie', 'Sport', 'Kursplan', 'Brutalist'],
        ],

        // 3 ──────────────────────────────────────────────── Marbach Weinbau
        [
            'slug' => 'marbach-weinbau',
            'name' => 'Marbach Weinbau',
            'branche' => 'Weingut',
            'stil' => 'editorial',
            'bg' => '#fdfbf7', 'flaeche' => '#ffffff',
            'akzent' => '#7f1d3f', 'zweit' => '#c2a878',
            'text' => '#2b1d1a', 'leise' => '#7c6a63',
            'schrift' => 'serif',
            'titel' => 'Vier Lagen, ein Hang',
            'lead' => 'Familienweingut am Bielersee. Seit 1908, in vierter Generation, '
                . 'mit dem Eigensinn, den ein steiler Hang verlangt.',
            'punkte' => ['Weine', 'Degustation', 'Hofladen'],
            'untertitel' => 'Weingut mit ruhigem Satzspiegel',
            'text_de' => "Wie eine gute Weinkarte: viel Weiss, wenig Lärm, jede Angabe dort, wo "
                . "man sie sucht. Die Schrift trägt den Auftritt, nicht der Effekt.\n\n"
                . "Jeder Wein hat seine eigene Seite mit Lage, Jahrgang und einem Satz darüber, "
                . "wozu er passt.",
            'text_en' => "Like a good wine list: plenty of white space, little noise, every detail "
                . "where you would look for it. The typography carries the site, not the effect.\n\n"
                . "Each wine has its own page with vineyard, vintage and one line on what it suits.",
            'tags' => ['Designstudie', 'Weingut', 'Editorial', 'Serifen'],
        ],

        // 4 ──────────────────────────────────────────────── Puls Physio
        [
            'slug' => 'puls-physiotherapie',
            'name' => 'Puls Physiotherapie',
            'branche' => 'Praxis',
            'stil' => 'sanft',
            'bg' => '#f4fbfa', 'flaeche' => '#ffffff',
            'akzent' => '#0e7490', 'zweit' => '#5eead4',
            'text' => '#0f2d32', 'leise' => '#5c7c80',
            'schrift' => 'rund',
            'titel' => 'Wieder in Bewegung',
            'lead' => 'Physiotherapie in Luzern. Termine innerhalb einer Woche, '
                . 'Abendstunden bis 20 Uhr.',
            'punkte' => ['Termine online', 'Alle Kassen', 'Hausbesuche'],
            'untertitel' => 'Praxis-Website mit Online-Terminen',
            'text_de' => "Wer Schmerzen hat, hat keine Geduld für Suchen. Der Knopf für einen "
                . "Termin steht deshalb im Aufmacher, nicht am Ende.\n\n"
                . "Weiche Formen und viel Luft nehmen der Seite das Klinische, ohne dass sie "
                . "unseriös wirkt – bei einer Praxis eine Gratwanderung.",
            'text_en' => "People in pain have no patience for hunting. The appointment button is "
                . "in the opening section, not at the bottom.\n\nSoft shapes and generous space "
                . "take the clinical edge off without making it look unserious — a fine line for "
                . "a medical practice.",
            'tags' => ['Designstudie', 'Gesundheit', 'Terminbuchung', 'Hell'],
        ],

        // 5 ───────────────────────────────────────────────────── Achtsam
        [
            'slug' => 'achtsam-kurse',
            'name' => 'Achtsam',
            'branche' => 'Meditationskurse',
            'stil' => 'organisch',
            'bg' => '#f7f5ef', 'flaeche' => '#fffdf8',
            'akzent' => '#6b7f5e', 'zweit' => '#d9c8a9',
            'text' => '#2f332a', 'leise' => '#77806d',
            'schrift' => 'serif',
            'titel' => 'Zehn Minuten genügen',
            'lead' => 'Kurse für Achtsamkeit in Zürich und online. '
                . 'Ohne Räucherstäbchen, ohne Versprechen, die niemand halten kann.',
            'punkte' => ['Einsteigerkurs', 'Online', 'Retreat'],
            'untertitel' => 'Kursangebot mit ruhigen Formen',
            'text_de' => "Alles rund, nichts eckig: Die Formen fliessen ineinander, die Farben "
                . "kommen aus Leinen und Salbei. Eine Seite, die nicht drängt.\n\n"
                . "Das Kursangebot ist trotzdem handfest – Termine, Preise, Ort, mit einem Klick "
                . "zur Anmeldung.",
            'text_en' => "Everything rounded, nothing sharp: the shapes flow into one another, the "
                . "colours come from linen and sage. A site that does not push.\n\nThe course "
                . "listing is concrete nonetheless — dates, prices, location, one click to sign up.",
            'tags' => ['Designstudie', 'Kurse', 'Organisch', 'Ruhig'],
        ],

        // 6 ────────────────────────────────────────────── Kernbau Architektur
        [
            'slug' => 'kernbau-architektur',
            'name' => 'Kernbau',
            'branche' => 'Architektur',
            'stil' => 'iso',
            'bg' => '#18181b', 'flaeche' => '#232327',
            'akzent' => '#facc15', 'zweit' => '#a1a1aa',
            'text' => '#fafafa', 'leise' => '#a1a1aa',
            'schrift' => 'schmal',
            'titel' => 'Bauen, was bleibt',
            'lead' => 'Architekturbüro in Basel. Wohnbau, Umbau, Verdichtung – '
                . 'und die Überzeugung, dass ein Haus länger hält als eine Mode.',
            'punkte' => ['Wohnbau', 'Umbau', 'Wettbewerb'],
            'untertitel' => 'Architekturbüro mit isometrischen Ansichten',
            'text_de' => "Ein Architekturbüro zeigt Räume, also zeigt die Seite Räume: Die "
                . "Projektbilder stehen als gekippte Ebenen im Raum, so wie eine Axonometrie auf "
                . "dem Plan.\n\nGelb auf Anthrazit – die Farben, die auf jeder Baustelle hängen.",
            'text_en' => "An architecture practice shows space, so the site shows space: project "
                . "images sit as tilted planes, the way an axonometric drawing sits on a plan.\n\n"
                . "Yellow on charcoal — the colours you find on every building site.",
            'tags' => ['Designstudie', 'Architektur', 'Isometrie', 'Dunkel'],
        ],

        // 7 ──────────────────────────────────────────────── Fuchsbau Kaffee
        [
            'slug' => 'fuchsbau-kaffee',
            'name' => 'Fuchsbau Rösterei',
            'branche' => 'Kaffeerösterei',
            'stil' => 'clay',
            'bg' => '#fdf6ee', 'flaeche' => '#ffffff',
            'akzent' => '#b45309', 'zweit' => '#f59e0b',
            'text' => '#3b2412', 'leise' => '#8a6b53',
            'schrift' => 'rund',
            'titel' => 'Dienstags wird geröstet',
            'lead' => 'Kleine Rösterei in St. Gallen. Drei Sorten, jede Woche frisch, '
                . 'und ein Abo, das man jederzeit pausieren kann.',
            'punkte' => ['Abo', 'Rösterei', 'Café'],
            'untertitel' => 'Rösterei mit Bestellabo',
            'text_de' => "Weiche, plastische Formen mit warmem Licht – die Packungen wirken, als "
                . "läge man sie gleich in die Hand.\n\nDas Abo ist der Kern: Menge, Takt, "
                . "Mahlgrad, fertig. Pausieren geht mit einem Klick, was mehr Leute abschliessen "
                . "lässt als jeder Rabatt.",
            'text_en' => "Soft, moulded shapes in warm light — the packs look as if you could pick "
                . "them up.\n\nThe subscription is the core: amount, rhythm, grind, done. Pausing "
                . "takes one click, which sells more subscriptions than any discount.",
            'tags' => ['Designstudie', 'Rösterei', 'Abo', 'Warm'],
        ],

        // 8 ──────────────────────────────────────────────────── Neonwerk
        [
            'slug' => 'neonwerk-club',
            'name' => 'Neonwerk',
            'branche' => 'Konzertclub',
            'stil' => 'neon',
            'bg' => '#05010d', 'flaeche' => '#0f0620',
            'akzent' => '#f0abfc', 'zweit' => '#22d3ee',
            'text' => '#f5f3ff', 'leise' => '#a5b4fc',
            'schrift' => 'schmal',
            'titel' => 'Freitag ab elf',
            'lead' => 'Konzerte und Clubnächte in einer alten Werkhalle in Zürich West. '
                . 'Programm bis Ende Saison, Tickets direkt hier.',
            'punkte' => ['Programm', 'Tickets', 'Halle mieten'],
            'untertitel' => 'Clubprogramm mit Ticketverkauf',
            'text_de' => "Leuchtschrift auf Schwarz, so wie die Halle nachts aussieht. Das "
                . "Programm ist das Wichtigste und steht deshalb sofort da – Datum, Band, "
                . "Tickets, sonst nichts.\n\n"
                . "Vergangene Abende rutschen von selbst nach unten.",
            'text_en' => "Neon on black, the way the venue looks at night. The programme matters "
                . "most and comes first — date, band, tickets, nothing else.\n\nPast nights move "
                . "down on their own.",
            'tags' => ['Designstudie', 'Kultur', 'Tickets', 'Neon'],
        ],

        // 9 ──────────────────────────────────────────────── Silbereis Schmuck
        [
            'slug' => 'silbereis-schmuck',
            'name' => 'Silbereis',
            'branche' => 'Goldschmiede',
            'stil' => 'luxe',
            'bg' => '#0c0a09', 'flaeche' => '#161311',
            'akzent' => '#d4b483', 'zweit' => '#78716c',
            'text' => '#f5f5f4', 'leise' => '#a8a29e',
            'schrift' => 'serif',
            'titel' => 'Einzelstücke, keine Serie',
            'lead' => 'Goldschmiede in Genf. Trauringe und Umarbeitungen, '
                . 'von der ersten Skizze bis zur Politur in derselben Werkstatt.',
            'punkte' => ['Trauringe', 'Umarbeitung', 'Beratung'],
            'untertitel' => 'Goldschmiede mit Terminanfrage',
            'text_de' => "Viel Schwarz, wenig Gold, sehr feine Linien: Der Schmuck soll das "
                . "Auffälligste auf der Seite sein, nicht die Seite selbst.\n\n"
                . "Statt eines Warenkorbs gibt es die Terminanfrage – wer einen Ring "
                . "umarbeiten lässt, will erst reden.",
            'text_en' => "Mostly black, a little gold, very fine rules: the jewellery should be "
                . "the most striking thing on the page, not the page itself.\n\nInstead of a "
                . "shopping cart there is an appointment request — people reworking a ring want "
                . "to talk first.",
            'tags' => ['Designstudie', 'Handwerk', 'Edel', 'Dunkel'],
        ],

        // 10 ──────────────────────────────────────────────── Terra Gartenbau
        [
            'slug' => 'terra-gartenbau',
            'name' => 'Terra Gartenbau',
            'branche' => 'Gartenbau',
            'stil' => 'schichten',
            'bg' => '#f2f7ef', 'flaeche' => '#ffffff',
            'akzent' => '#3f6212', 'zweit' => '#c2410c',
            'text' => '#1f2915', 'leise' => '#5f7050',
            'schrift' => 'sans',
            'titel' => 'Gärten, die mitwachsen',
            'lead' => 'Gartenbau im Aargau. Planung, Bau und Pflege – '
                . 'vom Sitzplatz bis zur Hecke, die in zehn Jahren noch stimmt.',
            'punkte' => ['Planung', 'Bau', 'Unterhalt'],
            'untertitel' => 'Gartenbau mit Vorher-Nachher-Strecke',
            'text_de' => "Ebenen, die sich überlagern wie Pflanzschichten: vorne der Sitzplatz, "
                . "dahinter die Stauden, ganz hinten die Hecke. Die Tiefe ist nicht Effekt, "
                . "sondern das Thema.\n\n"
                . "Vorher und Nachher stehen direkt nebeneinander – das überzeugt schneller als "
                . "jede Beschreibung.",
            'text_en' => "Layers that overlap like planting layers: the terrace in front, "
                . "perennials behind, the hedge at the back. The depth is not an effect, it is "
                . "the subject.\n\nBefore and after sit side by side — more convincing than any "
                . "description.",
            'tags' => ['Designstudie', 'Garten', 'Vorher-Nachher', 'Grün'],
        ],

        // 11 ──────────────────────────────────────────────── Codewerk
        [
            'slug' => 'codewerk-software',
            'name' => 'Codewerk',
            'branche' => 'Softwareentwicklung',
            'stil' => 'terminal',
            'bg' => '#0a0f0d', 'flaeche' => '#0f1714',
            'akzent' => '#4ade80', 'zweit' => '#34d399',
            'text' => '#e7f6ef', 'leise' => '#7f9f92',
            'schrift' => 'mono',
            'titel' => 'Software, die niemand erklären muss',
            'lead' => 'Kleine Entwicklungsfirma in Bern. Wir bauen interne Werkzeuge für '
                . 'Betriebe, die aus Excel herausgewachsen sind.',
            'punkte' => ['Werkzeuge', 'Schnittstellen', 'Wartung'],
            'untertitel' => 'Entwicklerfirma im Terminal-Stil',
            'text_de' => "Feste Schriftbreite, ein Raster, das man sieht, und Grün auf Schwarz: "
                . "Die Kundschaft erkennt die Sprache sofort und weiss, mit wem sie es zu tun hat.\n\n"
                . "Kein Fachjargon in den Texten – die Leute, die hier anfragen, sind Betriebe, "
                . "keine Entwickler.",
            'text_en' => "Fixed-width type, a visible grid and green on black: the audience "
                . "recognises the language immediately and knows who they are dealing with.\n\n"
                . "No jargon in the copy — the people enquiring here run businesses, they are not "
                . "developers.",
            'tags' => ['Designstudie', 'Software', 'Mono', 'Raster'],
        ],

        // 12 ──────────────────────────────────────────────── Alpenluft
        [
            'slug' => 'alpenluft-bergbahn',
            'name' => 'Alpenluft',
            'branche' => 'Bergbahn',
            'stil' => 'weite',
            'bg' => '#eaf4fb', 'flaeche' => '#ffffff',
            'akzent' => '#0369a1', 'zweit' => '#7dd3fc',
            'text' => '#0c2635', 'leise' => '#4a6b7c',
            'schrift' => 'sans',
            'titel' => 'In neun Minuten oben',
            'lead' => 'Luftseilbahn ins Wandergebiet. Fahrplan, Wetter und Tickets '
                . 'auf einen Blick, auch mit kaltem Finger auf dem Handy.',
            'punkte' => ['Fahrplan', 'Bergwetter', 'Tickets'],
            'untertitel' => 'Bergbahn mit Fahrplan und Wetter',
            'text_de' => "Der Aufmacher zeigt, worum es geht: Weite. Bergketten schichten sich in "
                . "die Tiefe, der Himmel wechselt mit der Tageszeit.\n\n"
                . "Darüber liegt das Nützliche: Wann fährt die Bahn, wie ist das Wetter oben, was "
                . "kostet es. Am Berg zählt das mehr als jedes schöne Bild.",
            'text_en' => "The opening shows the point: distance. Mountain ranges layer into the "
                . "depth, the sky shifts with the time of day.\n\nOn top of it sits the useful "
                . "part: when it runs, the weather up there, what it costs. On a mountain that "
                . "matters more than any pretty picture.",
            'tags' => ['Designstudie', 'Tourismus', 'Fahrplan', 'Weite'],
        ],

        // 13 ──────────────────────────────────────────────── Rosé Bakery
        [
            'slug' => 'rose-baeckerei',
            'name' => 'Rosé Bäckerei',
            'branche' => 'Bäckerei',
            'stil' => 'verspielt',
            'bg' => '#fff5f7', 'flaeche' => '#ffffff',
            'akzent' => '#be185d', 'zweit' => '#fbbf24',
            'text' => '#4a1d2e', 'leise' => '#8f6272',
            'schrift' => 'rund',
            'titel' => 'Ab fünf Uhr riecht es gut',
            'lead' => 'Handwerksbäckerei in Zug. Sauerteig, Zopf am Samstag '
                . 'und Torten, die man drei Tage vorher bestellt.',
            'punkte' => ['Brot', 'Torten', 'Vorbestellung'],
            'untertitel' => 'Bäckerei mit Tortenbestellung',
            'text_de' => "Runde Formen, warme Farben, schwebende Elemente: Die Seite darf Freude "
                . "machen, das passt zum Sortiment.\n\n"
                . "Die Tortenbestellung ist ein kurzes Formular statt eines Telefonats – das "
                . "spart der Backstube die halbe Woche.",
            'text_en' => "Round shapes, warm colours, floating elements: the site is allowed to be "
                . "cheerful, which suits the goods.\n\nOrdering a cake is a short form rather than "
                . "a phone call — which saves the bakery half its week.",
            'tags' => ['Designstudie', 'Bäckerei', 'Bestellung', 'Verspielt'],
        ],

        // 14 ──────────────────────────────────────────────── Stahlwerk
        [
            'slug' => 'stahlwerk-metallbau',
            'name' => 'Stahlwerk Rüegg',
            'branche' => 'Metallbau',
            'stil' => 'industrie',
            'bg' => '#1c1917', 'flaeche' => '#292524',
            'akzent' => '#dc2626', 'zweit' => '#a8a29e',
            'text' => '#fafaf9', 'leise' => '#a8a29e',
            'schrift' => 'schmal',
            'titel' => 'Geländer, Tore, Tragwerk',
            'lead' => 'Metallbau seit 1962 in Olten. Von der Wendeltreppe bis zur '
                . 'Halle – gezeichnet, geschweisst und montiert im eigenen Haus.',
            'punkte' => ['Geländer', 'Tore', 'Konstruktion'],
            'untertitel' => 'Metallbau mit Projektübersicht',
            'text_de' => "Schwer, dunkel, mit einer Diagonale, die durch die Seite schneidet – "
                . "wie ein Träger durch eine Halle. Der Auftritt darf hier ruhig hart sein.\n\n"
                . "Die Projekte stehen nach Art sortiert, weil genau danach gesucht wird.",
            'text_en' => "Heavy, dark, with a diagonal cutting through the page — like a girder "
                . "through a hall. This one is allowed to be hard.\n\nProjects are sorted by "
                . "type, because that is exactly how people search.",
            'tags' => ['Designstudie', 'Metallbau', 'Industriell', 'Dunkel'],
        ],

        // 15 ──────────────────────────────────────────────── Lumen
        [
            'slug' => 'lumen-lichtplanung',
            'name' => 'Lumen',
            'branche' => 'Lichtplanung',
            'stil' => 'licht',
            'bg' => '#fafafa', 'flaeche' => '#ffffff',
            'akzent' => '#171717', 'zweit' => '#fbbf24',
            'text' => '#171717', 'leise' => '#737373',
            'schrift' => 'schmal',
            'titel' => 'Licht ist ein Baustoff',
            'lead' => 'Lichtplanung für Läden, Museen und Wohnbauten. '
                . 'Wir rechnen, bevor jemand eine Lampe kauft.',
            'punkte' => ['Planung', 'Messung', 'Umbau'],
            'untertitel' => 'Lichtplanung in Schwarzweiss',
            'text_de' => "Fast ohne Farbe, dafür mit Licht und Schatten: Ein einziger warmer "
                . "Lichtkegel bringt die Tiefe. Bei einem Lichtplaner ist das kein Effekt, "
                . "sondern eine Arbeitsprobe.\n\n"
                . "Der Rest der Seite bleibt bewusst zurückhaltend.",
            'text_en' => "Almost without colour, but with light and shadow: a single warm cone "
                . "creates the depth. For a lighting designer that is not an effect, it is a work "
                . "sample.\n\nThe rest of the page stays deliberately quiet.",
            'tags' => ['Designstudie', 'Planung', 'Minimal', 'Licht'],
        ],

        // 16 ──────────────────────────────────────────────── Seeblick Hotel
        [
            'slug' => 'seeblick-hotel',
            'name' => 'Hotel Seeblick',
            'branche' => 'Hotel',
            'stil' => 'glas',
            'bg' => '#0f2027', 'flaeche' => '#16323b',
            'akzent' => '#2dd4bf', 'zweit' => '#e2b877',
            'text' => '#f0fdfa', 'leise' => '#9ec5c0',
            'schrift' => 'serif',
            'titel' => 'Vierzehn Zimmer, ein See',
            'lead' => 'Kleines Hotel am Vierwaldstättersee. Direkt buchbar, '
                . 'ohne Portal und ohne Provision.',
            'punkte' => ['Zimmer', 'Restaurant', 'Direkt buchen'],
            'untertitel' => 'Hotel mit direkter Buchung',
            'text_de' => "Milchige Glasflächen über einer tiefen Wasserfarbe – die Stimmung des "
                . "Hauses, ohne dass ein einziges Foto sie erklären müsste.\n\n"
                . "Der Buchungsknopf ist der auffälligste Punkt der Seite. Jede Buchung, die "
                . "hier statt über ein Portal läuft, spart dem Haus die Provision.",
            'text_en' => "Frosted glass panels over a deep water colour — the mood of the house, "
                . "without a single photo having to explain it.\n\nThe booking button is the most "
                . "prominent element. Every booking made here instead of through a portal saves "
                . "the hotel its commission.",
            'tags' => ['Designstudie', 'Hotel', 'Buchung', 'Glas'],
        ],

        // 17 ──────────────────────────────────────────────── Volt Mobilität
        [
            'slug' => 'volt-mobilitaet',
            'name' => 'Volt Mobilität',
            'branche' => 'E-Bikes',
            'stil' => 'speed',
            'bg' => '#060a1a', 'flaeche' => '#0d142e',
            'akzent' => '#a3e635', 'zweit' => '#38bdf8',
            'text' => '#f0f4ff', 'leise' => '#8fa3c8',
            'schrift' => 'schmal',
            'titel' => 'Achtzig Kilometer, eine Ladung',
            'lead' => 'E-Bikes aus Lausanne. Probefahrt am Samstag, '
                . 'Service im eigenen Haus, Ersatzteile auf Lager.',
            'punkte' => ['Modelle', 'Probefahrt', 'Service'],
            'untertitel' => 'E-Bike-Händler mit Probefahrt-Termin',
            'text_de' => "Schräge Linien und Bewegungsspuren geben der Seite Tempo, ohne dass "
                . "sich etwas bewegen müsste. Das passt zum Produkt und lädt schnell.\n\n"
                . "Die Probefahrt ist der eigentliche Verkauf – der Knopf dafür steht überall.",
            'text_en' => "Diagonal lines and motion trails give the page speed without anything "
                . "having to move. It suits the product and loads fast.\n\nThe test ride is the "
                . "actual sale — that button is everywhere.",
            'tags' => ['Designstudie', 'Handel', 'Termin', 'Dynamisch'],
        ],

        // 18 ──────────────────────────────────────────────── Papier & Co
        [
            'slug' => 'papier-und-co',
            'name' => 'Papier & Co',
            'branche' => 'Buchbinderei',
            'stil' => 'papier',
            'bg' => '#f6f2e9', 'flaeche' => '#fffdf6',
            'akzent' => '#1e3a5f', 'zweit' => '#b08968',
            'text' => '#26303b', 'leise' => '#6f7b86',
            'schrift' => 'serif',
            'titel' => 'Von Hand gebunden',
            'lead' => 'Buchbinderei in Chur. Restaurierung, Einzelanfertigung '
                . 'und Kurse für alle, die es selbst versuchen wollen.',
            'punkte' => ['Restaurierung', 'Anfertigung', 'Kurse'],
            'untertitel' => 'Buchbinderei mit Kursangebot',
            'text_de' => "Papiernuancen, feine Linien, ein Hauch Prägung: Der Auftritt fühlt sich "
                . "an wie das Material, mit dem hier gearbeitet wird.\n\n"
                . "Die Kurse sind der schnellste Weg zu neuen Kunden – sie stehen deshalb "
                . "gleichrangig neben der Restaurierung.",
            'text_en' => "Paper tones, fine rules, a hint of embossing: the site feels like the "
                . "material worked with here.\n\nThe courses are the fastest route to new "
                . "customers, so they sit level with the restoration work.",
            'tags' => ['Designstudie', 'Handwerk', 'Kurse', 'Papier'],
        ],

        // 19 ──────────────────────────────────────────────── Orbit Labs
        [
            'slug' => 'orbit-labs',
            'name' => 'Orbit Labs',
            'branche' => 'Biotechnologie',
            'stil' => 'partikel',
            'bg' => '#0b0a1f', 'flaeche' => '#141233',
            'akzent' => '#a78bfa', 'zweit' => '#22d3ee',
            'text' => '#eef0ff', 'leise' => '#9aa0d0',
            'schrift' => 'sans',
            'titel' => 'Diagnostik in vier Stunden',
            'lead' => 'Labor in Basel. Wir entwickeln Schnelltests für Kliniken – '
                . 'und veröffentlichen, was wir messen.',
            'punkte' => ['Forschung', 'Publikationen', 'Karriere'],
            'untertitel' => 'Forschungslabor mit Publikationsliste',
            'text_de' => "Ein Feld aus Punkten, die sich zu Strukturen ordnen: Es sieht aus wie "
                . "das, woran hier gearbeitet wird, ohne ein Bild aus dem Mikroskop zu brauchen.\n\n"
                . "Publikationen und offene Stellen stehen weit oben – danach suchen die Leute, "
                . "auf die es ankommt.",
            'text_en' => "A field of points arranging into structures: it looks like the work done "
                . "here without needing a microscope image.\n\nPublications and open positions sit "
                . "high up — that is what the people who matter are looking for.",
            'tags' => ['Designstudie', 'Forschung', 'Partikel', 'Dunkel'],
        ],

        // 20 ──────────────────────────────────────────────── Holzgeist
        [
            'slug' => 'holzgeist-schreinerei',
            'name' => 'Holzgeist',
            'branche' => 'Schreinerei',
            'stil' => 'maserung',
            'bg' => '#faf6f0', 'flaeche' => '#ffffff',
            'akzent' => '#78350f', 'zweit' => '#84a98c',
            'text' => '#2e2318', 'leise' => '#7d6a58',
            'schrift' => 'sans',
            'titel' => 'Möbel nach Mass',
            'lead' => 'Schreinerei im Emmental. Küchen, Einbauschränke und Tische – '
                . 'aus Holz, das keine zweihundert Kilometer gereist ist.',
            'punkte' => ['Küchen', 'Einbauten', 'Tische'],
            'untertitel' => 'Schreinerei mit Projektgalerie',
            'text_de' => "Warme Holztöne mit einem grünen Gegenpol, dazu eine Maserung, die durch "
                . "den Hintergrund läuft. Das Material bestimmt die Farbe, nicht umgekehrt.\n\n"
                . "Jedes Projekt hat wenige, dafür grosse Bilder – bei Möbeln zählt das Detail.",
            'text_en' => "Warm timber tones with a green counterpoint, and a grain running through "
                . "the background. The material sets the colour, not the other way round.\n\nEach "
                . "project has few but large images — with furniture the detail is what counts.",
            'tags' => ['Designstudie', 'Schreinerei', 'Galerie', 'Warm'],
        ],
    ];
}
