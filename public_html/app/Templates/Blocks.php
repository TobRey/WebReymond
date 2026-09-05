<?php

declare(strict_types=1);

namespace WebAtze\Templates;

/**
 * Die Bausteine des freien Abschnitts.
 *
 * Die sechzehn geprüften Abschnittstypen haben einen festen Bauplan:
 * Überzeile, Überschrift, Einleitung, dann die Liste. Das ist der Grund,
 * warum jede der 320 Varianten auf einem Telefon verlässlich funktioniert
 * – niemand kann dort etwas so anordnen, dass es unterwegs auseinander
 * fällt.
 *
 * Manchmal will man aber genau das: eine leere Fläche und selbst
 * entscheiden, was wohin kommt. Dafür gibt es den Abschnittstyp "frei".
 *
 * Frei heisst hier nicht beliebig. Es gibt acht Bausteine, und einer
 * davon nimmt andere auf (Spalten), genau eine Ebene tief. Der Grund für
 * die eine Ebene ist nicht Bequemlichkeit: Spalten in Spalten in Spalten
 * sind auf einem Telefon nicht mehr sinnvoll aufzulösen, und was sich
 * nicht auflösen lässt, wird dort zu einer Textwurst. Eine Ebene lässt
 * sich immer auflösen – die Spalten rutschen untereinander, fertig.
 *
 * Deshalb gibt es hier auch keine Pixelangaben und keine freie
 * Positionierung. Wer ein Element auf Position 340/120 legt, hat es auf
 * einem Bildschirm richtig und auf allen anderen falsch.
 */
final class Blocks
{
    /** Wie viele Bausteine ein freier Abschnitt höchstens trägt. */
    public const MAX = 40;

    /** Wie viele Spalten nebeneinander stehen dürfen. */
    private const SPALTEN = [2, 3, 4];

    /** Wie gross ein Abstand sein kann. */
    private const ABSTAENDE = ['klein', 'mittel', 'gross'];

    /** Wie breit ein Baustein sein darf. */
    private const BREITEN = ['schmal', 'normal', 'breit', 'voll'];

    /** Wie ausgerichtet. */
    private const AUSRICHTUNG = ['links', 'mitte', 'rechts'];

    /**
     * Welche Bausteine es gibt und was sie mitbringen.
     *
     * Diese Beschreibung ist wie Templates\Schema die einzige Quelle: Der
     * Editor baut daraus seine Felder, die Prüfung unten arbeitet dagegen,
     * und die Schnittstelle bekommt sie als Schema mit. Drei Kopien
     * derselben Liste wären drei Gelegenheiten, dass sie auseinanderlaufen.
     */
    public static function arten(): array
    {
        return [
            'ueberschrift' => [
                'label' => 'Überschrift',
                'felder' => [
                    'text' => ['type' => 'text', 'label' => 'Text', 'max' => 160],
                    'ebene' => ['type' => 'wahl', 'label' => 'Ebene', 'werte' => ['h2', 'h3', 'h4']],
                    'ausrichtung' => ['type' => 'wahl', 'label' => 'Ausrichtung', 'werte' => self::AUSRICHTUNG],
                ],
            ],
            'text' => [
                'label' => 'Text',
                'felder' => [
                    'text' => ['type' => 'richtext', 'label' => 'Text', 'max' => 4000],
                    'ausrichtung' => ['type' => 'wahl', 'label' => 'Ausrichtung', 'werte' => self::AUSRICHTUNG],
                ],
            ],
            'bild' => [
                'label' => 'Bild',
                'felder' => [
                    'bild' => ['type' => 'image', 'label' => 'Bild'],
                    'bildunterschrift' => ['type' => 'text', 'label' => 'Bildunterschrift', 'max' => 200],
                    'breite' => ['type' => 'wahl', 'label' => 'Breite', 'werte' => self::BREITEN],
                ],
            ],
            'knopf' => [
                'label' => 'Schaltfläche',
                'felder' => [
                    'link' => ['type' => 'link', 'label' => 'Ziel'],
                    'stil' => ['type' => 'wahl', 'label' => 'Stil', 'werte' => ['voll', 'umriss', 'schlicht']],
                    'ausrichtung' => ['type' => 'wahl', 'label' => 'Ausrichtung', 'werte' => self::AUSRICHTUNG],
                ],
            ],
            'symbol' => [
                'label' => 'Symbol',
                'felder' => [
                    'name' => ['type' => 'icon', 'label' => 'Symbol'],
                    'ausrichtung' => ['type' => 'wahl', 'label' => 'Ausrichtung', 'werte' => self::AUSRICHTUNG],
                ],
            ],
            'abstand' => [
                'label' => 'Abstand',
                'felder' => [
                    'groesse' => ['type' => 'wahl', 'label' => 'Grösse', 'werte' => self::ABSTAENDE],
                ],
            ],
            'linie' => [
                'label' => 'Trennlinie',
                'felder' => [
                    'breite' => ['type' => 'wahl', 'label' => 'Breite', 'werte' => self::BREITEN],
                ],
            ],
            'spalten' => [
                'label' => 'Spalten',
                'nimmtAuf' => true,
                'felder' => [
                    'anzahl' => ['type' => 'wahl', 'label' => 'Wie viele', 'werte' => self::SPALTEN],
                ],
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Prüfen
    // ------------------------------------------------------------------

    /**
     * Aus beliebigen Daten die Bausteine machen, die übrig bleiben dürfen.
     *
     * @param bool $innen true, wenn wir uns bereits in einer Spalte
     *                    befinden – dann sind weitere Spalten nicht mehr
     *                    erlaubt, und genau das hält die eine Ebene ein.
     */
    public static function clean(mixed $roh, bool $innen = false): array
    {
        if (!is_array($roh)) {
            return [];
        }

        $arten = self::arten();
        $sauber = [];

        foreach ($roh as $block) {
            if (count($sauber) >= self::MAX) {
                break;
            }

            if (!is_array($block)) {
                continue;
            }

            $art = (string) ($block['art'] ?? '');

            if (!isset($arten[$art])) {
                continue;
            }

            if ($art === 'spalten' && $innen) {
                continue;
            }

            $eintrag = ['art' => $art];

            foreach ($arten[$art]['felder'] as $name => $feld) {
                $eintrag[$name] = self::feld($feld, $block[$name] ?? null);
            }

            if ($art === 'spalten') {
                $eintrag['spalten'] = self::spalten($block['spalten'] ?? [], (int) $eintrag['anzahl']);
            }

            $sauber[] = $eintrag;
        }

        return $sauber;
    }

    /** Die Inhalte der einzelnen Spalten. */
    private static function spalten(mixed $roh, int $anzahl): array
    {
        $spalten = [];

        for ($i = 0; $i < $anzahl; $i++) {
            $spalten[] = self::clean(is_array($roh) ? ($roh[$i] ?? []) : [], true);
        }

        return $spalten;
    }

    /** Einen einzelnen Wert auf sein Feld zurechtstutzen. */
    private static function feld(array $feld, mixed $wert): mixed
    {
        return match ((string) $feld['type']) {
            'wahl' => self::wahl($wert, $feld['werte']),
            'text' => mb_substr(trim((string) (is_scalar($wert) ? $wert : '')), 0, (int) $feld['max']),
            'richtext' => mb_substr(
                Renderer::richtext((string) (is_scalar($wert) ? $wert : '')),
                0,
                (int) $feld['max']
            ),
            'icon' => Icons::exists((string) (is_scalar($wert) ? $wert : '')) ? (string) $wert : '',
            'image' => self::bild($wert),
            'link' => self::link($wert),
            default => '',
        };
    }

    /** Ein Wert aus einer Liste, sonst der erste Eintrag als Vorgabe. */
    private static function wahl(mixed $wert, array $erlaubt): mixed
    {
        foreach ($erlaubt as $moeglich) {
            if ((string) $wert === (string) $moeglich) {
                return $moeglich;
            }
        }

        return $erlaubt[0];
    }

    private static function bild(mixed $wert): ?array
    {
        if (!is_array($wert) || trim((string) ($wert['src'] ?? '')) === '') {
            return null;
        }

        return [
            'src' => Renderer::safeUrl((string) $wert['src']),
            'alt' => mb_substr(trim((string) ($wert['alt'] ?? '')), 0, 200),
            'width' => max(0, (int) ($wert['width'] ?? 0)),
            'height' => max(0, (int) ($wert['height'] ?? 0)),
        ];
    }

    private static function link(mixed $wert): array
    {
        if (!is_array($wert)) {
            return ['label' => '', 'url' => ''];
        }

        return [
            'label' => mb_substr(trim((string) ($wert['label'] ?? '')), 0, 80),
            'url' => Renderer::safeUrl((string) ($wert['url'] ?? '')),
        ];
    }

    // ------------------------------------------------------------------
    // Ausgeben
    // ------------------------------------------------------------------

    /** Alle Bausteine als HTML. */
    public static function render(array $blocks, int $tiefe = 0): string
    {
        $html = '';

        foreach ($blocks as $platz => $block) {
            $html .= self::einer(is_array($block) ? $block : [], $platz, $tiefe);
        }

        return $html;
    }

    private static function einer(array $block, int $platz, int $tiefe): string
    {
        $art = (string) ($block['art'] ?? '');

        // Die Kennung macht den Baustein im Editor greifbar. Sie steht
        // hier und nicht nur im Editor, weil der Editor die fertige
        // Seite bearbeitet - er sieht nur, was wirklich ausgeliefert wird.
        $marke = ' data-block="' . e($art) . '" data-block-index="' . (int) $platz . '"';

        return match ($art) {
            'ueberschrift' => self::ueberschrift($block, $marke),
            'text' => '<div class="b-text ' . self::ausrichtung($block) . '"' . $marke . '>'
                . $block['text'] . '</div>',
            'bild' => self::bildBaustein($block, $marke),
            'knopf' => self::knopf($block, $marke),
            'symbol' => '<div class="b-symbol ' . self::ausrichtung($block) . '"' . $marke . '>'
                . Renderer::icon((string) ($block['name'] ?? ''), 's-ico') . '</div>',
            'abstand' => '<div class="b-abstand b-abstand--' . e((string) ($block['groesse'] ?? 'mittel'))
                . '"' . $marke . ' aria-hidden="true"></div>',
            'linie' => '<hr class="b-linie b-breite--' . e((string) ($block['breite'] ?? 'normal'))
                . '"' . $marke . '>',
            'spalten' => self::spaltenBaustein($block, $marke, $tiefe),
            default => '',
        };
    }

    private static function ueberschrift(array $block, string $marke): string
    {
        $text = (string) ($block['text'] ?? '');

        if ($text === '') {
            return '';
        }

        $ebene = in_array((string) ($block['ebene'] ?? ''), ['h2', 'h3', 'h4'], true)
            ? (string) $block['ebene']
            : 'h2';

        return '<' . $ebene . ' class="b-ueberschrift s-title ' . self::ausrichtung($block) . '"'
            . $marke . '>' . e($text) . '</' . $ebene . '>';
    }

    private static function bildBaustein(array $block, string $marke): string
    {
        $bild = $block['bild'] ?? null;

        $html = '<figure class="b-bild b-breite--' . e((string) ($block['breite'] ?? 'normal'))
            . '"' . $marke . '>';

        $html .= is_array($bild)
            ? Renderer::image($bild, 's-img')
            : Renderer::placeholder('s-img');

        $unterschrift = (string) ($block['bildunterschrift'] ?? '');

        if ($unterschrift !== '') {
            $html .= '<figcaption class="b-bild__text">' . e($unterschrift) . '</figcaption>';
        }

        return $html . '</figure>';
    }

    private static function knopf(array $block, string $marke): string
    {
        $link = is_array($block['link'] ?? null) ? $block['link'] : [];

        if (trim((string) ($link['label'] ?? '')) === '') {
            return '';
        }

        $stil = match ((string) ($block['stil'] ?? 'voll')) {
            'umriss' => 's-btn s-btn--ghost',
            'schlicht' => 's-link',
            default => 's-btn',
        };

        return '<div class="b-knopf ' . self::ausrichtung($block) . '"' . $marke . '>'
            . Renderer::link($link, $stil) . '</div>';
    }

    private static function spaltenBaustein(array $block, string $marke, int $tiefe): string
    {
        // Mehr als eine Ebene gibt es nicht. Käme sie doch aus alten
        // Daten, wird sie hier flach ausgegeben statt verschachtelt -
        // lieber untereinander als unlesbar.
        if ($tiefe > 0) {
            return self::render(
                array_merge(...array_map(
                    static fn (mixed $s): array => is_array($s) ? $s : [],
                    is_array($block['spalten'] ?? null) ? $block['spalten'] : []
                )),
                $tiefe
            );
        }

        $anzahl = (int) ($block['anzahl'] ?? 2);
        $anzahl = in_array($anzahl, self::SPALTEN, true) ? $anzahl : 2;

        $html = '<div class="b-spalten" style="--b-cols: ' . $anzahl . '"' . $marke . '>';

        foreach ((array) ($block['spalten'] ?? []) as $nummer => $inhalt) {
            $html .= '<div class="b-spalte" data-block-column="' . (int) $nummer . '">'
                . self::render(is_array($inhalt) ? $inhalt : [], $tiefe + 1)
                . '</div>';
        }

        return $html . '</div>';
    }

    private static function ausrichtung(array $block): string
    {
        $wert = (string) ($block['ausrichtung'] ?? 'links');

        return in_array($wert, self::AUSRICHTUNG, true) ? 'b-' . $wert : 'b-links';
    }

    // ------------------------------------------------------------------

    /** Womit ein frisch eingesetzter freier Abschnitt anfängt. */
    public static function beispiel(): array
    {
        return [
            [
                'art' => 'ueberschrift',
                'text' => 'Freier Abschnitt',
                'ebene' => 'h2',
                'ausrichtung' => 'links',
            ],
            [
                'art' => 'text',
                'text' => '<p>Zieh Bausteine hierher: Überschrift, Text, Bild, Schaltfläche, '
                    . 'Symbol, Abstand, Trennlinie oder Spalten.</p>',
                'ausrichtung' => 'links',
            ],
        ];
    }
}
