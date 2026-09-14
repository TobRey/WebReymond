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

function section(string $title): void
{
    echo "\n\033[1m== " . $title . "\033[0m\n";
}

function check(string $label, bool $condition, string $detail = ''): bool
{
    if ($condition) {
        $GLOBALS['tests']['ok']++;
        echo "  \033[32m[OK]\033[0m   " . $label . "\n";
    } else {
        $GLOBALS['tests']['fail']++;
        $GLOBALS['tests']['messages'][] = $label . ($detail !== '' ? ' -> ' . $detail : '');
        echo "  \033[31m[FEHL]\033[0m " . $label . ($detail !== '' ? "\n         " . $detail : '') . "\n";
    }
    return $condition;
}

function newSession(): void
{
    @unlink($GLOBALS['jar']);
    $GLOBALS['jar'] = sys_get_temp_dir() . '/wit_cookies_' . bin2hex(random_bytes(4)) . '.txt';
}

/**
 * @return array{status:int,body:string,headers:string}
 */
function http(string $method, string $path, array|string|null $data = null, array $options = []): array
{
    $url = str_starts_with($path, 'http') ? $path : $GLOBALS['BASE'] . $path;
    $ch = curl_init($url);
    $headers = ['Accept: ' . ($options['json'] ?? false ? 'application/json' : 'text/html')];

    if (!empty($options['csrf'])) {
        $headers[] = 'X-CSRF-Token: ' . $options['csrf'];
    }
    if (!empty($options['jsonBody'])) {
        $headers[] = 'Content-Type: application/json';
        $data = json_encode($data);
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_COOKIEJAR      => $GLOBALS['jar'],
        CURLOPT_COOKIEFILE     => $GLOBALS['jar'],
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 60,
    ]);
    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($data) ? http_build_query($data) : $data);
    }
    $raw = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    return [
        'status'  => $status,
        'headers' => substr($raw, 0, $headerSize),
        'body'    => substr($raw, $headerSize),
    ];
}

function csrfFrom(string $body): string
{
    if (preg_match('~name="_csrf" value="([^"]+)"~', $body, $m)) {
        return $m[1];
    }
    if (preg_match('~"csrf":"([^"]+)"~', $body, $m)) {
        return str_replace('\\/', '/', $m[1]);
    }
    return '';
}

function api(string $path, array $payload = [], string $csrf = '', string $method = 'POST'): array
{
    $response = http($method, $path, $method === 'GET' ? null : $payload, ['json' => true, 'jsonBody' => $method !== 'GET', 'csrf' => $csrf]);
    $data = json_decode($response['body'], true);
    return is_array($data) ? $data + ['_status' => $response['status']] : ['_status' => $response['status'], '_raw' => $response['body']];
}

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

$noCsrf = http('POST', '/api/case/toby/note', ['text' => 'ohne token'], ['json' => true]);
check('POST ohne CSRF-Token wird abgelehnt', $noCsrf['status'] === 403, 'Status ' . $noCsrf['status']);

$traversal = http('GET', '/medien/..%2F..%2Fapp%2Fconfig.local.php');
check('Path-Traversal blockiert', in_array($traversal['status'], [400, 403, 404], true), 'Status ' . $traversal['status']);

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
