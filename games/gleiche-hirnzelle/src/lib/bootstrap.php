<?php
declare(strict_types=1);

/*
 * Gemeinsamer Einstieg für alle Server-Skripte.
 */

if (!defined('GH_APP')) {
    http_response_code(404);
    exit;
}

const GH_ROUNDS = 10;
const GH_ANSWER_MAX_LEN = 40;
const GH_ONLINE_SECONDS = 15;      // so lange gilt jemand nach dem letzten Lebenszeichen als online
const GH_SEAT_TAKEOVER_SECONDS = 90; // danach darf ein neues Gerät einen verwaisten Platz übernehmen
const GH_SKIP_WAIT_SECONDS = 30;   // danach darf man ohne abwesende Person weiterspielen

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Gleiche Hirnzelle braucht PHP 8.1 oder neuer. Stelle die PHP-Version in cPanel unter "Select PHP Version" / "MultiPHP Manager" um.';
    exit;
}

mb_internal_encoding('UTF-8');

function gh_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $file = dirname(__DIR__) . '/config.php';
        $loaded = is_file($file) ? require $file : [];
        $cfg = array_merge([
            'anthropic_api_key' => '',
            'model' => 'claude-opus-5-5',
            'effort' => 'low',
            'api_timeout' => 25,
            'use_fallback_models' => true,
            'data_dir' => null,
            'room_lifetime_hours' => 48,
            'max_rooms_per_hour' => 30,
            'api_base_url' => 'https://api.anthropic.com',
        ], is_array($loaded) ? $loaded : []);
        $key = trim((string) $cfg['anthropic_api_key']);
        if ($key === '') {
            $env = getenv('ANTHROPIC_API_KEY');
            $key = is_string($env) ? trim($env) : '';
        }
        $cfg['anthropic_api_key'] = $key;
    }
    return $cfg;
}

function gh_data_dir(): string
{
    static $dir = null;
    if ($dir !== null) {
        return $dir;
    }
    $cfg = gh_config();
    $dir = $cfg['data_dir'] ? rtrim((string) $cfg['data_dir'], '/\\') : dirname(__DIR__) . '/data';
    foreach ([$dir, $dir . '/rooms'] as $d) {
        if (!is_dir($d) && !@mkdir($d, 0750, true) && !is_dir($d)) {
            throw new RuntimeException('Datenordner kann nicht angelegt werden: ' . $d);
        }
        // Schutz, falls der Ordner im öffentlichen Webverzeichnis liegt.
        if (!is_file($d . '/.htaccess')) {
            @file_put_contents($d . '/.htaccess', "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
        }
        if (!is_file($d . '/index.html')) {
            @file_put_contents($d . '/index.html', '');
        }
    }
    return $dir;
}

function gh_log(string $message): void
{
    try {
        $file = gh_data_dir() . '/error-log.php';
        if (!is_file($file)) {
            @file_put_contents($file, "<?php http_response_code(404); exit; ?>\n");
        }
        if (@filesize($file) > 512 * 1024) {
            @file_put_contents($file, "<?php http_response_code(404); exit; ?>\n");
        }
        $line = '[' . date('Y-m-d H:i:s') . '] ' . str_replace(["\r", "\n"], ' ', $message) . "\n";
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    } catch (Throwable) {
        // Logging darf das Spiel nie stören.
    }
}

function gh_client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

require __DIR__ . '/storage.php';
require __DIR__ . '/names.php';
require __DIR__ . '/content.php';
require __DIR__ . '/claude.php';
require __DIR__ . '/game.php';
