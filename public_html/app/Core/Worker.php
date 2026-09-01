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
    /** Laenger als das darf ein einzelner Auftrag nicht brauchen. */
    private const MAX_RUNTIME_SECONDS = 4 * 3600;

    public static function run(?int $budgetSeconds = null): array
    {
        $started = microtime(true);
        $budget = $budgetSeconds ?? (int) Config::get('worker_budget_seconds', 300);

        // Fuenf Minuten statt fuenfzig Sekunden.
        //
        // Ein Aufruf an die KI dauert 20 bis 30 Sekunden. Bei fuenfzig
        // Sekunden Zeitfenster schaffte ein Durchlauf also zwei Stueck -
        // und eine Website mit elf Seiten und drei Sprachen braucht
        // gegen achtzig. Bei einem Cronjob je Minute sind das vierzig
        // Minuten. Der Bau war nie kaputt, er war zaeh; wer nach zehn
        // Minuten abbricht, sieht nur, dass sich nichts tut.
        //
        // Laenger ist gefahrlos: Faellt der Cronjob waehrenddessen
        // erneut, findet der zweite Arbeiter den Auftrag gesperrt und
        // geht sofort wieder. Und bricht der Server ab, ist nichts
        // verloren - jede Gruppe ist einzeln abgelegt, und die Messung
        // unten zieht das Zeitfenster automatisch nach unten.
        $budget = max(5, min($budget, 3600));

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
            // Der Server hat schon einmal abgebrochen - dann gilt seine
            // Grenze, mit acht Sekunden Sicherheitsabstand, damit der
            // Durchlauf noch sauber enden kann statt mittendrin zu
            // sterben.
            $budget = max(12, min($budget, $ueberlebt - 8));
        } elseif ($budgetSeconds === null && (int) Config::get('worker_budget_seconds', 300) >= 300) {
            // Noch nie ein Abbruch: Dieser Server setzt offenbar keine
            // Grenze, also darf laenger gearbeitet werden.
            //
            // Das ist wichtiger, als es aussieht. Bestehende
            // Installationen tragen in ihrer config.php noch die alten
            // fuenfzig Sekunden - und die Datei wird beim Update
            // absichtlich nicht angefasst. Bei fuenfzig Sekunden schafft
            // ein Durchlauf zwei Aufrufe, eine groessere Website braucht
            // achtzig: vierzig Minuten bei einem Cronjob je Minute. Wer
            // vorher abbricht, sieht nur, dass sich nichts tut.
            //
            // Also entscheidet hier die Messung, nicht die Datei. Bricht
            // der Server doch ab, greift der Zweig darueber ab dem
            // naechsten Durchlauf.
            // In einem Zug durcharbeiten, statt in Minutenscheiben.
            //
            // Das war die eigentliche Schwaeche: Ein Bau wurde in vierzig
            // Haeppchen zerlegt, und zwischen je zwei lag ein
            // Prozessende, eine Sperre, ein Cron-Lauf. Vierzig
            // Uebergaenge sind vierzig Stellen, an denen etwas
            // haengenbleiben kann - und genau das ist immer wieder
            // passiert, jedes Mal woanders.
            //
            // Dieser Server setzt keine Grenze (sonst waere oben etwas
            // gemessen worden), also wird der Auftrag am Stueck zu Ende
            // gebracht. Faellt der Cronjob zwischendurch erneut, findet
            // der zweite Arbeiter den Auftrag gesperrt und geht sofort
            // wieder.
            $budget = max($budget, 3000);
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
            self::$currentJob = (int) $job['id'];

            // Notbremse nach der Uhr, nicht nach dem Zaehler.
            //
            // Wie oft ein Auftrag abgeholt wurde, sagt nichts: Er ist
            // darauf ausgelegt, in vielen kleinen Takten zu laufen. Wie
            // lange er insgesamt braucht, sagt sehr wohl etwas. Ein Bau
            // dauert normal Minuten; wer nach Stunden noch laeuft, dreht
            // sich im Kreis, und jeder Kreis kostet Geld.
            $seit = strtotime((string) ($job['started_at'] ?? '')) ?: time();

            if (time() - $seit > self::MAX_RUNTIME_SECONDS) {
                Jobs::fail(
                    $job['id'],
                    'Der Auftrag laeuft seit ueber ' . round(self::MAX_RUNTIME_SECONDS / 3600, 1)
                    . ' Stunden und kommt nicht ans Ziel. Er wurde angehalten, damit er '
                    . 'nicht weiter Kosten verursacht. Bitte melde dich - so etwas soll '
                    . 'nicht vorkommen.',
                    false,
                    'Angehalten - dauerte zu lange.'
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
                        ? ($e->field() === 'anthropic_credit'
                            ? 'Angehalten – das Guthaben ist aufgebraucht.'
                            : 'Angehalten – eine Einstellung fehlt.')
                        : ''
                );
            }
        }

        self::$currentJob = null;

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

    /** Woran gerade gearbeitet wird - fuer den Herzschlag. */
    private static ?int $currentJob = null;

    /**
     * Ein Lebenszeichen setzen.
     *
     * Zwei Aufgaben auf einmal. Erstens: Stirbt der Vorgang, steht hier
     * der letzte Zeitpunkt, an dem er noch lebte - daraus lernt der
     * naechste Durchlauf das Zeitfenster dieses Servers.
     *
     * Zweitens, und das ist das Wichtigere: Die Sperre des Auftrags wird
     * mitgezogen. Deshalb darf sie kurz sein. Frueher musste sie lang
     * sein, damit kein zweiter Arbeiter dazwischenfunkt - und nach jedem
     * Abschuss lag der Auftrag genau so lange brach. Das war der
     * Stillstand: nicht ein Fehler, sondern Wartezeit. Jetzt haelt ein
     * lebender Vorgang seine Sperre selbst aufrecht, und ein toter gibt
     * sie binnen Sekunden frei.
     */
    public static function beat(): void
    {
        Settings::put('worker_tick_beat', (string) time());

        if (self::$currentJob !== null) {
            Jobs::keepAlive(self::$currentJob);
        }
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
