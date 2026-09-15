<?php
/**
 * Automatischer Ende-zu-Ende-Test.
 *
 * Aufruf:  php tools/test_e2e.php [Basis-URL] [Installationsverzeichnis]
 * Beispiel: php tools/test_e2e.php http://127.0.0.1:8787 /tmp/wit
 *
 * Der Test installiert die Anwendung ueber den echten Installationsassistenten,
 * legt Konten an, spielt den Fall "Toby" komplett durch und prueft die
 * Sicherheitsvorkehrungen.
 */
declare(strict_types=1);

$BASE = rtrim($argv[1] ?? 'http://127.0.0.1:8787', '/');
$DIR  = rtrim($argv[2] ?? '', '/');

$GLOBALS['tests'] = ['ok' => 0, 'fail' => 0, 'messages' => []];
$GLOBALS['jar'] = sys_get_temp_dir() . '/wit_cookies_' . bin2hex(random_bytes(4)) . '.txt';

require_once __DIR__ . '/test_helpers.php';

$GLOBALS['BASE'] = $BASE;

/* ================================================================
 |  1. Installation
 ================================================================ */
section('1. Installation ueber den Assistenten');

$page = http('GET', '/install.php?step=1');
check('Installer erreichbar', $page['status'] === 200, 'Status ' . $page['status']);
check('Systempruefung zeigt PHP-Version', str_contains($page['body'], 'PHP-Version'));
check('Keine blockierenden Fehler', !str_contains($page['body'], 'Es sind Pflichtpunkte offen'));

$page = http('GET', '/install.php?step=3');
$csrf = csrfFrom($page['body']);
check('CSRF-Token im Installationsformular', $csrf !== '');

$install = http('POST', '/install.php?step=3', [
    '_csrf' => $csrf,
    'site_name' => 'WHERE IS TOBY?',
    'storage_outside' => '0',
    'admin_user' => 'tobi',
    'admin_password' => 'Marihuana420!!',
    'admin_password_repeat' => 'Marihuana420!!',
    'admin_email' => '',
    'ai_provider' => 'offline',
    'ai_base_url' => '',
    'ai_model' => '',
    'ai_key' => '',
    'accept_terms' => '1',
]);
check('Installation abgeschlossen (Weiterleitung zu Schritt 4)', $install['status'] === 302 && str_contains($install['headers'], 'step=4'), 'Status ' . $install['status']);

if ($DIR !== '') {
    check('Konfigurationsdatei angelegt', is_file($DIR . '/app/config.local.php'));
    check('Sperrdatei angelegt', is_file($DIR . '/storage/settings/install.lock'));
    check('Fall "toby" eingespielt', is_file($DIR . '/storage/cases/toby.json'));
    check('Benutzerindex angelegt', is_file($DIR . '/storage/users/_index.json'));
    $config = @file_get_contents($DIR . '/app/config.local.php');
    check('App-Schluessel gesetzt', is_string($config) && preg_match("~'app_key' => '[a-f0-9]{64}'~", $config) === 1);
}

$again = http('GET', '/install.php');
check('Installer sperrt sich selbst', $again['status'] === 403, 'Status ' . $again['status']);

/* ================================================================
 |  2. Admin-Login und Passwortwechsel
 ================================================================ */
section('2. Adminbereich');

newSession();
$login = http('GET', '/login');
$csrf = csrfFrom($login['body']);
$result = http('POST', '/login', ['_csrf' => $csrf, 'username' => 'tobi', 'password' => 'Marihuana420!!']);
check('Admin-Login mit Startzugangsdaten', $result['status'] === 302, 'Status ' . $result['status']);
check('Hinweis auf Passwortwechsel (Weiterleitung /konto)', str_contains($result['headers'], '/konto'));

$dashboard = http('GET', '/admin');
check('Adminbereich erreichbar', $dashboard['status'] === 200 && str_contains($dashboard['body'], 'Adminbereich'), 'Status ' . $dashboard['status']);
check('Startpasswort-Warnung sichtbar', str_contains($dashboard['body'], 'Startpasswort'));
$adminCsrf = csrfFrom($dashboard['body']);

$wrongLogin = http('POST', '/login', ['_csrf' => $csrf, 'username' => 'tobi', 'password' => 'falsch']);
check('Falsches Passwort wird abgelehnt', $wrongLogin['status'] === 200 && str_contains($wrongLogin['body'], 'falsch'), 'Status ' . $wrongLogin['status']);

$change = api('/api/admin/password', [
    'current_password' => 'Marihuana420!!',
    'password' => 'Ermittlung#2024x',
    'password_repeat' => 'Ermittlung#2024x',
], $adminCsrf);
check('Adminpasswort geaendert', ($change['ok'] ?? false) === true, (string)($change['error'] ?? ''));

$weak = api('/api/admin/password', [
    'current_password' => 'Ermittlung#2024x',
    'password' => 'kurz',
    'password_repeat' => 'kurz',
], $adminCsrf);
check('Schwaches Passwort wird abgelehnt', ($weak['ok'] ?? true) === false);

$diag = api('/api/admin/diagnostics?deep=1', [], $adminCsrf, 'GET');
$summary = $diag['result']['summary'] ?? [];
check('Diagnose laeuft durch', isset($summary['ok']), json_encode($summary));
check('Diagnose ohne Fehler', (int)($summary['fail'] ?? 1) === 0, 'Fehler: ' . (int)($summary['fail'] ?? -1));

$concurrency = null;
foreach ($diag['result']['groups'] ?? [] as $group) {
    foreach ($group['checks'] ?? [] as $entry) {
        if ($entry['name'] === 'Paralleles Schreiben') {
            $concurrency = $entry;
        }
    }
}
check('Paralleles Schreiben ohne Datenverlust', ($concurrency['status'] ?? '') === 'ok', (string)($concurrency['message'] ?? 'Pruefung nicht gefunden'));

/* ================================================================
 |  3. Registrierung, Login, Gastmodus
 ================================================================ */
section('3. Konten');

newSession();
$page = http('GET', '/registrieren');
$csrf = csrfFrom($page['body']);
$register = http('POST', '/registrieren', [
    '_csrf' => $csrf,
    'username' => 'testagent',
    'email' => 'test@example.org',
    'password' => 'Ermittlung#2024',
    'password_repeat' => 'Ermittlung#2024',
    'age_confirm' => '1',
]);
check('Registrierung erfolgreich', $register['status'] === 302 && str_contains($register['headers'], '/faelle'), 'Status ' . $register['status']);

$cases = http('GET', '/faelle');
check('Fallliste sichtbar', $cases['status'] === 200 && str_contains($cases['body'], 'Where is Toby'), 'Status ' . $cases['status']);

$dup = http('POST', '/registrieren', [
    '_csrf' => csrfFrom(http('GET', '/registrieren')['body']),
    'username' => 'testagent', 'email' => '', 'password' => 'Ermittlung#2024',
    'password_repeat' => 'Ermittlung#2024', 'age_confirm' => '1',
]);
check('Doppelter Benutzername abgelehnt', str_contains($dup['body'], 'bereits vergeben'));

newSession();
$page = http('GET', '/login');
$csrf = csrfFrom($page['body']);
$guest = http('POST', '/gast', ['_csrf' => $csrf]);
check('Gastmodus startet', $guest['status'] === 302 && str_contains($guest['headers'], '/faelle'), 'Status ' . $guest['status']);
$guestCases = http('GET', '/faelle');
check('Gast sieht die Fallliste', $guestCases['status'] === 200 && str_contains($guestCases['body'], 'Where is Toby'));

/* ================================================================
 |  4. Sicherheitspruefungen
 ================================================================ */
section('4. Sicherheit');

foreach ([
    '/storage/settings/settings.json',
    '/storage/users/_index.json',
    '/app/config.local.php',
    '/app/Data/cases/toby.json',
] as $path) {
    $response = http('GET', $path);
    check('Direktzugriff blockiert: ' . $path, in_array($response['status'], [403, 404], true), 'Status ' . $response['status']);
}

/* Die Apache-Regeln lassen sich lokal nicht ausfuehren, darum wird das Muster
   selbst geprueft: Es muss am Ordneranfang verankert sein, sonst wuerde auch eine
   Adresse wie /api/case/toby/device/dev_phone/app/messages gesperrt. */
if ($DIR !== '' && is_file($DIR . '/.htaccess')) {
    $rules = (string)file_get_contents($DIR . '/.htaccess');
    check('Sperrregel fuer app/storage/uploads vorhanden',
        str_contains($rules, 'RewriteRule ^(app|storage|uploads)/ - [F,L]'));
    check('Sperrregel ist am Ordneranfang verankert',
        preg_match('~RewriteRule\s+\(\^\|/\)\((?:app|storage)~', $rules) !== 1);
    check('Kein fester RewriteBase (Unterordner bleiben moeglich)',
        !preg_match('~^\s*RewriteBase~m', $rules));
}

$appRoute = http('GET', '/api/case/toby/device/dev_toby_phone/app/messages', null, ['json' => true]);
check('Adresse mit "/app/" erreicht die Anwendung',
    str_contains($appRoute['headers'], 'application/json') && json_decode($appRoute['body'], true) !== null,
    'Status ' . $appRoute['status'] . ' / ' . substr($appRoute['body'], 0, 60));

$noCsrf = http('POST', '/api/case/toby/note', ['text' => 'ohne token'], ['json' => true]);
check('POST ohne CSRF-Token wird abgelehnt', $noCsrf['status'] === 403, 'Status ' . $noCsrf['status']);

$traversal = http('GET', '/medien/..%2F..%2Fapp%2Fconfig.local.php');
check('Path-Traversal blockiert', in_array($traversal['status'], [400, 403, 404], true), 'Status ' . $traversal['status']);

/* Der Front-Controller darf auch direkt aufrufbar sein (z. B. ohne mod_rewrite eingetippt) */
$front = http('GET', '/index.php');
check('Direkter Aufruf von /index.php landet auf der Startseite',
    in_array($front['status'], [200, 302], true), 'Status ' . $front['status']);
check('Kein Treffer auf einen aehnlichen Pfad', http('GET', '/indexXphp')['status'] === 404);

/* Ein falscher Basispfad in der Konfiguration darf nicht die ganze Seite lahmlegen:
   frueher lieferte dann jede Adresse 404. */
if ($DIR !== '' && is_file($DIR . '/app/config.local.php')) {
    $configFile = $DIR . '/app/config.local.php';
    $original = (string)file_get_contents($configFile);
    $broken = preg_replace("~'base_path' => '[^']*'~", "'base_path' => '/falscher-ordner'", $original, 1);
    if (is_string($broken) && $broken !== $original && file_put_contents($configFile, $broken) !== false) {
        clearstatcache();
        $rescue = http('GET', '/');
        check('Falscher Basispfad wird selbst korrigiert',
            in_array($rescue['status'], [200, 302], true) && !str_contains($rescue['headers'], '/falscher-ordner'),
            'Status ' . $rescue['status']);
        $rescuePage = http('GET', '/faelle');
        check('Unterseiten bleiben erreichbar', $rescuePage['status'] === 200, 'Status ' . $rescuePage['status']);
        file_put_contents($configFile, $original);
        clearstatcache();
        check('Konfiguration wiederhergestellt', (string)file_get_contents($configFile) === $original);
    }
}

$headers = http('GET', '/login')['headers'];
check('Content-Security-Policy gesetzt', str_contains($headers, 'Content-Security-Policy'));
check('X-Content-Type-Options gesetzt', str_contains($headers, 'nosniff'));
check('Kein API-Schluessel im HTML', !str_contains(http('GET', '/login')['body'], 'api_key_enc'));

echo "\n";
printf("Zwischenstand: %d bestanden, %d fehlgeschlagen\n", $GLOBALS['tests']['ok'], $GLOBALS['tests']['fail']);

require __DIR__ . '/test_playthrough.php';

/* ================================================================
 |  Ergebnis
 ================================================================ */
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
