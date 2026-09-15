<?php
/**
 * Test der Variante ohne Installationsassistent.
 *
 * Aufruf: php tools/test_autosetup.php [Basis-URL] [Installationsverzeichnis]
 *
 * Prueft, dass sich die Anwendung beim ersten Seitenaufruf selbst einrichtet,
 * und spielt anschliessend den kompletten Fall durch.
 */
declare(strict_types=1);

$BASE = rtrim($argv[1] ?? 'http://127.0.0.1:8791', '/');
$DIR  = rtrim($argv[2] ?? '', '/');

$GLOBALS['tests'] = ['ok' => 0, 'fail' => 0, 'messages' => []];
$GLOBALS['jar'] = sys_get_temp_dir() . '/wit_auto_' . bin2hex(random_bytes(4)) . '.txt';
$GLOBALS['BASE'] = $BASE;

/* Hilfsfunktionen aus dem Ende-zu-Ende-Test wiederverwenden */
require_once __DIR__ . '/test_helpers.php';

section('1. Automatische Ersteinrichtung (ohne install.php)');

check('install.php ist nicht im Paket', $DIR === '' || !is_file($DIR . '/install.php'));
check('Vor dem ersten Aufruf existiert keine Konfiguration', $DIR === '' || !is_file($DIR . '/app/config.local.php'));

$home = http('GET', '/');
check('Erster Seitenaufruf gelingt', $home['status'] === 200, 'Status ' . $home['status']);
check('Startseite wird angezeigt', str_contains($home['body'], 'WHERE IS TOBY?'));
check('Keine Installationsaufforderung', !str_contains($home['body'], 'install.php'));

if ($DIR !== '') {
    $config = is_file($DIR . '/app/config.local.php')
        ? $DIR . '/app/config.local.php'
        : $DIR . '/storage/settings/config.local.php';
    check('Konfiguration wurde automatisch erzeugt', is_file($config), $config);
    $contents = is_file($config) ? (string)file_get_contents($config) : '';
    check('App-Schluessel gesetzt', preg_match("~'app_key' => '[a-f0-9]{64}'~", $contents) === 1);
    check('Ersteinrichtung ist vermerkt', str_contains($contents, "'auto_setup' => true"));
    check('Administratorkonto angelegt', is_file($DIR . '/storage/users/_index.json')
        || is_file(dirname($DIR) . '/wit_data/users/_index.json'));
    check('Fall "toby" eingespielt', is_file($DIR . '/storage/cases/toby.json')
        || is_file(dirname($DIR) . '/wit_data/cases/toby.json'));
    check('Sperrdatei angelegt', is_file($DIR . '/storage/settings/install.lock')
        || is_file(dirname($DIR) . '/wit_data/settings/install.lock'));
}

$missing = http('GET', '/install.php');
check('install.php ist nicht erreichbar', in_array($missing['status'], [404, 403], true), 'Status ' . $missing['status']);

$second = http('GET', '/');
check('Zweiter Aufruf laeuft ohne erneute Einrichtung', $second['status'] === 200, 'Status ' . $second['status']);

section('2. Anmeldung mit den Startzugangsdaten');

$login = http('GET', '/login');
$csrf = csrfFrom($login['body']);
$result = http('POST', '/login', ['_csrf' => $csrf, 'username' => 'tobi', 'password' => 'Marihuana420!!']);
check('Admin-Login funktioniert', $result['status'] === 302, 'Status ' . $result['status']);
check('Passwortwechsel wird verlangt', str_contains($result['headers'], '/konto'));

$dashboard = http('GET', '/admin');
check('Adminbereich erreichbar', $dashboard['status'] === 200 && str_contains($dashboard['body'], 'Adminbereich'));
check('Warnung vor dem Startpasswort sichtbar', str_contains($dashboard['body'], 'Startpasswort'));
$adminCsrf = csrfFrom($dashboard['body']);

$diag = api('/api/admin/diagnostics', [], $adminCsrf, 'GET');
$summary = $diag['result']['summary'] ?? [];
check('Diagnose ohne Fehler', (int)($summary['fail'] ?? 1) === 0, json_encode($summary));

$change = api('/api/admin/password', [
    'current_password' => 'Marihuana420!!',
    'password' => 'Ermittlung#2024x',
    'password_repeat' => 'Ermittlung#2024x',
], $adminCsrf);
check('Passwortwechsel moeglich', ($change['ok'] ?? false) === true, (string)($change['error'] ?? ''));

section('3. Spielbarkeit');

$cases = http('GET', '/faelle');
check('Fallliste enthaelt den Fall "Toby"', str_contains($cases['body'], 'Where is Toby'));

/* Kompletter Durchlauf mit allen Pruefungen */
require __DIR__ . '/test_playthrough.php';

section('Ergebnis');
printf("%d Pruefungen bestanden, %d fehlgeschlagen\n", $GLOBALS['tests']['ok'], $GLOBALS['tests']['fail']);
if ($GLOBALS['tests']['fail'] > 0) {
    echo "\nFehlgeschlagen:\n";
    foreach ($GLOBALS['tests']['messages'] as $message) {
        echo ' - ' . $message . "\n";
    }
}
@unlink($GLOBALS['jar']);
exit($GLOBALS['tests']['fail'] > 0 ? 1 : 0);
