<?php

declare(strict_types=1);

namespace WebAtze\Build;

use WebAtze\Templates\{Catalog, Page, Renderer, Schema};

/**
 * Nimmt ab, ob eine erzeugte Website im Editor wirklich zu bearbeiten ist.
 *
 * Das ist die Zusage, um die es geht: „Von Anfang an kann man jede
 * Section bearbeiten." Bisher war das eine Hoffnung. Der Auftragstext
 * bat darum, die Vorlagen taten es – aber geprüft hat es niemand, und
 * eine Seite, die der Editor nicht anfassen kann, sieht bis zum ersten
 * Klick genauso aus wie eine, die er anfassen kann.
 *
 * Vier Dinge werden nachgesehen, und jedes davon ist ein Fall, in dem
 * die Oberfläche da wäre und nichts täte:
 *
 *   1. Jeder Abschnitt trägt eine Kennung. Ohne sie findet der Editor
 *      ihn nicht – Anklicken, Ziehen, Ausblenden: nichts davon greift.
 *   2. Typ und Vorlage stehen im Katalog. Steht die Vorlage nicht
 *      darin, ändert „Vorlage wechseln" eine Datenbankspalte und auf
 *      dem Bildschirm passiert nichts.
 *   3. Jedes Pflichtfeld des Schemas erscheint tatsächlich im HTML.
 *      Sonst gibt es im Blatt ein Feld, dessen Änderung unsichtbar
 *      bleibt – die ärgerlichste Sorte von kaputt, weil sie wie ein
 *      Speicherfehler aussieht.
 *   4. Kopf zuerst, Fuss zuletzt.
 *
 * Ein Fehler hier ist ein Baufehler und keine Schönheitsfrage. Genau
 * dafür ist die Prüfung da: damit er beim Bauen auffällt und nicht,
 * wenn jemand die Seite zum ersten Mal bearbeiten will.
 */
final class EditorCheck
{
    /**
     * Eine ganze Website durchsehen.
     *
     * @param array<int, array<string, mixed>> $pages Seiten mit ihren Abschnitten
     * @return array{ok:bool, geprueft:int, fehler:array<int, string>, warnungen:array<int, string>}
     */
    public static function run(array $site, array $pages): array
    {
        $fehler = [];
        $warnungen = [];
        $geprueft = 0;

        foreach ($pages as $seite) {
            $abschnitte = is_array($seite['sections'] ?? null) ? $seite['sections'] : [];
            $wo = (string) ($seite['path'] ?? ($seite['title'] ?? '?'));

            $ergebnis = self::seite($site, $seite, $abschnitte, $wo);

            $fehler = array_merge($fehler, $ergebnis['fehler']);
            $warnungen = array_merge($warnungen, $ergebnis['warnungen']);
            $geprueft += $ergebnis['geprueft'];
        }

        return [
            'ok' => $fehler === [],
            'geprueft' => $geprueft,
            'fehler' => $fehler,
            'warnungen' => $warnungen,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $abschnitte
     * @return array{geprueft:int, fehler:array<int, string>, warnungen:array<int, string>}
     */
    private static function seite(array $site, array $seite, array $abschnitte, string $wo): array
    {
        $fehler = [];
        $warnungen = [];
        $geprueft = 0;

        $kontext = Page::context($site, $seite, (string) ($site['locale'] ?? 'de'));

        // --- Kopf zuerst, Fuss zuletzt -------------------------------
        $typen = array_map(
            static fn (array $a): string => (string) ($a['type'] ?? ''),
            array_values($abschnitte)
        );

        $kopf = array_search('header', $typen, true);
        $fuss = array_search('footer', $typen, true);

        if ($kopf !== false && $kopf !== 0) {
            $fehler[] = $wo . ': Die Kopfzeile steht an Stelle ' . ($kopf + 1) . ' statt vorn.';
        }

        if ($fuss !== false && $fuss !== count($typen) - 1) {
            $fehler[] = $wo . ': Die Fusszeile steht an Stelle ' . ($fuss + 1) . ' statt hinten.';
        }

        // --- Abschnitt für Abschnitt ---------------------------------
        foreach (array_values($abschnitte) as $platz => $abschnitt) {
            $typ = (string) ($abschnitt['type'] ?? '');
            $id = (int) ($abschnitt['id'] ?? 0);
            $name = $wo . ' / ' . ($typ !== '' ? $typ : 'Abschnitt ' . ($platz + 1));

            $geprueft++;

            if (!Schema::exists($typ)) {
                $fehler[] = $name . ': Diesen Abschnittstyp gibt es nicht.';

                continue;
            }

            // (1) Ohne Kennung findet der Editor den Abschnitt nicht.
            if ($id <= 0) {
                $fehler[] = $name . ': Der Abschnitt hat keine Kennung – der Editor kann ihn nicht auswählen.';
            }

            // (2) Vorlage aus dem Katalog.
            $vorlage = (string) ($abschnitt['template_key'] ?? '');

            if ($vorlage === '' || !Catalog::exists($typ, $vorlage)) {
                $fehler[] = $name . ': Die Vorlage „' . $vorlage . '" steht nicht im Katalog – '
                    . '„Vorlage wechseln" hätte keine Wirkung.';

                continue;
            }

            $html = Renderer::section($abschnitt, $kontext);

            if (trim($html) === '') {
                $fehler[] = $name . ': Der Abschnitt rendert nichts.';

                continue;
            }

            if ($id > 0 && !str_contains($html, 'data-section-id="' . $id . '"')) {
                $fehler[] = $name . ': Die Kennung steht nicht im HTML – der Editor findet den Abschnitt nicht.';
            }

            // (3) Erscheint, was im Blatt steht, auch auf der Seite?
            foreach (self::unsichtbareFelder($typ, $abschnitt, $kontext) as $feld) {
                $warnungen[] = $name . ': Das Feld „' . $feld . '" erscheint nicht auf der Seite. '
                    . 'Wer es im Editor ändert, sieht keine Wirkung.';
            }

            // (4) Farben kommen aus Variablen, sonst zieht das Thema nicht nach.
            foreach (self::festeFarben($html) as $farbe) {
                $fehler[] = $name . ': Die feste Farbe ' . $farbe . ' steht im HTML. '
                    . 'Eine Änderung am Thema ginge daran vorbei.';
            }
        }

        return ['geprueft' => $geprueft, 'fehler' => $fehler, 'warnungen' => $warnungen];
    }

    /**
     * Welche Textfelder ändert man, ohne dass sich etwas ändert?
     *
     * Geprüft wird, indem in jedes Feld ein Wort gesetzt wird, das
     * sonst nirgends vorkommt, und danach im HTML danach gesucht wird.
     * Steht es nicht darin, bewirkt dieses Feld in dieser Vorlage
     * nichts – das ist keine Katastrophe (manche Vorlagen zeigen
     * absichtlich weniger), aber es gehört gesagt.
     *
     * @return array<int, string>
     */
    private static function unsichtbareFelder(string $typ, array $abschnitt, array $kontext): array
    {
        $schema = Schema::forType($typ);
        $felder = is_array($schema['fields'] ?? null) ? $schema['fields'] : [];

        $inhalt = is_array($abschnitt['content'] ?? null) ? $abschnitt['content'] : [];
        $unsichtbar = [];

        foreach ($felder as $name => $feld) {
            $art = (string) ($feld['type'] ?? '');

            // Nur einfache Texte. Bilder, Listen und Schalter zeigen
            // sich anders, und dort wäre ein Suchwort im HTML kein
            // Beweis für gar nichts.
            if (!in_array($art, ['text', 'textarea', 'richtext'], true)) {
                continue;
            }

            // Nur Felder, die tatsächlich gefüllt sind: Ein leeres Feld
            // erscheint zu Recht nicht.
            if (trim((string) ($inhalt[$name] ?? '')) === '') {
                continue;
            }

            $marke = 'ZZPRUEF' . strtoupper(substr(md5($name), 0, 6)) . 'ZZ';

            $probe = $abschnitt;
            $probe['content'] = array_merge($inhalt, [$name => $marke]);

            try {
                $html = Renderer::section($probe, $kontext);
            } catch (\Throwable) {
                continue;
            }

            if (!str_contains($html, $marke)) {
                $unsichtbar[] = $name;
            }
        }

        return $unsichtbar;
    }

    /**
     * Feste Farben im HTML.
     *
     * Sie sind der Grund, warum ein Themenwechsel manchmal die halbe
     * Seite stehen lässt: Was als #1a2b3c im style-Attribut steht,
     * kennt keine Variable und zieht nicht nach.
     *
     * @return array<int, string>
     */
    private static function festeFarben(string $html): array
    {
        if (preg_match_all('/style="([^"]*)"/i', $html, $treffer) === 0) {
            return [];
        }

        $gefunden = [];

        foreach ($treffer[1] as $stil) {
            if (preg_match_all('/#[0-9a-f]{3,8}\b|\brgba?\(|\bhsla?\(/i', $stil, $farben) > 0) {
                foreach ($farben[0] as $farbe) {
                    $gefunden[] = rtrim($farbe, '(');
                }
            }
        }

        return array_values(array_unique($gefunden));
    }
}
