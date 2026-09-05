<?php
/**
 * Wochenbericht der Besucherzahlen an die Betreuung schicken.
 *
 * Zwei Wege, beide vorbereitet:
 *   1. Auf der Kommandozeile:  php wochenbericht.php
 *   2. Über die Adresse:       /wochenbericht.php?key=<visit_key aus data/config.php>
 *
 * Ohne eingerichteten Cron-Auftrag geht der Bericht trotzdem raus: die
 * Zählung löst ihn montags beim ersten Seitenaufruf selbst aus.
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/zaehlung.php';

$aufKonsole = PHP_SAPI === 'cli';

if (!$aufKonsole) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');

    $schluessel = (string) ($_GET['key'] ?? '');
    if ($schluessel === '' || !hash_equals(cfg('visit_key'), $schluessel)) {
        http_response_code(404);
        echo "Nicht gefunden.\n";
        exit;
    }
}

$erzwingen = $aufKonsole
    ? in_array('--jetzt', $argv ?? [], true)
    : isset($_GET['jetzt']);

$ok = wochenbericht_senden($erzwingen);

echo $ok
    ? "Wochenbericht an " . cfg('support_email') . " gesendet.\n"
    : "Nichts gesendet: für diese Woche liegt der Bericht bereits vor, oder der Versand hat nicht geklappt.\n";
