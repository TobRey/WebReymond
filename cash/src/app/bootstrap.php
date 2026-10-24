<?php
/**
 * CASH – gemeinsamer Start für index.php und api.php.
 */
declare(strict_types=1);

if (!defined('CASH_ENTRY')) {
    http_response_code(404);
    exit;
}

const CASH_VERSION = '1.1.0';

date_default_timezone_set('Europe/Zurich');
mb_internal_encoding('UTF-8');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/Store.php';
require __DIR__ . '/Money.php';
require __DIR__ . '/Http.php';
require __DIR__ . '/Auth.php';
require __DIR__ . '/Finance.php';
require __DIR__ . '/Backup.php';

/**
 * Optionale lokale Konfiguration (wird nie mit der ZIP ausgeliefert und bei
 * Updates daher nicht überschrieben):  cash/config.local.php
 *   <?php return ['data_dir' => '/home/BENUTZER/cash_data', 'force_https' => true];
 */
function cash_local_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = [];
        $file = dirname(__DIR__) . '/config.local.php';
        if (is_file($file)) {
            $loaded = require $file;
            if (is_array($loaded)) {
                $cfg = $loaded;
            }
        }
    }
    return $cfg;
}

/** Ermittelt (und erstellt bei Bedarf) das Datenverzeichnis – bevorzugt ausserhalb von public_html. */
function cash_data_dir(): string
{
    static $dir = null;
    if ($dir !== null) {
        return $dir;
    }
    $cfg = cash_local_config();
    $candidates = [];
    if (!empty($cfg['data_dir'])) {
        $candidates = [rtrim((string)$cfg['data_dir'], '/')];
    } elseif (($env = getenv('CASH_DATA_DIR')) !== false && $env !== '') {
        $candidates = [rtrim($env, '/')];
    } else {
        $outside = dirname(__DIR__, 3) . '/cash_data';   // z. B. /home/BENUTZER/cash_data
        $inside = dirname(__DIR__) . '/data';            // Rückfall: public_html/cash/data (gesperrt)
        // Bereits eingerichtete Ablage hat Vorrang, damit Daten nie "verschwinden".
        if (@is_file($outside . '/config.json.php')) {
            $candidates = [$outside];
        } elseif (@is_file($inside . '/config.json.php')) {
            $candidates = [$inside];
        } else {
            $candidates = [$outside, $inside];
        }
    }
    foreach ($candidates as $cand) {
        if (!@is_dir($cand)) {
            @mkdir($cand, 0700, true);
        }
        if (@is_dir($cand) && @is_writable($cand)) {
            $dir = $cand;
            cash_protect_dir($dir);
            foreach (['users', 'backups', 'sessions', 'logs'] as $sub) {
                if (!is_dir($dir . '/' . $sub)) {
                    @mkdir($dir . '/' . $sub, 0700, true);
                }
                cash_protect_dir($dir . '/' . $sub);
            }
            ini_set('error_log', $dir . '/logs/php-error.log');
            return $dir;
        }
    }
    throw new RuntimeException('Kein beschreibbares Datenverzeichnis gefunden.');
}

/** Legt Sperrdateien an, falls ein Verzeichnis doch per HTTP erreichbar wäre. */
function cash_protect_dir(string $dir): void
{
    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n");
    }
    $idx = $dir . '/index.php';
    if (!is_file($idx)) {
        @file_put_contents($idx, "<?php\nhttp_response_code(404);\nexit;\n");
    }
}

function cash_path(string $rel): string
{
    return cash_data_dir() . '/' . $rel;
}

/** Globale Konfiguration (im Datenverzeichnis, bleibt bei Updates erhalten). */
function cash_config(): array
{
    return Store::read(cash_path('config.json.php'), []);
}

function cash_today(): string
{
    return date('Y-m-d');
}

function cash_new_id(): string
{
    return bin2hex(random_bytes(8));
}
