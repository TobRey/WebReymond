<?php

declare(strict_types=1);

namespace WebAtze\Http;

use WebAtze\Build\{SiteBuilder, Theme};
use WebAtze\Core\{Db, Request, Response};

/**
 * Das Stylesheet der eigenen Website, aus PHP.
 *
 * Bisher steckte das Aussehen der eigenen Website im gebauten Paket -
 * eine Änderung daran brauchte Node, einen Build und eine Auslieferung.
 * Auf gemietetem Hosting läuft davon nichts.
 *
 * Wird die eigene Website aus Abschnittsdaten gebaut, geht das nicht
 * mehr: Ein neuer Abschnittstyp braucht neue Regeln, und die müssten
 * sonst jedes Mal durch einen Build. Deshalb entsteht das Stylesheet
 * hier zur Laufzeit aus drei Teilen:
 *
 *   1. die Farben und Schriften der Website (Theme)
 *   2. der Grundstil, den jede Website bekommt (Kit/site/css)
 *   3. das Stilpaket, das den Unterschied macht (Kit/site/css/stil)
 *
 * Alle drei sind Dateien im Auslieferungspaket. Ein neuer Abschnittstyp
 * heisst damit: Vorlage, Schema, Katalog, CSS - und kein einziger
 * Node-Aufruf.
 *
 * Das Ergebnis wird zwischengespeichert und mit seiner Prüfsumme in der
 * Adresse ausgeliefert. Deshalb darf der Browser es ein Jahr behalten:
 * Eine neue Fassung hat eine neue Adresse.
 */
final class StyleController
{
    /** Die Teile des Grundstils, in dieser Reihenfolge. */
    private const GRUND = ['tokens', 'base', 'layout', 'sections', 'motion'];

    public function stylesheet(Request $request): Response
    {
        $projekt = self::eigenes();

        return Response::make(self::css($projekt))
            ->header('Content-Type', 'text/css; charset=utf-8')
            // Ein Jahr, weil die Prüfsumme in der Adresse steht. Ohne sie
            // wäre das falsch: Dann sähe jemand nach einer Änderung
            // wochenlang die alte Fassung und niemand wüsste warum.
            ->header('Cache-Control', 'public, max-age=31536000, immutable');
    }

    /**
     * Das fertige Stylesheet.
     *
     * @param array|null $projekt die eigene Website, falls es sie gibt
     */
    public static function css(?array $projekt): string
    {
        $datei = self::datei($projekt);

        if (is_file($datei)) {
            return (string) file_get_contents($datei);
        }

        $teile = [];

        $thema = is_array($projekt['theme'] ?? null) ? $projekt['theme'] : [];

        if ($thema !== []) {
            $teile[] = Theme::toCss($thema);
        }

        foreach (self::GRUND as $name) {
            $pfad = APP_DIR . '/Kit/site/css/' . $name . '.css';

            if (is_file($pfad)) {
                $inhalt = (string) file_get_contents($pfad);

                // Die Standardfarben aus tokens.css fallen weg, wenn das
                // Thema eigene mitbringt - sonst stünden zwei Sätze
                // Farben da, und der zweite gewinnt aus Versehen.
                $teile[] = $name === 'tokens' && $thema !== []
                    ? SiteBuilder::stripDefaultColours($inhalt)
                    : $inhalt;
            }
        }

        $paket = self::paket($projekt);

        if ($paket !== 'basis') {
            $pfad = APP_DIR . '/Kit/site/css/stil/' . $paket . '.css';

            if (is_file($pfad)) {
                $teile[] = (string) file_get_contents($pfad);
            }
        }

        $css = SiteBuilder::minifyCss(implode("\n", $teile));

        @mkdir(dirname($datei), 0755, true);
        @file_put_contents($datei, $css);

        return $css;
    }

    /**
     * Die Prüfsumme des Stylesheets.
     *
     * Sie hängt an allem, was hineinfliesst: dem Thema, dem Stilpaket
     * und den Dateien selbst. Ändert sich eine Datei durch eine
     * Aktualisierung, ändert sich die Adresse - ohne dass jemand daran
     * denken müsste.
     */
    public static function version(?array $projekt): string
    {
        $material = [
            json_encode($projekt['theme'] ?? []),
            self::paket($projekt),
        ];

        foreach (self::GRUND as $name) {
            $pfad = APP_DIR . '/Kit/site/css/' . $name . '.css';
            $material[] = is_file($pfad) ? (string) filemtime($pfad) : '';
        }

        $paket = APP_DIR . '/Kit/site/css/stil/' . self::paket($projekt) . '.css';
        $material[] = is_file($paket) ? (string) filemtime($paket) : '';

        return substr(hash('sha256', implode('|', $material)), 0, 12);
    }

    /** Die Adresse mit Prüfsumme. */
    public static function url(?array $projekt = null): string
    {
        $projekt ??= self::eigenes();

        return '/site.css?v=' . self::version($projekt);
    }

    /** Wo die zwischengespeicherte Fassung liegt. */
    private static function datei(?array $projekt): string
    {
        return STORAGE_DIR . '/cache/site-' . self::version($projekt) . '.css';
    }

    private static function paket(?array $projekt): string
    {
        $name = (string) ($projekt['style_pack'] ?? 'basis');

        // Nur Buchstaben, Ziffern und Bindestrich: Der Name wird zu
        // einem Dateipfad, und ein Pfad aus der Datenbank ist trotzdem
        // ein Pfad.
        return preg_match('/^[a-z0-9-]{1,40}$/', $name) === 1 ? $name : 'basis';
    }

    /**
     * Die eigene Website als Projektzeile.
     *
     * Sie hat source = 'eigen' und ist die einzige ihrer Art. Gibt es
     * sie noch nicht, ist das kein Fehler: Dann liefert diese
     * Schnittstelle den Grundstil, und die Website läuft wie bisher aus
     * dem gebauten Paket.
     */
    public static function eigenes(): ?array
    {
        $zeile = Db::first("SELECT * FROM projects WHERE source = 'eigen' LIMIT 1");

        if ($zeile === null) {
            return null;
        }

        foreach (['theme', 'chrome', 'brief'] as $feld) {
            $wert = $zeile[$feld] ?? null;

            if (is_string($wert) && $wert !== '') {
                $zeile[$feld] = json_decode($wert, true) ?: [];
            }
        }

        return $zeile;
    }
}
