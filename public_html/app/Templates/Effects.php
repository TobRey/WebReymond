<?php

declare(strict_types=1);

namespace WebAtze\Templates;

/**
 * Hintergrund, Bewegung und Parallaxe eines Abschnitts.
 *
 * Das hier ist bewusst ein Wortschatz und keine Freitextspalte.
 *
 * Der Grund: Im Editor steht ein Promptfeld, und was dort verlangt wird
 * ("mach den Hintergrund dunkler mit einem Verlauf, und die Karten
 * sollen beim Scrollen nacheinander auftauchen"), landet als Antwort
 * eines Sprachmodells in der Datenbank. Käme von dort freies CSS, könnte
 * ein einziger unglücklicher Satz eine Kundenwebsite zerlegen - und
 * niemand merkt es, bis der Kunde anruft. Ein geschlossener Wortschatz
 * kann das nicht: Was nicht in den Listen unten steht, wird verworfen.
 *
 * Deckt der Wortschatz genug ab? Er deckt ab, was tatsächlich verlangt
 * wird. Neun Hintergrundarten mal drei Töne mal drei Stärken sind 81
 * Hintergründe, dazu sechs Bewegungen und vier Parallaxenstufen. Wer
 * mehr braucht, bekommt eine neue Zeile in diesen Listen - und dann
 * gilt sie für jede Website, geprüft, statt einmalig und ungeprüft.
 *
 * Ausgegeben wird zweierlei:
 *
 *   Klassen  (bg-verlauf, bg-ton-marke, ...) für das Aussehen. Die
 *            zugehörigen Regeln stehen in Kit/site/css/layout.css und
 *            arbeiten ausschliesslich mit den Farbvariablen der Website.
 *            Deshalb passt ein Hintergrund automatisch zur Marke.
 *
 *   Attribute (data-reveal, data-parallax, data-tilt, ...) für die
 *            Bewegung. Sie werden vom Bewegungsapparat gelesen, der auf
 *            beiden Seiten derselbe ist. Es entsteht kein einziges
 *            Stück JavaScript aus einer Antwort - nur die Auswahl,
 *            welcher vorhandene Effekt greift.
 */
final class Effects
{
    /** Woraus ein Hintergrund bestehen kann. */
    public const HINTERGRUND = [
        'keine' => 'Kein eigener Hintergrund',
        'flaeche' => 'Ruhige Fläche',
        'verlauf' => 'Farbverlauf',
        'strahlen' => 'Weiches Leuchten',
        'raster' => 'Feines Raster',
        'punkte' => 'Punktmuster',
        'linien' => 'Schräge Linien',
        'wellen' => 'Welle am Rand',
        'rauschen' => 'Feines Korn',
    ];

    /** In welchem Ton. */
    public const TON = [
        'sanft' => 'Zurückhaltend',
        'hell' => 'Hell',
        'dunkel' => 'Dunkel',
        'marke' => 'Markenfarbe',
        'akzent' => 'Zweitfarbe',
    ];

    /** Wie kräftig. */
    public const STAERKE = ['zart' => 'Zart', 'normal' => 'Normal', 'kraeftig' => 'Kräftig'];

    /** Was beim Scrollen passiert. */
    public const BEWEGUNG = [
        'keine' => 'Nichts',
        'aufblenden' => 'Sanft aufblenden',
        'hoch' => 'Von unten hereinschieben',
        'nacheinander' => 'Die Teile nacheinander',
        'worte' => 'Die Überschrift Wort für Wort',
    ];

    /** Wie stark der Hintergrund beim Scrollen nachzieht. */
    public const PARALLAXE = [
        'keine' => 'Keine',
        'sanft' => 'Sanft',
        'mittel' => 'Deutlich',
        'stark' => 'Stark',
    ];

    /** Wie schnell die Parallaxe nachzieht. Der Bewegungsapparat liest data-speed. */
    private const TEMPO = ['sanft' => '0.25', 'mittel' => '0.45', 'stark' => '0.7'];

    /** Wie weit ein Knopf dem Mauszeiger folgt. */
    private const MAGNET = '0.24';

    // ------------------------------------------------------------------
    // Prüfen
    // ------------------------------------------------------------------

    /**
     * Aus beliebigen Daten das machen, was übrig bleiben darf.
     *
     * Alles, was nicht in den Listen steht, fällt weg - nicht mit einem
     * Fehler, sondern still. Ein Sprachmodell, das "supersanft" statt
     * "sanft" schreibt, soll nicht die ganze Änderung scheitern lassen;
     * es soll nur keine Parallaxe bekommen.
     *
     * @return array<string, mixed> leer, wenn nichts Gültiges übrig ist
     */
    public static function clean(mixed $roh): array
    {
        if (!is_array($roh)) {
            return [];
        }

        $sauber = [];

        $art = self::wahl($roh['hintergrund']['art'] ?? null, self::HINTERGRUND, 'keine');

        if ($art !== 'keine') {
            $sauber['hintergrund'] = [
                'art' => $art,
                'ton' => self::wahl($roh['hintergrund']['ton'] ?? null, self::TON, 'sanft'),
                'staerke' => self::wahl($roh['hintergrund']['staerke'] ?? null, self::STAERKE, 'normal'),
            ];
        }

        $bewegung = self::wahl($roh['bewegung'] ?? null, self::BEWEGUNG, 'keine');

        if ($bewegung !== 'keine') {
            $sauber['bewegung'] = $bewegung;
        }

        $parallaxe = self::wahl($roh['parallaxe'] ?? null, self::PARALLAXE, 'keine');

        if ($parallaxe !== 'keine') {
            $sauber['parallaxe'] = $parallaxe;
        }

        foreach (['kippen', 'magnet', 'zaehlen'] as $schalter) {
            if (!empty($roh[$schalter])) {
                $sauber[$schalter] = true;
            }
        }

        return $sauber;
    }

    /** Einen Wert nur durchlassen, wenn er in der Liste steht. */
    private static function wahl(mixed $wert, array $erlaubt, string $vorgabe): string
    {
        $wert = is_string($wert) ? strtolower(trim($wert)) : '';

        return isset($erlaubt[$wert]) ? $wert : $vorgabe;
    }

    // ------------------------------------------------------------------
    // Ausgeben
    // ------------------------------------------------------------------

    /** Die Klassen für den Abschnitt. */
    public static function classes(array $effects): string
    {
        $klassen = [];

        if (isset($effects['hintergrund'])) {
            $h = $effects['hintergrund'];

            $klassen[] = 'bg-' . $h['art'];
            $klassen[] = 'bg-ton-' . $h['ton'];
            $klassen[] = 'bg-' . $h['staerke'];
        }

        return implode(' ', $klassen);
    }

    /**
     * Die Attribute für den Abschnitt selbst.
     *
     * Bewegung und Parallaxe hängen am Abschnitt; was innerhalb davon
     * nacheinander erscheint, regelt itemAttributes().
     */
    public static function attributes(array $effects): string
    {
        $attribute = [];

        $bewegung = (string) ($effects['bewegung'] ?? '');

        if ($bewegung === 'aufblenden') {
            $attribute['data-reveal'] = 'fade';
        } elseif ($bewegung === 'hoch') {
            $attribute['data-reveal'] = 'up';
        } elseif ($bewegung === 'nacheinander') {
            $attribute['data-reveal-stagger'] = '';
        } elseif ($bewegung === 'worte') {
            $attribute['data-reveal'] = 'up';
            $attribute['data-words'] = '';
        }

        $parallaxe = (string) ($effects['parallaxe'] ?? '');

        if (isset(self::TEMPO[$parallaxe])) {
            $attribute['data-parallax'] = 'deep';
            $attribute['data-speed'] = self::TEMPO[$parallaxe];
        }

        if (!empty($effects['magnet'])) {
            $attribute['data-magnet-zone'] = self::MAGNET;
        }

        if (!empty($effects['zaehlen'])) {
            $attribute['data-zaehlen'] = '';
        }

        return self::schreiben($attribute);
    }

    /** Die Attribute für die einzelnen Teile innerhalb des Abschnitts. */
    public static function itemAttributes(array $effects): string
    {
        $attribute = [];

        if (!empty($effects['kippen'])) {
            $attribute['data-tilt'] = '';
        }

        return self::schreiben($attribute);
    }

    /** @param array<string, string> $attribute */
    private static function schreiben(array $attribute): string
    {
        $teile = [];

        foreach ($attribute as $name => $wert) {
            // Die Namen stammen ausschliesslich aus diesem Kopf, die
            // Werte aus den Listen oben. Trotzdem wird escaped: Diese
            // Zusage soll nicht davon abhaengen, dass jemand die Datei
            // spaeter richtig liest.
            $teile[] = $wert === ''
                ? htmlspecialchars($name, ENT_QUOTES, 'UTF-8')
                : htmlspecialchars($name, ENT_QUOTES, 'UTF-8')
                    . '="' . htmlspecialchars($wert, ENT_QUOTES, 'UTF-8') . '"';
        }

        return $teile === [] ? '' : ' ' . implode(' ', $teile);
    }

    // ------------------------------------------------------------------
    // Für die Oberfläche und für die Schnittstelle
    // ------------------------------------------------------------------

    /**
     * Der Wortschatz als Beschreibung.
     *
     * Der Editor baut daraus seine Auswahlfelder, und die Schnittstelle
     * bekommt dasselbe als Schema mit. Beides aus einer Quelle, damit
     * die Oberfläche nicht etwas anbietet, was die Prüfung verwirft.
     */
    public static function schema(): array
    {
        return [
            'hintergrund' => [
                'label' => 'Hintergrund',
                'felder' => [
                    'art' => ['label' => 'Art', 'werte' => self::HINTERGRUND],
                    'ton' => ['label' => 'Ton', 'werte' => self::TON],
                    'staerke' => ['label' => 'Stärke', 'werte' => self::STAERKE],
                ],
            ],
            'bewegung' => ['label' => 'Beim Scrollen', 'werte' => self::BEWEGUNG],
            'parallaxe' => ['label' => 'Parallaxe', 'werte' => self::PARALLAXE],
            'kippen' => ['label' => 'Karten kippen unter der Maus', 'werte' => null],
            'magnet' => ['label' => 'Schaltflächen folgen der Maus', 'werte' => null],
            'zaehlen' => ['label' => 'Zahlen zählen hoch', 'werte' => null],
        ];
    }
}
