<?php

declare(strict_types=1);

namespace WebAtze\Core;

use Throwable;

/**
 * Aufträge, die länger dauern als eine Webseite laden darf.
 *
 * Warum überhaupt?
 * Eine Website zu erzeugen dauert Minuten. Auf einem gemieteten Hosting
 * bricht PHP eine Anfrage aber nach 30 bis 120 Sekunden ab. Deshalb wird
 * die Arbeit in Schritte zerlegt und in einer Warteschlange abgearbeitet:
 *
 *   1. Das Formular legt einen Auftrag an und antwortet sofort.
 *   2. worker.php arbeitet immer nur so lange, wie sicher erlaubt ist,
 *      merkt sich den Stand und hört auf.
 *   3. Der nächste Durchlauf macht dort weiter.
 *
 * Angestossen wird der Worker doppelt: sofort nach dem Anlegen über eine
 * eigene Anfrage, und zusätzlich jede Minute durch den Cronjob. Fällt das
 * eine aus, greift das andere.
 */
final class Jobs
{
    public const QUEUED = 'queued';
    public const RUNNING = 'running';
    public const DONE = 'done';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';

    private const MAX_ATTEMPTS = 3;
    private const LOCK_SECONDS = 300;

    /** Neuen Auftrag anlegen. */
    public static function enqueue(string $type, array $payload = [], ?int $projectId = null): int
    {
        $id = Db::insert('jobs', [
            'project_id' => $projectId,
            'type' => $type,
            'step' => '',
            'status' => self::QUEUED,
            'progress' => 0,
            'message' => 'Wird vorbereitet …',
            'payload' => $payload,
            'state' => [],
            'attempts' => 0,
            'run_after' => Db::now(),
            'created_at' => Db::now(),
        ]);

        Logger::info('Auftrag angelegt', ['job' => $id, 'type' => $type]);
        return $id;
    }

    public static function find(int $id): ?array
    {
        $row = Db::first('SELECT * FROM jobs WHERE id = :id', ['id' => $id]);
        return $row === null ? null : self::hydrate($row);
    }

    /** Aktueller Auftrag eines Projekts, falls einer läuft. */
    public static function activeFor(int $projectId): ?array
    {
        $row = Db::first(
            'SELECT * FROM jobs
             WHERE project_id = :p AND status IN (:queued, :running)
             ORDER BY id DESC LIMIT 1',
            ['p' => $projectId, 'queued' => self::QUEUED, 'running' => self::RUNNING]
        );
        return $row === null ? null : self::hydrate($row);
    }

    /**
     * Nächsten Auftrag holen und für andere Durchläufe sperren.
     *
     * Die Sperre verhindert, dass zwei gleichzeitig gestartete Worker
     * denselben Auftrag doppelt bearbeiten. Sie läuft nach fünf Minuten
     * von selbst ab – so bleibt kein Auftrag hängen, wenn ein Durchlauf
     * mitten in der Arbeit abgeschossen wird.
     */
    public static function claim(): ?array
    {
        return Db::transaction(static function (): ?array {
            $now = Db::now();

            $row = Db::first(
                'SELECT * FROM jobs
                 WHERE status IN (:queued, :running)
                   AND (run_after IS NULL OR run_after <= :now1)
                   AND (locked_until IS NULL OR locked_until <= :now2)
                 ORDER BY id ASC LIMIT 1',
                ['queued' => self::QUEUED, 'running' => self::RUNNING, 'now1' => $now, 'now2' => $now]
            );

            if ($row === null) {
                return null;
            }

            Db::update('jobs', [
                'status' => self::RUNNING,
                'locked_until' => date('Y-m-d H:i:s', time() + self::LOCK_SECONDS),
                'started_at' => $row['started_at'] ?? $now,
                'attempts' => (int) $row['attempts'] + 1,
            ], 'id = :id', ['id' => (int) $row['id']]);

            $row['status'] = self::RUNNING;
            $row['attempts'] = (int) $row['attempts'] + 1;

            return self::hydrate($row);
        });
    }

    /** Stand festhalten, damit der nächste Durchlauf weiss, wo es weitergeht. */
    public static function progress(int $id, string $step, int $progress, string $message, array $state = []): void
    {
        $data = [
            'step' => mb_substr($step, 0, 40),
            'progress' => max(0, min(100, $progress)),
            'message' => mb_substr($message, 0, 250),
            'locked_until' => date('Y-m-d H:i:s', time() + self::LOCK_SECONDS),
        ];

        if ($state !== []) {
            $data['state'] = $state;
        }

        Db::update('jobs', $data, 'id = :id', ['id' => $id]);
    }

    /** Auftrag unterbrechen und beim nächsten Durchlauf fortsetzen. */
    public static function yield(int $id, array $state): void
    {
        Db::update('jobs', [
            'status' => self::QUEUED,
            'state' => $state,
            'locked_until' => null,
            'run_after' => Db::now(),
        ], 'id = :id', ['id' => $id]);
    }

    public static function finish(int $id, string $message = 'Fertig.'): void
    {
        Db::update('jobs', [
            'status' => self::DONE,
            'progress' => 100,
            'message' => mb_substr($message, 0, 250),
            'locked_until' => null,
            'finished_at' => Db::now(),
        ], 'id = :id', ['id' => $id]);

        Logger::info('Auftrag fertig', ['job' => $id]);
    }

    /**
     * Auftrag ist gescheitert.
     *
     * Vorübergehende Störungen (Netz, Zeitüberschreitung) bekommen einen
     * zweiten und dritten Versuch mit wachsendem Abstand. Was auch dann
     * nicht klappt, bleibt sichtbar stehen – niemals stillschweigend.
     */
    public static function fail(int $id, string $error, bool $retryable = true, string $shortMessage = ''): void
    {
        $job = self::find($id);
        $attempts = (int) ($job['attempts'] ?? 0);

        if ($retryable && $attempts < self::MAX_ATTEMPTS) {
            $wait = 30 * (2 ** ($attempts - 1));

            Db::update('jobs', [
                'status' => self::QUEUED,
                'error' => mb_substr($error, 0, 2000),
                'message' => sprintf('Versuch %d fehlgeschlagen, neuer Versuch in %d Sekunden.', $attempts, $wait),
                'locked_until' => null,
                'run_after' => date('Y-m-d H:i:s', time() + $wait),
            ], 'id = :id', ['id' => $id]);

            Logger::warning('Auftrag wiederholt', ['job' => $id, 'versuch' => $attempts]);
            return;
        }

        Db::update('jobs', [
            'status' => self::FAILED,
            'error' => mb_substr($error, 0, 2000),
            'message' => $shortMessage !== '' ? mb_substr($shortMessage, 0, 190) : 'Fehlgeschlagen.',
            'locked_until' => null,
            'finished_at' => Db::now(),
        ], 'id = :id', ['id' => $id]);

        Logger::error('Auftrag endgültig fehlgeschlagen', ['job' => $id, 'fehler' => $error]);
    }

    /**
     * Einen gescheiterten Auftrag wieder aufnehmen.
     *
     * Bewusst ohne Rücksetzen des Zwischenstands: Jeder Schritt merkt
     * sich, wie weit er gekommen ist. Wer eine fehlende Einstellung
     * nachträgt, soll dort weitermachen, wo es geklemmt hat – nicht
     * bezahlte Arbeit noch einmal bezahlen.
     */
    public static function retry(int $id): bool
    {
        $job = self::find($id);

        if ($job === null || !in_array((string) $job['status'], [self::FAILED, self::CANCELLED], true)) {
            return false;
        }

        Db::update('jobs', [
            'status' => self::QUEUED,
            'attempts' => 0,
            'error' => '',
            'message' => 'Wird fortgesetzt …',
            'locked_until' => null,
            'run_after' => null,
            'finished_at' => null,
        ], 'id = :id', ['id' => $id]);

        Logger::info('Auftrag wieder aufgenommen', ['job' => $id, 'schritt' => (string) $job['step']]);

        return true;
    }

    public static function cancel(int $id): void
    {
        Db::update('jobs', [
            'status' => self::CANCELLED,
            'message' => 'Abgebrochen.',
            'locked_until' => null,
            'finished_at' => Db::now(),
        ], 'id = :id AND status IN (:q, :r)', [
            'id' => $id, 'q' => self::QUEUED, 'r' => self::RUNNING,
        ]);
    }

    /**
     * Den Worker sofort anstossen, ohne auf die Antwort zu warten.
     *
     * Ohne das würde ein Auftrag bis zum nächsten Cron-Lauf liegenbleiben –
     * also bis zu eine Minute. Die Verbindung wird bewusst nach dem
     * Absenden geschlossen: die Antwort interessiert nicht.
     */
    public static function nudge(): void
    {
        $url = Config::url('worker/tick?key=' . self::nudgeKey());
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['host'])) {
            return;
        }

        $secure = ($parts['scheme'] ?? 'http') === 'https';
        $port = (int) ($parts['port'] ?? ($secure ? 443 : 80));
        $target = ($secure ? 'ssl://' : '') . $parts['host'];

        $socket = @fsockopen($target, $port, $errno, $errstr, 2);
        if ($socket === false) {
            return;
        }

        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

        $request = "GET {$path} HTTP/1.1\r\n"
            . 'Host: ' . $parts['host'] . "\r\n"
            . "User-Agent: WebAtze-Worker\r\n"
            . "Connection: Close\r\n\r\n";

        @stream_set_timeout($socket, 1);
        @fwrite($socket, $request);
        @fclose($socket);
    }

    /** Einfacher Schlüssel, damit der Anstoss nicht von aussen missbraucht wird. */
    public static function nudgeKey(): string
    {
        return substr(hash_hmac('sha256', 'worker-nudge', (string) Config::get('app_key')), 0, 32);
    }

    /** Aufträge, die zu lange hängen, wieder freigeben. */
    public static function releaseStale(): int
    {
        return Db::update('jobs', [
            'locked_until' => null,
            'status' => self::QUEUED,
        ], 'status = :r AND locked_until IS NOT NULL AND locked_until < :now', [
            'r' => self::RUNNING,
            'now' => Db::now(),
        ]);
    }

    /** Alte, abgeschlossene Aufträge aufräumen. */
    public static function purgeOld(int $days = 30): int
    {
        return Db::delete(
            'jobs',
            'status IN (:d, :f, :c) AND finished_at < :before',
            [
                'd' => self::DONE, 'f' => self::FAILED, 'c' => self::CANCELLED,
                'before' => date('Y-m-d H:i:s', time() - $days * 86400),
            ]
        );
    }

    /** JSON-Spalten in Arrays verwandeln. */
    private static function hydrate(array $row): array
    {
        foreach (['payload', 'state'] as $column) {
            $decoded = json_decode((string) ($row[$column] ?? '[]'), true);
            $row[$column] = is_array($decoded) ? $decoded : [];
        }
        $row['id'] = (int) $row['id'];
        $row['progress'] = (int) $row['progress'];
        $row['attempts'] = (int) $row['attempts'];
        $row['project_id'] = $row['project_id'] === null ? null : (int) $row['project_id'];

        return $row;
    }
}
