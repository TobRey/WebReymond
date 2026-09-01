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
    /** Nach so vielen Anlaeufen wird ein Auftrag angehalten. */
    private const MAX_TICKS = 70;

    public static function run(?int $budgetSeconds = null): array
    {
        $started = microtime(true);
        $budget = $budgetSeconds ?? (int) Config::get('worker_budget_seconds', 50);

        // Etwas Luft lassen: die letzte Runde soll noch sauber enden können.
        $budget = max(5, min($budget, 280));

        // Wie lange laesst dieser Server einen Vorgang leben?
        //
        // Auf geteiltem Hosting steht in der php.ini oft eine Grenze, und
        // set_time_limit() ist manchmal gesperrt. Wird der Vorgang
        // abgeschossen, endet er nicht sauber - der Auftrag bleibt
        // gesperrt liegen, bis die Sperre ablaeuft. Von aussen sieht das
        // aus, als haenge er.
        //
        // Deshalb wird gemessen statt vermutet: Beim Start wird ein
        // Merker gesetzt und waehrend der Arbeit fortgeschrieben. Findet
        // der naechste Durchlauf den Merker noch vor, ist der letzte
        // gestorben - und der Abstand sagt, wie lange er durchgehalten
        // hat. Danach richtet sich das Zeitfenster.
        self::measureLastTick();

        $ueberlebt = (int) Settings::get('worker_survival_seconds', '0');

        if ($ueberlebt > 0) {
            // Acht Sekunden Sicherheitsabstand, damit der Durchlauf noch
            // sauber enden kann statt mittendrin zu sterben.
            $budget = max(12, min($budget, $ueberlebt - 8));
        }

        Settings::put('worker_tick_open', (string) time());
        Settings::put('worker_tick_beat', (string) time());

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

            // Notbremse. Ein Auftrag, der nach sehr vielen Anlaeufen
            // immer noch laeuft, kommt nicht mehr ans Ziel - er dreht
            // sich im Kreis. Jeder Kreis kostet Geld, deshalb wird hier
            // angehalten statt weiterprobiert. Bei einem Anlauf je
            // Minute ist das gut eine Stunde Geduld.
            if ((int) ($job['attempts'] ?? 0) > self::MAX_TICKS) {
                Jobs::fail(
                    $job['id'],
                    'Der Auftrag wurde nach ' . self::MAX_TICKS . ' Anlaeufen angehalten, '
                    . 'weil er nicht weiterkam. Bitte melde dich - so etwas soll nicht vorkommen.',
                    false,
                    'Angehalten - kam nicht weiter.'
                );
                continue;
            }

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

        // Sauber zu Ende gekommen - der Merker darf weg. Nur wenn er
        // stehen bleibt, weiss der naechste Durchlauf, dass hier etwas
        // abgeschossen wurde.
        Settings::put('worker_tick_open', '');

        return [
            'jobs' => $handled,
            'seconds' => round(microtime(true) - $started, 2),
            'housekeeping' => $housekeeping,
            'budget' => $budget,
            'ueberlebt' => $ueberlebt,
        ];
    }

    /**
     * Ein Lebenszeichen setzen.
     *
     * Wird waehrend der Arbeit regelmaessig gerufen. Stirbt der Vorgang,
     * steht hier der letzte Zeitpunkt, an dem er noch lebte.
     */
    public static function beat(): void
    {
        Settings::put('worker_tick_beat', (string) time());
    }

    /**
     * Nachsehen, ob der letzte Durchlauf sauber geendet hat.
     *
     * Steht der Merker noch, wurde er abgeschossen. Der Abstand zwischen
     * Start und letztem Lebenszeichen ist dann die Zeit, die dieser
     * Server einem Vorgang zugesteht.
     */
    private static function measureLastTick(): void
    {
        $offen = (int) Settings::get('worker_tick_open', '');

        if ($offen <= 0) {
            return;
        }

        $letzter = (int) Settings::get('worker_tick_beat', '');
        $gelebt = $letzter - $offen;

        // Unter fuenf Sekunden ist keine Messung, sondern ein Fehlstart.
        // Ueber 280 auch nicht - dann lag es an etwas anderem.
        if ($gelebt < 5 || $gelebt > 280) {
            Settings::put('worker_tick_open', '');
            return;
        }

        $bisher = (int) Settings::get('worker_survival_seconds', '0');

        // Den vorsichtigeren Wert behalten: Ein Durchlauf kann zufaellig
        // laenger gelebt haben, aber die kuerzeste beobachtete Grenze ist
        // die, mit der man rechnen muss.
        $neu = $bisher > 0 ? min($bisher, $gelebt) : $gelebt;

        Settings::put('worker_survival_seconds', (string) $neu);
        Settings::put('worker_tick_open', '');

        Logger::warning('Der Durchlauf wurde abgeschossen - Zeitfenster wird angepasst.', [
            'gelebt' => $gelebt,
            'rechnet_ab_jetzt_mit' => $neu,
        ]);
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
