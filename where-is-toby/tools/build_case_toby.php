<?php
/**
 * Baut die Falldatei "toby.json" aus den Teilen in tools/case/ zusammen
 * und legt sie als mitgelieferten Fall unter src/app/Data/cases/ ab.
 *
 * Aufruf: php tools/build_case_toby.php
 */
declare(strict_types=1);

$base = __DIR__ . '/case';
$case = require $base . '/meta.php';

$case['media']         = require $base . '/media.php';
$case['evidence']      = require $base . '/evidence.php';
$case['devices']       = require $base . '/devices.php';
$case['npcs']          = array_merge(require $base . '/npcs_a.php', require $base . '/npcs_b.php');
$case['puzzles']       = require $base . '/puzzles.php';
$case['horror_events'] = require $base . '/horror.php';
$case['objectives']    = require $base . '/objectives.php';

$report = require $base . '/report.php';
foreach ($report as $key => $value) {
    $case[$key] = $value;
}

/* Aktenstuecke ergaenzen (Fotos und Dokumente als Akteneintraege) */
$case['file_entries'] = array_merge($case['file_entries'], [
    ['code' => 'A-5', 'title' => 'Tatortaufnahme 001 - Tobys Zimmer', 'summary' => 'Fotografische Dokumentation, zoombar. Details anklickbar.', 'media' => 'ph_bedroom'],
    ['code' => 'A-6', 'title' => 'Tatortaufnahme 004 - Schreibtisch', 'summary' => 'Nahaufnahme von Kalender und Notizzettel.', 'media' => 'ph_desk'],
    ['code' => 'A-7', 'title' => 'Streiflichtaufnahme Asservat 03', 'summary' => 'Displayspuren des alten Telefons.', 'media' => 'ph_smudge', 'requires_flags' => ['phone_offen']],
    ['code' => 'A-8', 'title' => 'Asservat 07 - Fahrrad', 'summary' => 'Erst nach Angabe des Fundorts verfuegbar.', 'media' => 'ph_bike', 'requires_flags' => ['frank_gestanden']],
    ['code' => 'A-9', 'title' => 'Nachschau Kontrollraum Wasserwerke', 'summary' => 'Aufnahme des Arbeitsplatzes der Betriebsleitung.', 'media' => 'ph_office', 'requires_flags' => ['wasserwerke_im_blick']],
    ['code' => 'A-10', 'title' => 'Zeitungsarchiv der Schule', 'summary' => 'Archivraum, Ausleihzettel.', 'media' => 'ph_school', 'requires_flags' => ['lantern_bekannt']],
    ['code' => 'A-11', 'title' => 'Objektaufnahme Pumpstation 4', 'summary' => 'Aussenaufnahme der stillgelegten Anlage.', 'media' => 'ph_pump_ext', 'requires_flags' => ['ort_pumpstation_bekannt']],
    ['code' => 'A-12', 'title' => 'Auswertung der Nachricht vom 12.10., 03:14 Uhr', 'summary' => 'Stilvergleich und Funkzelle.', 'media' => 'doc_message', 'requires_flags' => ['nachricht_erschienen']],
    ['code' => 'A-13', 'title' => 'Fernbusbuchung Millbrook - Montreal', 'summary' => 'Buchung auf Tobys Namen, bezahlt mit seiner Karte.', 'media' => 'doc_bus', 'requires_flags' => ['nachricht_erschienen']],
    ['code' => 'A-14', 'title' => 'Halterabfrage VT 7KD-418', 'summary' => 'Ergebnis der Kennzeichenabfrage.', 'media' => 'doc_registry', 'requires_flags' => ['kennzeichen_bekannt']],
    ['code' => 'A-15', 'title' => 'Ordner WARTUNG_ALT', 'summary' => 'Inhalt des geschuetzten Ordners aus dem Kontrollraum.', 'media' => 'doc_folder', 'requires_flags' => ['doss_ordner_offen']],
    ['code' => 'A-16', 'title' => 'Zeitungsausschnitte 1998 - 2015', 'summary' => 'Vier Archivkopien aus dem Millbrook Sentinel.', 'media' => 'doc_clippings', 'requires_flags' => ['laptop_offen']],
]);

$case['updated_at'] = gmdate('c');
$case['created_at'] = '2024-10-13T08:00:00+00:00';

/* ---------------------------------------------------------------
 |  Konsistenzpruefung mit der Engine des Spiels
 --------------------------------------------------------------- */
define('WIT_VERSION', '1.0.0');
define('WIT_SCHEMA_VERSION', 3);
define('WIT_ROOT', dirname(__DIR__) . '/src');
define('WIT_APP', WIT_ROOT . '/app');
define('WIT_UPLOADS', WIT_ROOT . '/uploads');
define('WIT_PUBLIC_ASSETS', WIT_ROOT . '/assets');
require_once WIT_APP . '/Core/Autoloader.php';
\App\Core\Autoloader::register(WIT_APP);

$validator = new \App\Service\CaseValidator();
$result = $validator->validate($case, true);

echo "=== Konsistenzpruefung Fall \"toby\" ===\n";
foreach ($result['errors'] as $issue) {
    printf("[%s] %-12s %s\n", strtoupper($issue['level']), $issue['area'], $issue['message']);
}
printf(
    "\nRaetsel: %d · Beweise: %d · NPCs: %d · Geraete: %d · Medien: %d\nFehler: %d · Hinweise: %d\n",
    $result['stats']['puzzles'], $result['stats']['evidence'], $result['stats']['npcs'],
    $result['stats']['devices'], $result['stats']['media'],
    $result['stats']['errors'], $result['stats']['warnings']
);

/* ---------------------------------------------------------------
 |  Schreiben
 --------------------------------------------------------------- */
$target = WIT_APP . '/Data/cases/toby.json';
@mkdir(dirname($target), 0755, true);
$json = json_encode($case, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    fwrite(STDERR, "JSON-Fehler: " . json_last_error_msg() . "\n");
    exit(1);
}
file_put_contents($target, $json);
printf("\nGeschrieben: %s (%.1f KB)\n", str_replace(dirname(__DIR__) . '/', '', $target), strlen($json) / 1024);

exit($result['stats']['errors'] > 0 ? 1 : 0);
