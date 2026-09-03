<?php

declare(strict_types=1);

namespace WebAtze\Domain;

use WebAtze\Core\{Config, Db, Settings};

/**
 * Besucherzahlen – für die eigene Website und für jede Kundenwebsite.
 *
 * Es gibt zwei Wege, auf denen Zahlen hereinkommen:
 *
 *   1. Die eigene Website zählt sich selbst. Das läuft hier auf dem
 *      Server, mitten in der Antwort, und ist deshalb exakt: kein
 *      Skript, das blockiert werden kann, kein Umweg.
 *
 *   2. Kundenwebsites melden sich beim Zähler (/z.js und /z). Ein
 *      Einzeiler im Fusszeilenbereich genügt – deshalb funktioniert das
 *      auch bei Seiten, die nicht von WebAtze gebaut wurden. Genau das
 *      war die Anforderung: Jede Website, die unter «Websites» steht,
 *      wird gezählt, egal woher sie kommt.
 *
 * Was NICHT gespeichert wird: keine IP-Adresse, kein dauerhafter
 * Wiedererkennungswert, kein Cookie. Die Kennung eines Besuchers
 * entsteht aus IP, Browserkennung und einem Salz, das jede Nacht
 * wechselt. Am nächsten Tag ist dieselbe Person eine andere Kennung,
 * und rückwärts rechnen kann niemand – auch ich nicht. Deshalb braucht
 * weder meine noch eine Kundenwebsite dafür ein Zustimmungsbanner.
 */
final class Visits
{
    /**
     * Die eigene Website.
     *
     * Sie ist kein Projekt und hat deshalb keine Zeile in 'projects'.
     * Die Null ist frei: Kennungen beginnen bei eins.
     */
    public const OWN = 0;

    /** Wie lange die Tageskennungen liegen bleiben. */
    private const KEEP_SEEN_DAYS = 3;

    // ------------------------------------------------------------------
    // Zählen
    // ------------------------------------------------------------------

    /**
     * Einen Aufruf festhalten.
     *
     * @return bool true, wenn gezählt wurde
     */
    public static function record(
        int $projectId,
        string $path,
        string $referrer = '',
        string $ip = '',
        string $agent = ''
    ): bool {
        if ($projectId < 0 || self::isRobot($agent)) {
            return false;
        }

        $day = date('Y-m-d');
        $path = self::path($path);
        $host = self::referrerHost($referrer, self::selfHost($projectId));

        // Neu oder schon bekannt? Erst danach wird geschrieben, damit
        // «Aufrufe» und «Besucher» nicht dieselbe Zahl sind.
        $neu = self::firstToday($projectId, $day, $ip, $agent);

        $zeile = Db::first(
            'SELECT id, views, visitors FROM visits
             WHERE project_id = :p AND day = :d AND path = :s AND referrer = :r',
            ['p' => $projectId, 'd' => $day, 's' => $path, 'r' => $host]
        );

        if ($zeile !== null) {
            Db::update('visits', [
                'views' => (int) $zeile['views'] + 1,
                'visitors' => (int) $zeile['visitors'] + ($neu ? 1 : 0),
            ], 'id = :id', ['id' => (int) $zeile['id']]);

            return true;
        }

        Db::insert('visits', [
            'project_id' => $projectId,
            'day' => $day,
            'path' => $path,
            'referrer' => $host,
            'views' => 1,
            'visitors' => $neu ? 1 : 0,
            'created_at' => Db::now(),
        ]);

        return true;
    }

    /**
     * War diese Person heute schon da?
     *
     * Die Kennung ist ein Streuwert aus IP, Browserkennung und dem Salz
     * des Tages. Sie taugt für genau eine Frage – «schon gezählt?» –
     * und für nichts sonst.
     */
    private static function firstToday(int $projectId, string $day, string $ip, string $agent): bool
    {
        if ($ip === '' && $agent === '') {
            return false;
        }

        $kennung = substr(hash_hmac('sha256', $ip . '|' . $agent, self::salt($day)), 0, 40);

        $da = Db::first(
            'SELECT id FROM visit_seen WHERE project_id = :p AND day = :d AND fingerprint = :f',
            ['p' => $projectId, 'd' => $day, 'f' => $kennung]
        );

        if ($da !== null) {
            return false;
        }

        try {
            Db::insert('visit_seen', [
                'project_id' => $projectId,
                'day' => $day,
                'fingerprint' => $kennung,
                'created_at' => Db::now(),
            ]);
        } catch (\Throwable) {
            // Zwei Aufrufe im selben Augenblick: Der eindeutige Index
            // hat gewonnen, und der zweite ist eben kein neuer Besucher.
            return false;
        }

        return true;
    }

    /**
     * Das Salz des Tages.
     *
     * Es entsteht einmal je Tag und wird danach nicht mehr geändert.
     * Das Salz von gestern wird nicht aufbewahrt – damit ist die
     * Kennung von gestern endgültig nicht mehr nachzubilden.
     */
    private static function salt(string $day): string
    {
        $key = 'visit_salt';
        $wert = Settings::get($key, '');

        if (str_starts_with($wert, $day . ':')) {
            return substr($wert, strlen($day) + 1);
        }

        $neu = bin2hex(random_bytes(16));
        Settings::put($key, $day . ':' . $neu);

        return $neu;
    }

    // ------------------------------------------------------------------
    // Schlüssel und Einbau
    // ------------------------------------------------------------------

    /** Der öffentliche Zählschlüssel einer Website – erzeugt ihn bei Bedarf. */
    public static function key(int $projectId): string
    {
        $zeile = Db::first('SELECT visit_key FROM projects WHERE id = :id', ['id' => $projectId]);

        if ($zeile === null) {
            return '';
        }

        $schluessel = trim((string) ($zeile['visit_key'] ?? ''));

        if ($schluessel !== '') {
            return $schluessel;
        }

        $schluessel = bin2hex(random_bytes(8));

        Db::update('projects', ['visit_key' => $schluessel], 'id = :id', ['id' => $projectId]);

        return $schluessel;
    }

    /** Zu welchem Projekt gehört dieser Schlüssel? */
    public static function byKey(string $key): ?array
    {
        $key = trim($key);

        if ($key === '' || !preg_match('/^[a-f0-9]{16}$/', $key)) {
            return null;
        }

        return Db::first('SELECT id, name, domain FROM projects WHERE visit_key = :k', ['k' => $key]);
    }

    /**
     * Der Einzeiler, der in eine fremde Website kommt.
     *
     * Bewusst ein Verweis auf eine Datei und kein eingebetteter Code:
     * So lässt sich der Zähler später ändern, ohne jede Kundenwebsite
     * anzufassen.
     */
    public static function snippet(int $projectId): string
    {
        $basis = rtrim((string) Config::get('app_url', ''), '/');
        $key = self::key($projectId);

        if ($basis === '' || $key === '') {
            return '';
        }

        return '<script defer src="' . $basis . '/z.js?k=' . $key . '"></script>';
    }

    // ------------------------------------------------------------------
    // Auswerten
    // ------------------------------------------------------------------

    /**
     * Die Übersicht: die eigene Website und jede Kundenwebsite.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function overview(int $days = 7): array
    {
        $zeilen = [[
            'id' => self::OWN,
            'name' => 'webatze.ch – die eigene Website',
            'domain' => preg_replace('~^https?://~i', '', (string) Config::get('app_url', '')) ?? '',
            'eigen' => true,
            'quelle' => 'eigen',
            'schluessel' => '',
            'abholbar' => false,
        ]];

        $alle = Db::all(
            'SELECT id, name, domain, source, stats_token FROM projects ORDER BY name ASC LIMIT 500'
        );

        foreach ($alle as $p) {
            $zeilen[] = [
                'id' => (int) $p['id'],
                'name' => (string) $p['name'],
                'domain' => (string) $p['domain'],
                'eigen' => false,
                'quelle' => (string) ($p['source'] ?? 'ki'),
                'schluessel' => self::key((int) $p['id']),
                // Eine selbst gebaute Website liefert die Zahlen auf
                // Nachfrage. Bei allen anderen kommen sie von selbst.
                'abholbar' => trim((string) ($p['stats_token'] ?? '')) !== '',
            ];
        }

        foreach ($zeilen as $nummer => $zeile) {
            $zeilen[$nummer] += self::totals((int) $zeile['id'], $days);
        }

        return $zeilen;
    }

    /**
     * Aufrufe und Besucher der letzten Tage, mit Vergleich davor.
     *
     * @return array{aufrufe:int, besucher:int, davor:int, tage:array<string,int>, letzter:string}
     */
    public static function totals(int $projectId, int $days = 7): array
    {
        $days = max(1, min(365, $days));
        $bis = date('Y-m-d');
        $von = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        $davorVon = date('Y-m-d', strtotime('-' . (2 * $days - 1) . ' days'));
        $davorBis = date('Y-m-d', strtotime('-' . $days . ' days'));

        $zeilen = Db::all(
            'SELECT day, SUM(views) AS aufrufe, SUM(visitors) AS besucher
             FROM visits WHERE project_id = :p AND day >= :a AND day <= :b
             GROUP BY day ORDER BY day ASC',
            ['p' => $projectId, 'a' => $von, 'b' => $bis]
        );

        $tage = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $tage[date('Y-m-d', strtotime('-' . $i . ' days'))] = 0;
        }

        $aufrufe = 0;
        $besucher = 0;

        foreach ($zeilen as $zeile) {
            $aufrufe += (int) $zeile['aufrufe'];
            $besucher += (int) $zeile['besucher'];
            $tage[(string) $zeile['day']] = (int) $zeile['aufrufe'];
        }

        $davor = (int) Db::value(
            'SELECT SUM(views) FROM visits WHERE project_id = :p AND day >= :a AND day <= :b',
            ['p' => $projectId, 'a' => $davorVon, 'b' => $davorBis],
            0
        );

        $letzter = (string) Db::value(
            'SELECT MAX(day) FROM visits WHERE project_id = :p',
            ['p' => $projectId],
            ''
        );

        return [
            'aufrufe' => $aufrufe,
            'besucher' => $besucher,
            'davor' => $davor,
            'tage' => $tage,
            'letzter' => $letzter,
        ];
    }

    /**
     * Die meistbesuchten Seiten und die häufigste Herkunft.
     *
     * @return array{seiten:array<int,array{pfad:string,aufrufe:int}>,
     *               herkunft:array<int,array{name:string,aufrufe:int}>}
     */
    public static function detail(int $projectId, int $days = 30): array
    {
        $von = date('Y-m-d', strtotime('-' . max(1, min(365, $days)) . ' days'));

        $seiten = Db::all(
            'SELECT path AS pfad, SUM(views) AS aufrufe FROM visits
             WHERE project_id = :p AND day >= :a
             GROUP BY path ORDER BY aufrufe DESC LIMIT 12',
            ['p' => $projectId, 'a' => $von]
        );

        $herkunft = Db::all(
            "SELECT referrer AS name, SUM(views) AS aufrufe FROM visits
             WHERE project_id = :p AND day >= :a AND referrer <> :leer
             GROUP BY referrer ORDER BY aufrufe DESC LIMIT 12",
            ['p' => $projectId, 'a' => $von, 'leer' => '']
        );

        return [
            'seiten' => array_map(
                static fn (array $z): array => ['pfad' => (string) $z['pfad'], 'aufrufe' => (int) $z['aufrufe']],
                $seiten
            ),
            'herkunft' => array_map(
                static fn (array $z): array => ['name' => (string) $z['name'], 'aufrufe' => (int) $z['aufrufe']],
                $herkunft
            ),
        ];
    }

    /** Alte Tageskennungen wegräumen. Die Zahlen selbst bleiben. */
    public static function purgeSeen(): int
    {
        return Db::run(
            'DELETE FROM visit_seen WHERE day < :vor',
            ['vor' => date('Y-m-d', strtotime('-' . self::KEEP_SEEN_DAYS . ' days'))]
        )->rowCount();
    }

    // ------------------------------------------------------------------
    // Kleinkram
    // ------------------------------------------------------------------

    /**
     * Ist das ein Programm statt eines Menschen?
     *
     * Bewusst grob: Wer sich als Programm zu erkennen gibt, wird nicht
     * gezählt. Wer sich verstellt, wird gezählt – ihn zu enttarnen
     * hiesse, Merkmale zu sammeln, und genau das soll dieser Zähler
     * nicht tun.
     */
    public static function isRobot(string $agent): bool
    {
        if ($agent === '') {
            return true;
        }

        $klein = strtolower($agent);

        foreach ([
            'bot', 'crawler', 'spider', 'slurp', 'curl', 'wget', 'python',
            'httpclient', 'headless', 'lighthouse', 'pingdom', 'uptime',
            'monitor', 'preview', 'facebookexternalhit', 'embedly', 'scrapy',
        ] as $wort) {
            if (str_contains($klein, $wort)) {
                return true;
            }
        }

        return false;
    }

    /** Nur der Pfad, ohne Anfrageteil – sonst zählt jede Kampagne einzeln. */
    private static function path(string $path): string
    {
        $path = (string) (parse_url(trim($path), PHP_URL_PATH) ?: '/');
        $path = '/' . ltrim(preg_replace('/[^\P{C}]/u', '', $path) ?? '', '/');

        return mb_substr($path === '' ? '/' : $path, 0, 191);
    }

    /**
     * Von der Herkunft bleibt der Rechnername.
     *
     * Der ganze Verweis kann persönliche Angaben enthalten – eine
     * Suchanfrage etwa. «google.com» sagt alles, was ich wissen muss.
     */
    private static function referrerHost(string $referrer, string $eigen = ''): string
    {
        $referrer = trim($referrer);

        if ($referrer === '') {
            return '';
        }

        $host = (string) (parse_url($referrer, PHP_URL_HOST) ?: '');
        $host = preg_replace('/^www\./i', '', strtolower($host)) ?? '';

        // Ein Verweis von der Seite auf sich selbst ist keine Herkunft.
        if ($host === '' || ($eigen !== '' && $host === $eigen)) {
            return '';
        }

        return mb_substr($host, 0, 191);
    }

    /** Der eigene Rechnername einer gezählten Website. */
    private static function selfHost(int $projectId): string
    {
        $adresse = $projectId === self::OWN
            ? (string) Config::get('app_url', '')
            : (string) Db::value('SELECT domain FROM projects WHERE id = :id', ['id' => $projectId], '');

        if ($adresse === '') {
            return '';
        }

        if (!preg_match('~^https?://~i', $adresse)) {
            $adresse = 'https://' . $adresse;
        }

        $host = strtolower((string) (parse_url($adresse, PHP_URL_HOST) ?: ''));

        return preg_replace('/^www\./i', '', $host) ?? '';
    }
}
