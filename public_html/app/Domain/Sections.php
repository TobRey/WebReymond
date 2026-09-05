<?php

declare(strict_types=1);

namespace WebAtze\Domain;

use WebAtze\Core\{Db, Logger};
use WebAtze\Templates\{Blocks, Catalog, Effects, Schema};

/**
 * Was der Editor mit Abschnitten machen darf.
 *
 * Bisher gab es dafür nur "tausche mit dem Nachbarn" – gebaut für zwei
 * Knöpfe, nicht für eine Maus, die einen Abschnitt über fünf andere
 * hinwegzieht. Fünf Tauschvorgänge hintereinander wären fünf Anfragen,
 * fünf Zwischenstände in der Datenbank und ein Zustand, in dem die Seite
 * halb umsortiert ist, falls einer davon abbricht.
 *
 * Deshalb steht hier "setze diesen Abschnitt auf Platz N" als eine
 * einzige Anweisung, in einer einzigen Transaktion.
 *
 * Zwei Zusagen gelten in dieser ganzen Klasse:
 *
 *   Kopf und Fuss bleiben, wo sie sind. Ein Kopf in der Mitte einer
 *   Seite ist kein Gestaltungsmittel, sondern ein Versehen.
 *
 *   Die Sortierung ist nach jedem Eingriff wieder lückenlos 0..n-1.
 *   Sonst zeigen die Zahlen irgendwann in eine Vergangenheit, die
 *   niemand mehr rekonstruieren kann.
 */
final class Sections
{
    // ------------------------------------------------------------------
    // Lesen
    // ------------------------------------------------------------------

    public static function find(int $id): ?array
    {
        $zeile = Db::first('SELECT * FROM project_sections WHERE id = :id', ['id' => $id]);

        return $zeile === null ? null : self::decode($zeile);
    }

    /** Alle Abschnitte einer Seite, in ihrer Reihenfolge. */
    public static function forPage(int $pageId): array
    {
        $zeilen = Db::all(
            'SELECT * FROM project_sections WHERE page_id = :p ORDER BY sort_order ASC, id ASC',
            ['p' => $pageId]
        );

        return array_map([self::class, 'decode'], $zeilen);
    }

    /** Die JSON-Spalten aufmachen. */
    private static function decode(array $zeile): array
    {
        foreach (['content', 'overrides', 'effects', 'translations'] as $feld) {
            $wert = $zeile[$feld] ?? null;

            $zeile[$feld] = is_string($wert) && $wert !== ''
                ? (json_decode($wert, true) ?: [])
                : (is_array($wert) ? $wert : []);
        }

        return $zeile;
    }

    // ------------------------------------------------------------------
    // Verschieben
    // ------------------------------------------------------------------

    /**
     * Einen Abschnitt auf einen bestimmten Platz setzen.
     *
     * @return array{ok:bool, error:string, order:list<int>}
     */
    public static function move(int $id, int $ziel): array
    {
        $abschnitt = self::find($id);

        if ($abschnitt === null) {
            return self::fehler('Diesen Abschnitt gibt es nicht.');
        }

        $geschwister = self::forPage((int) $abschnitt['page_id']);
        $anzahl = count($geschwister);

        $von = null;

        foreach ($geschwister as $platz => $zeile) {
            if ((int) $zeile['id'] === $id) {
                $von = $platz;
                break;
            }
        }

        if ($von === null) {
            return self::fehler('Der Abschnitt gehört nicht auf diese Seite.');
        }

        [$erster, $letzter] = self::beweglich($geschwister);

        if ($von < $erster || $von > $letzter) {
            return self::fehler('Kopf und Fuss bleiben, wo sie sind.');
        }

        // Ein Ziel ausserhalb des beweglichen Bereichs ist keine
        // Fehleingabe, sondern das Ende der Liste: Wer einen Abschnitt
        // ganz nach unten zieht, meint "so weit wie möglich".
        $ziel = max($erster, min($letzter, $ziel));

        if ($ziel === $von) {
            return ['ok' => true, 'error' => '', 'order' => self::ids($geschwister)];
        }

        $bewegt = $geschwister[$von];
        array_splice($geschwister, $von, 1);
        array_splice($geschwister, $ziel, 0, [$bewegt]);

        self::speichern($geschwister);

        return ['ok' => true, 'error' => '', 'order' => self::ids($geschwister)];
    }

    /**
     * Welche Plätze beweglich sind.
     *
     * @return array{0:int, 1:int} erster und letzter beweglicher Platz
     */
    private static function beweglich(array $geschwister): array
    {
        $erster = 0;
        $letzter = count($geschwister) - 1;

        if (($geschwister[0]['type'] ?? '') === 'header') {
            $erster = 1;
        }

        if ($letzter >= 0 && ($geschwister[$letzter]['type'] ?? '') === 'footer') {
            $letzter--;
        }

        return [$erster, max($erster, $letzter)];
    }

    /** Die Reihenfolge lückenlos in die Datenbank schreiben. */
    private static function speichern(array $geschwister): void
    {
        Db::transaction(static function () use ($geschwister): void {
            foreach ($geschwister as $platz => $zeile) {
                Db::update(
                    'project_sections',
                    ['sort_order' => $platz, 'updated_at' => Db::now()],
                    'id = :id',
                    ['id' => (int) $zeile['id']]
                );
            }
        });
    }

    /** @return list<int> */
    private static function ids(array $geschwister): array
    {
        return array_map(static fn (array $z): int => (int) $z['id'], $geschwister);
    }

    // ------------------------------------------------------------------
    // Anlegen und löschen
    // ------------------------------------------------------------------

    /**
     * Einen neuen Abschnitt einsetzen.
     *
     * Er kommt mit Beispielinhalt, nicht leer. Ein leerer Abschnitt sieht
     * im Editor aus wie ein Fehler, und wer ihn füllen will, muss erst
     * raten, was hineingehört.
     *
     * @return array{ok:bool, error:string, id:int}
     */
    public static function add(int $pageId, string $typ, ?int $ziel = null, string $variante = ''): array
    {
        if (!Schema::exists($typ)) {
            return self::fehler('Diesen Abschnittstyp gibt es nicht.') + ['id' => 0];
        }

        // Kopf und Fuss gibt es genau einmal je Seite.
        if (in_array($typ, ['header', 'footer'], true)) {
            $schon = Db::value(
                'SELECT id FROM project_sections WHERE page_id = :p AND type = :t',
                ['p' => $pageId, 't' => $typ]
            );

            if ($schon !== null) {
                return self::fehler('Kopf und Fuss gibt es nur einmal.') + ['id' => 0];
            }
        }

        $seite = Db::first('SELECT * FROM project_pages WHERE id = :id', ['id' => $pageId]);

        if ($seite === null) {
            return self::fehler('Diese Seite gibt es nicht.') + ['id' => 0];
        }

        if (!Catalog::exists($typ, $variante)) {
            $variante = Catalog::defaultKey($typ);
        }

        $id = Db::insert('project_sections', [
            'project_id' => (int) $seite['project_id'],
            'page_id' => $pageId,
            'type' => $typ,
            'template_key' => $variante,
            'content' => self::beispiel($typ),
            'overrides' => [],
            'effects' => [],
            'hidden' => 0,
            'sort_order' => 9999,
            'created_at' => Db::now(),
            'updated_at' => Db::now(),
        ]);

        $geschwister = self::forPage($pageId);

        if ($ziel !== null) {
            self::move($id, $ziel);
        } else {
            self::speichern($geschwister);
        }

        return ['ok' => true, 'error' => '', 'id' => $id];
    }

    /** Einen Abschnitt entfernen. */
    public static function remove(int $id): array
    {
        $abschnitt = self::find($id);

        if ($abschnitt === null) {
            return self::fehler('Diesen Abschnitt gibt es nicht.');
        }

        if (in_array((string) $abschnitt['type'], ['header', 'footer'], true)) {
            return self::fehler('Kopf und Fuss lassen sich nicht löschen, nur ausblenden.');
        }

        Db::delete('project_sections', 'id = :id', ['id' => $id]);

        $geschwister = self::forPage((int) $abschnitt['page_id']);
        self::speichern($geschwister);

        return ['ok' => true, 'error' => '', 'order' => self::ids($geschwister)];
    }

    /** Einen Abschnitt verdoppeln – meist schneller als neu aufbauen. */
    public static function duplicate(int $id): array
    {
        $abschnitt = self::find($id);

        if ($abschnitt === null) {
            return self::fehler('Diesen Abschnitt gibt es nicht.') + ['id' => 0];
        }

        if (in_array((string) $abschnitt['type'], ['header', 'footer'], true)) {
            return self::fehler('Kopf und Fuss gibt es nur einmal.') + ['id' => 0];
        }

        $neu = Db::insert('project_sections', [
            'project_id' => (int) $abschnitt['project_id'],
            'page_id' => (int) $abschnitt['page_id'],
            'type' => (string) $abschnitt['type'],
            'template_key' => (string) $abschnitt['template_key'],
            'content' => $abschnitt['content'],
            'overrides' => $abschnitt['overrides'],
            'effects' => $abschnitt['effects'],
            'translations' => $abschnitt['translations'],
            'hidden' => (int) $abschnitt['hidden'],
            'sort_order' => 9999,
            'created_at' => Db::now(),
            'updated_at' => Db::now(),
        ]);

        self::move($neu, (int) $abschnitt['sort_order'] + 1);

        return ['ok' => true, 'error' => '', 'id' => $neu];
    }

    // ------------------------------------------------------------------
    // Listen innerhalb eines Abschnitts
    // ------------------------------------------------------------------

    /**
     * Einen Listeneintrag verschieben.
     *
     * Die Listen sind das, was in einem Abschnitt tatsächlich mehrfach
     * vorkommt: Karten, Punkte, Bilder, Teammitglieder, Fragen. Sie zu
     * ziehen ist das, was jemand meint, wenn er "die Reihenfolge der
     * Leistungen ändern" sagt.
     */
    public static function moveItem(int $id, int $von, int $nach, string $liste = 'items'): array
    {
        return self::mitListe($id, $liste, static function (array $eintraege) use ($von, $nach): array {
            $anzahl = count($eintraege);

            if ($von < 0 || $von >= $anzahl) {
                return $eintraege;
            }

            $nach = max(0, min($anzahl - 1, $nach));

            $bewegt = $eintraege[$von];
            array_splice($eintraege, $von, 1);
            array_splice($eintraege, $nach, 0, [$bewegt]);

            return $eintraege;
        });
    }

    /** Einen Listeneintrag anlegen. */
    public static function addItem(int $id, ?int $nach = null, string $liste = 'items'): array
    {
        $abschnitt = self::find($id);

        if ($abschnitt === null) {
            return self::fehler('Diesen Abschnitt gibt es nicht.');
        }

        $feld = Schema::forType((string) $abschnitt['type'])['fields'][$liste] ?? null;

        if (!is_array($feld) || ($feld['type'] ?? '') !== 'list') {
            return self::fehler('Dieser Abschnitt hat keine solche Liste.');
        }

        $vorlage = self::leererEintrag($feld['item'] ?? []);
        $hoechstens = (int) ($feld['max'] ?? 0);

        return self::mitListe(
            $id,
            $liste,
            static function (array $eintraege) use ($vorlage, $nach, $hoechstens): array {
                if ($hoechstens > 0 && count($eintraege) >= $hoechstens) {
                    return $eintraege;
                }

                $platz = $nach === null ? count($eintraege) : max(0, min(count($eintraege), $nach + 1));

                array_splice($eintraege, $platz, 0, [$vorlage]);

                return $eintraege;
            }
        );
    }

    /** Einen Listeneintrag löschen – aber nie unter das Mindestmass. */
    public static function removeItem(int $id, int $platz, string $liste = 'items'): array
    {
        $abschnitt = self::find($id);

        if ($abschnitt === null) {
            return self::fehler('Diesen Abschnitt gibt es nicht.');
        }

        $feld = Schema::forType((string) $abschnitt['type'])['fields'][$liste] ?? null;
        $mindestens = (int) (is_array($feld) ? ($feld['min'] ?? 0) : 0);

        $vorher = count(is_array($abschnitt['content'][$liste] ?? null) ? $abschnitt['content'][$liste] : []);

        if ($vorher <= $mindestens) {
            return self::fehler(
                $mindestens === 1
                    ? 'Ein Eintrag muss bleiben.'
                    : 'Mindestens ' . $mindestens . ' Einträge müssen bleiben.'
            );
        }

        return self::mitListe($id, $liste, static function (array $eintraege) use ($platz): array {
            if ($platz >= 0 && $platz < count($eintraege)) {
                array_splice($eintraege, $platz, 1);
            }

            return $eintraege;
        });
    }

    /** Eine Liste holen, verändern lassen, zurückschreiben. */
    private static function mitListe(int $id, string $liste, callable $aendern): array
    {
        $abschnitt = self::find($id);

        if ($abschnitt === null) {
            return self::fehler('Diesen Abschnitt gibt es nicht.');
        }

        $inhalt = $abschnitt['content'];
        $eintraege = is_array($inhalt[$liste] ?? null) ? array_values($inhalt[$liste]) : [];

        $inhalt[$liste] = array_values($aendern($eintraege));

        Db::update(
            'project_sections',
            ['content' => $inhalt, 'updated_at' => Db::now()],
            'id = :id',
            ['id' => $id]
        );

        return ['ok' => true, 'error' => '', 'count' => count($inhalt[$liste])];
    }

    /** Ein leerer Eintrag, der zum Schema der Liste passt. */
    private static function leererEintrag(array $felder): array
    {
        $eintrag = [];

        foreach ($felder as $name => $feld) {
            $eintrag[$name] = match ((string) ($feld['type'] ?? 'text')) {
                'list' => [],
                'flag' => (bool) ($feld['default'] ?? false),
                'number' => (int) ($feld['min'] ?? 0),
                'image' => null,
                'link' => ['label' => '', 'url' => ''],
                default => '',
            };
        }

        return $eintrag;
    }

    // ------------------------------------------------------------------
    // Effekte
    // ------------------------------------------------------------------

    /** Hintergrund, Bewegung und Parallaxe eines Abschnitts setzen. */
    public static function setEffects(int $id, mixed $roh): array
    {
        if (self::find($id) === null) {
            return self::fehler('Diesen Abschnitt gibt es nicht.');
        }

        $sauber = Effects::clean($roh);

        Db::update(
            'project_sections',
            ['effects' => $sauber, 'updated_at' => Db::now()],
            'id = :id',
            ['id' => $id]
        );

        return ['ok' => true, 'error' => '', 'effects' => $sauber];
    }

    // ------------------------------------------------------------------

    /**
     * Beispielinhalt für einen frisch eingesetzten Abschnitt.
     *
     * Bewusst konkret statt "Lorem ipsum": Wer sieht, wie der Abschnitt
     * mit echtem Text aussieht, weiss sofort, ob er der richtige ist.
     */
    private static function beispiel(string $typ): array
    {
        if ($typ === 'frei') {
            return ['blocks' => Blocks::beispiel()];
        }

        $basis = [
            'eyebrow' => '',
            'title' => 'Neue Überschrift',
            'lead' => 'Hier steht, worum es in diesem Abschnitt geht.',
        ];

        $felder = Schema::forType($typ)['fields'] ?? [];
        $inhalt = [];

        foreach ($felder as $name => $feld) {
            if (($feld['type'] ?? '') === 'list') {
                $wieviele = max(1, (int) ($feld['min'] ?? 1));
                $eintraege = [];

                for ($i = 0; $i < $wieviele; $i++) {
                    $eintrag = self::leererEintrag($feld['item'] ?? []);

                    foreach (['title', 'name', 'question', 'label'] as $kopf) {
                        if (array_key_exists($kopf, $eintrag)) {
                            $eintrag[$kopf] = 'Eintrag ' . ($i + 1);
                            break;
                        }
                    }

                    $eintraege[] = $eintrag;
                }

                $inhalt[$name] = $eintraege;
                continue;
            }

            if (isset($basis[$name])) {
                $inhalt[$name] = $basis[$name];
            }
        }

        return $inhalt;
    }

    /** @return array{ok:bool, error:string} */
    private static function fehler(string $text): array
    {
        return ['ok' => false, 'error' => $text];
    }
}
