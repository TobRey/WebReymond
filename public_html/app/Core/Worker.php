<?php

declare(strict_types=1);

namespace WebAtze\Core;

use Throwable;
use WebAtze\Build\{Backup, Contracts, Pipeline, VisitCollector};

/**
 * Arbeitet die Warteschlange ab.
 *
 * Wird auf zwei Wegen gestartet:
 *   - vom Cronjob (jede Minute)
 *   - direkt nach dem Anlegen eines Auftrags, über /worker/tick
 *
 * Beide Wege enden hier. Der Worker hält ein Zeitbudget ein und hört
 * rechtzeitig auf, damit ihn das Zeitlimit des Hosters nicht mitten in
 * der Arbeit abschneidet.
 */
final class Worker
{
    /**
     * @return array{jobs:int, seconds:float, housekeeping:bool}
     */
    public static function run(?int $budgetSeconds = null): array
    {
        $started = microtime(true);
        $budget = $budgetSeconds ?? (int) Config::get('worker_budget_seconds', 50);

        // Etwas Luft lassen: die letzte Runde soll noch sauber enden können.
        $budget = max(5, min($budget, 280));

        @set_time_limit($budget + 30);
        @ignore_user_abort(true);

        $handled = 0;
        $housekeeping = false;

        // Aufträge, deren Sperre abgelaufen ist, wieder freigeben.
        Jobs::releaseStale();

        while (microtime(true) - $started < $budget) {
            $job = Jobs::claim();

            if ($job === null) {
                // Nichts zu tun – dann die Gelegenheit zum Aufräumen nutzen.
                $housekeeping = self::housekeeping();
                break;
            }

            $handled++;

            try {
                $remaining = $budget - (microtime(true) - $started);
                Pipeline::step($job, max(3.0, $remaining));
            } catch (Throwable $e) {
                $id = Logger::exception($e);
                Jobs::fail(
                    $job['id'],
                    self::describe($e, $id),
                    self::looksTemporary($e),
                    $e instanceof ConfigurationError
                        ? 'Angehalten – eine Einstellung fehlt.'
                        : ''
                );
            }
        }

        return [
            'jobs' => $handled,
            'seconds' => round(microtime(true) - $started, 2),
            'housekeeping' => $housekeeping,
        ];
    }

    /**
     * Regelmässige Pflege: abgelaufene Vorschauen löschen, alte Sitzungen
     * und Zähler entfernen, erledigte Aufträge nach einem Monat aufräumen.
     *
     * Läuft höchstens einmal pro Stunde – öfter bringt nichts und kostet
     * auf einem gemieteten Hosting nur Rechenzeit.
     */
    public static function housekeeping(): bool
    {
        $last = (int) Settings::get('housekeeping_at', '0');
        if (time() - $last < 3600) {
            return false;
        }

        Settings::put('housekeeping_at', (string) time());

        $removedPreviews = self::purgeExpiredPreviews();
        $removedSessions = Session::purgeExpired();
        $removedLimits = RateLimit::purgeExpired();
        $removedJobs = Jobs::purgeOld(30);
        $removedDevices = SecondFactor::purgeExpired();
        $removedForms = \WebAtze\Domain\Questionnaire::purgeExpired();

        Db::delete('login_attempts', 'attempted_at < :before', [
            'before' => date('Y-m-d H:i:s', time() - 30 * 86400),
        ]);

        // Besucherzahlen holen und montags berichten.
        $visits = VisitCollector::tick();

        // Sicherungen: einmal am Tag die eigene, dazu je Durchgang eine
        // Kundenseite. Alte Stände werden dabei gleich mit aufgeräumt.
        $backup = Backup::tick();

        // Fällige Wartungsverträge: Der Entwurf entsteht, verschickt
        // wird er von Hand.
        $billed = Contracts::billDue();

        Logger::info('Aufgeräumt', [
            'vorschauen' => $removedPreviews,
            'sitzungen' => $removedSessions,
            'limits' => $removedLimits,
            'auftraege' => $removedJobs,
            'geraete' => $removedDevices,
            'fragebogen' => $removedForms,
            'besuchszahlen' => $visits['geholt'],
            'berichte' => $visits['berichte'],
            'sicherung_eigen' => $backup['eigen'],
            'sicherung_projekt' => $backup['projekt'],
            'sicherungen_geloescht' => $backup['geloescht'],
            'wartungsrechnungen' => $billed,
        ]);

        return true;
    }

    /**
     * Vorschauen verschwinden nach der eingestellten Zeit (Standard 24 Stunden).
     * Das hält den Speicher frei und ist so vereinbart.
     */
    public static function purgeExpiredPreviews(): int
    {
        $expired = Db::all(
            'SELECT id, slug, preview_token FROM projects
             WHERE preview_expires_at IS NOT NULL AND preview_expires_at < :now
               AND preview_token <> :empty',
            ['now' => Db::now(), 'empty' => '']
        );

        $count = 0;

        foreach ($expired as $project) {
            $dir = STORAGE_DIR . '/previews/' . self::safeName((string) $project['preview_token']);

            if (is_dir($dir)) {
                delete_tree($dir);
            }

            Db::update('projects', [
                'preview_token' => '',
                'preview_expires_at' => null,
                'updated_at' => Db::now(),
            ], 'id = :id', ['id' => (int) $project['id']]);

            $count++;
        }

        return $count;
    }

    /**
     * War der Fehler vermutlich vorübergehend?
     *
     * Netzstörungen und Zeitüberschreitungen lohnen einen zweiten Versuch.
     * Ein Programmierfehler oder eine ungültige Eingabe dagegen nicht –
     * der zweite Versuch scheitert genauso und verzögert nur die Meldung.
     */
    private static function looksTemporary(Throwable $e): bool
    {
        // Eine falsche Einstellung behebt sich durch Warten nicht. Das
        // hier zu unterscheiden ist der Unterschied zwischen einer
        // Meldung und einer Meldung alle dreissig Sekunden, bis jemand
        // hinsieht.
        if ($e instanceof ConfigurationError) {
            return false;
        }

        $message = mb_strtolower($e->getMessage());

        foreach (['timeout', 'zeitüberschreitung', 'timed out', 'connection', 'verbindung',
                  'temporarily', 'try again', 'network', 'netzwerk', 'overloaded',
                  '429', '502', '503', '504'] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return !($e instanceof \InvalidArgumentException || $e instanceof \TypeError);
    }

    /**
     * Wie die Meldung beim Auftrag stehen soll.
     *
     * Bei einem Einstellungsfehler gehört dazu, was zu tun ist. Eine
     * Fehlermeldung ohne Anweisung lässt jemanden ratlos zurück – und
     * genau dann wird stattdessen einfach nochmal geklickt.
     */
    private static function describe(Throwable $e, string $id): string
    {
        $text = $e instanceof ConfigurationError ? $e->full() : $e->getMessage();

        return $text . ' (Kennnummer ' . $id . ')';
    }

    private static function safeName(string $name): string
    {
        return preg_replace('/[^A-Za-z0-9_-]/', '', $name) ?? '';
    }
}
