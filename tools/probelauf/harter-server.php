<?php

/**
 * Der Nachbau eines unfreundlichen Servers.
 *
 * Auf geteiltem Hosting wird ein Vorgang nach einer festen Zeit
 * abgeschossen - mitten in der Arbeit, ohne Fehlermeldung, ohne dass der
 * Auftrag sauber enden koennte. Genau das liess sich hier nie
 * nachstellen: Wer den Arbeiter im selben Prozess aufruft, sieht diesen
 * Fall nicht.
 *
 * Also wird er hier erzeugt. worker.php laeuft als eigener Prozess und
 * wird nach einer vorgegebenen Zeit hart beendet - wie es der Cronjob
 * jede Minute tut, wenn der Server dazwischenfunkt.
 *
 * Aufruf:
 *   php tools/probelauf/harter-server.php --sekunden=25 --takte=60
 *
 * Ausgabe ist eine Zeile je Takt: Fortschritt, Schritt, Seite/Gruppe,
 * Anzahl bezahlter Aufrufe. Bleibt der Fortschritt stehen, waehrend die
 * Aufrufe steigen, ist genau der Fehler sichtbar, um den es geht.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/public_html/app/bootstrap.php';

use WebAtze\Core\Db;

$argumente = [];
foreach ($argv as $arg) {
    if (preg_match('/^--([a-z]+)=(.+)$/', $arg, $t)) {
        $argumente[$t[1]] = $t[2];
    }
}

$sekunden = max(5, (int) ($argumente['sekunden'] ?? 25));
$takte = max(1, (int) ($argumente['takte'] ?? 40));
$pause = max(0, (int) ($argumente['pause'] ?? 1));

$worker = BASE_DIR . '/worker.php';

echo "Server bricht nach {$sekunden}s ab, {$takte} Takte, {$pause}s Pause.\n\n";
printf("%-5s %-6s %-11s %-5s %-8s %-7s %-6s %s\n",
    'Takt', 'Zust.', 'Schritt', '%', 'Seite/Gr', 'Aufrufe', 'Tot', 'Meldung');
echo str_repeat('-', 100), "\n";

$letzte = '';
$stillstand = 0;
$vorherAufrufe = 0;

for ($takt = 1; $takt <= $takte; $takt++) {
    $start = microtime(true);

    // Hart abbrechen - kein SIGTERM zum Aufraeumen, sondern SIGKILL,
    // genau wie eine ueberschrittene Zeitgrenze.
    exec(sprintf('timeout -s KILL %d %s %s > /dev/null 2>&1', $sekunden, PHP_BINARY, escapeshellarg($worker)), $_, $code);

    $gelaufen = round(microtime(true) - $start);
    $getoetet = $code === 137;

    $job = Db::first('SELECT * FROM jobs ORDER BY id DESC LIMIT 1');

    if ($job === null) {
        echo "Kein Auftrag da.\n";
        break;
    }

    $state = json_decode((string) $job['state'], true) ?: [];
    $aufrufe = (int) (Db::first('SELECT COUNT(*) c FROM ai_calls')['c'] ?? 0);

    $marke = $job['progress'] . '|' . ($state['page_index'] ?? '-') . '|' . ($state['group_index'] ?? '-');

    printf("%-5d %-6s %-11s %-5s %-8s %-7s %-6s %s\n",
        $takt,
        substr((string) $job['status'], 0, 6),
        (string) $job['step'],
        $job['progress'] . '%',
        ($state['page_index'] ?? '-') . '/' . ($state['group_index'] ?? '-'),
        $aufrufe . ($aufrufe > $vorherAufrufe ? ' +' . ($aufrufe - $vorherAufrufe) : ''),
        $getoetet ? $gelaufen . 's K' : $gelaufen . 's',
        mb_substr((string) $job['message'], 0, 44));

    $vorherAufrufe = $aufrufe;

    if (in_array((string) $job['status'], ['done', 'failed', 'cancelled'], true)) {
        echo "\nEnde: {$job['status']} - {$job['message']}\n";
        if ($job['error']) { echo "Fehler: {$job['error']}\n"; }
        break;
    }

    if ($marke === $letzte) {
        $stillstand++;
        if ($stillstand >= 8) {
            echo "\n*** STECKT FEST: acht Takte ohne Fortschritt ***\n";
            echo "Zustand: " . json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n";
            break;
        }
    } else {
        $stillstand = 0;
    }

    $letzte = $marke;

    if ($pause > 0) { sleep($pause); }
}

$gemessen = \WebAtze\Core\Settings::get('worker_survival_seconds', '');
echo "\nGemessenes Zeitfenster: " . ($gemessen === '' ? 'keins' : $gemessen . 's') . "\n";
$summe = Db::first('SELECT SUM(cost_micro) s, COUNT(*) c FROM ai_calls');
echo 'Aufrufe: ' . (int) $summe['c'] . ', Kosten: ' . round(((int) $summe['s']) / 1000000, 4) . " USD\n";
