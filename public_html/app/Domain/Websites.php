<?php

declare(strict_types=1);

namespace WebAtze\Domain;

use WebAtze\Core\{Db, Logger};

/**
 * Alle Websites – die selbst gebauten und die hinzugefügten.
 *
 * Warum dieselbe Tabelle wie für die eigenen Projekte und nicht eine
 * zweite: Wer alle seine Websites sehen will, will eine Liste und nicht
 * zwei, die er im Kopf zusammenfügt. Eine Website, die WebAtze nicht
 * gebaut hat, wird trotzdem betreut, überwacht und in Rechnung
 * gestellt – sie unterscheidet sich nur in ihrer Herkunft.
 *
 * Die steht in 'source':
 *
 *   ki    von WebAtze aufgebaut, mit Auftrag, Abschnitten und Paketen
 *   hand  von Hand eingetragen, weil sie schon da war
 *
 * Alles, was den Aufbau betrifft, gibt es nur bei 'ki'. Alles andere –
 * Kunde, Domain, Überwachung, Notizen – gilt für beide.
 */
final class Websites
{
    /** Woher eine Website kommt. */
    public const SOURCES = [
        'ki' => 'Von WebAtze gebaut',
        'hand' => 'Hinzugefügt',
    ];

    /** In welchem Zustand sie ist. */
    public const STATUS = [
        'draft' => 'Entwurf',
        'building' => 'Wird gebaut',
        'ready' => 'Fertig, noch nicht online',
        'live' => 'Online',
        'paused' => 'Ruht',
        'failed' => 'Fehlgeschlagen',
    ];

    /** Womit sie gebaut ist – nur ein Vorschlag, eintippen geht auch. */
    public const PLATFORMS = [
        'WebAtze',
        'WordPress',
        'Wix',
        'Jimdo',
        'Squarespace',
        'Shopify',
        'TYPO3',
        'Joomla',
        'Webflow',
        'Von Hand gebaut',
    ];

    // ------------------------------------------------------------------
    // Lesen
    // ------------------------------------------------------------------

    /**
     * Die Liste, wie sie im Adminbereich steht.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(string $suche = '', string $herkunft = '', int $kunde = 0): array
    {
        $sql = 'SELECT p.*, c.name AS kunde,
                       (SELECT COUNT(*) FROM project_pages WHERE project_id = p.id) AS seiten,
                       (SELECT MAX(version) FROM builds WHERE project_id = p.id) AS fassung
                FROM projects p
                LEFT JOIN customers c ON c.id = p.customer_id';

        $wo = [];
        $werte = [];

        $suche = trim($suche);

        if ($suche !== '') {
            $wo[] = '(LOWER(p.name) LIKE :suche OR LOWER(p.domain) LIKE :suche OR LOWER(c.name) LIKE :suche)';
            $werte['suche'] = '%' . mb_strtolower($suche) . '%';
        }

        if (isset(self::SOURCES[$herkunft])) {
            $wo[] = 'p.source = :quelle';
            $werte['quelle'] = $herkunft;
        }

        if ($kunde > 0) {
            $wo[] = 'p.customer_id = :kunde';
            $werte['kunde'] = $kunde;
        }

        if ($wo !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $wo);
        }

        $sql .= ' ORDER BY p.updated_at DESC, p.id DESC';

        $zeilen = Db::all($sql, $werte);

        // Der Zustand der Überwachung, sofern eine läuft. Er gehört in
        // diese Liste: «läuft die Seite» ist die erste Frage bei einer
        // Website, die man betreut.
        foreach ($zeilen as $nummer => $zeile) {
            $zeilen[$nummer]['waechter'] = self::monitorFor($zeile);
        }

        return $zeilen;
    }

    /** Die Websites eines Kunden. */
    public static function forCustomer(int $kunde): array
    {
        return self::all('', '', $kunde);
    }

    public static function find(int $id): ?array
    {
        return Db::first(
            'SELECT p.*, c.name AS kunde FROM projects p
             LEFT JOIN customers c ON c.id = p.customer_id
             WHERE p.id = :id',
            ['id' => $id]
        );
    }

    /** @return array<string, int> */
    public static function counts(): array
    {
        return [
            'gesamt' => (int) Db::value('SELECT COUNT(*) FROM projects', [], 0),
            'live' => (int) Db::value("SELECT COUNT(*) FROM projects WHERE status = 'live'", [], 0),
            'gebaut' => (int) Db::value("SELECT COUNT(*) FROM projects WHERE source = 'ki'", [], 0),
            'hinzugefuegt' => (int) Db::value("SELECT COUNT(*) FROM projects WHERE source = 'hand'", [], 0),
            'ohne_kunde' => (int) Db::value('SELECT COUNT(*) FROM projects WHERE customer_id IS NULL', [], 0),
        ];
    }

    /**
     * Die Überwachung zu einer Website, falls es eine gibt.
     *
     * @param array<string, mixed> $website
     * @return array<string, mixed>|null
     */
    public static function monitorFor(array $website): ?array
    {
        $zeile = Db::first(
            'SELECT * FROM monitors WHERE project_id = :p ORDER BY id ASC LIMIT 1',
            ['p' => (int) $website['id']]
        );

        if ($zeile !== null) {
            return $zeile;
        }

        // Auch ohne Verknüpfung: Wer dieselbe Domain überwacht, meint
        // dieselbe Website.
        $host = self::host((string) ($website['domain'] ?? ''));

        if ($host === '') {
            return null;
        }

        return Db::first(
            'SELECT * FROM monitors WHERE url LIKE :muster ORDER BY id ASC LIMIT 1',
            ['muster' => '%' . $host . '%']
        );
    }

    // ------------------------------------------------------------------
    // Schreiben
    // ------------------------------------------------------------------

    /**
     * Eine Website von Hand eintragen oder ändern.
     *
     * @param array<string, mixed> $daten
     * @return array{ok:bool, meldung:string, id:int}
     */
    public static function save(array $daten, ?int $id = null): array
    {
        $name = mb_substr(trim((string) ($daten['name'] ?? '')), 0, 191);

        if ($name === '') {
            return ['ok' => false, 'meldung' => 'Ohne Namen lässt sich die Website später nicht finden.', 'id' => 0];
        }

        $satz = [
            'name' => $name,
            'domain' => self::domain($daten['domain'] ?? ''),
            'customer_id' => ((int) ($daten['customer_id'] ?? 0)) ?: null,
            'status' => isset(self::STATUS[(string) ($daten['status'] ?? '')])
                ? (string) $daten['status']
                : 'live',
            'platform' => mb_substr(trim((string) ($daten['platform'] ?? '')), 0, 60),
            'hosting' => mb_substr(trim((string) ($daten['hosting'] ?? '')), 0, 120),
            'notes' => mb_substr(trim((string) ($daten['notes'] ?? '')), 0, 4000),
            'live_since' => self::day($daten['live_since'] ?? ''),
            'updated_at' => Db::now(),
        ];

        if ($id !== null) {
            Db::update('projects', $satz, 'id = :id', ['id' => $id]);

            return ['ok' => true, 'meldung' => 'Gespeichert.', 'id' => $id];
        }

        // Der Slug ist eindeutig und wird gebraucht, auch wenn diese
        // Website nie gebaut wird - andere Teile verlassen sich darauf.
        $satz['slug'] = self::uniqueSlug($name);
        $satz['source'] = 'hand';
        $satz['locale'] = 'de';
        $satz['locales'] = 'de';
        $satz['created_at'] = Db::now();

        $neu = Db::insert('projects', $satz);

        Logger::info('Website hinzugefügt.', ['id' => $neu, 'name' => $name]);

        return ['ok' => true, 'meldung' => 'Die Website steht in der Liste.', 'id' => $neu];
    }

    /** Eine Website einem Kunden zuordnen – oder die Zuordnung lösen. */
    public static function assign(int $id, int $kunde): bool
    {
        return Db::update('projects', [
            'customer_id' => $kunde > 0 ? $kunde : null,
            'updated_at' => Db::now(),
        ], 'id = :id', ['id' => $id]) > 0;
    }

    /**
     * Eine hinzugefügte Website entfernen.
     *
     * Selbst gebaute werden hier nicht angefasst: An ihnen hängen
     * Abschnitte, Pakete und Vorschauen, und die räumt der eigene Weg
     * über die Projektseite auf.
     */
    public static function remove(int $id): bool
    {
        $website = self::find($id);

        if ($website === null || (string) $website['source'] !== 'hand') {
            return false;
        }

        Db::update('monitors', ['project_id' => null], 'project_id = :p', ['p' => $id]);

        return Db::delete('projects', 'id = :id', ['id' => $id]) > 0;
    }

    // ------------------------------------------------------------------
    // Kleinkram
    // ------------------------------------------------------------------

    /** Die volle Adresse zum Anklicken. */
    public static function url(array $website): string
    {
        $domain = trim((string) ($website['domain'] ?? ''));

        if ($domain === '') {
            return '';
        }

        return preg_match('#^https?://#i', $domain) === 1 ? $domain : 'https://' . $domain;
    }

    private static function domain(mixed $wert): string
    {
        $text = trim((string) $wert);

        // Mit oder ohne Vorsilbe eingegeben - gespeichert wird der
        // nackte Name, damit die Liste ruhig aussieht.
        $text = preg_replace('#^https?://#i', '', $text) ?? $text;

        return mb_substr(rtrim($text, '/'), 0, 191);
    }

    private static function host(string $domain): string
    {
        $host = preg_replace('#^https?://#i', '', trim($domain)) ?? $domain;
        $host = explode('/', $host)[0];

        return preg_replace('/^www\./i', '', $host) ?? $host;
    }

    private static function day(mixed $wert): string
    {
        $text = trim((string) $wert);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1 ? $text : '';
    }

    /** Ein Kurzname, den es noch nicht gibt. */
    private static function uniqueSlug(string $name): string
    {
        $basis = str_slug($name, 60);

        if ($basis === '') {
            $basis = 'website';
        }

        $slug = $basis;
        $zahl = 2;

        while (Db::value('SELECT id FROM projects WHERE slug = :s', ['s' => $slug]) !== null) {
            $slug = $basis . '-' . $zahl;
            $zahl++;
        }

        return $slug;
    }
}
