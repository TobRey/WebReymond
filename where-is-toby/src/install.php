<?php
/**
 * WHERE IS TOBY? - Installationsassistent
 *
 * Schritte:
 *   1. Systempruefung (PHP, Erweiterungen, Schreibrechte)
 *   2. Grundeinstellungen und Administratorkonto
 *   3. KI-Verbindung (optional, Offline-Modus moeglich)
 *   4. Abschluss - der Installer sperrt sich anschliessend selbst
 */
declare(strict_types=1);

$container = require __DIR__ . '/app/bootstrap.php';

use App\Core\Crypto;
use App\Core\Csrf;
use App\Core\Environment;
use App\Core\Json;
use App\Core\Request;
use App\Core\View;

$lockFile = WIT_STORAGE . '/settings/install.lock';
$request = Request::capture();
$step = max(1, min(4, (int)($_GET['step'] ?? 1)));
$errors = [];
$notices = [];

if (!headers_sent()) {
    header('X-Robots-Tag: noindex, nofollow');
    header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; script-src 'self' 'unsafe-inline'");
}

/* -----------------------------------------------------------------
 |  Selbstsperre
 ----------------------------------------------------------------- */
if (is_file($lockFile) && WIT_INSTALLED) {
    http_response_code(403);
    $reset = htmlspecialchars((string)($lockFile), ENT_QUOTES, 'UTF-8');
    echo installerPage('Installation abgeschlossen', <<<HTML
        <p class="lead">Diese Installation ist bereits abgeschlossen. Der Assistent hat sich aus Sicherheitsgruenden gesperrt.</p>
        <p>Loesche die Datei <code>install.php</code> vom Server. Das Spiel ist unter <a href="./">./</a> erreichbar,
        der Adminbereich unter <a href="./admin">./admin</a>.</p>
        <p class="hint">Eine Neuinstallation ist nur moeglich, wenn die Sperrdatei entfernt wird:<br><code>{$reset}</code></p>
    HTML);
    exit;
}

/* -----------------------------------------------------------------
 |  Systempruefung
 ----------------------------------------------------------------- */
function requirementChecks(): array
{
    $checks = [];
    $checks[] = [
        'label'   => 'PHP-Version (mindestens 8.1, empfohlen 8.4)',
        'value'   => PHP_VERSION,
        'status'  => PHP_VERSION_ID >= 80100 ? (PHP_VERSION_ID >= 80200 ? 'ok' : 'warn') : 'fail',
        'fix'     => 'In cPanel unter "MultiPHP Manager" die PHP-Version auf 8.2 oder hoeher stellen.',
        'required'=> true,
    ];
    $required = ['json' => true, 'mbstring' => true, 'session' => true, 'pcre' => true];
    $recommended = [
        'curl'     => 'noetig fuer KI-Anbieter (ohne curl wird ein langsamerer Rueckfall genutzt)',
        'openssl'  => 'Verschluesselung des API-Schluessels',
        'sodium'   => 'bevorzugte Verschluesselung',
        'gd'       => 'automatische Vermisstenplakate und Aktenkarten',
        'fileinfo' => 'Pruefung hochgeladener Dateien',
        'zip'      => 'Sicherungen als ZIP',
        'exif'     => 'Bildmetadaten',
    ];
    foreach ($required as $extension => $_) {
        $loaded = extension_loaded($extension);
        $checks[] = [
            'label' => 'Erweiterung ' . $extension,
            'value' => $loaded ? 'vorhanden' : 'fehlt',
            'status'=> $loaded ? 'ok' : 'fail',
            'fix'   => 'In cPanel unter "Select PHP Version" > "Extensions" aktivieren.',
            'required' => true,
        ];
    }
    foreach ($recommended as $extension => $why) {
        $loaded = extension_loaded($extension);
        $checks[] = [
            'label' => 'Erweiterung ' . $extension,
            'value' => $loaded ? 'vorhanden' : 'fehlt (' . $why . ')',
            'status'=> $loaded ? 'ok' : 'warn',
            'fix'   => 'Optional. In cPanel unter "Select PHP Version" aktivierbar.',
            'required' => false,
        ];
    }

    foreach ([
        'Datenverzeichnis storage/' => WIT_ROOT . '/storage',
        'Uploadverzeichnis uploads/' => WIT_ROOT . '/uploads',
        'Programmverzeichnis app/'  => WIT_ROOT . '/app',
    ] as $label => $path) {
        $exists = is_dir($path);
        $writable = $exists && is_writable($path);
        $checks[] = [
            'label' => $label,
            'value' => !$exists ? 'fehlt' : ($writable ? 'beschreibbar' : 'nicht beschreibbar'),
            'status'=> $writable ? 'ok' : 'fail',
            'fix'   => 'Im cPanel-Dateimanager Rechte auf 755 setzen (Dateien 644).',
            'required' => true,
        ];
    }

    $checks[] = [
        'label' => 'Zufallszahlen (random_bytes)',
        'value' => function_exists('random_bytes') ? 'verfuegbar' : 'fehlt',
        'status'=> function_exists('random_bytes') ? 'ok' : 'fail',
        'fix'   => 'PHP-Installation pruefen.',
        'required' => true,
    ];
    $checks[] = [
        'label' => 'HTTPS',
        'value' => Environment::isHttps() ? 'aktiv' : 'nicht erkannt',
        'status'=> Environment::isHttps() ? 'ok' : 'warn',
        'fix'   => 'In cPanel ein kostenloses AutoSSL-Zertifikat aktivieren und auf HTTPS umleiten.',
        'required' => false,
    ];
    return $checks;
}

$checks = requirementChecks();
$blocking = array_filter($checks, static fn(array $c): bool => $c['status'] === 'fail' && $c['required']);

/* -----------------------------------------------------------------
 |  Installation ausfuehren
 ----------------------------------------------------------------- */
if ($request->isPost() && $step === 3) {
    try {
        Csrf::check($request);
    } catch (\Throwable) {
        $errors[] = 'Sicherheitstoken abgelaufen. Bitte die Seite neu laden.';
    }

    if ($blocking !== []) {
        $errors[] = 'Die Systempruefung ist nicht bestanden. Bitte zuerst die rot markierten Punkte beheben.';
    }

    $siteName = trim($request->str('site_name', 'WHERE IS TOBY?', 120));
    $adminUser = trim($request->str('admin_user', 'tobi', 64));
    $adminPassword = $request->str('admin_password', '', 200);
    $adminPasswordRepeat = $request->str('admin_password_repeat', '', 200);
    $adminEmail = trim($request->str('admin_email', '', 190));
    $storageOutside = $request->bool('storage_outside', false);
    $provider = $request->str('ai_provider', 'offline', 40);
    $apiKey = trim($request->str('ai_key', '', 400));
    $model = trim($request->str('ai_model', '', 140));
    $baseUrl = trim($request->str('ai_base_url', '', 300));

    if (!preg_match('~^[a-zA-Z0-9_.\-]{3,32}$~', $adminUser)) {
        $errors[] = 'Der Administrator-Benutzername ist ungueltig (3-32 Zeichen).';
    }
    if (mb_strlen($adminPassword) < 10) {
        $errors[] = 'Das Administratorpasswort muss mindestens 10 Zeichen lang sein.';
    }
    if ($adminPassword !== $adminPasswordRepeat) {
        $errors[] = 'Die Passwortwiederholung stimmt nicht ueberein.';
    }
    if ($adminEmail !== '' && !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Die E-Mail-Adresse ist ungueltig.';
    }
    if (!$request->bool('accept_terms', false)) {
        $errors[] = 'Bitte bestaetigen, dass das Spiel Inhalte ab 18 Jahren enthaelt.';
    }

    if ($errors === []) {
        /* Datenverzeichnis festlegen */
        $storagePath = WIT_ROOT . '/storage';
        $uploadsPath = WIT_ROOT . '/uploads';
        if ($storageOutside) {
            $candidate = dirname(WIT_ROOT) . '/wit_data';
            if (@mkdir($candidate, 0750, true) || is_dir($candidate)) {
                if (is_writable($candidate)) {
                    $storagePath = $candidate;
                    $notices[] = 'Die Spieldaten liegen ausserhalb des Webverzeichnisses: ' . $candidate;
                } else {
                    $notices[] = 'Das Verzeichnis ausserhalb des Webordners ist nicht beschreibbar - es wird storage/ im Webordner mit .htaccess-Schutz genutzt.';
                }
            } else {
                $notices[] = 'Ein Verzeichnis ausserhalb des Webordners konnte nicht angelegt werden - es wird storage/ mit .htaccess-Schutz genutzt.';
            }
        }

        /* Verzeichnisse anlegen */
        $directories = ['users', 'cases', 'cases/_versions', 'progress', 'sessions', 'logs', 'backups', 'settings', 'cache', 'cache/ratelimit', 'cache/audio', 'media'];
        foreach ($directories as $directory) {
            $path = $storagePath . '/' . $directory;
            if (!is_dir($path) && !@mkdir($path, 0750, true) && !is_dir($path)) {
                $errors[] = 'Verzeichnis konnte nicht angelegt werden: ' . $path;
            }
        }
        foreach (['media', 'thumbs'] as $directory) {
            $path = $uploadsPath . '/' . $directory;
            if (!is_dir($path)) {
                @mkdir($path, 0750, true);
            }
        }

        /* Schutzdateien schreiben */
        $deny = "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\nphp_flag engine off\n";
        @file_put_contents($storagePath . '/.htaccess', $deny);
        @file_put_contents($storagePath . '/index.php', '<?php http_response_code(403); exit("Kein Zugriff.");');
        @file_put_contents($uploadsPath . '/.htaccess', $deny . "RemoveHandler .php .phtml .phar\nAddType text/plain .php .phtml .phar\n");

        if ($errors === []) {
            /* Lokale Konfiguration schreiben */
            $appKey = Crypto::generateKey();
            $config = "<?php\n"
                . "/**\n * Lokale Konfiguration - vom Installationsassistenten erzeugt.\n"
                . " * Diese Datei enthaelt Geheimnisse und darf nicht oeffentlich erreichbar sein.\n */\n"
                . "return " . var_export([
                    'installed'    => true,
                    'installed_at' => gmdate('c'),
                    'storage_path' => $storagePath,
                    'uploads_path' => $uploadsPath,
                    'app_key'      => $appKey,
                    'base_path'    => rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/'),
                    'debug'        => false,
                ], true) . ";\n";

            if (@file_put_contents(WIT_APP . '/config.local.php', $config) === false) {
                $errors[] = 'Die Konfigurationsdatei app/config.local.php konnte nicht geschrieben werden. Bitte Schreibrechte fuer app/ pruefen.';
            } else {
                @chmod(WIT_APP . '/config.local.php', 0640);

                /* Ab hier mit den echten Pfaden weiterarbeiten */
                $store = new \App\Repository\JsonStore($storagePath, $storagePath . '/backups');
                $settingsRepository = new \App\Repository\SettingsRepository($store);
                $userRepository = new \App\Repository\UserRepository($store);
                $caseRepository = new \App\Repository\CaseRepository($store);

                // Achtung: Crypto nutzt WIT_APP_KEY. Da die Konstante bereits gesetzt ist,
                // wird der Schluessel hier direkt uebergeben.
                $settings = \App\Repository\SettingsRepository::defaults();
                $settings['site']['name'] = $siteName !== '' ? $siteName : 'WHERE IS TOBY?';
                $settings['ai']['provider'] = in_array($provider, ['offline', 'gemini', 'openai_compatible'], true) ? $provider : 'offline';
                $settings['ai']['model'] = $model;
                $settings['ai']['base_url'] = $baseUrl;
                if ($apiKey !== '' && $settings['ai']['provider'] !== 'offline') {
                    $settings['ai']['api_key_enc'] = encryptWithKey($apiKey, $appKey);
                }
                $store->write('settings/settings.json', $settings);

                /* Administratorkonto */
                if (!$userRepository->usernameTaken($adminUser)) {
                    $userRepository->create($adminUser, $adminPassword, $adminEmail, 'admin', [
                        'agent_name'           => 'Special Agent ' . ucfirst($adminUser),
                        'must_change_password' => $adminPassword === 'Marihuana420!!',
                        'age_confirmed'        => true,
                    ]);
                }

                /* Mitgelieferte Faelle einspielen */
                $seedDirectory = WIT_APP . '/Data/cases';
                $imported = 0;
                foreach (glob($seedDirectory . '/*.json') ?: [] as $seedFile) {
                    $data = Json::decode((string)file_get_contents($seedFile));
                    if ($data === null || !isset($data['id'])) {
                        continue;
                    }
                    if (!$caseRepository->exists((string)$data['id'])) {
                        $caseRepository->save($data, false);
                        $imported++;
                    }
                }
                $notices[] = $imported . ' Fall/Faelle eingespielt.';

                /* Installer sperren */
                @file_put_contents($storagePath . '/settings/install.lock', Json::encode([
                    'installed_at' => gmdate('c'),
                    'version'      => WIT_VERSION,
                    'php'          => PHP_VERSION,
                ], true));

                header('Location: install.php?step=4');
                exit;
            }
        }
    }
    $step = 3;
}

/** Verschluesselt mit einem explizit uebergebenen Schluessel (der Installer kennt die Konstante noch nicht). */
function encryptWithKey(string $plain, string $appKey): string
{
    $key = hash('sha256', 'wit:' . $appKey, true);
    if (function_exists('sodium_crypto_secretbox')) {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 'sb1:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
    }
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return 'og1:' . base64_encode($iv . $tag . (string)$cipher);
}

function installerPage(string $title, string $body, int $step = 0): string
{
    $steps = ['Systempruefung', 'Grundeinstellungen', 'KI-Verbindung', 'Fertig'];
    $nav = '';
    foreach ($steps as $index => $label) {
        $number = $index + 1;
        $class = $number < $step ? 'done' : ($number === $step ? 'active' : '');
        $nav .= '<li class="' . $class . '"><span>' . $number . '</span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</li>';
    }
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $stepNav = $step > 0 ? '<ol class="steps">' . $nav . '</ol>' : '';

    return <<<HTML
<!doctype html>
<html lang="de"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>{$safeTitle} &middot; WHERE IS TOBY?</title>
<link rel="stylesheet" href="assets/css/install.css">
</head><body>
<div class="shell">
  <header class="masthead">
    <div class="badge">FBI</div>
    <div>
      <h1>WHERE IS TOBY?</h1>
      <p>Installationsassistent &middot; Version 1.0.0</p>
    </div>
  </header>
  {$stepNav}
  <main class="panel">
    <h2>{$safeTitle}</h2>
    {$body}
  </main>
  <footer class="foot">Dateibasierte Installation &middot; keine Datenbank noetig &middot; PHP 8.1+</footer>
</div>
</body></html>
HTML;
}

/* -----------------------------------------------------------------
 |  Ausgabe
 ----------------------------------------------------------------- */
$e = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$csrf = Csrf::token();

$errorBlock = '';
if ($errors !== []) {
    $errorBlock = '<div class="alert error"><strong>Bitte pruefen:</strong><ul><li>' . implode('</li><li>', array_map($e, $errors)) . '</li></ul></div>';
}
$noticeBlock = '';
if ($notices !== []) {
    $noticeBlock = '<div class="alert info"><ul><li>' . implode('</li><li>', array_map($e, $notices)) . '</li></ul></div>';
}

if ($step === 1) {
    $rows = '';
    foreach ($checks as $check) {
        $rows .= '<tr class="' . $check['status'] . '">'
            . '<td>' . $e($check['label']) . '</td>'
            . '<td>' . $e($check['value']) . '</td>'
            . '<td>' . ($check['status'] === 'ok' ? 'OK' : ($check['status'] === 'warn' ? 'Hinweis' : 'Fehler')) . '</td>'
            . '<td class="fix">' . ($check['status'] === 'ok' ? '' : $e($check['fix'])) . '</td>'
            . '</tr>';
    }
    $next = $blocking === []
        ? '<a class="button" href="install.php?step=2">Weiter zu den Grundeinstellungen</a>'
        : '<p class="alert error">Es sind Pflichtpunkte offen. Bitte zuerst beheben und die Seite neu laden.</p><a class="button ghost" href="install.php?step=1">Erneut pruefen</a>';

    echo installerPage('Schritt 1: Systempruefung', <<<HTML
        {$errorBlock}
        <p class="lead">Der Assistent prueft, ob der Webspace alle Voraussetzungen erfuellt.</p>
        <table class="checks"><thead><tr><th>Pruefung</th><th>Ergebnis</th><th>Status</th><th>Loesung</th></tr></thead><tbody>{$rows}</tbody></table>
        <div class="actions">{$next}</div>
    HTML, 1);
    exit;
}

if ($step === 2) {
    echo installerPage('Schritt 2: Grundeinstellungen', <<<HTML
        <p class="lead">Diese Angaben koennen spaeter im Adminbereich geaendert werden.</p>
        <form method="get" action="install.php" class="form">
            <input type="hidden" name="step" value="3">
            <p class="hint">Im naechsten Schritt werden Administratorkonto und KI-Verbindung eingerichtet.
            Halte dafuer - falls gewuenscht - einen kostenlosen API-Schluessel bereit. Ohne Schluessel laeuft das Spiel im Offline-Modus,
            der Fall &bdquo;Toby&ldquo; bleibt vollstaendig loesbar.</p>
            <div class="actions"><button class="button" type="submit">Weiter</button></div>
        </form>
    HTML, 2);
    exit;
}

if ($step === 3) {
    $siteName = $e($request->str('site_name', 'WHERE IS TOBY?', 120));
    $adminUser = $e($request->str('admin_user', 'tobi', 64));
    $adminEmail = $e($request->str('admin_email', '', 190));
    echo installerPage('Schritt 3: Konto und KI-Verbindung', <<<HTML
        {$errorBlock}{$noticeBlock}
        <form method="post" action="install.php?step=3" class="form" autocomplete="off">
            <input type="hidden" name="_csrf" value="{$csrf}">

            <fieldset>
                <legend>Seite</legend>
                <label>Name der Seite
                    <input type="text" name="site_name" value="{$siteName}" maxlength="120" required>
                </label>
                <label class="check">
                    <input type="checkbox" name="storage_outside" value="1" checked>
                    Spieldaten nach Moeglichkeit ausserhalb von public_html speichern (empfohlen)
                </label>
            </fieldset>

            <fieldset>
                <legend>Administratorkonto</legend>
                <label>Benutzername
                    <input type="text" name="admin_user" value="{$adminUser}" maxlength="32" required>
                </label>
                <label>Passwort (mindestens 10 Zeichen)
                    <input type="password" name="admin_password" value="Marihuana420!!" minlength="10" maxlength="200" required>
                </label>
                <label>Passwort wiederholen
                    <input type="password" name="admin_password_repeat" value="Marihuana420!!" minlength="10" maxlength="200" required>
                </label>
                <label>E-Mail (optional)
                    <input type="email" name="admin_email" value="{$adminEmail}" maxlength="190">
                </label>
                <p class="hint">Voreingetragen sind die Startzugangsdaten <strong>tobi / Marihuana420!!</strong>.
                Beim ersten Login fordert das System zum Wechsel auf. Das Passwort wird ausschliesslich als Hash gespeichert.</p>
            </fieldset>

            <fieldset>
                <legend>KI-Verbindung (optional)</legend>
                <label>Anbieter
                    <select name="ai_provider" id="ai_provider">
                        <option value="offline">Offline-Modus (regelbasierte Dialoge, kein Schluessel noetig)</option>
                        <option value="gemini">Google Gemini (kostenloses Kontingent)</option>
                        <option value="openai_compatible">OpenAI-kompatibel (OpenRouter, Groq, lokal ...)</option>
                    </select>
                </label>
                <label>Basis-URL (leer lassen fuer Standard)
                    <input type="text" name="ai_base_url" placeholder="https://generativelanguage.googleapis.com/v1beta" maxlength="300">
                </label>
                <label>Modellname
                    <input type="text" name="ai_model" placeholder="gemini-2.0-flash" maxlength="140">
                </label>
                <label>API-Schluessel
                    <input type="password" name="ai_key" maxlength="400" autocomplete="new-password">
                </label>
                <p class="hint">Der Schluessel wird verschluesselt gespeichert und niemals an den Browser gesendet.
                Kostenlose Kontingente und Modellnamen aendern sich haeufig - beides ist spaeter im Adminbereich aenderbar.</p>
            </fieldset>

            <fieldset>
                <legend>Inhaltshinweis</legend>
                <label class="check">
                    <input type="checkbox" name="accept_terms" value="1" required>
                    Ich habe verstanden, dass dieses Spiel verstoerende Inhalte enthaelt und ab 18 Jahren freigegeben ist.
                </label>
            </fieldset>

            <div class="actions">
                <button class="button" type="submit">Installation abschliessen</button>
                <a class="button ghost" href="install.php?step=1">Zurueck zur Pruefung</a>
            </div>
        </form>
    HTML, 3);
    exit;
}

echo installerPage('Fertig', <<<HTML
    <div class="alert ok"><strong>Die Installation ist abgeschlossen.</strong> Der Assistent hat sich selbst gesperrt.</div>
    <ol class="next">
        <li>Loesche jetzt die Datei <code>install.php</code> auf dem Server (empfohlen).</li>
        <li>Oeffne das Spiel: <a href="./">Startseite</a></li>
        <li>Adminbereich: <a href="./admin">./admin</a> - dort Passwort aendern, Faelle verwalten und die KI konfigurieren.</li>
        <li>Fuehre im Adminbereich die <strong>Diagnose</strong> aus, um alle Punkte gruen zu sehen.</li>
    </ol>
    <p class="hint">Der mitgelieferte Fall &bdquo;WHERE IS TOBY?&ldquo; ist sofort spielbar - auch ohne KI-Schluessel.</p>
HTML, 4);
