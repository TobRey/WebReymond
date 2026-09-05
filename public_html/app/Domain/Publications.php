<?php

declare(strict_types=1);

namespace WebAtze\Domain;

use WebAtze\Core\{Db, Logger, Session};

/**
 * Entwurf und Veröffentlicht.
 *
 * Der Entwurf sind die Zeilen in project_sections. Daran wird gearbeitet,
 * und dort ändert sich beim Ziehen sofort etwas.
 *
 * Veröffentlicht ist eine Abschrift davon, die liegen bleibt. Sie ist
 * nicht die Quelle für irgendetwas – die Website wird immer aus dem
 * Entwurf gebaut – sondern das Gedächtnis: Was stand hier, bevor jemand
 * eine halbe Stunde lang umgeräumt hat?
 *
 * Zwei Dinge werden dadurch möglich, die vorher nicht gingen:
 *
 *   "Entwurf verwerfen" – zurück auf den letzten veröffentlichten Stand.
 *   Ohne das ist jeder Umbau eine Einbahnstrasse, und wer sich verrennt,
 *   baut von Hand zurück und vergisst dabei etwas.
 *
 *   "Zurück auf die Fassung von Dienstag" – für den Fall, dass etwas
 *   erst nach dem Veröffentlichen auffällt.
 *
 * Warum eine Abschrift und keine zweite Tabelle für Entwurfsabschnitte:
 * Zwei Tabellen bedeuten zwei Wahrheiten, und jede Stelle im Programm
 * müsste wissen, welche gerade gilt. Eine Abschrift ist ein Datensatz
 * ohne Anspruch – niemand rendert daraus, niemand fragt sie ab, sie
 * liegt nur da.
 */
final class Publications
{
    /** Wie viele Fassungen je Seite aufbewahrt werden. */
    private const BEHALTEN = 30;

    /**
     * Den aktuellen Stand einer Seite festhalten.
     *
     * @return int die Kennung der Fassung, 0 wenn nichts festzuhalten war
     */
    public static function record(int $pageId, string $note = ''): int
    {
        $seite = Db::first('SELECT * FROM project_pages WHERE id = :id', ['id' => $pageId]);

        if ($seite === null) {
            return 0;
        }

        $abschnitte = Sections::forPage($pageId);

        if ($abschnitte === []) {
            return 0;
        }

        $id = Db::insert('publications', [
            'project_id' => (int) $seite['project_id'],
            'page_id' => $pageId,
            'data' => self::abschrift($seite, $abschnitte),
            'note' => mb_substr(trim($note), 0, 190),
            'actor' => mb_substr((string) (Session::user()['username'] ?? 'system'), 0, 120),
            'created_at' => Db::now(),
        ]);

        self::aufraeumen($pageId);

        return $id;
    }

    /**
     * Was in eine Abschrift gehört.
     *
     * Nur das, was den Inhalt ausmacht. Zeitstempel und Kennungen der
     * Datenbank sind bewusst nicht dabei: Beim Zurückholen entstehen
     * ohnehin neue, und sie mitzuschleppen weckt den Eindruck, man könne
     * eine Fassung "wiederherstellen" statt sie neu zu schreiben.
     */
    private static function abschrift(array $seite, array $abschnitte): array
    {
        return [
            'seite' => [
                'title' => (string) $seite['title'],
                'meta_description' => (string) ($seite['meta_description'] ?? ''),
                'in_navigation' => (int) ($seite['in_navigation'] ?? 0),
                'translations' => $seite['translations'] ?? null,
            ],
            'abschnitte' => array_map(static fn (array $a): array => [
                'type' => (string) $a['type'],
                'template_key' => (string) $a['template_key'],
                'content' => $a['content'],
                'overrides' => $a['overrides'],
                'effects' => $a['effects'],
                'translations' => $a['translations'],
                'hidden' => (int) $a['hidden'],
            ], $abschnitte),
        ];
    }

    /** Die Fassungen einer Seite, neueste zuerst. */
    public static function forPage(int $pageId, int $limit = 30): array
    {
        return Db::all(
            'SELECT id, note, actor, created_at FROM publications
             WHERE page_id = :p ORDER BY id DESC LIMIT :lim',
            ['p' => $pageId, 'lim' => max(1, min(100, $limit))]
        );
    }

    /** Die neueste Fassung einer Seite. */
    public static function latest(int $pageId): ?array
    {
        return Db::first(
            'SELECT * FROM publications WHERE page_id = :p ORDER BY id DESC LIMIT 1',
            ['p' => $pageId]
        );
    }

    /**
     * Gibt es ungespeicherte Änderungen?
     *
     * Verglichen wird die Abschrift mit dem, was jetzt dasteht. Das ist
     * genauer als ein Zeitstempel: Wer etwas ändert und wieder
     * zurückändert, hat keinen Entwurf mehr – und soll dann auch keinen
     * Knopf sehen, der ihm etwas anderes sagt.
     */
    public static function hasDraft(int $pageId): bool
    {
        $letzte = self::latest($pageId);

        if ($letzte === null) {
            return true;
        }

        $seite = Db::first('SELECT * FROM project_pages WHERE id = :id', ['id' => $pageId]);

        if ($seite === null) {
            return false;
        }

        $jetzt = self::abschrift($seite, Sections::forPage($pageId));
        $damals = json_decode((string) $letzte['data'], true);

        return json_encode($jetzt) !== json_encode(is_array($damals) ? $damals : []);
    }

    /**
     * Eine Fassung zurückholen.
     *
     * Die bestehenden Abschnitte werden gelöscht und aus der Abschrift
     * neu geschrieben. Das ist ehrlicher als ein Abgleich Zeile für
     * Zeile: Ein Abgleich müsste raten, welcher Abschnitt von damals
     * welchem von heute entspricht, und läge irgendwann falsch.
     *
     * Vorher wird der aktuelle Stand festgehalten – wer sich beim
     * Zurückholen vertut, kommt zurück.
     */
    public static function restore(int $publicationId): array
    {
        $fassung = Db::first('SELECT * FROM publications WHERE id = :id', ['id' => $publicationId]);

        if ($fassung === null) {
            return ['ok' => false, 'error' => 'Diese Fassung gibt es nicht.'];
        }

        $daten = json_decode((string) $fassung['data'], true);

        if (!is_array($daten) || !isset($daten['abschnitte'])) {
            return ['ok' => false, 'error' => 'Diese Fassung lässt sich nicht lesen.'];
        }

        $pageId = (int) $fassung['page_id'];
        $projectId = (int) $fassung['project_id'];

        self::record($pageId, 'Vor dem Zurückholen');

        Db::transaction(static function () use ($daten, $pageId, $projectId): void {
            Db::delete('project_sections', 'page_id = :p', ['p' => $pageId]);

            foreach ($daten['abschnitte'] as $platz => $a) {
                Db::insert('project_sections', [
                    'project_id' => $projectId,
                    'page_id' => $pageId,
                    'type' => (string) ($a['type'] ?? 'text'),
                    'template_key' => (string) ($a['template_key'] ?? ''),
                    'content' => $a['content'] ?? [],
                    'overrides' => $a['overrides'] ?? [],
                    'effects' => $a['effects'] ?? [],
                    'translations' => $a['translations'] ?? null,
                    'hidden' => (int) ($a['hidden'] ?? 0),
                    'sort_order' => (int) $platz,
                    'created_at' => Db::now(),
                    'updated_at' => Db::now(),
                ]);
            }

            if (isset($daten['seite']) && is_array($daten['seite'])) {
                Db::update('project_pages', [
                    'title' => (string) ($daten['seite']['title'] ?? ''),
                    'meta_description' => (string) ($daten['seite']['meta_description'] ?? ''),
                    'in_navigation' => (int) ($daten['seite']['in_navigation'] ?? 0),
                    'translations' => $daten['seite']['translations'] ?? null,
                    'updated_at' => Db::now(),
                ], 'id = :id', ['id' => $pageId]);
            }
        });

        Logger::info('Fassung zurückgeholt.', ['seite' => $pageId, 'fassung' => $publicationId]);

        return ['ok' => true, 'error' => '', 'sections' => count($daten['abschnitte'])];
    }

    /** Den Entwurf verwerfen: zurück auf die letzte Veröffentlichung. */
    public static function discard(int $pageId): array
    {
        $letzte = self::latest($pageId);

        if ($letzte === null) {
            return ['ok' => false, 'error' => 'Diese Seite wurde noch nie veröffentlicht.'];
        }

        return self::restore((int) $letzte['id']);
    }

    /** Alte Fassungen wegräumen. */
    private static function aufraeumen(int $pageId): int
    {
        $behalten = Db::all(
            'SELECT id FROM publications WHERE page_id = :p ORDER BY id DESC LIMIT :lim',
            ['p' => $pageId, 'lim' => self::BEHALTEN]
        );

        if (count($behalten) < self::BEHALTEN) {
            return 0;
        }

        $aelteste = (int) end($behalten)['id'];

        return Db::delete(
            'publications',
            'page_id = :p AND id < :i',
            ['p' => $pageId, 'i' => $aelteste]
        );
    }
}
