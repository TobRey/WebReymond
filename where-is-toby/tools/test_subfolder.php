<?php
/**
 * Test der Installation in einem Unterordner (z. B. public_html/spiel).
 *
 * Aufruf: php tools/test_subfolder.php <Basis-URL mit Unterordner> <Installationsverzeichnis>
 *
 * Wichtigster Punkt: Liegt die Anwendung in einem Unterordner des Webverzeichnisses,
 * darf das Datenverzeichnis NICHT als Nachbarordner angelegt werden, weil es dort
 * ueber die URL erreichbar waere.
 */
declare(strict_types=1);

$BASE = rtrim($argv[1] ?? 'http://127.0.0.1:8792/spiel', '/');
$DIR  = rtrim($argv[2] ?? '', '/');

$GLOBALS['tests'] = ['ok' => 0, 'fail' => 0, 'messages' => []];
$GLOBALS['jar'] = sys_get_temp_dir() . '/wit_sub_' . bin2hex(random_bytes(4)) . '.txt';
$GLOBALS['BASE'] = $BASE;

require_once __DIR__ . '/test_helpers.php';

section('1. Ersteinrichtung im Unterordner');

$home = http('GET', '/');
check('Erster Aufruf im Unterordner gelingt', $home['status'] === 200, 'Status ' . $home['status']);
check('Startseite wird angezeigt', str_contains($home['body'], 'WHERE IS TOBY?'));

$config = $DIR . '/app/config.local.php';
if (!is_file($config)) {
    $config = $DIR . '/storage/settings/config.local.php';
}
check('Konfiguration wurde erzeugt', is_file($config), $config);
$contents = is_file($config) ? (string)file_get_contents($config) : '';

$suffix = '/' . trim((string)parse_url($BASE, PHP_URL_PATH), '/');
check('Basispfad wurde erkannt', str_contains($contents, "'base_path' => '" . $suffix . "'"), $suffix);

section('2. Datenverzeichnis bleibt geschuetzt');

$neighbour = dirname($DIR) . '/wit_data';
check('Kein Datenordner neben dem Webordner', !is_dir($neighbour), $neighbour);
check('Daten liegen im gesperrten Ordner "storage"', is_file($DIR . '/storage/users/_index.json'));
check('Zugriffssperre vorhanden', is_file($DIR . '/storage/.htaccess')
    && str_contains((string)file_get_contents($DIR . '/storage/.htaccess'), 'denied'));

$blocked = http('GET', '/storage/users/_index.json');
check('Datenverzeichnis nicht per URL erreichbar', in_array($blocked['status'], [403, 404], true), 'Status ' . $blocked['status']);

section('2b. Fehlende .htaccess wird wiederhergestellt');

if ($DIR !== '' && is_file($DIR . '/.htaccess')) {
    $rules = (string)file_get_contents($DIR . '/.htaccess');
    check('Vorlage liegt unter gewoehnlichem Namen bei', is_file($DIR . '/app/Data/htaccess.dist'));
    check('Vorlage stimmt mit der .htaccess ueberein',
        (string)file_get_contents($DIR . '/app/Data/htaccess.dist') === $rules);

    unlink($DIR . '/.htaccess');
    clearstatcache();
    check('.htaccess ist entfernt', !is_file($DIR . '/.htaccess'));

    $page = http('GET', '/');
    clearstatcache();
    check('Seite bleibt erreichbar', $page['status'] === 200, 'Status ' . $page['status']);
    check('.htaccess wurde selbst wiederhergestellt', is_file($DIR . '/.htaccess'));
    check('Wiederhergestellte Datei ist vollstaendig',
        (string)file_get_contents($DIR . '/.htaccess') === $rules);
}

section('3. Bedienung unter dem Unterpfad');

/* Verweise und Medien muessen den Unterordner enthalten */
check('Verweise enthalten den Unterordner', str_contains($home['body'], $suffix . '/assets/'), 'kein Praefix in den Verweisen');

if (preg_match('~(?:href|src)="([^"]*assets/css/base\.css[^"]*)"~', $home['body'], $match) === 1) {
    $assetPath = $match[1];
    if (str_starts_with($assetPath, 'http')) {
        $assetPath = (string)parse_url($assetPath, PHP_URL_PATH);
    }
    $asset = http('GET', substr($assetPath, strlen($suffix)));
    check('Gestaltung wird ausgeliefert', $asset['status'] === 200 && str_contains($asset['body'], '[hidden]'), 'Status ' . $asset['status']);
} else {
    check('Gestaltung wird ausgeliefert', false, 'base.css nicht in der Seite gefunden');
}

$front = http('GET', '/index.php');
check('Direkter Aufruf von /index.php funktioniert auch im Unterordner',
    in_array($front['status'], [200, 302], true), 'Status ' . $front['status']);

$login = http('GET', '/login');
$csrf = csrfFrom($login['body']);
$result = http('POST', '/login', ['_csrf' => $csrf, 'username' => 'tobi', 'password' => 'Marihuana420!!']);
check('Anmeldung im Unterordner funktioniert', $result['status'] === 302, 'Status ' . $result['status']);
check('Weiterleitung enthaelt den Unterordner', str_contains($result['headers'], $suffix . '/'), 'Weiterleitung ohne Praefix');

$dashboard = http('GET', '/admin');
check('Adminbereich erreichbar', $dashboard['status'] === 200 && str_contains($dashboard['body'], 'Adminbereich'));
$adminCsrf = csrfFrom($dashboard['body']);

$diag = api('/api/admin/diagnostics', [], $adminCsrf, 'GET');
$summary = $diag['result']['summary'] ?? [];
check('Diagnose ohne Fehler', (int)($summary['fail'] ?? 1) === 0, json_encode($summary));

$cases = http('GET', '/faelle');
check('Fall "Toby" steht bereit', str_contains($cases['body'], 'Where is Toby'));

$start = api('/api/case/toby/start', [], $adminCsrf);
check('Fall laesst sich starten', ($start['ok'] ?? false) === true, (string)($start['error'] ?? ''));

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
