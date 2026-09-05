<?php

declare(strict_types=1);

namespace WebAtze\Templates;

/**
 * Die Vorlagenbibliothek: 20 Varianten je Abschnittstyp.
 *
 * Eine Variante besteht aus einer Beschriftung und einer Liste von
 * Merkmalen. Die Merkmale werden zu CSS-Klassen und bestimmen den Aufbau:
 * ob zweispaltig oder mittig, wo das Bild sitzt, ob die Fläche hell oder
 * dunkel ist. Der Aufbau selbst steckt im Stylesheet der Kundenwebsite.
 *
 * Warum so und nicht 320 einzelne HTML-Dateien?
 * Alle Varianten eines Typs benutzen dasselbe Inhaltsschema. Dadurch
 * lässt sich die Vorlage jederzeit wechseln, ohne dass ein Text oder ein
 * Bild verlorengeht – und eine neue Variante ist eine Zeile, kein Neubau.
 *
 * Zusätzlich kann die KI jederzeit weitere Varianten erzeugen; sie landen
 * dann als zusätzliche Einträge in der Datenbank des Projekts.
 */
final class Catalog
{
    /**
     * @return array<string, array<string, array{label:string, mods:string}>>
     */
    public static function all(): array
    {
        return [

            // ---------------------------------------------------------- Kopf
            'header' => self::build([
                ['links-rechts', 'Logo links, Navigation rechts', 'split'],
                ['mittig', 'Logo mittig, Navigation darunter', 'stacked center'],
                ['drei-teilig', 'Navigation links, Logo mittig, Knopf rechts', 'triple'],
                ['schmal', 'Sehr flach und zurückhaltend', 'split slim'],
                ['gross', 'Grosszügig mit viel Luft', 'split roomy'],
                ['balken', 'Mit farbigem Streifen oben', 'split accentbar'],
                ['transparent', 'Durchsichtig über dem Aufmacher', 'split overlay'],
                ['milchglas', 'Milchglas beim Scrollen', 'split glass'],
                ['dunkel', 'Dunkle Fläche', 'split dark'],
                ['rahmen', 'Mit Trennlinie unten', 'split bordered'],
                ['knopf-voll', 'Auffälliger Knopf rechts', 'split cta-solid'],
                ['knopf-umriss', 'Knopf nur mit Umriss', 'split cta-outline'],
                ['ohne-knopf', 'Nur Navigation, kein Knopf', 'split no-cta'],
                ['telefon', 'Mit Telefonnummer im Kopf', 'split with-phone'],
                ['zweizeilig', 'Kontaktzeile über der Navigation', 'twoline'],
                ['schwebend', 'Abgesetzt als schwebende Leiste', 'split floating'],
                ['gross-logo', 'Betontes Logo', 'split logo-lg'],
                ['versal', 'Navigation in Grossbuchstaben', 'split caps'],
                ['unterstrich', 'Aktive Seite unterstrichen', 'split underline'],
                ['pille', 'Aktive Seite als Pille hinterlegt', 'split pill'],
            ]),

            // ---------------------------------------------------------- Aufmacher
            'hero' => self::build([
                ['bild-rechts', 'Text links, Bild rechts', 'split media-right'],
                ['bild-links', 'Bild links, Text rechts', 'split media-left'],
                ['mittig', 'Alles mittig, Bild darunter', 'center media-below'],
                ['mittig-gross', 'Mittig mit sehr grosser Schrift', 'center display media-below'],
                ['bildhintergrund', 'Bild füllt den Hintergrund', 'center media-cover overlay'],
                ['dunkel', 'Dunkle Fläche ohne Bild', 'center dark no-media'],
                ['verlauf', 'Farbverlauf im Hintergrund', 'center gradient no-media'],
                ['gestapelt', 'Text oben, breites Bild darunter', 'stacked media-wide'],
                ['versetzt', 'Bild versetzt über die Kante', 'split media-right offset'],
                ['schlicht', 'Nur Überschrift und ein Knopf', 'center minimal no-media'],
                ['karte', 'Text in einer Karte auf dem Bild', 'boxed media-cover'],
                ['randlos', 'Bild bis an den Bildschirmrand', 'split media-right bleed'],
                ['zwei-bilder', 'Zwei Bilder neben dem Text', 'split media-duo'],
                ['vorteile', 'Mit Vorteilszeile unter den Knöpfen', 'split media-right badges'],
                ['schmale-spalte', 'Schmale Textspalte, grosses Bild', 'split narrow media-right'],
                ['ecke', 'Bild in der Ecke angeschnitten', 'center media-corner'],
                ['banner', 'Flach, wenig Höhe', 'center compact no-media'],
                ['vollbild', 'Füllt den ganzen Bildschirm', 'center fullheight media-cover overlay'],
                ['akzentlinie', 'Überschrift mit Akzentlinie', 'split media-right ruled'],
                ['grosse-ueberzeile', 'Überzeile gross über dem Titel', 'center eyebrow-lg media-below'],
            ]),

            // ---------------------------------------------------------- Vorteile
            'features' => self::build([
                ['raster-3', 'Drei nebeneinander', 'grid cols-3'],
                ['raster-2', 'Zwei nebeneinander', 'grid cols-2'],
                ['raster-4', 'Vier nebeneinander', 'grid cols-4'],
                ['karten', 'Als Karten mit Rahmen', 'grid cols-3 carded'],
                ['karten-schatten', 'Karten mit Schatten', 'grid cols-3 carded shadowed'],
                ['liste', 'Untereinander als Liste', 'list'],
                ['liste-zweispaltig', 'Liste in zwei Spalten', 'list cols-2'],
                ['sinnbilder-oben', 'Sinnbild über dem Titel', 'grid cols-3 icon-top'],
                ['sinnbilder-links', 'Sinnbild neben dem Text', 'grid cols-2 icon-left'],
                ['sinnbilder-gross', 'Grosse Sinnbilder', 'grid cols-3 icon-top icon-lg'],
                ['nummeriert', 'Durchnummeriert', 'grid cols-3 numbered'],
                ['nummern-gross', 'Grosse Ziffern im Hintergrund', 'grid cols-3 numbered number-ghost'],
                ['abwechselnd', 'Abwechselnd eingerückt', 'grid cols-3 staggered'],
                ['trennlinien', 'Mit Trennlinien dazwischen', 'grid cols-3 divided'],
                ['dunkel', 'Auf dunkler Fläche', 'grid cols-3 dark'],
                ['akzentflaeche', 'Auf farbiger Fläche', 'grid cols-3 accent'],
                ['gross-erste', 'Erster Punkt hervorgehoben', 'grid cols-3 feature-first'],
                ['kompakt', 'Eng gesetzt, wenig Abstand', 'grid cols-4 compact'],
                ['mit-bildern', 'Mit kleinem Bild statt Sinnbild', 'grid cols-3 media-top'],
                ['zeile', 'Eine Zeile, waagerecht scrollend', 'row scroll'],
            ]),

            // ---------------------------------------------------------- Leistungen
            'services' => self::build([
                ['raster-3', 'Drei Karten nebeneinander', 'grid cols-3 carded'],
                ['raster-2', 'Zwei grosse Karten', 'grid cols-2 carded'],
                ['raster-4', 'Vier kompakte Karten', 'grid cols-4 carded compact'],
                ['bilder-oben', 'Bild oben in der Karte', 'grid cols-3 carded media-top'],
                ['bilder-gross', 'Grosse Bilder, Text darunter', 'grid cols-2 media-top'],
                ['abwechselnd', 'Abwechselnd Bild links und rechts', 'alternating media-side'],
                ['liste-gross', 'Grosse Zeilen untereinander', 'rows divided'],
                ['liste-nummeriert', 'Nummerierte Zeilen', 'rows divided numbered'],
                ['sinnbilder', 'Mit Sinnbildern statt Bildern', 'grid cols-3 icon-top'],
                ['sinnbilder-links', 'Sinnbild links neben dem Text', 'grid cols-2 icon-left'],
                ['dunkel', 'Auf dunkler Fläche', 'grid cols-3 carded dark'],
                ['akzent', 'Karten in Markenfarbe', 'grid cols-3 carded accent'],
                ['rahmenlos', 'Ohne Rahmen, nur Abstand', 'grid cols-3 plain'],
                ['erste-gross', 'Erste Leistung doppelt so gross', 'grid cols-3 feature-first'],
                ['zwei-spalten-text', 'Überschrift links, Leistungen rechts', 'sidebar'],
                ['mit-preisen', 'Mit Platz für Zusatzangabe', 'grid cols-3 carded with-meta'],
                ['aufklappbar', 'Zum Aufklappen', 'accordion'],
                ['reiter', 'Als Reiter zum Umschalten', 'tabs'],
                ['zeile', 'Waagerecht scrollend', 'row scroll carded'],
                ['gross-bild-links', 'Ein grosses Bild, Liste daneben', 'split media-left rows'],
            ]),

            // ---------------------------------------------------------- Über uns
            'about' => self::build([
                ['bild-rechts', 'Text links, Bild rechts', 'split media-right'],
                ['bild-links', 'Bild links, Text rechts', 'split media-left'],
                ['bild-oben', 'Breites Bild über dem Text', 'stacked media-wide'],
                ['mittig', 'Text mittig, kein Bild', 'center no-media'],
                ['zwei-spalten', 'Text in zwei Spalten', 'columns no-media'],
                ['zitat', 'Mit hervorgehobenem Zitat', 'split media-right pullquote'],
                ['merkmale', 'Mit Merkmalen unter dem Text', 'split media-right highlights'],
                ['merkmale-raster', 'Merkmale als Raster daneben', 'split media-left highlights-grid'],
                ['dunkel', 'Auf dunkler Fläche', 'split media-right dark'],
                ['akzent', 'Auf farbiger Fläche', 'split media-left accent'],
                ['bild-versetzt', 'Bild versetzt und überlappend', 'split media-right offset'],
                ['bild-randlos', 'Bild bis zum Bildschirmrand', 'split media-right bleed'],
                ['zwei-bilder', 'Zwei Bilder übereinander versetzt', 'split media-duo'],
                ['schmal', 'Schmale Textspalte, viel Luft', 'center narrow no-media'],
                ['unterschrift', 'Mit Unterschriftszeile am Ende', 'split media-right signed'],
                ['gross-titel', 'Sehr grosse Überschrift', 'split media-right display'],
                ['rahmen', 'Text in einem Rahmen', 'split media-right boxed'],
                ['zeitleiste', 'Als Zeitleiste dargestellt', 'timeline no-media'],
                ['bild-rund', 'Rundes Bild', 'split media-left media-round'],
                ['kompakt', 'Kurz und knapp', 'split media-right compact'],
            ]),

            // ---------------------------------------------------------- Galerie
            'gallery' => self::build([
                ['raster-3', 'Drei Spalten', 'grid cols-3'],
                ['raster-2', 'Zwei grosse Spalten', 'grid cols-2'],
                ['raster-4', 'Vier Spalten', 'grid cols-4'],
                ['mauerwerk', 'Unterschiedlich hoch versetzt', 'masonry'],
                ['mosaik', 'Erstes Bild doppelt so gross', 'mosaic'],
                ['streifen', 'Waagerecht scrollender Streifen', 'row scroll'],
                ['quadratisch', 'Alle Bilder quadratisch', 'grid cols-3 square'],
                ['hochkant', 'Alle Bilder hochkant', 'grid cols-4 portrait'],
                ['breit', 'Alle Bilder im Breitformat', 'grid cols-2 wide'],
                ['randlos', 'Ohne Abstand zwischen den Bildern', 'grid cols-3 seamless'],
                ['mit-unterschrift', 'Mit Bildunterschriften', 'grid cols-3 captions'],
                ['unterschrift-innen', 'Unterschrift im Bild', 'grid cols-3 captions-overlay'],
                ['abgerundet', 'Stark abgerundete Ecken', 'grid cols-3 rounded'],
                ['rahmen', 'Mit dünnem Rahmen', 'grid cols-3 framed'],
                ['dunkel', 'Auf dunkler Fläche', 'grid cols-3 dark'],
                ['gross-erstes', 'Ein grosses Bild, Rest klein', 'feature-first'],
                ['zweireihig', 'Zwei versetzte Reihen', 'grid cols-4 staggered'],
                ['schwebend', 'Bilder mit Schatten', 'grid cols-3 shadowed'],
                ['lupe', 'Bilder wachsen beim Zeigen', 'grid cols-3 zoom'],
                ['diagonal', 'Leicht schräg gesetzt', 'grid cols-3 tilted'],
            ]),

            // ---------------------------------------------------------- Kundenstimmen
            'testimonials' => self::build([
                ['raster-3', 'Drei Zitate nebeneinander', 'grid cols-3 carded'],
                ['raster-2', 'Zwei grosse Zitate', 'grid cols-2 carded'],
                ['einzeln', 'Ein grosses Zitat mittig', 'single center'],
                ['einzeln-gross', 'Ein Zitat in sehr grosser Schrift', 'single center display'],
                ['karussell', 'Zum Durchblättern', 'carousel'],
                ['streifen', 'Waagerecht scrollend', 'row scroll carded'],
                ['mit-bild', 'Mit Bild der Person', 'grid cols-3 carded avatar'],
                ['bild-gross', 'Grosses Bild neben dem Zitat', 'split media-left single'],
                ['sterne', 'Mit Sternebewertung', 'grid cols-3 carded rated'],
                ['sterne-gross', 'Sterne gross über dem Zitat', 'grid cols-3 carded rated rating-lg'],
                ['dunkel', 'Auf dunkler Fläche', 'grid cols-3 carded dark'],
                ['akzent', 'In Markenfarbe', 'grid cols-3 carded accent'],
                ['anfuehrung', 'Mit grossem Anführungszeichen', 'grid cols-3 carded quotemark'],
                ['ohne-rahmen', 'Ohne Karten, nur Text', 'grid cols-3 plain'],
                ['mauerwerk', 'Unterschiedlich hoch', 'masonry'],
                ['zweispaltig-text', 'Überschrift links, Zitate rechts', 'sidebar'],
                ['kompakt', 'Kurze Zitate, eng gesetzt', 'grid cols-4 compact'],
                ['abwechselnd', 'Abwechselnd eingerückt', 'grid cols-2 staggered'],
                ['logo-zeile', 'Mit Firmenlogo statt Bild', 'grid cols-3 carded logo'],
                ['zeitleiste', 'Untereinander mit Linie', 'timeline'],
            ]),

            // ---------------------------------------------------------- Team
            'team' => self::build([
                ['raster-3', 'Drei Personen nebeneinander', 'grid cols-3'],
                ['raster-4', 'Vier nebeneinander', 'grid cols-4'],
                ['raster-2', 'Zwei grosse Vorstellungen', 'grid cols-2'],
                ['rund', 'Runde Bilder', 'grid cols-4 media-round'],
                ['quadratisch', 'Quadratische Bilder', 'grid cols-3 square'],
                ['hochkant', 'Hochformatige Bilder', 'grid cols-4 portrait'],
                ['karten', 'Als Karten mit Rahmen', 'grid cols-3 carded'],
                ['text-ueber-bild', 'Name im Bild', 'grid cols-3 overlay'],
                ['zeilen', 'Untereinander mit Bild links', 'rows media-left'],
                ['abwechselnd', 'Abwechselnd Bild links und rechts', 'alternating'],
                ['dunkel', 'Auf dunkler Fläche', 'grid cols-3 dark'],
                ['ohne-bild', 'Nur Namen und Funktion', 'grid cols-4 no-media'],
                ['gross-erste', 'Leitung hervorgehoben', 'grid cols-3 feature-first'],
                ['mit-kontakt', 'Mit E-Mail-Verweis', 'grid cols-3 with-contact'],
                ['streifen', 'Waagerecht scrollend', 'row scroll'],
                ['versetzt', 'Versetzt angeordnet', 'grid cols-3 staggered'],
                ['schwarzweiss', 'Bilder in Graustufen', 'grid cols-3 grayscale'],
                ['farbe-bei-zeigen', 'Farbe erscheint beim Zeigen', 'grid cols-3 grayscale hover-colour'],
                ['kompakt', 'Kleine Bilder, eng gesetzt', 'grid cols-5 compact'],
                ['mit-text', 'Mit längerer Beschreibung', 'grid cols-2 with-bio'],
            ]),

            // ---------------------------------------------------------- Ablauf
            'process' => self::build([
                ['schritte-3', 'Drei Schritte nebeneinander', 'grid cols-3 numbered'],
                ['schritte-4', 'Vier Schritte nebeneinander', 'grid cols-4 numbered'],
                ['zeitleiste', 'Senkrechte Zeitleiste', 'timeline'],
                ['zeitleiste-mittig', 'Zeitleiste mit Wechsel links und rechts', 'timeline alternating'],
                ['linie', 'Waagerechte Linie mit Punkten', 'track connected'],
                ['pfeile', 'Mit Pfeilen dazwischen', 'grid cols-4 numbered arrows'],
                ['karten', 'Als Karten', 'grid cols-3 numbered carded'],
                ['nummern-gross', 'Grosse Ziffern', 'grid cols-4 numbered number-lg'],
                ['nummern-ghost', 'Ziffern gross im Hintergrund', 'grid cols-3 numbered number-ghost'],
                ['sinnbilder', 'Mit Sinnbildern statt Ziffern', 'grid cols-4 icon-top'],
                ['zeilen', 'Untereinander als Liste', 'rows numbered divided'],
                ['dunkel', 'Auf dunkler Fläche', 'grid cols-4 numbered dark'],
                ['akzent', 'In Markenfarbe', 'grid cols-3 numbered accent'],
                ['abwechselnd', 'Abwechselnd eingerückt', 'grid cols-3 numbered staggered'],
                ['treppe', 'Treppenförmig ansteigend', 'stairs numbered'],
                ['kompakt', 'Eng gesetzt', 'grid cols-4 numbered compact'],
                ['gross-text', 'Viel Text je Schritt', 'rows numbered spacious'],
                ['zwei-spalten', 'Überschrift links, Schritte rechts', 'sidebar numbered'],
                ['streifen', 'Waagerecht scrollend', 'row scroll numbered carded'],
                ['kreise', 'Ziffern in Kreisen', 'grid cols-4 numbered circles'],
            ]),

            // ---------------------------------------------------------- Zahlen
            'stats' => self::build([
                ['reihe-4', 'Vier Zahlen in einer Reihe', 'grid cols-4'],
                ['reihe-3', 'Drei Zahlen in einer Reihe', 'grid cols-3'],
                ['reihe-2', 'Zwei grosse Zahlen', 'grid cols-2'],
                ['gross', 'Sehr grosse Ziffern', 'grid cols-4 display'],
                ['karten', 'Als Karten', 'grid cols-4 carded'],
                ['trennlinien', 'Mit senkrechten Trennlinien', 'grid cols-4 divided'],
                ['dunkel', 'Auf dunkler Fläche', 'grid cols-4 dark'],
                ['akzent', 'In Markenfarbe', 'grid cols-4 accent'],
                ['verlauf', 'Ziffern im Farbverlauf', 'grid cols-4 gradient-text'],
                ['mit-titel', 'Mit Überschrift darüber', 'grid cols-4 with-title'],
                ['seitlich', 'Überschrift links, Zahlen rechts', 'sidebar'],
                ['gestapelt', 'Untereinander', 'rows'],
                ['kompakt', 'Klein und dezent', 'grid cols-4 compact'],
                ['rahmen', 'Jede Zahl in einem Rahmen', 'grid cols-4 boxed'],
                ['streifen', 'Als schmaler Streifen', 'grid cols-4 band'],
                ['bild-hintergrund', 'Auf einem Bild', 'grid cols-4 overlay'],
                ['zaehlend', 'Zahlen zählen beim Erscheinen hoch', 'grid cols-4 counting'],
                ['sechs', 'Sechs Zahlen', 'grid cols-3 six'],
                ['mittig', 'Mittig und schmal', 'grid cols-3 center narrow'],
                ['ohne-flaeche', 'Ganz ohne Hintergrund', 'grid cols-4 plain'],
            ]),

            // ---------------------------------------------------------- Partner
            'logos' => self::build([
                ['reihe', 'Eine Reihe', 'row'],
                ['reihe-laufend', 'Laufband', 'marquee'],
                ['raster-4', 'Vier Spalten', 'grid cols-4'],
                ['raster-5', 'Fünf Spalten', 'grid cols-5'],
                ['raster-6', 'Sechs Spalten', 'grid cols-6'],
                ['graustufen', 'In Graustufen', 'row grayscale'],
                ['farbe-bei-zeigen', 'Farbe beim Zeigen', 'row grayscale hover-colour'],
                ['rahmen', 'Mit Rahmen', 'grid cols-4 framed'],
                ['karten', 'Als Karten', 'grid cols-4 carded'],
                ['dunkel', 'Auf dunkler Fläche', 'row dark'],
                ['akzent', 'Auf farbiger Fläche', 'row accent'],
                ['mit-titel', 'Mit Überschrift', 'row with-title'],
                ['titel-links', 'Überschrift links daneben', 'sidebar'],
                ['gross', 'Grosse Logos', 'row logo-lg'],
                ['klein', 'Kleine Logos', 'row logo-sm'],
                ['zweireihig', 'Zwei Reihen', 'grid cols-4 tworow'],
                ['trennlinien', 'Mit Trennlinien', 'row divided'],
                ['schmal', 'Als schmaler Streifen', 'row band'],
                ['laufband-zwei', 'Zwei Laufbänder gegenläufig', 'marquee double'],
                ['zentriert', 'Mittig, mit viel Luft', 'row center roomy'],
            ]),

            // ---------------------------------------------------------- Fragen
            'faq' => self::build([
                ['aufklappen', 'Zum Aufklappen', 'accordion'],
                ['aufklappen-erste', 'Erste Frage offen', 'accordion first-open'],
                ['aufklappen-karten', 'Aufklappen, als Karten', 'accordion carded'],
                ['aufklappen-linien', 'Aufklappen, nur mit Linien', 'accordion lined'],
                ['zwei-spalten', 'Zwei Spalten', 'grid cols-2'],
                ['zwei-spalten-offen', 'Zwei Spalten, alles sichtbar', 'grid cols-2 open'],
                ['liste', 'Alles offen untereinander', 'list open'],
                ['seitlich', 'Überschrift links, Fragen rechts', 'sidebar accordion'],
                ['dunkel', 'Auf dunkler Fläche', 'accordion dark'],
                ['akzent', 'Aktive Frage in Markenfarbe', 'accordion accent-active'],
                ['nummeriert', 'Durchnummeriert', 'accordion numbered'],
                ['gross', 'Grosse Schrift', 'accordion display'],
                ['kompakt', 'Eng gesetzt', 'accordion compact'],
                ['plus-minus', 'Mit Plus und Minus', 'accordion icon-plus'],
                ['pfeil', 'Mit Pfeil', 'accordion icon-chevron'],
                ['gruppen', 'Nach Themen gruppiert', 'accordion grouped'],
                ['mit-kontakt', 'Mit Hinweis auf Kontakt am Ende', 'accordion with-cta'],
                ['schmal', 'Schmale Spalte, mittig', 'accordion narrow'],
                ['rahmen', 'Alles in einem Rahmen', 'accordion boxed'],
                ['zebra', 'Abwechselnd hinterlegt', 'accordion striped'],
            ]),

            // ---------------------------------------------------------- Aufruf
            'cta' => self::build([
                ['mittig', 'Mittig mit Knopf', 'center'],
                ['mittig-gross', 'Mittig, sehr grosse Schrift', 'center display'],
                ['links-rechts', 'Text links, Knopf rechts', 'split'],
                ['karte', 'Als abgesetzte Karte', 'center boxed'],
                ['karte-verlauf', 'Karte mit Farbverlauf', 'center boxed gradient'],
                ['dunkel', 'Dunkle Fläche', 'center dark'],
                ['akzent', 'Ganz in Markenfarbe', 'center accent'],
                ['bild', 'Auf einem Bild', 'center media-cover overlay'],
                ['bild-seitlich', 'Bild neben dem Text', 'split media-right'],
                ['streifen', 'Schmaler Streifen', 'split band'],
                ['zwei-knoepfe', 'Mit zwei Schaltflächen', 'center two-cta'],
                ['rahmen', 'Nur mit Rahmen, ohne Fläche', 'center outlined'],
                ['leuchtrand', 'Mit leuchtendem Rand', 'center boxed glow'],
                ['schraeg', 'Schräg abgeschnittene Fläche', 'center slanted'],
                ['gross-knopf', 'Sehr grosse Schaltfläche', 'center cta-lg'],
                ['mit-telefon', 'Mit Telefonnummer daneben', 'split with-phone'],
                ['schmal', 'Schmal und ruhig', 'center narrow'],
                ['ueberlappend', 'Überlappt den nächsten Abschnitt', 'center boxed overlap'],
                ['muster', 'Mit Hintergrundmuster', 'center patterned'],
                ['minimal', 'Nur eine Zeile und ein Verweis', 'center minimal'],
            ]),

            // ---------------------------------------------------------- Kontakt
            'contact' => self::build([
                ['formular-rechts', 'Angaben links, Formular rechts', 'split form-right'],
                ['formular-links', 'Formular links, Angaben rechts', 'split form-left'],
                ['nur-formular', 'Nur das Formular, mittig', 'center form-only'],
                ['nur-angaben', 'Nur Kontaktangaben', 'center info-only'],
                ['karte-rechts', 'Angaben links, Karte rechts', 'split map-right'],
                ['karte-unten', 'Karte über die volle Breite unten', 'stacked map-below'],
                ['karte-hintergrund', 'Karte als Hintergrund', 'overlay map-cover'],
                ['drei-spalten', 'Angaben in drei Spalten', 'grid cols-3 info-only'],
                ['karten', 'Angaben als Karten', 'grid cols-3 carded info-only'],
                ['dunkel', 'Auf dunkler Fläche', 'split form-right dark'],
                ['akzent', 'Formular auf farbiger Fläche', 'split form-right accent'],
                ['rahmen', 'Formular in einem Rahmen', 'split form-right boxed'],
                ['kompakt', 'Kurzes Formular, wenige Felder', 'center form-only compact'],
                ['ausfuehrlich', 'Ausführliches Formular', 'split form-right extended'],
                ['sinnbilder', 'Angaben mit Sinnbildern', 'split form-right icons'],
                ['oeffnungszeiten', 'Mit Öffnungszeiten-Tabelle', 'split form-right hours'],
                ['zwei-spalten-felder', 'Felder zweispaltig', 'center form-only cols-2'],
                ['gross', 'Grosse Felder, viel Luft', 'split form-right roomy'],
                ['seitlich', 'Überschrift links, alles rechts', 'sidebar form-right'],
                ['direkt', 'Grosse Telefonnummer im Mittelpunkt', 'center info-only phone-lg'],
            ]),

            // ---------------------------------------------------------- Text
            'text' => self::build([
                ['schmal', 'Schmale Spalte, gut lesbar', 'narrow'],
                ['breit', 'Über die volle Breite', 'wide'],
                ['zwei-spalten', 'Zweispaltig gesetzt', 'columns'],
                ['mit-titel-links', 'Überschrift links daneben', 'sidebar'],
                ['mittig', 'Mittig ausgerichtet', 'center narrow'],
                ['gross', 'Grosse Schrift', 'narrow display'],
                ['dunkel', 'Auf dunkler Fläche', 'narrow dark'],
                ['akzent', 'Auf farbiger Fläche', 'narrow accent'],
                ['rahmen', 'In einem Rahmen', 'narrow boxed'],
                ['zitat', 'Als Zitat gesetzt', 'narrow quoted'],
                ['einzug', 'Erster Absatz hervorgehoben', 'narrow lead-first'],
                ['nummeriert', 'Mit nummerierten Abschnitten', 'narrow numbered'],
                ['verzeichnis', 'Mit Inhaltsverzeichnis', 'sidebar toc'],
                ['kompakt', 'Eng gesetzt', 'narrow compact'],
                ['luftig', 'Sehr luftig gesetzt', 'narrow roomy'],
                ['serifen', 'In einer ruhigeren Schrift', 'narrow serif'],
                ['linien', 'Mit Trennlinien zwischen Abschnitten', 'narrow divided'],
                ['zwei-spalten-breit', 'Zwei breite Spalten', 'wide columns'],
                ['rand-notizen', 'Mit Randbemerkungen', 'sidebar notes'],
                ['schlicht', 'Ganz ohne Auszeichnung', 'narrow plain'],
            ]),

            // ---------------------------------------------------------- Fuss
            'footer' => self::build([
                ['vier-spalten', 'Vier Spalten', 'grid cols-4'],
                ['drei-spalten', 'Drei Spalten', 'grid cols-3'],
                ['zwei-spalten', 'Zwei Spalten', 'grid cols-2'],
                ['schmal', 'Eine schmale Zeile', 'slim'],
                ['mittig', 'Alles mittig', 'center'],
                ['gross', 'Grosser Fuss mit viel Inhalt', 'grid cols-4 roomy'],
                ['dunkel', 'Dunkle Fläche', 'grid cols-4 dark'],
                ['akzent', 'In Markenfarbe', 'grid cols-4 accent'],
                ['mit-logo', 'Mit grossem Logo', 'grid cols-4 logo-lg'],
                ['mit-aufruf', 'Mit Aufruf zum Handeln oben', 'grid cols-4 with-cta'],
                ['mit-karte', 'Mit kleiner Karte', 'grid cols-4 with-map'],
                ['sozial-gross', 'Soziale Netzwerke betont', 'grid cols-3 social-lg'],
                ['sozial-zeile', 'Netzwerke in einer Zeile', 'center social-row'],
                ['trennlinie', 'Mit Trennlinie oben', 'grid cols-4 ruled'],
                ['zwei-ebenen', 'Zwei Ebenen mit Trennung', 'grid cols-4 twotier'],
                ['kontakt-links', 'Kontaktangaben links betont', 'grid cols-4 contact-first'],
                ['kompakt', 'Eng gesetzt', 'grid cols-4 compact'],
                ['nur-verweise', 'Nur Verweise, sonst nichts', 'center links-only'],
                ['gross-schrift', 'Grosse Verweise', 'grid cols-3 display'],
                ['minimal', 'Nur eine Zeile mit Rechtsverweisen', 'slim minimal'],
            ]),

            // ------------------------------------------- Freier Abschnitt
            'frei' => self::frei(),
        ];
    }

    /**
     * Die Varianten des freien Abschnitts.
     *
     * Sechs statt zwanzig, und das mit Absicht: Ein freier Abschnitt
     * bestimmt sein Aussehen über seine Bausteine. Was die Variante noch
     * beisteuert, ist die Fläche darum herum - Breite, Abstand, Grund.
     * Zwanzig Knöpfe anzubieten, von denen vierzehn dasselbe tun, wäre
     * eine Behauptung und keine Auswahl.
     */
    private static function frei(): array
    {
        return self::build([
            ['standard', 'Normal breit', ''],
            ['schmal', 'Schmal', 'narrow'],
            ['breit', 'Über die volle Breite', 'wide'],
            ['dunkel', 'Auf dunkler Fläche', 'dark'],
            ['gedraengt', 'Wenig Abstand', 'compact'],
            ['luftig', 'Viel Abstand', 'roomy'],
        ]);
    }

    /** Alle Varianten eines Typs. */
    public static function forType(string $type): array
    {
        return self::all()[$type] ?? [];
    }

    /** Gibt es diese Vorlage? */
    public static function exists(string $type, string $key): bool
    {
        return isset(self::all()[$type][$key]);
    }

    /** Die erste (und damit voreingestellte) Vorlage eines Typs. */
    public static function defaultKey(string $type): string
    {
        $variants = self::forType($type);
        return (string) (array_key_first($variants) ?? 'standard');
    }

    /** CSS-Klassen einer Vorlage. */
    public static function modifiers(string $type, string $key): string
    {
        return (string) (self::all()[$type][$key]['mods'] ?? '');
    }

    /**
     * Welche Bewegung zu einer Vorlage gehört.
     *
     * Leer bei allen 320 bestehenden Varianten – deshalb ändert sich an
     * einer Kundenwebsite kein einziges Byte. Gefüllt wird das erst bei
     * den Varianten, die für ein Stilpaket dazukommen.
     */
    public static function hooks(string $type, string $key): string
    {
        return (string) (self::all()[$type][$key]['hooks'] ?? '');
    }

    public static function label(string $type, string $key): string
    {
        return (string) (self::all()[$type][$key]['label'] ?? $key);
    }

    /** Wie viele Varianten gibt es insgesamt? */
    public static function count(): int
    {
        return array_sum(array_map('count', self::all()));
    }

    /**
     * Aus der kurzen Schreibweise die vollständige Struktur machen.
     *
     * Das vierte Feld ist die Bewegung und darf fehlen: Alle bisherigen
     * Zeilen haben drei, und sie sollen nicht angefasst werden müssen,
     * nur damit überall eine leere Zeichenkette mehr steht.
     *
     * @param list<array{0:string,1:string,2:string,3?:string}> $rows
     */
    private static function build(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            [$key, $label, $mods] = $row;

            $out[$key] = [
                'label' => $label,
                'mods' => $mods,
                'hooks' => (string) ($row[3] ?? ''),
            ];
        }
        return $out;
    }
}
