<?php

declare(strict_types=1);

namespace WebAtze\Domain;

use WebAtze\Core\{Db, Http, Logger, Mailer, Settings};

/**
 * Hosting-Überwachung: läuft die Kundenwebsite noch?
 *
 * Der Kunde merkt einen Ausfall, wenn ihn jemand anruft. Das ist zu spät
 * und peinlich obendrein. Deshalb ruft WebAtze die Seiten selbst
 * regelmässig auf und meldet sich, wenn eine nicht mehr antwortet.
 *
 * Zwei Entscheidungen, die absichtlich so sind:
 *
 * Gemeldet wird erst beim zweiten Fehlschlag in Folge. Ein einzelner
 * Aussetzer ist meistens das Netz und nicht der Server; wer bei jedem
 * Zucken eine E-Mail bekommt, liest die E-Mails irgendwann nicht mehr.
 *
 * Der Verlauf wird gekürzt. Alle fünfzehn Minuten eine Zeile ergibt im
 * Jahr fünfunddreissigtausend – und niemand sieht sich an, wie es im
 * März um 03:15 Uhr stand.
 */
final class Monitor
{
    /** So viele Zeilen Verlauf bleiben je Überwachung stehen. */
    private const KEEP_CHECKS = 300;

    /** Erst ab hier gilt eine Seite als ausgefallen. */
    private const FAILS_BEFORE_ALARM = 2;

    /** Nicht öfter als einmal am Tag dieselbe Warnung. */
    private const ALARM_PAUSE = 21600;

    /** Ab wann ein Zertifikat als knapp gilt. */
    public const CERT_WARN_DAYS = 21;

    // ------------------------------------------------------------------
    // Lesen
    // ------------------------------------------------------------------

    /** @return array<int, array<string, mixed>> */
    public static function all(): array
    {
        return Db::all(
            'SELECT m.*, c.name AS kunde, p.name AS projekt
             FROM monitors m
             LEFT JOIN customers c ON c.id = m.customer_id
             LEFT JOIN projects p ON p.id = m.project_id
             ORDER BY m.active DESC, m.last_ok ASC, m.label ASC, m.url ASC'
        );
    }

    public static function find(int $id): ?array
    {
        return Db::first('SELECT * FROM monitors WHERE id = :id', ['id' => $id]);
    }

    /** @return array<int, array<string, mixed>> */
    public static function history(int $monitorId, int $limit = 40): array
    {
        return Db::all(
            'SELECT * FROM monitor_checks WHERE monitor_id = :id ORDER BY checked_at DESC LIMIT '
            . max(1, $limit),
            ['id' => $monitorId]
        );
    }

    /**
     * Wie zuverlässig lief die Seite in den letzten Tagen?
     *
     * Ohne eine einzige Prüfung gibt es keine Aussage – dann steht dort
     * null und die Ansicht schreibt einen Strich statt einer erfundenen
     * Hundert.
     */
    public static function uptime(int $monitorId, int $days = 30): ?float
    {
        $zeile = Db::first(
            'SELECT COUNT(*) AS gesamt, SUM(ok) AS gut FROM monitor_checks
             WHERE monitor_id = :id AND checked_at >= :seit',
            ['id' => $monitorId, 'seit' => date('Y-m-d H:i:s', time() - $days * 86400)]
        );

        $gesamt = (int) ($zeile['gesamt'] ?? 0);

        return $gesamt === 0 ? null : round((int) ($zeile['gut'] ?? 0) / $gesamt * 100, 2);
    }

    /** @return array{gesamt:int, aktiv:int, unten:int, zertifikate:int} */
    public static function summary(): array
    {
        return [
            'gesamt' => (int) Db::value('SELECT COUNT(*) FROM monitors', [], 0),
            'aktiv' => (int) Db::value('SELECT COUNT(*) FROM monitors WHERE active = 1', [], 0),
            'unten' => (int) Db::value(
                'SELECT COUNT(*) FROM monitors WHERE active = 1 AND last_ok = 0 AND last_checked_at IS NOT NULL',
                [],
                0
            ),
            'zertifikate' => (int) Db::value(
                "SELECT COUNT(*) FROM monitors WHERE active = 1 AND cert_expires_on <> :leer
                 AND cert_expires_on <= :grenze",
                ['leer' => '', 'grenze' => date('Y-m-d', time() + self::CERT_WARN_DAYS * 86400)],
                0
            ),
        ];
    }

    /** Die Seiten, die gerade nicht antworten. */
    public static function down(): array
    {
        return Db::all(
            'SELECT m.*, c.name AS kunde FROM monitors m
             LEFT JOIN customers c ON c.id = m.customer_id
             WHERE m.active = 1 AND m.last_ok = 0 AND m.last_checked_at IS NOT NULL
             ORDER BY m.down_since ASC'
        );
    }

    // ------------------------------------------------------------------
    // Anlegen und ändern
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $daten
     * @return array{ok:bool, meldung:string, id:int}
     */
    public static function save(array $daten, ?int $id = null): array
    {
        $url = trim((string) ($daten['url'] ?? ''));

        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }

        if ($url === '') {
            return ['ok' => false, 'meldung' => 'Ohne Adresse lässt sich nichts prüfen.', 'id' => 0];
        }

        $grund = Http::assertPublicUrl($url);

        if ($grund !== null) {
            return ['ok' => false, 'meldung' => $grund, 'id' => 0];
        }

        $satz = [
            'customer_id' => ((int) ($daten['customer_id'] ?? 0)) ?: null,
            'project_id' => ((int) ($daten['project_id'] ?? 0)) ?: null,
            'label' => mb_substr(trim((string) ($daten['label'] ?? '')), 0, 191),
            'url' => mb_substr($url, 0, 255),
            'expect' => mb_substr(trim((string) ($daten['expect'] ?? '')), 0, 191),
            'active' => !empty($daten['active']) ? 1 : 0,
            'every_minutes' => max(5, min(1440, (int) ($daten['every_minutes'] ?? 15))),
            'notify' => !empty($daten['notify']) ? 1 : 0,
            'updated_at' => Db::now(),
        ];

        if ($satz['label'] === '') {
            $satz['label'] = (string) (parse_url($url, PHP_URL_HOST) ?: $url);
        }

        if ($id !== null) {
            Db::update('monitors', $satz, 'id = :id', ['id' => $id]);

            return ['ok' => true, 'meldung' => 'Gespeichert.', 'id' => $id];
        }

        $satz['created_at'] = Db::now();
        $neu = Db::insert('monitors', $satz);

        return ['ok' => true, 'meldung' => 'Die Seite wird jetzt überwacht.', 'id' => $neu];
    }

    public static function remove(int $id): bool
    {
        Db::delete('monitor_checks', 'monitor_id = :id', ['id' => $id]);

        return Db::delete('monitors', 'id = :id', ['id' => $id]) > 0;
    }

    // ------------------------------------------------------------------
    // Prüfen
    // ------------------------------------------------------------------

    /**
     * Der Takt für die Pflege: die fälligen Seiten prüfen, aber nicht alle
     * auf einmal. Bei zwanzig Kunden und je zehn Sekunden Wartezeit wäre
     * der Cron-Lauf sonst länger unterwegs als er darf.
     *
     * @return array{geprueft:int, ausfaelle:int}
     */
    public static function tick(int $max = 5): array
    {
        $faellig = Db::all(
            'SELECT * FROM monitors WHERE active = 1 ORDER BY
             CASE WHEN last_checked_at IS NULL THEN 0 ELSE 1 END, last_checked_at ASC
             LIMIT ' . max(1, $max * 3)
        );

        $geprueft = 0;
        $ausfaelle = 0;

        foreach ($faellig as $monitor) {
            if ($geprueft >= $max) {
                break;
            }

            $letzte = strtotime((string) ($monitor['last_checked_at'] ?? '')) ?: 0;
            $abstand = max(5, (int) $monitor['every_minutes']) * 60;

            if ($letzte > 0 && time() - $letzte < $abstand) {
                continue;
            }

            $ergebnis = self::run($monitor);
            $geprueft++;

            if (!$ergebnis['ok']) {
                $ausfaelle++;
            }
        }

        if ($geprueft > 0) {
            self::prune();
        }

        return ['geprueft' => $geprueft, 'ausfaelle' => $ausfaelle];
    }

    /**
     * Eine Seite prüfen, das Ergebnis festhalten und notfalls Bescheid geben.
     *
     * @param array<string, mixed> $monitor
     * @return array{ok:bool, code:int, ms:int, note:string}
     */
    public static function run(array $monitor): array
    {
        $id = (int) $monitor['id'];
        $ergebnis = self::probe((string) $monitor['url'], (string) ($monitor['expect'] ?? ''));

        Db::insert('monitor_checks', [
            'monitor_id' => $id,
            'checked_at' => Db::now(),
            'ok' => $ergebnis['ok'] ? 1 : 0,
            'code' => $ergebnis['code'],
            'ms' => $ergebnis['ms'],
            'note' => mb_substr($ergebnis['note'], 0, 255),
        ]);

        $warVorher = (int) ($monitor['last_ok'] ?? 1) === 1;
        $reihe = $ergebnis['ok'] ? 0 : (int) ($monitor['fail_streak'] ?? 0) + 1;

        $satz = [
            'last_checked_at' => Db::now(),
            'last_ok' => $ergebnis['ok'] ? 1 : 0,
            'last_code' => $ergebnis['code'],
            'last_ms' => $ergebnis['ms'],
            'last_note' => mb_substr($ergebnis['note'], 0, 255),
            'fail_streak' => $reihe,
            'updated_at' => Db::now(),
        ];

        if ($ergebnis['cert'] !== '') {
            $satz['cert_expires_on'] = $ergebnis['cert'];
        }

        if ($ergebnis['ok']) {
            $satz['down_since'] = null;
        } elseif ($warVorher) {
            $satz['down_since'] = Db::now();
        }

        Db::update('monitors', $satz, 'id = :id', ['id' => $id]);

        // Meldung beim Ausfall – und die Entwarnung, wenn sie wieder da ist.
        if (!$ergebnis['ok'] && $reihe === self::FAILS_BEFORE_ALARM) {
            self::alarm($monitor, $ergebnis, false);
        } elseif ($ergebnis['ok'] && !$warVorher && (int) ($monitor['fail_streak'] ?? 0) >= self::FAILS_BEFORE_ALARM) {
            self::alarm($monitor, $ergebnis, true);
        }

        return $ergebnis;
    }

    /**
     * Der eigentliche Abruf.
     *
     * Geprüft wird gegen dieselbe SSRF-Sperre wie beim Einlesen alter
     * Websites: Was in ein privates Netz zeigt, wird nicht abgerufen.
     *
     * @return array{ok:bool, code:int, ms:int, note:string, cert:string}
     */
    public static function probe(string $url, string $expect = ''): array
    {
        $grund = Http::assertPublicUrl($url);

        if ($grund !== null) {
            return ['ok' => false, 'code' => 0, 'ms' => 0, 'note' => $grund, 'cert' => ''];
        }

        $start = microtime(true);

        $ch = curl_init();
        $zertifikat = '';

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CERTINFO => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; WebAtze-Wächter/'
                . (defined('WEBATZE_VERSION') ? WEBATZE_VERSION : '1') . '; +https://webatze.ch)',
            CURLOPT_HTTPHEADER => ['Accept: text/html,*/*', 'Cache-Control: no-cache'],
        ]);

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $fehler = curl_error($ch);

        $info = curl_getinfo($ch, CURLINFO_CERTINFO);

        if (is_array($info) && isset($info[0]['Expire date'])) {
            $zeit = strtotime((string) $info[0]['Expire date']);

            if ($zeit !== false) {
                $zertifikat = date('Y-m-d', $zeit);
            }
        }

        curl_close($ch);

        $ms = (int) round((microtime(true) - $start) * 1000);

        if ($body === false) {
            return [
                'ok' => false,
                'code' => $code,
                'ms' => $ms,
                'note' => self::verstaendlich($fehler),
                'cert' => $zertifikat,
            ];
        }

        if ($code < 200 || $code >= 400) {
            return [
                'ok' => false,
                'code' => $code,
                'ms' => $ms,
                'note' => 'Der Server antwortet mit ' . $code . '.',
                'cert' => $zertifikat,
            ];
        }

        // Ein Server kann 200 melden und trotzdem eine Fehlerseite zeigen.
        // Deshalb der Wortvergleich, wenn einer hinterlegt ist.
        if ($expect !== '' && !str_contains((string) $body, $expect)) {
            return [
                'ok' => false,
                'code' => $code,
                'ms' => $ms,
                'note' => 'Die Seite lädt, aber «' . mb_substr($expect, 0, 60) . '» steht nicht darin.',
                'cert' => $zertifikat,
            ];
        }

        return ['ok' => true, 'code' => $code, 'ms' => $ms, 'note' => '', 'cert' => $zertifikat];
    }

    /** Aus einer curl-Meldung einen Satz machen, den man am Telefon vorlesen kann. */
    private static function verstaendlich(string $fehler): string
    {
        $klein = strtolower($fehler);

        return match (true) {
            str_contains($klein, 'could not resolve host') =>
                'Der Name der Website lässt sich nicht auflösen – meist ein Problem mit der Domain.',
            str_contains($klein, 'timed out') =>
                'Der Server hat nicht innerhalb von 20 Sekunden geantwortet.',
            str_contains($klein, 'connection refused') =>
                'Der Server nimmt keine Verbindungen an.',
            str_contains($klein, 'certificate') || str_contains($klein, 'ssl') =>
                'Mit dem Sicherheitszertifikat stimmt etwas nicht: ' . $fehler,
            $fehler === '' => 'Die Seite liess sich nicht laden.',
            default => $fehler,
        };
    }

    /**
     * Bescheid geben – aber nicht in Dauerschleife.
     *
     * @param array<string, mixed> $monitor
     * @param array{ok:bool, code:int, ms:int, note:string} $ergebnis
     */
    private static function alarm(array $monitor, array $ergebnis, bool $entwarnung): void
    {
        if (empty($monitor['notify'])) {
            return;
        }

        $empfaenger = trim((string) Settings::get('alert_email', ''));

        if ($empfaenger === '') {
            $empfaenger = trim((string) Settings::get('contact_email', ''));
        }

        if ($empfaenger === '' || !Mailer::isValidAddress($empfaenger)) {
            return;
        }

        $zuletzt = strtotime((string) ($monitor['notified_at'] ?? '')) ?: 0;

        if (!$entwarnung && time() - $zuletzt < self::ALARM_PAUSE) {
            return;
        }

        $name = (string) ($monitor['label'] ?? $monitor['url']);

        $betreff = $entwarnung
            ? $name . ' ist wieder erreichbar'
            : $name . ' antwortet nicht';

        $text = $entwarnung
            ? $name . ' ist wieder online.' . "\n\n"
                . $monitor['url'] . "\n"
                . 'Antwortzeit: ' . $ergebnis['ms'] . ' ms.'
            : $name . ' antwortet seit zwei Prüfungen nicht mehr.' . "\n\n"
                . $monitor['url'] . "\n"
                . 'Grund: ' . ($ergebnis['note'] !== '' ? $ergebnis['note'] : 'unbekannt') . "\n\n"
                . 'Nachgesehen wird weiter; sobald die Seite wieder da ist, kommt eine Entwarnung.';

        Mailer::send($empfaenger, $betreff, $text);

        Db::update('monitors', ['notified_at' => Db::now()], 'id = :id', ['id' => (int) $monitor['id']]);

        Logger::info('Überwachung gemeldet.', [
            'monitor' => (int) $monitor['id'],
            'entwarnung' => $entwarnung,
        ]);
    }

    /** Den Verlauf kurz halten. */
    public static function prune(): int
    {
        $weg = 0;

        foreach (Db::all('SELECT id FROM monitors') as $monitor) {
            $id = (int) $monitor['id'];

            $grenze = Db::value(
                'SELECT checked_at FROM monitor_checks WHERE monitor_id = :id
                 ORDER BY checked_at DESC LIMIT 1 OFFSET ' . self::KEEP_CHECKS,
                ['id' => $id]
            );

            if ($grenze === null) {
                continue;
            }

            $weg += Db::delete(
                'monitor_checks',
                'monitor_id = :id AND checked_at < :grenze',
                ['id' => $id, 'grenze' => (string) $grenze]
            );
        }

        return $weg;
    }

    /**
     * Eine Website automatisch in die Überwachung nehmen.
     *
     * Wird nach dem Hochladen gerufen: Wer eine Seite veröffentlicht, will
     * sie überwacht haben, ohne daran denken zu müssen.
     */
    public static function adopt(string $url, string $label = '', ?int $customerId = null, ?int $projectId = null): void
    {
        $url = trim($url);

        if ($url === '') {
            return;
        }

        $host = (string) (parse_url($url, PHP_URL_HOST) ?: '');

        if ($host !== '' && Db::value(
            'SELECT id FROM monitors WHERE url LIKE :muster LIMIT 1',
            ['muster' => '%' . $host . '%']
        )) {
            return;
        }

        self::save([
            'url' => $url,
            'label' => $label !== '' ? $label : $host,
            'customer_id' => $customerId,
            'project_id' => $projectId,
            'active' => true,
            'notify' => true,
            'every_minutes' => 15,
        ]);
    }
}
