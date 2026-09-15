<?php
/**
 * WHERE IS TOBY? - Pfad-Test
 *
 * Diese Datei gehoert NICHT zum Spiel. Sie wird nur zur Fehlersuche neben die
 * "index.php" gelegt, im Browser geoeffnet - und danach wieder geloescht.
 *
 * Sie zeigt, welche Pfade der Webserver meldet und welchen Basispfad die
 * Anwendung daraus ableitet. Sie veraendert nichts und gibt keine Geheimnisse aus.
 */
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

$here = str_replace('\\', '/', __DIR__);

/** Zeigt einen Wert an, ohne HTML zu zerbrechen. */
function show(mixed $value): string
{
    if ($value === null || $value === '') {
        return '<em>(leer)</em>';
    }
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* --- Werte des Webservers ------------------------------------------------ */
$server = [
    'REQUEST_URI'     => $_SERVER['REQUEST_URI'] ?? null,
    'SCRIPT_NAME'     => $_SERVER['SCRIPT_NAME'] ?? null,
    'PHP_SELF'        => $_SERVER['PHP_SELF'] ?? null,
    'SCRIPT_FILENAME' => $_SERVER['SCRIPT_FILENAME'] ?? null,
    'DOCUMENT_ROOT'   => $_SERVER['DOCUMENT_ROOT'] ?? null,
    'SERVER_SOFTWARE' => $_SERVER['SERVER_SOFTWARE'] ?? null,
    'Ordner dieser Datei' => $here,
    'PHP-Version'     => PHP_VERSION,
];

/* --- Liegt das Spiel hier? ----------------------------------------------- */
$files = [
    'index.php'             => is_file($here . '/index.php'),
    '.htaccess'             => is_file($here . '/.htaccess'),
    'app/'                  => is_dir($here . '/app'),
    'app/Core/Environment.php' => is_file($here . '/app/Core/Environment.php'),
    'app/config.local.php'  => is_file($here . '/app/config.local.php'),
    'storage/'              => is_dir($here . '/storage'),
];

/* --- Gespeicherter Basispfad (ohne Geheimnisse) -------------------------- */
$stored = null;
$stored_found = false;
foreach ([$here . '/app/config.local.php', $here . '/storage/settings/config.local.php'] as $candidate) {
    if (is_file($candidate)) {
        $loaded = @require $candidate;
        if (is_array($loaded)) {
            $stored = $loaded['base_path'] ?? '';
            $stored_found = true;
            break;
        }
    }
}

/* --- Was leitet die Anwendung daraus ab? --------------------------------- */
$resolved = null;
$environment = $here . '/app/Core/Environment.php';
if (is_file($environment)) {
    if (!defined('WIT_ROOT')) {
        define('WIT_ROOT', $here);
    }
    require_once $environment;
    if (method_exists('App\\Core\\Environment', 'resolveBasePath')) {
        $resolved = \App\Core\Environment::resolveBasePath($stored);
    } elseif (method_exists('App\\Core\\Environment', 'detectBasePath')) {
        $resolved = '(alte Fassung) ' . \App\Core\Environment::detectBasePath();
    }
}

/* --- mod_rewrite ---------------------------------------------------------- */
$rewrite = function_exists('apache_get_modules')
    ? (in_array('mod_rewrite', apache_get_modules(), true) ? 'aktiv' : 'NICHT geladen')
    : 'nicht feststellbar (kein Apache-Modul-Zugriff)';

/* Der oeffentlich gueltige Pfad steht in REQUEST_URI - SCRIPT_NAME kann davon
   abweichen, wenn die uebergeordnete Seite Adressen auf Ordner abbildet. */
$selfUrl = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
$appUrl  = $selfUrl !== '' ? rtrim(dirname($selfUrl), '/') : '';
$appUrl  = $appUrl === '/' ? '' : $appUrl;
?>
<!doctype html>
<html lang="de"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Pfad-Test</title>
<style>
body{margin:0;padding:1.5rem;background:#0b0d10;color:#d7dae0;
font-family:ui-monospace,Menlo,Consolas,monospace;font-size:14px;line-height:1.6}
h1{font-size:1rem;letter-spacing:.2em;text-transform:uppercase;color:#8a939f}
h2{font-size:.85rem;letter-spacing:.14em;text-transform:uppercase;color:#8a939f;margin:1.8rem 0 .4rem}
table{border-collapse:collapse;width:100%;max-width:900px}
td{border-bottom:1px solid #23272e;padding:.35rem .5rem;vertical-align:top;word-break:break-all}
td:first-child{color:#8a939f;white-space:nowrap;width:1%;padding-right:1.5rem}
.ja{color:#5f9e6a}.nein{color:#b4555a}
.warn{border:1px solid #7a1f24;background:#17090b;padding:.8rem 1rem;max-width:900px;margin:1.5rem 0}
a{color:#5b8db8}
</style></head><body>

<h1>WHERE IS TOBY? &middot; Pfad-Test</h1>

<h2>1. Was der Webserver meldet</h2>
<table>
<?php foreach ($server as $key => $value): ?>
  <tr><td><?= show($key) ?></td><td><?= show($value) ?></td></tr>
<?php endforeach; ?>
  <tr><td>mod_rewrite</td><td><?= show($rewrite) ?></td></tr>
</table>

<h2>2. Liegt das Spiel in diesem Ordner?</h2>
<table>
<?php foreach ($files as $name => $exists): ?>
  <tr><td><?= show($name) ?></td>
      <td class="<?= $exists ? 'ja' : 'nein' ?>"><?= $exists ? 'vorhanden' : 'FEHLT' ?></td></tr>
<?php endforeach; ?>
</table>

<h2>3. Basispfad</h2>
<table>
  <tr><td>in der Konfiguration</td>
      <td><?= $stored_found ? show($stored) : '<em>(keine Konfiguration gefunden)</em>' ?></td></tr>
  <tr><td>von der Anwendung verwendet</td>
      <td><?= $resolved === null ? '<em>(Environment.php nicht gefunden)</em>' : show($resolved) ?></td></tr>
  <tr><td>erwartet waere</td><td><?= show($appUrl === '' ? '(leer - Hauptverzeichnis)' : $appUrl) ?></td></tr>
</table>

<h2>4. Zum Ausprobieren</h2>
<p>
  <a href="<?= show($appUrl) ?>/">Startseite oeffnen</a> &nbsp;|&nbsp;
  <a href="<?= show($appUrl) ?>/faelle">Fallliste oeffnen</a>
</p>

<div class="warn">
  <strong>Diese Datei bitte nach der Pruefung wieder loeschen.</strong><br>
  Sie zeigt Serverpfade an und wird fuer den Betrieb nicht gebraucht.
</div>

</body></html>
