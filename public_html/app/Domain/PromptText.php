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
            $zeilen[] = "**Besucherzählung gewünscht.** Eine eigene, ohne\n"
                . "Drittanbieter: ein winziges Zählskript, das Aufrufe je Seite und\n"
                . "Tag in eine Datei schreibt, ohne IP-Adressen zu speichern. Dazu\n"
                . "eine geschützte Seite, die die Zahlen zeigt.";
        }

        if (!empty($brief['wants_docs'])) {
            $zeilen[] = "**Anleitung gewünscht.** Eine Seite /doc.html, die dem Kunden\n"
                . "in einfachen Worten erklärt, wie er seine Website pflegt – ohne\n"
                . "Fachbegriffe, mit Beispielen.";
        }

        if (Brief::needsDatabase($brief)) {
            $zeilen[] = "**Achtung:** Aus den Zusatzwünschen unten geht hervor, dass\n"
                . "etwas gespeichert werden muss (Buchung, Bestellung, Anmeldung).\n"
                . "Bau das mit – Datenbank oder Dateien, je nachdem was reicht –\n"
                . "und eine Verwaltungsoberfläche dazu.";
        }

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
            $zeilen[] = "Bestehende Website: {$alt}\n"
                . "Schau sie an und übernimm die Texte, die brauchbar sind. Den\n"
                . "Aufbau übernimmst du ausdrücklich NICHT – die Seite wird neu\n"
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
