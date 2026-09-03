<?php

declare(strict_types=1);

namespace WebAtze\Domain;

/**
 * Baut aus dem Formular einen fertigen Auftrag zum Kopieren.
 *
 * Der Grund für diese Datei ist eine Erfahrung, die Geld gekostet hat.
 * Vorher rief WebAtze die Schnittstelle selbst auf: rund achtzig
 * Anfragen, aneinandergekettet über eine Warteschlange, eine Datenbank
 * und einen Cronjob, der jede Minute einen neuen Vorgang startete. Jeder
 * dieser Übergänge ist eine Stelle, an der etwas hängenbleiben kann –
 * und es blieb hängen, immer wieder, jedes Mal woanders.
 *
 * Hier passiert davon nichts mehr. Das Formular erzeugt einen Text. Der
 * Text wird kopiert und von Hand eingefügt. Eine Anfrage, eine Antwort,
 * fertig – so wie ein Mensch es auch täte.
 *
 * Was der Text leisten muss: Er muss vollständig sein. Wer ihn einfügt,
 * hat das Formular nicht gesehen und kann nicht nachfragen. Alles, was
 * fehlt, wird erfunden – und Erfundenes auf einer Firmenwebsite ist
 * schlimmer als eine Lücke.
 */
final class PromptText
{
    /** Wie viele Seiten bei welchem Umfang. */
    private const PAGES = [
        'small' => '4 bis 5',
        'medium' => '6 bis 8',
        'large' => '9 bis 12',
    ];

    /**
     * Der ganze Auftrag als Text.
     *
     * @param array<string, mixed> $brief geprüfte Formulardaten
     */
    public static function build(array $brief): string
    {
        $teile = [
            self::auftrag($brief),
            self::firma($brief),
            self::gestaltung($brief),
            self::umfang($brief),
            self::technik($brief),
            self::betreuung($brief),
            self::zusatz($brief),
            self::regeln($brief),
            self::abschluss($brief),
        ];

        return implode("\n\n", array_filter($teile, static fn (string $t): bool => trim($t) !== ''));
    }

    // ------------------------------------------------------------------

    private static function auftrag(array $brief): string
    {
        $name = (string) ($brief['company_name'] ?? 'die Firma');

        return "# Auftrag: Website für {$name}\n\n"
            . "Bau eine vollständige, sofort lauffähige Website. Kein Gerüst, kein\n"
            . "Entwurf, keine Platzhalter, wo Inhalt hingehört – eine Website, die\n"
            . "man so hochladen und ausliefern kann.\n\n"
            . "Leg sie in einem neuen Ordner an und schreib am Ende hin, welche\n"
            . "Dateien entstanden sind.";
    }

    private static function firma(array $brief): string
    {
        $text = "## Die Firma\n\n" . Brief::toPromptText($brief);

        return $text;
    }

    private static function gestaltung(array $brief): string
    {
        $zeilen = ["## Gestaltung"];

        if (($brief['color_mode'] ?? '') === 'manual') {
            $zeilen[] = sprintf(
                "Farben sind vorgegeben: Hauptfarbe %s, Zweitfarbe %s, Akzent %s.\n"
                . "Leg sie als CSS-Variablen an genau einer Stelle ab und benutze\n"
                . "ausschliesslich diese – keine weiteren Farbwerte im Stylesheet\n"
                . "verstreut. Prüf den Kontrast: Text auf Fläche muss 4.5:1\n"
                . "erreichen, grosse Überschriften 3:1. Reicht eine Farbe für Text\n"
                . "nicht, benutz sie als Fläche und nimm dafür dunkle Schrift.",
                (string) ($brief['color_primary'] ?? ''),
                (string) ($brief['color_secondary'] ?? ''),
                (string) ($brief['color_accent'] ?? '')
            );
        } else {
            $zeilen[] = "Wähl selbst eine Farbwelt, die zur Branche passt, und leg sie als\n"
                . "CSS-Variablen an genau einer Stelle ab. Prüf den Kontrast: Text auf\n"
                . "Fläche muss 4.5:1 erreichen.";
        }

        $zeilen[] = "Schriften: zwei, höchstens drei Schnitte, als Webfont mitgeliefert\n"
            . "oder Systemschrift – nichts, was beim Aufruf von aussen nachgeladen\n"
            . "wird.";

        $zeilen[] = "Modern heisst hier: grosszügige Abstände, klare Hierarchie,\n"
            . "zurückhaltende Bewegung beim Scrollen. Kein Effekt um seiner selbst\n"
            . "willen. Wer die Seite mit reduzierter Bewegung öffnet\n"
            . "(prefers-reduced-motion), bekommt eine ruhige Fassung.";

        return implode("\n\n", $zeilen);
    }

    private static function umfang(array $brief): string
    {
        $umfang = (string) ($brief['scope'] ?? 'small');
        $seiten = self::PAGES[$umfang] ?? '4 bis 5';

        $zeilen = ["## Seiten und Aufbau"];

        $zeilen[] = "{$seiten} Seiten, plus Impressum und Datenschutz.";

        $gewuenscht = trim((string) ($brief['wanted_pages'] ?? ''));

        if ($gewuenscht !== '') {
            $zeilen[] = "Diese Seiten sind ausdrücklich gewünscht:\n" . $gewuenscht;
        }

        $zeilen[] = "Jede Seite hat vier bis sechs Abschnitte. Denk dir den Aufbau je\n"
            . "Seite selbst aus – Aufmacher, Leistungen, Über uns, Ablauf, Zahlen,\n"
            . "Stimmen, Fragen und Antworten, Aufruf zum Handeln, Kontakt sind die\n"
            . "üblichen Bausteine. Keine Seite soll aussehen wie die vorige.";

        $sprachen = (string) ($brief['locales'] ?? 'de');

        if ($sprachen !== 'de') {
            $zeilen[] = "Sprachen: " . (Brief::LOCALES[$sprachen] ?? $sprachen) . ".\n"
                . "Die Hauptsprache liegt im Wurzelverzeichnis, jede weitere in einem\n"
                . "eigenen Unterordner (/en/, /fr/). Umschalter im Kopf, hreflang im\n"
                . "Kopfbereich. Adressen, Telefonnummern und Eigennamen bleiben\n"
                . "unübersetzt – eine übersetzte Strasse ist falsch.";
        }

        return implode("\n\n", $zeilen);
    }

    private static function technik(array $brief): string
    {
        $zeilen = ["## Technik"];

        $zeilen[] = "Statisches HTML, CSS und ein wenig JavaScript. Kein Framework,\n"
            . "kein Build-Schritt, keine Abhängigkeiten aus dem Netz. Die Seite\n"
            . "muss laufen, wenn man den Ordner auf ein gewöhnliches Webhosting\n"
            . "kopiert.";

        $zeilen[] = "Kein fremder Dienst wird eingebunden – keine Schriften von\n"
            . "aussen, keine Analysewerkzeuge, keine Karten, keine Videoeinbettung\n"
            . "von Drittanbietern. Damit kommt die Seite ohne Zustimmungsbanner\n"
            . "aus, und das soll sie auch.";

        $zeilen[] = "Bilder: Es gibt keine. Setz saubere Platzhalter mit fester\n"
            . "Grösse (damit nichts springt) und einem sprechenden alt-Text, der\n"
            . "sagt, was dort hingehört. Erfinde keine Fotos von Menschen,\n"
            . "Räumen oder Arbeiten – das wären Falschaussagen über eine echte\n"
            . "Firma.";

        $zeilen[] = "Barrierefreiheit ist keine Kür: eine h1 je Seite, Überschriften\n"
            . "ohne Sprünge, jedes Formularfeld beschriftet, alles mit der\n"
            . "Tastatur bedienbar, sichtbarer Fokusrahmen, Sprungmarke zum Inhalt.";

        if (!empty($brief['wants_admin'])) {
            $zeilen[] = "**Bearbeitungsbereich gewünscht.** Bau unter /admin einen\n"
                . "einfachen Bereich in PHP, mit dem der Kunde Texte und Bilder\n"
                . "ändern kann. Anmeldung mit Argon2id, Inhalte als JSON-Dateien\n"
                . "mit atomarem Schreiben und einer Sicherungskopie je Änderung.\n"
                . "Benutzername: " . (string) ($brief['admin_username'] ?? 'admin') . ".\n"
                . "Das Passwort setze ich selbst – schreib hin, wo es hingehört.";
        }

        if (!empty($brief['wants_stats'])) {
            $bericht = trim((string) ($brief['report_email'] ?? ''));

            $zeilen[] = "**Besucherzählung gewünscht.** Eine eigene, ohne\n"
                . "Drittanbieter: ein winziges Zählskript, das Aufrufe je Seite und\n"
                . "Tag in eine Datei schreibt, ohne IP-Adressen zu speichern. Dazu\n"
                . "eine geschützte Seite, die die Zahlen zeigt."
                . ($bericht !== ''
                    ? "\nJeden Montag geht eine kurze Zusammenfassung an {$bericht}."
                    : '');
        }

        if (Brief::needsDatabase($brief)) {
            $zeilen[] = "**Achtung:** Aus den Zusatzwünschen unten geht hervor, dass\n"
                . "etwas gespeichert werden muss (Buchung, Bestellung, Anmeldung).\n"
                . "Bau das mit – Datenbank oder Dateien, je nachdem was reicht –\n"
                . "und eine Verwaltungsoberfläche dazu.";
        }

        return implode("\n\n", $zeilen);
    }

    /**
     * Anleitung und Supportbereich - bei jeder Website, ohne Ausnahme.
     *
     * Frueher hing beides an einem Haken im Formular, und der Support
     * stand ueberhaupt nicht im Auftragstext. Ergebnis: eine fertige
     * Website ohne /doc und ohne /support. Der Haken ist deshalb weg.
     * Beides gehoert zur Betreuung, und die verkaufe ich - also wird es
     * gebaut, auch wenn niemand daran denkt.
     */
    private static function betreuung(array $brief): string
    {
        $an = trim((string) ($brief['contact_email'] ?? ''));
        $ziel = $an !== '' ? $an : 'die Adresse, die in data/support.php steht';

        $zeilen = ["## Betreuung: Anleitung und Support"];

        $zeilen[] = "Diese beiden Seiten gehören zu **jeder** Website, die ich\n"
            . "ausliefere. Sie sind nicht optional und nicht verhandelbar. Bau\n"
            . "sie, auch wenn oben nichts davon steht.";

        // ------------------------------------------------------------- /doc
        $zeilen[] = "### /doc – die Anleitung\n\n"
            . "Eine eigene Seite `doc.html` (oder `doc/index.html`), verlinkt in\n"
            . "der Fusszeile. Sie erklärt dem Kunden in einfachen Worten, wie\n"
            . "seine Website funktioniert – ohne Fachbegriffe, mit Beispielen,\n"
            . "in der Anrede, die auch der Rest der Seite benutzt.\n\n"
            . "Sie enthält mindestens:\n"
            . "- **Was wo steht:** eine kurze Übersicht aller Seiten und was\n"
            . "  auf welche gehört.\n"
            . "- **Wie Anfragen ankommen:** wohin das Kontaktformular schickt,\n"
            . "  was der Kunde tun muss, wenn nichts ankommt (Spam-Ordner).\n"
            . "- **Was der Kunde selbst ändern kann und was nicht.**\n"
            . "- **Die Dateien:** welche Datei wofür da ist, in einer Tabelle.\n"
            . "- **Wenn etwas nicht stimmt:** der Weg über /support.\n"
            . "- **Was der Kunde noch liefern muss:** Bilder, Texte, Angaben\n"
            . "  fürs Impressum – alles, wo jetzt noch ein Platzhalter steht.\n\n"
            . "Sie trägt `<meta name=\"robots\" content=\"noindex, nofollow\">` und\n"
            . "steht nicht in der sitemap.xml. Sie ist für den Kunden, nicht\n"
            . "für Suchmaschinen.";

        // --------------------------------------------------------- /support
        $zeilen[] = "### /support – der Draht zu mir\n\n"
            . "Eine kleine Seite `support.php`, ebenfalls in der Fusszeile\n"
            . "verlinkt. Der Kunde schreibt dort eine Nachricht, sie geht per\n"
            . "E-Mail an {$ziel}, und der bisherige Verlauf bleibt auf der\n"
            . "Seite sichtbar. Kein Sofort-Chat, keine Zusage einer sofortigen\n"
            . "Antwort – schreib das auch so hin.";

        $zeilen[] = "**Diese Seite ist nur für den Kunden.** Nicht für Besucher,\n"
            . "nicht für Automaten. Sie ist der einzige Weg, auf dem mich jemand\n"
            . "unaufgefordert erreicht, und genau deshalb ist sie das lohnendste\n"
            . "Ziel auf der ganzen Website. Bau die Absicherung von Anfang an\n"
            . "ein, nicht als Nachtrag:";

        $zeilen[] = "1. **Zugangscode.** Vor dem Formular steht ein einzelnes Feld\n"
            . "   „Zugangscode“. Der Code liegt in `data/support.php`\n"
            . "   (`return ['code' => 'BITTE-VOR-DEM-AUSLIEFERN-AENDERN', "
            . "'email' => '…', 'secret' => '…'];`).\n"
            . "   Ich setze ihn selbst und gebe ihn dem Kunden – **erfinde\n"
            . "   keinen echten Code**, lass den Platzhalter stehen. Verglichen\n"
            . "   wird mit `hash_equals`, nie mit `==`.\n"
            . "2. **Merken statt jedesmal fragen.** Nach richtigem Code ein\n"
            . "   Cookie mit einem Ticket: Ablaufzeitpunkt plus\n"
            . "   `hash_hmac('sha256', …, \$secret)`, geprüft mit `hash_equals`.\n"
            . "   180 Tage, `HttpOnly`, `SameSite=Lax`, `Secure` sobald HTTPS\n"
            . "   anliegt. Im Cookie steht kein Code und kein Klartext.\n"
            . "3. **Versuche begrenzen.** Höchstens 5 Code-Eingaben je Stunde und\n"
            . "   IP, gezählt in einer Datei unter `data/support/`. Danach eine\n"
            . "   freundliche Meldung, kein Hinweis darauf, was falsch war.\n"
            . "4. **Honigtopf und Zeitfalle.** Ein für Menschen unsichtbares\n"
            . "   Feld, das leer bleiben muss, und ein signierter Zeitstempel im\n"
            . "   Formular: Wer in unter 3 Sekunden abschickt, ist kein Mensch.\n"
            . "   In beiden Fällen freundlich bestätigen und nichts versenden –\n"
            . "   ein Automat soll nicht lernen, woran er gescheitert ist.\n"
            . "5. **Menge begrenzen.** Höchstens 5 Nachrichten je Stunde, auch\n"
            . "   mit gültigem Ticket. Nachricht 10 bis 4000 Zeichen.\n"
            . "6. **Sauber verschicken.** Steuerzeichen raus, `\\r` und `\\n` aus\n"
            . "   allen Kopfzeilen entfernen (sonst schleust jemand eigene\n"
            . "   Empfänger ein). `Reply-To` nur bei einer Adresse, die\n"
            . "   `filter_var(…, FILTER_VALIDATE_EMAIL)` besteht.\n"
            . "7. **Verlauf schützen.** Die Nachrichten liegen als JSON unter\n"
            . "   `data/support/`, der Ordner bekommt eine `.htaccess` mit\n"
            . "   `Require all denied` und eine leere `index.html`. Beim\n"
            . "   Ausgeben wird escaped.\n"
            . "8. **Nicht auffindbar.** `X-Robots-Tag: noindex, nofollow` als\n"
            . "   Kopfzeile und dasselbe als meta-Angabe. Nicht in die\n"
            . "   sitemap.xml.";

        $zeilen[] = "Wenn `data/support.php` fehlt oder der Code noch der\n"
            . "Platzhalter ist, zeigt die Seite einen ruhigen Hinweis statt eines\n"
            . "Formulars – und keinen Fehler.";

        $zeilen[] = "Falls das Hosting kein PHP kann, sag es mir, statt still auf\n"
            . "einen fremden Formulardienst auszuweichen. Ein Dienst von aussen\n"
            . "wäre wieder ein Zustimmungsbanner, und das ist es nicht wert.";

        return implode("\n\n", $zeilen);
    }

    private static function zusatz(array $brief): string
    {
        $extra = trim((string) ($brief['extra_notes'] ?? ''));
        $design = trim((string) ($brief['design_notes'] ?? ''));
        $alt = trim((string) ($brief['old_url'] ?? ''));

        if ($extra === '' && $design === '' && $alt === '') {
            return '';
        }

        $zeilen = ["## Besondere Wünsche"];

        if ($design !== '') {
            $zeilen[] = "Die Designwünsche oben haben Vorrang vor allem, was hier als\n"
                . "Empfehlung steht.";
        }

        if ($alt !== '') {
            $texte = !empty($brief['take_texts']);
            $bilder = !empty($brief['take_images']);

            $was = match (true) {
                $texte && $bilder => "Übernimm die Texte, die brauchbar sind, und die Bilder.",
                $texte => "Übernimm die Texte, die brauchbar sind. Die Bilder ausdrücklich nicht.",
                $bilder => "Übernimm die Bilder. Die Texte werden neu geschrieben.",
                default => "Übernimm weder Texte noch Bilder – sie dient nur als "
                    . "Anhaltspunkt, was die Firma tut.",
            };

            $zeilen[] = "Bestehende Website: {$alt}\n"
                . "Schau sie an. {$was}\n"
                . "Den Aufbau übernimmst du ausdrücklich NICHT – die Seite wird neu\n"
                . "gedacht, nicht abgemalt.";
        }

        if ($extra !== '') {
            $zeilen[] = "Die \"Zusätzlichen Wünsche\" oben sind kein Beiwerk. Lies sie\n"
                . "genau und bau, was dort steht – auch wenn es Arbeit macht. Was\n"
                . "du davon nicht umsetzen kannst, schreib mir am Ende hin, statt\n"
                . "es stillschweigend wegzulassen.";
        }

        return implode("\n\n", $zeilen);
    }

    private static function regeln(array $brief): string
    {
        $zeilen = ["## Zwei Dinge, die nicht verhandelbar sind"];

        $zeilen[] = "**Erstens: Nirgends ein Hinweis darauf, wie diese Website\n"
            . "entstanden ist.** Kein \"mit KI erstellt\", kein Modellname, kein\n"
            . "Hinweis in Kommentaren, Metadaten, Dateinamen oder der\n"
            . "Fusszeile. Weder im sichtbaren Text noch im Quelltext.";

        $zeilen[] = "**Zweitens: Erfinde nichts über diese Firma.** Keine Zahlen,\n"
            . "keine Jahreszahlen, keine Mitarbeiterzahlen, keine Auszeichnungen,\n"
            . "keine Kundenstimmen, keine Referenzen – nichts, was oben nicht\n"
            . "dasteht. Wo ein Abschnitt so etwas bräuchte und es fehlt, lass ihn\n"
            . "weg oder setz einen erkennbaren Platzhalter mit dem Hinweis, was\n"
            . "der Kunde liefern muss. Eine erfundene Kundenstimme auf einer\n"
            . "echten Firmenwebsite ist eine Lüge gegenüber deren Kunden.";

        $zeilen[] = "Schweizer Rechtschreibung: ss statt ß, durchgehend.";

        $anrede = (string) ($brief['tone'] ?? 'sie');
        $zeilen[] = $anrede === 'du'
            ? "Angesprochen wird geduzt, durchgehend und natürlich – nicht\nkumpelhaft."
            : "Angesprochen wird gesiezt, durchgehend.";

        return implode("\n\n", $zeilen);
    }

    private static function abschluss(array $brief): string
    {
        $zeilen = ["## Zum Schluss"];

        $zeilen[] = "Rechtliches: Impressum und Datenschutzerklärung nach\n"
            . "Schweizer Recht, mit den Angaben von oben. Wo Angaben fehlen,\n"
            . "setz einen deutlich erkennbaren Platzhalter statt einer\n"
            . "Erfindung.";

        $zeilen[] = "Dazu gehören: robots.txt, sitemap.xml, eine 404-Seite,\n"
            . "Open-Graph-Angaben, ein Favicon.";

        $zeilen[] = "Das Kontaktformular schickt per PHP an "
            . (string) ($brief['contact_email'] ?? 'die Adresse oben') . ".\n"
            . "Schutz gegen Spam ohne Captcha: ein verstecktes Feld, eine\n"
            . "Zeitfalle und eine Begrenzung je Adresse. Jede Eingabe wird\n"
            . "geprüft und beim Ausgeben escaped.";

        $zeilen[] = "Wenn du fertig bist, prüf selbst nach: Führt jeder Verweis\n"
            . "irgendwohin? Hat jede Seite genau eine h1? Steht irgendwo noch ein\n"
            . "Platzhalter, der keiner sein sollte? Schreib mir hin, was du\n"
            . "gefunden und behoben hast – und was der Kunde noch liefern muss.";

        return implode("\n\n", $zeilen);
    }

    /**
     * Ein Dateiname für den Download.
     */
    public static function filename(array $brief): string
    {
        $name = mb_strtolower(trim((string) ($brief['company_name'] ?? 'auftrag')));
        $name = preg_replace('/[^a-z0-9]+/u', '-', $name) ?? 'auftrag';

        return 'auftrag-' . trim($name, '-') . '.md';
    }
}
