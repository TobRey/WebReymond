<?php

/**
 * Der Bau, wie er beim Kunden laeuft - und er muss ankommen.
 *
 * Alle bisherigen Probelaeufe hatten denselben blinden Fleck: Sie
 * riefen die Pipeline im eigenen Prozess auf. Beim Kunden startet der
 * Cronjob jede Minute worker.php als eigenen Prozess, der nichts von
 * seinem Vorgaenger weiss. Alles, was zwischen zwei Takten verlorengeht,
 * ist so nicht zu sehen.
 *
 * Hier laeuft es genau so: worker.php als eigener Prozess, im Takt, mit
 * einer Gegenstelle, die so lange braucht wie der echte Dienst.
 * Bedingung ist nicht "kommt voran", sondern "erreicht 100 Prozent".
 *
 * Aufruf:
 *   WEBATZE_ATTRAPPE_DELAY=20 php -S 127.0.0.1:8126 tools/probelauf/api-attrappe.php &
 *   php tools/probelauf/wie-beim-kunden.php --takte=60
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

$takte = max(1, (int) ($argumente['takte'] ?? 60));
$abschuss = (int) ($argumente['abschuss'] ?? 0);
$pause = max(0, (int) ($argumente['pause'] ?? 1));
$ueberlappend = in_array('--ueberlappend', $argv, true);

$worker = BASE_DIR . '/worker.php';

echo "worker.php als eigener Prozess, {$takte} Takte";
echo $abschuss > 0 ? ", Abschuss nach {$abschuss}s" : ', ohne Zeitgrenze';
echo $ueberlappend ? ', ueberlappend wie ein echter Cronjob' : ', einer nach dem anderen';
echo ", {$pause}s Pause.\n\n";

printf("%-5s %-7s %-11s %-5s %-8s %-8s %s\n",
    'Takt', 'Zust.', 'Schritt', '%', 'Aufrufe', 'Dauer', 'Meldung');
echo str_repeat('-', 96), "\n";

$letzte = '';
$stillstand = 0;
$vorher = 0;
$hoechster = -1;

for ($takt = 1; $takt <= $takte; $takt++) {
    $start = microtime(true);

    $befehl = $abschuss > 0
        ? sprintf('timeout -s KILL %d %s %s', $abschuss, PHP_BINARY, escapeshellarg($worker))
        : sprintf('%s %s', PHP_BINARY, escapeshellarg($worker));

    if ($ueberlappend) {
        // So arbeitet ein Cronjob wirklich: Er startet jede Minute einen
        // neuen Vorgang und wartet nicht, ob der vorige fertig ist.
        // Laeuft ein Durchlauf laenger als eine Minute - und das tut er
        // seit dem groesseren Zeitfenster - arbeiten mehrere
        // gleichzeitig. Wer das nicht nachstellt, sieht nie, ob sie sich
        // ins Gehege kommen.
        exec($befehl . ' > /dev/null 2>&1 &');
        $code = 0;
    } else {
        exec($befehl . ' > /dev/null 2>&1', $_, $code);
    }

    $dauer = round(microtime(true) - $start);
    $job = Db::first('SELECT * FROM jobs ORDER BY id DESC LIMIT 1');

    if ($job === null) { echo "Kein Auftrag da.\n"; break; }

    $aufrufe = (int) (Db::first('SELECT COUNT(*) c FROM ai_calls')['c'] ?? 0);
    $state = json_decode((string) $job['state'], true) ?: [];

    printf("%-5d %-7s %-11s %-5s %-8s %-8s %s\n",
        $takt, substr((string) $job['status'], 0, 7), (string) $job['step'],
        $job['progress'] . '%',
        $aufrufe . ($aufrufe > $vorher ? ' +' . ($aufrufe - $vorher) : ''),
        $dauer . 's' . ($code === 137 ? ' K' : ''),
        mb_substr((string) $job['message'], 0, 40));

    $vorher = $aufrufe;
    $hoechster = max($hoechster, (int) $job['progress']);

    if ((string) $job['status'] === 'done') {
        echo "\n\033[32mANGEKOMMEN: 100 Prozent nach {$takt} Takten.\033[0m\n";
        break;
    }

    if (in_array((string) $job['status'], ['failed', 'cancelled'], true)) {
        echo "\n\033[31mGESCHEITERT: {$job['message']}\033[0m\n";
        echo "Fehler: {$job['error']}\n";
        break;
    }

    $marke = $job['progress'] . '|' . (string) $job['step']
        . '|' . ($state['page_index'] ?? '-') . '|' . ($state['group_index'] ?? '-')
        . '|' . ($state['plan_index'] ?? '-');

    if ($marke === $letzte) {
        $stillstand++;
        if ($stillstand >= 10) {
            echo "\n\033[31m*** STECKT FEST bei {$job['progress']}% ({$job['step']}) ***\033[0m\n";
            echo 'Zustand: ' . mb_substr((string) $job['state'], 0, 400) . "\n";
            break;
        }
    } else {
        $stillstand = 0;
    }

    $letzte = $marke;

    if ($pause > 0) { sleep($pause); }
}

$job = Db::first('SELECT * FROM jobs ORDER BY id DESC LIMIT 1');
echo "\nEndstand: {$job['status']} bei {$job['progress']}% (hoechster Stand: {$hoechster}%)\n";

$s = Db::first('SELECT COUNT(*) c FROM project_pages')['c'] ?? 0;
$a = Db::first('SELECT COUNT(*) c FROM project_sections')['c'] ?? 0;
echo "Seiten: {$s}, Abschnitte: {$a}\n";

exit((string) $job['status'] === 'done' ? 0 : 1);
