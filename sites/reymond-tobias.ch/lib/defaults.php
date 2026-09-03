<?php
/**
 * Die Inhalte der Website.
 *
 * Hier steht der Grundtext jeder Stelle – fertig geschrieben, nicht als
 * Platzhalter. Was im Bearbeitungsbereich unter /admin geaendert wird, landet
 * in content/content.json und geht diesen Angaben vor. Loescht man dort ein
 * Feld wieder leer, erscheint automatisch wieder der Text von hier.
 *
 * Schluessel, die auf eine Liste zeigen (werdegang, projekte, kenntnisse, ...),
 * lassen sich im Bearbeitungsbereich um beliebig viele Eintraege erweitern.
 */

if (!defined('RT_STORE')) { require_once __DIR__ . '/store.php'; }

/** Feste Angaben, die in beiden Sprachen gleich sind. */
function rt_site()
{
    return array(
        'name'      => 'Reymond Tobias',
        'domain'    => 'reymond-tobias.ch',
        'url'       => 'https://reymond-tobias.ch',
        'email'     => 'tobias.reymond05@gmail.com',
        'linkedin'  => 'https://www.linkedin.com/in/tobias-reymond-714a9919b',
        'dj'        => 'https://dj-atze.com',
    );
}

/** Grundinhalt beider Sprachfassungen. */
function rt_defaults()
{
    return array(
        'de' => rt_defaults_de(),
        'en' => rt_defaults_en(),
        /* Bilder stehen in content/content.json, weil sie im Bearbeitungsbereich
           hochgeladen, ersetzt und entfernt werden. */
        'media' => array(),
    );
}

/* ==========================================================================
   Deutsch
   ========================================================================== */

function rt_defaults_de()
{
    return array(

        /* --- Kopf der Seite (Suchmaschinen und geteilte Verweise) -------- */
        'meta_titel' => "Reymond Tobias — Informatiker EFZ, Websites und Spiele",
        'meta_text'  => "Tobias Reymond, Informatiker EFZ aus Känerkinden im Baselland. Ich baue Websites und kleine Spiele — und lege als DJ auf. Projekte, Werdegang und Kontakt auf einer Seite.",

        /* --- Aufmacher --------------------------------------------------- */
        'hero_kicker' => "Informatiker EFZ · Applikationsentwicklung",
        'hero_text'   => "Ich bin Tobias. Ich programmiere Websites und kleine Spiele — und wenn der Rechner aus ist, stehe ich hinter dem DJ-Pult. Hier siehst du, woran ich arbeite, welchen Weg ich gegangen bin und wie du mich erreichst.",
        'hero_cta1_text' => "Schreib mir",
        'hero_cta1_url'  => "#kontakt",
        'hero_cta2_text' => "Projekte ansehen",
        'hero_cta2_url'  => "#projekte",
        'hero_fakten' => array(
            array('label' => "Beruf",     'wert' => "Informatiker EFZ, Applikationsentwicklung", 'link_url' => ""),
            array('label' => "Standort",  'wert' => "Känerkinden, Baselland", 'link_url' => ""),
            array('label' => "Erreichbar",'wert' => "tobias.reymond05@gmail.com", 'link_url' => "mailto:tobias.reymond05@gmail.com"),
        ),

        /* --- Über mich ---------------------------------------------------- */
        'ueber_eyebrow' => "Über mich",
        'ueber_titel'   => "Wer hier schreibt",
        'ueber_lede'    => "Informatiker EFZ mit Fachrichtung Applikationsentwicklung. Am liebsten baue ich Websites und kleine Spiele.",
        'ueber_text'    => "Ich heisse Tobias Reymond, bin in Känerkinden im Baselland zuhause und habe meine Ausbildung zum Informatiker EFZ in der Fachrichtung Applikationsentwicklung abgeschlossen. Zwei Jahre davon habe ich im Praktikum bei der business+design AG in Laupersdorf verbracht.\n\nAm liebsten programmiere ich Websites und kleine Spiele. Mich reizt der Moment, in dem etwas zum ersten Mal wirklich läuft: ein Menü, das sich richtig anfühlt, eine Spielfigur, die endlich sauber springt, eine Seite, die auf dem Handy so gut aussieht wie am grossen Bildschirm.\n\nDaneben gehört Musik zu mir. Ich lege als DJ auf, stelle meine Sets selbst zusammen und probiere gern Neues aus — beim Auflegen wie beim Programmieren.",
        'ueber_bild_alt' => "Tobias Reymond an seinem Arbeitsplatz vor dem Laptop",
        'ueber_fakten' => array(
            array('titel' => "Informatiker EFZ", 'text' => "Applikationsentwicklung, abgeschlossen"),
            array('titel' => "Känerkinden",      'text' => "Kanton Basel-Landschaft"),
            array('titel' => "Offen für Neues",  'text' => "Fragen, Projekte, Zusammenarbeit"),
        ),

        /* --- Was ich mache ------------------------------------------------ */
        'arbeit_eyebrow' => "Schwerpunkte",
        'arbeit_titel'   => "Was ich mache",
        'arbeit_lede'    => "Drei Felder, in denen ich zuhause bin — und die Werkzeuge, mit denen ich arbeite.",
        'schwerpunkte' => array(
            array(
                'titel' => "Websites bauen",
                'text'  => "Vom ersten Entwurf bis zur fertigen Seite: Aufbau, Gestaltung und Code von Hand. Mir ist wichtig, dass eine Seite schnell lädt, auf jedem Bildschirm funktioniert und sich später ohne Programmierkenntnisse pflegen lässt.",
                'link_text' => "Beispiele ansehen",
                'link_url'  => "#projekte",
            ),
            array(
                'titel' => "Applikationsentwicklung",
                'text'  => "Mein Ausbildungsschwerpunkt: aus einer Anforderung eine Anwendung machen. Daten sauber ablegen, Code schreiben, testen, verbessern — und so dokumentieren, dass auch jemand anderes damit weiterkommt.",
                'link_text' => "",
                'link_url'  => "",
            ),
            array(
                'titel' => "Spiele programmieren",
                'text'  => "In der Freizeit baue ich kleine Spiele. Ich fange mit einer Mechanik an, die Spass macht, und baue den Rest darum herum: Steuerung, Levels, Ton — und viele Anläufe, bis sich alles richtig anfühlt.",
                'link_text' => "",
                'link_url'  => "",
            ),
        ),
        'kenntnisse_titel' => "Womit ich arbeite",
        'kenntnisse' => array(
            array(
                'titel' => "Programmieren",
                'text'  => "HTML, CSS, JavaScript, PHP und SQL — dazu alles, was zu einer Website gehört: Aufbau, Gestaltung, Formulare und Datenbank.",
                'link_text' => "", 'link_url' => "",
            ),
            array(
                'titel' => "Werkzeuge",
                'text'  => "Git und GitHub, Visual Studio Code, Arbeiten mit Tickets im Team. Ich teste lieber einmal mehr, bevor etwas online geht.",
                'link_text' => "", 'link_url' => "",
            ),
            array(
                'titel' => "Sprachen",
                'text'  => "Deutsch als Muttersprache, Englisch in Wort und Schrift, Französisch mit Grundkenntnissen.",
                'link_text' => "", 'link_url' => "",
            ),
            array(
                'titel' => "Persönlich",
                'text'  => "Offen, kommunikativ und teamorientiert. Ausbildung und Militärdienst haben meine Flexibilität, Belastbarkeit und Zuverlässigkeit geprägt.",
                'link_text' => "", 'link_url' => "",
            ),
        ),

        /* --- Projekte ------------------------------------------------------ */
        'projekte_eyebrow' => "Projekte",
        'projekte_titel'   => "Woran ich arbeite",
        'projekte_lede'    => "Ein paar Sachen, die ich gebaut habe oder gerade baue. Es kommen laufend neue dazu.",
        'projekte' => array(
            array(
                'meta'  => "Website",
                'titel' => "reymond-tobias.ch",
                'text'  => "Diese Seite. Von Hand gebaut, ohne Baukasten und ohne fremde Bibliotheken — dazu ein eigener Bereich, in dem ich Texte, Bilder und Verweise selbst ändere, ohne eine Zeile Code anzufassen.",
                'link_text' => "Du bist gerade hier",
                'link_url'  => "#top",
            ),
            array(
                'meta'  => "Musik",
                'titel' => "dj-atze.com",
                'text'  => "Meine Seite als DJ: alles rund ums Auflegen, mit eigenem Gesicht und getrennt von hier.",
                'link_text' => "dj-atze.com öffnen",
                'link_url'  => "https://dj-atze.com",
            ),
            array(
                'meta'  => "Spiele",
                'titel' => "Kleine Spiele aus der Freizeit",
                'text'  => "Prototypen und kleine Spiele, an denen ich neben der Arbeit sitze: zuerst die Mechanik, dann Steuerung und Levels. Was spielbar wird, verlinke ich hier.",
                'link_text' => "",
                'link_url'  => "",
            ),
        ),

        /* --- Werdegang ------------------------------------------------------ */
        'werdegang_eyebrow' => "Werdegang",
        'werdegang_titel'   => "Stationen",
        'werdegang_lede'    => "Scroll weiter — die Stationen ziehen mit.",
        'werdegang' => array(
            array(
                'zeit'  => "2025 – 2026",
                'titel' => "Militärdienst",
                'ort'   => "KDO Wpl Frauenfeld",
                'text'  => "Rekrut, Soldat und Wachtmeister. Dazu Module und Zertifikate in Kommunikation, Information und Selbstmanagement auf Stufe Gruppe und Team.",
                'link_text' => "", 'link_url' => "",
            ),
            array(
                'zeit'  => "2020 – 2025",
                'titel' => "Informatiker EFZ, Applikationsentwicklung",
                'ort'   => "Informatikschule Olten GmbH · Praktikum bei der business+design AG, Laupersdorf",
                'text'  => "Grundbildung mit Schwerpunkt Software-Entwicklung, Webtechnologien und IT-Projektarbeit. Zwei der fünf Jahre habe ich im Praktikum bei der business+design AG gearbeitet.",
                'link_text' => "", 'link_url' => "",
            ),
            array(
                'zeit'  => "2017 – 2020",
                'titel' => "Sekundarschule",
                'ort'   => "Sissach, Niveau E",
                'text'  => "Abschluss der Sekundarstufe I. In dieser Zeit habe ich angefangen, selbst zu programmieren.",
                'link_text' => "", 'link_url' => "",
            ),
            array(
                'zeit'  => "2011 – 2017",
                'titel' => "Primarschule",
                'ort'   => "Itingen",
                'text'  => "Sechs Jahre Primarschule im Baselbiet.",
                'link_text' => "", 'link_url' => "",
            ),
        ),
        'werdegang_ende_meta'      => "Mehr Details",
        'werdegang_ende_titel'     => "Auf LinkedIn",
        'werdegang_ende_text'      => "Der vollständige Lebenslauf mit allen Stationen liegt in meinem Profil.",
        'werdegang_ende_link_text' => "Profil öffnen",
        'werdegang_ende_link_url'  => "https://www.linkedin.com/in/tobias-reymond-714a9919b",

        /* --- Neben der Arbeit ------------------------------------------------ */
        'neben_eyebrow' => "Neben der Arbeit",
        'neben_titel'   => "Was sonst noch dazugehört",
        'neben_lede'    => "Weil ein Lebenslauf nur die halbe Geschichte erzählt.",
        'neben' => array(
            array(
                'meta'  => "Musik",
                'titel' => "Ich lege auf",
                'text'  => "Hinter dem Pult an privaten Feiern und Anlässen. Ich stelle meine Sets selbst zusammen und mag es, wenn ein Abend einen Bogen hat statt nur eine Playlist.",
                'link_text' => "dj-atze.com",
                'link_url'  => "https://dj-atze.com",
            ),
            array(
                'meta'  => "Gaming",
                'titel' => "Spielen und nachbauen",
                'text'  => "Ich spiele gern — und schaue dabei fast immer, wie ein Spiel gemacht ist. Oft endet so ein Abend damit, dass ich eine Idee daraus selbst nachbaue.",
                'link_text' => "", 'link_url' => "",
            ),
            array(
                'meta'  => "Technik",
                'titel' => "Ausprobieren statt nachlesen",
                'text'  => "Neue Sprache, neues Werkzeug, neues Gerät: Ich lerne am schnellsten, wenn ich etwas damit baue, das ich danach wirklich benutze.",
                'link_text' => "", 'link_url' => "",
            ),
        ),

        /* --- Kontakt ---------------------------------------------------------- */
        'kontakt_eyebrow' => "Kontakt",
        'kontakt_titel'   => "Schreib mir",
        'kontakt_lede'    => "Ob berufliche Anfrage, Projektidee oder einfach eine Frage — erzähl kurz, worum es geht.",
        'kontakt_email'   => "tobias.reymond05@gmail.com",
        'kontakt_telefon' => "+41 79 355 72 42",
        'kontakt_adresse' => "Reymond Tobias\nIm Hinrauft 5\n4447 Känerkinden\nSchweiz",
        'kontakt_links' => array(
            array('titel' => "LinkedIn", 'link_text' => "Profil öffnen", 'link_url' => "https://www.linkedin.com/in/tobias-reymond-714a9919b"),
            array('titel' => "DJ-Seite", 'link_text' => "dj-atze.com",   'link_url' => "https://dj-atze.com"),
        ),
        'cta_eyebrow'    => "Kennenlernen",
        'cta_titel'      => "Sag einfach Hallo",
        'cta_text'       => "Eine kurze Nachricht genügt — der Rest ergibt sich im Gespräch.",
        'cta_link1_text' => "E-Mail schreiben",
        'cta_link1_url'  => "mailto:tobias.reymond05@gmail.com",
        'cta_link2_text' => "LinkedIn",
        'cta_link2_url'  => "https://www.linkedin.com/in/tobias-reymond-714a9919b",
    );
}

/* ==========================================================================
   Englisch
   ========================================================================== */

function rt_defaults_en()
{
    return array(

        'meta_titel' => "Reymond Tobias — Informatiker EFZ, websites and games",
        'meta_text'  => "Tobias Reymond, qualified IT specialist (Informatiker EFZ) from Känerkinden, Switzerland. I build websites and small games — and I DJ. Projects, career and contact on one page.",

        'hero_kicker' => "Informatiker EFZ · Application development",
        'hero_text'   => "I am Tobias. I build websites and small games — and when the computer is off, you will find me behind the DJ decks. This page shows what I work on, the path I have taken and how to reach me.",
        'hero_cta1_text' => "Write to me",
        'hero_cta1_url'  => "#kontakt",
        'hero_cta2_text' => "See my projects",
        'hero_cta2_url'  => "#projekte",
        'hero_fakten' => array(
            array('label' => "Profession", 'wert' => "Informatiker EFZ, application development", 'link_url' => ""),
            array('label' => "Location",   'wert' => "Känerkinden, Switzerland", 'link_url' => ""),
            array('label' => "Reach me",   'wert' => "tobias.reymond05@gmail.com", 'link_url' => "mailto:tobias.reymond05@gmail.com"),
        ),

        'ueber_eyebrow' => "About me",
        'ueber_titel'   => "Who is writing here",
        'ueber_lede'    => "Swiss-qualified IT specialist (Informatiker EFZ) in application development. What I like building most: websites and small games.",
        'ueber_text'    => "My name is Tobias Reymond. I live in Känerkinden in the canton of Basel-Landschaft and I completed my apprenticeship as an Informatiker EFZ specialising in application development. Two of those years I spent as an intern at business+design AG in Laupersdorf.\n\nWhat I enjoy most is building websites and small games. I like the moment when something works for the first time: a menu that feels right, a character that finally jumps cleanly, a page that looks as good on a phone as it does on a large screen.\n\nMusic is the other half of it. I DJ, put my sets together myself and like trying out new things — behind the decks as much as in the editor.",
        'ueber_bild_alt' => "Tobias Reymond at his desk in front of a laptop",
        'ueber_fakten' => array(
            array('titel' => "Informatiker EFZ", 'text' => "Application development, completed"),
            array('titel' => "Känerkinden",      'text' => "Canton of Basel-Landschaft"),
            array('titel' => "Open to new things", 'text' => "Questions, projects, working together"),
        ),

        'arbeit_eyebrow' => "Focus areas",
        'arbeit_titel'   => "What I do",
        'arbeit_lede'    => "Three fields I am at home in — and the tools I work with.",
        'schwerpunkte' => array(
            array(
                'titel' => "Building websites",
                'text'  => "From the first draft to the finished page: structure, design and code by hand. What matters to me is that a page loads fast, works on every screen and can be kept up to date later without any programming knowledge.",
                'link_text' => "See examples",
                'link_url'  => "#projekte",
            ),
            array(
                'titel' => "Application development",
                'text'  => "The focus of my apprenticeship: turning a requirement into working software. Store the data properly, write the code, test it, improve it — and document it so someone else can pick it up.",
                'link_text' => "", 'link_url' => "",
            ),
            array(
                'titel' => "Programming games",
                'text'  => "In my free time I build small games. I start with one mechanic that is fun and build the rest around it: controls, levels, sound — and plenty of attempts until it feels right.",
                'link_text' => "", 'link_url' => "",
            ),
        ),
        'kenntnisse_titel' => "What I work with",
        'kenntnisse' => array(
            array(
                'titel' => "Programming",
                'text'  => "HTML, CSS, JavaScript, PHP and SQL — plus everything that belongs to a website: structure, design, forms and database.",
                'link_text' => "", 'link_url' => "",
            ),
            array(
                'titel' => "Tools",
                'text'  => "Git and GitHub, Visual Studio Code, working with tickets in a team. I would rather test once more before something goes live.",
                'link_text' => "", 'link_url' => "",
            ),
            array(
                'titel' => "Languages",
                'text'  => "German as my mother tongue, English written and spoken, French at a basic level.",
                'link_text' => "", 'link_url' => "",
            ),
            array(
                'titel' => "Personally",
                'text'  => "Open, communicative and a team player. My apprenticeship and military service shaped my flexibility, resilience and reliability.",
                'link_text' => "", 'link_url' => "",
            ),
        ),

        'projekte_eyebrow' => "Projects",
        'projekte_titel'   => "What I am working on",
        'projekte_lede'    => "A few things I have built or am building right now. More are added as they come.",
        'projekte' => array(
            array(
                'meta'  => "Website",
                'titel' => "reymond-tobias.ch",
                'text'  => "This page. Built by hand, without a page builder and without third-party libraries — plus an editing area of its own where I change texts, images and links myself, without touching a line of code.",
                'link_text' => "You are here right now",
                'link_url'  => "#top",
            ),
            array(
                'meta'  => "Music",
                'titel' => "dj-atze.com",
                'text'  => "My page as a DJ: everything around the decks, with a look of its own and kept separate from here.",
                'link_text' => "Open dj-atze.com",
                'link_url'  => "https://dj-atze.com",
            ),
            array(
                'meta'  => "Games",
                'titel' => "Small games from my free time",
                'text'  => "Prototypes and small games I work on beside my job: the mechanic first, then controls and levels. Whatever becomes playable gets linked here.",
                'link_text' => "", 'link_url' => "",
            ),
        ),

        'werdegang_eyebrow' => "Career",
        'werdegang_titel'   => "Stations",
        'werdegang_lede'    => "Keep scrolling — the stations move along with you.",
        'werdegang' => array(
            array(
                'zeit'  => "2025 – 2026",
                'titel' => "Military service",
                'ort'   => "KDO Wpl Frauenfeld, Switzerland",
                'text'  => "Recruit, soldier and sergeant. Alongside that, modules and certificates in communication, information and self-management at group and team level.",
                'link_text' => "", 'link_url' => "",
            ),
            array(
                'zeit'  => "2020 – 2025",
                'titel' => "Informatiker EFZ, application development",
                'ort'   => "Informatikschule Olten GmbH · internship at business+design AG, Laupersdorf",
                'text'  => "Swiss vocational training focused on software development, web technologies and IT project work. Two of the five years were an internship at business+design AG.",
                'link_text' => "", 'link_url' => "",
            ),
            array(
                'zeit'  => "2017 – 2020",
                'titel' => "Secondary school",
                'ort'   => "Sissach, level E",
                'text'  => "Lower secondary school. This is where I started programming on my own.",
                'link_text' => "", 'link_url' => "",
            ),
            array(
                'zeit'  => "2011 – 2017",
                'titel' => "Primary school",
                'ort'   => "Itingen",
                'text'  => "Six years of primary school in the Basel countryside.",
                'link_text' => "", 'link_url' => "",
            ),
        ),
        'werdegang_ende_meta'      => "More detail",
        'werdegang_ende_titel'     => "On LinkedIn",
        'werdegang_ende_text'      => "The full CV with every station is in my profile.",
        'werdegang_ende_link_text' => "Open profile",
        'werdegang_ende_link_url'  => "https://www.linkedin.com/in/tobias-reymond-714a9919b",

        'neben_eyebrow' => "Beyond work",
        'neben_titel'   => "What else belongs to me",
        'neben_lede'    => "Because a CV only tells half the story.",
        'neben' => array(
            array(
                'meta'  => "Music",
                'titel' => "I DJ",
                'text'  => "Behind the decks at private parties and events. I put my sets together myself and like an evening that has an arc to it, not just a playlist.",
                'link_text' => "dj-atze.com",
                'link_url'  => "https://dj-atze.com",
            ),
            array(
                'meta'  => "Gaming",
                'titel' => "Playing and rebuilding",
                'text'  => "I like playing games — and I almost always end up looking at how one is made. Often an evening ends with me rebuilding one of those ideas myself.",
                'link_text' => "", 'link_url' => "",
            ),
            array(
                'meta'  => "Tinkering",
                'titel' => "Trying it out beats reading about it",
                'text'  => "New language, new tool, new device: I learn fastest when I build something with it that I actually use afterwards.",
                'link_text' => "", 'link_url' => "",
            ),
        ),

        'kontakt_eyebrow' => "Contact",
        'kontakt_titel'   => "Write to me",
        'kontakt_lede'    => "A job enquiry, a project idea or simply a question — tell me briefly what it is about.",
        'kontakt_email'   => "tobias.reymond05@gmail.com",
        'kontakt_telefon' => "+41 79 355 72 42",
        'kontakt_adresse' => "Reymond Tobias\nIm Hinrauft 5\n4447 Känerkinden\nSwitzerland",
        'kontakt_links' => array(
            array('titel' => "LinkedIn", 'link_text' => "Open profile", 'link_url' => "https://www.linkedin.com/in/tobias-reymond-714a9919b"),
            array('titel' => "DJ page",  'link_text' => "dj-atze.com",  'link_url' => "https://dj-atze.com"),
        ),
        'cta_eyebrow'    => "Get in touch",
        'cta_titel'      => "Just say hello",
        'cta_text'       => "A short message is enough — the rest follows in conversation.",
        'cta_link1_text' => "Send an email",
        'cta_link1_url'  => "mailto:tobias.reymond05@gmail.com",
        'cta_link2_text' => "LinkedIn",
        'cta_link2_url'  => "https://www.linkedin.com/in/tobias-reymond-714a9919b",
    );
}
