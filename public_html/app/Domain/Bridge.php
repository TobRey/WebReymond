<?php

declare(strict_types=1);

namespace WebAtze\Domain;

use WebAtze\Core\{Db, Http, Logger};

/**
 * Die Brücke zur Kundendomain.
 *
 * Das Relais gibt es schon – nur in die andere Richtung: Das
 * Kundenbackend ruft bei WebAtze an, weist sich mit einem Kennwort aus
 * und lässt einen Abschnitt ändern. Die Brücke ist dasselbe rückwärts:
 * WebAtze ruft beim Kunden an und veröffentlicht dort.
 *
 * Der Unterschied ist nicht klein, und deshalb ist die Absicherung eine
 * andere. Das Relais **liest** im Ergebnis nur; die Brücke **schreibt**
 * auf einem fremden Server. Ein Kennwort im Kopf einer Anfrage genügt
 * dafür nicht: Wer es einmal mitliest, kann jede Anfrage stellen, die er
 * will.
 *
 * Stattdessen wird unterschrieben:
 *
 *   Über Methode, Pfad, Zeitstempel, Einmalwert und den vollständigen
 *   Rumpf läuft ein HMAC-SHA256. Der Schlüssel steht auf beiden Servern
 *   und geht nie über die Leitung – er unterschreibt nur.
 *
 *   Ein Zeitfenster von zwei Minuten und ein Einmalwert, den die
 *   Gegenseite vermerkt. Eine mitgeschnittene Anfrage lässt sich damit
 *   nicht wiederholen: Sie ist entweder zu alt oder schon dagewesen.
 *
 * Und die wichtigste Zusage: Die Brücke nimmt **Daten** entgegen, nie
 * Code. Was ankommt, geht durch dieselbe Prüfung wie eine Eingabe im
 * Kundenbackend, und geschrieben werden anschliessend HTML-Dateien aus
 * dem Vorlagenwerk des Kunden. Selbst eine gefälschte Anfrage könnte
 * damit kein PHP auf den Kundenserver bringen – nur Inhalt, und der
 * wird beim Rendern maskiert.
 */
final class Bridge
{
    /** Wie lange eine Unterschrift gilt. */
    public const FENSTER = 120;

    /** Wie lange auf die Gegenseite gewartet wird. */
    private const TIMEOUT = 45;

    /** Wie gross ein Datensatz höchstens sein darf. */
    public const MAX_BYTES = 4 * 1024 * 1024;

    // ------------------------------------------------------------------
    // Schlüssel
    // ------------------------------------------------------------------

    /** Den Schlüssel einer Website holen, notfalls anlegen. */
    public static function secret(int $projectId): string
    {
        $vorhanden = (string) Db::value(
            'SELECT bridge_secret FROM projects WHERE id = :id',
            ['id' => $projectId],
            ''
        );

        if ($vorhanden !== '') {
            return $vorhanden;
        }

        return self::newSecret($projectId);
    }

    /** Einen neuen Schlüssel erzeugen – der alte gilt dann nicht mehr. */
    public static function newSecret(int $projectId): string
    {
        $neu = bin2hex(random_bytes(32));

        Db::update(
            'projects',
            ['bridge_secret' => $neu, 'bridge_seen' => null, 'updated_at' => Db::now()],
            'id = :id',
            ['id' => $projectId]
        );

        return $neu;
    }

    /** Die Brücke abschalten. */
    public static function revoke(int $projectId): void
    {
        Db::update(
            'projects',
            ['bridge_secret' => '', 'bridge_seen' => null, 'updated_at' => Db::now()],
            'id = :id',
            ['id' => $projectId]
        );
    }

    // ------------------------------------------------------------------
    // Unterschreiben
    // ------------------------------------------------------------------

    /**
     * Die Unterschrift einer Anfrage.
     *
     * Sie deckt alles ab, was die Gegenseite auswertet. Fehlte auch nur
     * der Pfad, liesse sich eine gültige Anfrage an eine andere Adresse
     * umbiegen; fehlte der Rumpf, liesse sich sein Inhalt austauschen.
     */
    public static function sign(
        string $secret,
        string $methode,
        string $pfad,
        int $zeit,
        string $einmal,
        string $rumpf
    ): string {
        $material = implode("\n", [
            strtoupper($methode),
            $pfad,
            (string) $zeit,
            $einmal,
            hash('sha256', $rumpf),
        ]);

        return hash_hmac('sha256', $material, $secret);
    }

    // ------------------------------------------------------------------
    // Sprechen
    // ------------------------------------------------------------------

    /**
     * Etwas an die Brücke einer Website schicken.
     *
     * @return array{ok:bool, error:string, data:array, status:int}
     */
    public static function call(array $projekt, string $aktion, array $rumpf = []): array
    {
        $adresse = self::url($projekt);

        if ($adresse === '') {
            return self::fehler('Für diese Website ist keine Adresse hinterlegt.');
        }

        $secret = (string) ($projekt['bridge_secret'] ?? '');

        if ($secret === '') {
            return self::fehler('Für diese Website ist keine Brücke eingerichtet.');
        }

        $rumpf['aktion'] = $aktion;
        $json = json_encode($rumpf, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false || strlen($json) > self::MAX_BYTES) {
            return self::fehler('Der Datensatz ist zu gross für eine Übertragung.');
        }

        $zeit = time();
        $einmal = bin2hex(random_bytes(16));
        $pfad = parse_url($adresse, PHP_URL_PATH) ?: '/';

        $unterschrift = self::sign($secret, 'POST', $pfad, $zeit, $einmal, $json);

        $antwort = Http::postRaw($adresse, $json, [
            'Content-Type: application/json',
            'X-WebAtze-Zeit: ' . $zeit,
            'X-WebAtze-Einmal: ' . $einmal,
            'X-WebAtze-Unterschrift: ' . $unterschrift,
        ], self::TIMEOUT);

        if (!($antwort['ok'] ?? false)) {
            return self::fehler((string) ($antwort['error'] ?? 'Die Brücke antwortet nicht.'));
        }

        $daten = json_decode((string) ($antwort['body'] ?? ''), true);

        if (!is_array($daten)) {
            return self::fehler('Die Brücke hat etwas geantwortet, das keine Antwort ist.');
        }

        // Ein Lebenszeichen. Ohne es wüsste niemand, ob die Brücke
        // steht, bis das erste Veröffentlichen fehlschlägt.
        if (!empty($daten['ok'])) {
            Db::update('projects', [
                'bridge_seen' => Db::now(),
                'bridge_state' => mb_substr((string) ($daten['stand'] ?? ''), 0, 64),
                'updated_at' => Db::now(),
            ], 'id = :id', ['id' => (int) $projekt['id']]);
        }

        return [
            'ok' => (bool) ($daten['ok'] ?? false),
            'error' => (string) ($daten['error'] ?? ''),
            'data' => $daten,
            'status' => (int) ($antwort['status'] ?? 0),
        ];
    }

    /** Kurz nachfragen, ob die Brücke steht. */
    public static function ping(array $projekt): array
    {
        return self::call($projekt, 'ping');
    }

    /**
     * Einen neuen Stand veröffentlichen.
     *
     * Vorher wird gefragt, welchen Stand der Kunde hat. Hat er selbst
     * etwas geändert – und das darf er, es ist seine Website –, wird
     * nicht stillschweigend darübergeschrieben.
     */
    public static function publish(array $projekt, array $site, bool $ueberschreiben = false): array
    {
        if (!$ueberschreiben) {
            $stand = self::call($projekt, 'stand');

            if ($stand['ok'] && self::fremdGeaendert($projekt, $stand['data'])) {
                return self::fehler(
                    'Auf der Kundenseite wurde seit der letzten Veröffentlichung etwas geändert. '
                    . 'Veröffentlichen würde es überschreiben.'
                );
            }
        }

        return self::call($projekt, 'veroeffentlichen', ['site' => $site]);
    }

    /**
     * Hat der Kunde selbst etwas geändert?
     *
     * Verglichen wird die Prüfsumme, die die Brücke meldet, mit der,
     * die beim letzten Kontakt vermerkt wurde. Weichen sie ab, war
     * jemand anderes am Werk.
     */
    private static function fremdGeaendert(array $projekt, array $antwort): bool
    {
        $gemerkt = (string) ($projekt['bridge_state'] ?? '');
        $jetzt = (string) ($antwort['stand'] ?? '');

        return $gemerkt !== '' && $jetzt !== '' && $gemerkt !== $jetzt;
    }

    // ------------------------------------------------------------------

    /** Wo die Brücke einer Website liegt. */
    public static function url(array $projekt): string
    {
        $domain = trim((string) ($projekt['domain'] ?? ''));

        if ($domain === '') {
            return '';
        }

        if (!str_starts_with($domain, 'http')) {
            $domain = 'https://' . $domain;
        }

        return rtrim($domain, '/') . '/wa-bruecke.php';
    }

    /** @return array{ok:bool, error:string, data:array, status:int} */
    private static function fehler(string $text): array
    {
        return ['ok' => false, 'error' => $text, 'data' => [], 'status' => 0];
    }
}
