<?php

declare(strict_types=1);

namespace WebAtze\Build;

use WebAtze\Core\{Config, Db, Http, Logger, Mailer, Settings};

/**
 * Besucherzahlen einsammeln und einmal in der Woche berichten.
 *
 * Der eigene Dienst, kein fremder. Gezählt wird auf dem Server des
 * Kunden (Kit/site/php/zaehler.php), und zwar nur, was sich zählen
 * lässt, ohne jemanden wiederzuerkennen: Der Tag, die Seite, woher der
 * Verweis kam. Die Kennung eines Besuchers entsteht aus einem Salz, das
 * jede Nacht wechselt – am nächsten Tag ist dieselbe Person nicht mehr
 * dieselbe Kennung, und rückwärts rechnen kann niemand.
 *
 * Hier holen wir einmal am Tag ab, was dort steht. Es kommen nur
 * Summen an. Deshalb braucht die Website keinen Zustimmungsbanner:
 * Es gibt nichts, wofür man zustimmen müsste.
 */
final class VisitCollector
{
    /** So viele Websites holen wir pro Durchlauf ab. */
    private const BATCH = 8;

    /** Ältere Zeilen brauchen wir nicht – der Bericht schaut auf Wochen. */
    private const KEEP_DAYS = 400;

    // ------------------------------------------------------------ Ablegen

    /**
     * Zeilen einer Website übernehmen.
     *
     * Dieselbe Zeile kann mehrfach kommen – der laufende Tag wird ja
     * jeden Tag erneut geholt, solange er nicht vorbei ist. Deshalb
     * wird ersetzt, nicht addiert.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    public static function store(int $projectId, array $rows): int
    {
        if ($projectId <= 0 || $rows === []) {
            return 0;
        }

        $count = 0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $day = self::day((string) ($row['tag'] ?? ''));

            if ($day === '') {
                continue;
            }

            $path = self::text((string) ($row['pfad'] ?? '/'), 191);
            $referrer = self::text((string) ($row['herkunft'] ?? ''), 191);

            $values = [
                'views' => max(0, min(10_000_000, (int) ($row['aufrufe'] ?? 0))),
                'visitors' => max(0, min(10_000_000, (int) ($row['besucher'] ?? 0))),
            ];

            $where = 'project_id = :p AND day = :d AND path = :s AND referrer = :r';
            $keys = ['p' => $projectId, 'd' => $day, 's' => $path === '' ? '/' : $path, 'r' => $referrer];

            $exists = Db::first('SELECT id FROM visits WHERE ' . $where, $keys);

            if ($exists !== null) {
                Db::update('visits', $values, 'id = :id', ['id' => (int) $exists['id']]);
            } else {
                Db::insert('visits', $values + [
                    'project_id' => $projectId,
                    'day' => $day,
                    'path' => $keys['s'],
                    'referrer' => $referrer,
                    'created_at' => Db::now(),
                ]);
            }

            $count++;
        }

        if ($count > 0) {
            Db::update('projects', ['visits_synced_at' => Db::now()], 'id = :id', ['id' => $projectId]);
        }

        return $count;
    }

    // ------------------------------------------------------------- Abholen

    /**
     * Die fälligen Websites abfragen.
     *
     * Läuft aus der Stundenpflege heraus. Wir nehmen nur eine Handvoll
     * pro Durchlauf: Auf einem gemieteten Hosting soll ein einzelner
     * Aufruf nicht minutenlang an fremden Servern hängen.
     */
    public static function collectDue(): int
    {
        $due = Db::all(
            // Zwei Platzhalter fuer denselben leeren Text: MySQL
            // erlaubt einen benannten Platzhalter nur einmal je Abfrage.
            "SELECT * FROM projects
             WHERE stats_token <> :empty1 AND domain <> :empty2
               AND (visits_synced_at IS NULL OR visits_synced_at < :before)
             ORDER BY visits_synced_at IS NULL DESC, visits_synced_at ASC
             LIMIT " . self::BATCH,
            ['empty1' => '', 'empty2' => '', 'before' => date('Y-m-d H:i:s', time() - 20 * 3600)]
        );

        $total = 0;

        foreach ($due as $project) {
            $total += self::collect($project);
        }

        if ($total > 0) {
            self::purgeOld();
        }

        return $total;
    }

    /**
     * Eine einzelne Website abfragen.
     *
     * Schlägt es fehl, wird der Zeitstempel trotzdem gesetzt. Sonst
     * würde eine abgeschaltete Website jede Stunde erneut versucht und
     * verdrängte die anderen aus dem Durchlauf.
     */
    public static function collect(array $project): int
    {
        $url = self::statsUrl($project);
        $token = (string) ($project['stats_token'] ?? '');

        if ($url === '' || $token === '') {
            return 0;
        }

        Db::update('projects', ['visits_synced_at' => Db::now()], 'id = :id', [
            'id' => (int) $project['id'],
        ]);

        $answer = Http::get($url, 20, ['X-WebAtze-Token: ' . $token]);

        if (($answer['status'] ?? 0) !== 200) {
            Logger::info('Besucherzahlen nicht erreichbar.', [
                'projekt' => (string) $project['slug'],
                'status' => (int) ($answer['status'] ?? 0),
            ]);
            return 0;
        }

        $data = json_decode((string) ($answer['body'] ?? ''), true);

        if (!is_array($data) || empty($data['ok'])) {
            return 0;
        }

        return self::store((int) $project['id'], (array) ($data['zeilen'] ?? []));
    }

    /** Die Adresse, unter der die Zahlen beim Kunden liegen. */
    public static function statsUrl(array $project): string
    {
        $domain = trim((string) ($project['domain'] ?? ''));

        if ($domain === '') {
            return '';
        }

        if (!preg_match('~^https?://~i', $domain)) {
            $domain = 'https://' . $domain;
        }

        return rtrim($domain, '/') . '/zahlen.php';
    }

    /** Ein neuer Schlüssel fürs Abholen – gilt nur für diese eine Website. */
    public static function newToken(): string
    {
        return random_token(32);
    }

    // ------------------------------------------------------------ Auswerten

    /**
     * Die Zahlen einer Woche.
     *
     * @return array{
     *     von:string, bis:string, aufrufe:int, besucher:int,
     *     davor:array{aufrufe:int, besucher:int},
     *     seiten:array<int, array{pfad:string, aufrufe:int}>,
     *     herkunft:array<int, array{name:string, aufrufe:int}>,
     *     tage:array<string, int>
     * }
     */
    public static function week(int $projectId, string $until = ''): array
    {
        $end = $until !== '' ? $until : date('Y-m-d', strtotime('yesterday'));
        $start = date('Y-m-d', strtotime($end . ' -6 days'));
        $prevEnd = date('Y-m-d', strtotime($start . ' -1 day'));
        $prevStart = date('Y-m-d', strtotime($prevEnd . ' -6 days'));

        $rows = Db::all(
            'SELECT day, path, referrer, views, visitors FROM visits
             WHERE project_id = :p AND day >= :a AND day <= :b',
            ['p' => $projectId, 'a' => $start, 'b' => $end]
        );

        $views = 0;
        $visitors = 0;
        $pages = [];
        $sources = [];
        $days = [];

        for ($i = 0; $i < 7; $i++) {
            $days[date('Y-m-d', strtotime($start . ' +' . $i . ' days'))] = 0;
        }

        foreach ($rows as $row) {
            $v = (int) $row['views'];
            $views += $v;
            $visitors += (int) $row['visitors'];

            $path = (string) $row['path'];
            $pages[$path] = ($pages[$path] ?? 0) + $v;

            $source = (string) $row['referrer'];
            $name = $source === '' ? 'Direkt eingegeben' : $source;
            $sources[$name] = ($sources[$name] ?? 0) + $v;

            $day = (string) $row['day'];
            if (isset($days[$day])) {
                $days[$day] += $v;
            }
        }

        arsort($pages);
        arsort($sources);

        $before = Db::first(
            'SELECT COALESCE(SUM(views), 0) AS views, COALESCE(SUM(visitors), 0) AS visitors
             FROM visits WHERE project_id = :p AND day >= :a AND day <= :b',
            ['p' => $projectId, 'a' => $prevStart, 'b' => $prevEnd]
        ) ?? [];

        $top = static function (array $list, string $key): array {
            $out = [];
            foreach (array_slice($list, 0, 5, true) as $name => $count) {
                $out[] = [$key => (string) $name, 'aufrufe' => (int) $count];
            }
            return $out;
        };

        return [
            'von' => $start,
            'bis' => $end,
            'aufrufe' => $views,
            'besucher' => $visitors,
            'davor' => [
                'aufrufe' => (int) ($before['views'] ?? 0),
                'besucher' => (int) ($before['visitors'] ?? 0),
            ],
            'seiten' => $top($pages, 'pfad'),
            'herkunft' => $top($sources, 'name'),
            'tage' => $days,
        ];
    }

    // ------------------------------------------------------------- Berichten

    /**
     * Der Wochenbericht.
     *
     * Geht am Montag hinaus, an die im Formular hinterlegte Adresse und –
     * falls keine da ist – an niemanden. Wer keine Zahlen bekommen will,
     * lässt das Feld einfach leer; abbestellen muss er nichts.
     */
    public static function sendWeeklyReports(): int
    {
        if (date('N') !== '1') {
            return 0;
        }

        $today = date('Y-m-d');

        $projects = Db::all(
            "SELECT * FROM projects
             WHERE report_email <> :empty AND report_sent_on <> :today
             ORDER BY id ASC LIMIT " . self::BATCH,
            ['empty' => '', 'today' => $today]
        );

        $sent = 0;

        foreach ($projects as $project) {
            // Auch wenn nichts hinausgeht: Der Tag wird vermerkt, sonst
            // versucht es die nächste Stunde von vorne.
            Db::update('projects', ['report_sent_on' => $today], 'id = :id', [
                'id' => (int) $project['id'],
            ]);

            $week = self::week((int) $project['id']);

            // Eine Woche ganz ohne Besuch ist keine Nachricht wert.
            if ($week['aufrufe'] === 0 && $week['davor']['aufrufe'] === 0) {
                continue;
            }

            $ok = Mailer::send(
                (string) $project['report_email'],
                'Ihre Website in Zahlen · ' . self::niceDate($week['von']) . ' bis ' . self::niceDate($week['bis']),
                self::reportText($project, $week)
            );

            if ($ok) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Der Text der Nachricht.
     *
     * Bewusst schlicht und ohne Fachwörter: "Aufrufe" und "Besucher"
     * versteht jeder, "Sessions" und "Bounce Rate" nicht.
     */
    public static function reportText(array $project, array $week): string
    {
        $lines = [];
        $lines[] = 'Guten Tag';
        $lines[] = '';
        $lines[] = 'So lief die vergangene Woche auf Ihrer Website '
            . (string) $project['name'] . '.';
        $lines[] = '';
        $lines[] = 'Vom ' . self::niceDate($week['von']) . ' bis ' . self::niceDate($week['bis']) . ':';
        $lines[] = '';
        $lines[] = '  Seitenaufrufe   ' . $week['aufrufe'] . self::trend($week['aufrufe'], $week['davor']['aufrufe']);
        $lines[] = '  Besucher        ' . $week['besucher'] . self::trend($week['besucher'], $week['davor']['besucher']);
        $lines[] = '';

        if ($week['seiten'] !== []) {
            $lines[] = 'Am häufigsten angesehen:';
            foreach ($week['seiten'] as $page) {
                $lines[] = '  ' . str_pad((string) $page['aufrufe'], 6, ' ', STR_PAD_LEFT)
                    . '  ' . $page['pfad'];
            }
            $lines[] = '';
        }

        if ($week['herkunft'] !== []) {
            $lines[] = 'Woher die Leute kamen:';
            foreach ($week['herkunft'] as $source) {
                $lines[] = '  ' . str_pad((string) $source['aufrufe'], 6, ' ', STR_PAD_LEFT)
                    . '  ' . $source['name'];
            }
            $lines[] = '';
        }

        $lines[] = 'Gezählt wird auf Ihrem eigenen Server, ohne fremde Dienste und';
        $lines[] = 'ohne Zustimmungsbanner. Wer Ihre Website besucht, bleibt anonym:';
        $lines[] = 'Wir speichern keine Adressen und erkennen niemanden wieder.';
        $lines[] = '';
        $lines[] = 'Sie möchten diese Nachricht nicht mehr? Eine kurze Antwort genügt.';
        $lines[] = '';
        $lines[] = 'Freundliche Grüsse';
        $lines[] = (string) Config::get('mail.from_name', 'WebAtze');

        return implode("\n", $lines);
    }

    /** " (+12 gegenüber der Vorwoche)" – oder nichts, wenn es nichts zu vergleichen gibt. */
    private static function trend(int $now, int $before): string
    {
        if ($before === 0) {
            return '';
        }

        $diff = $now - $before;

        if ($diff === 0) {
            return '   (gleich wie in der Vorwoche)';
        }

        return '   (' . ($diff > 0 ? '+' : '−') . abs($diff) . ' gegenüber der Vorwoche)';
    }

    // ------------------------------------------------------------ Aufräumen

    public static function purgeOld(): int
    {
        return Db::delete('visits', 'day < :before', [
            'before' => date('Y-m-d', time() - self::KEEP_DAYS * 86400),
        ]);
    }

    /**
     * Der stündliche Anstoss.
     *
     * Einmal am Tag abholen, montags berichten. Beides wird hier
     * zusammengefasst, damit die Pflege nur eine Stelle aufrufen muss.
     */
    public static function tick(): array
    {
        $last = (int) Settings::get('visits_tick_at', '0');

        // Die Pflege ruft stündlich hier an. Die Sperre soll nur einen
        // zweiten Aufruf kurz danach abfangen, nicht die nächste Stunde
        // verschlucken – deshalb eine halbe Stunde, nicht eine ganze.
        if (time() - $last < 1800) {
            return ['geholt' => 0, 'berichte' => 0, 'uebersprungen' => true];
        }

        Settings::put('visits_tick_at', (string) time());

        return [
            'geholt' => self::collectDue(),
            'berichte' => self::sendWeeklyReports(),
            'uebersprungen' => false,
        ];
    }

    // -------------------------------------------------------------- Kleinkram

    /** Nur ein sauberes Datum, nichts anderes. */
    private static function day(string $value): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
    }

    private static function text(string $value, int $max): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
        return mb_substr(trim($value), 0, $max);
    }

    private static function niceDate(string $day): string
    {
        $time = strtotime($day);
        return $time === false ? $day : date('d.m.Y', $time);
    }
}
