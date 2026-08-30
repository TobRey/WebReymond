<?php

/**
 * WebAtze – Startdatei
 * ====================
 *
 * Wird von index.php und worker.php eingebunden. Lädt den Klassenlader,
 * die Konfiguration und stellt die Grundeinstellungen von PHP ein.
 */

declare(strict_types=1);

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('WebAtze braucht PHP 8.1 oder neuer. Aktuell läuft PHP ' . PHP_VERSION
        . '. In cPanel unter "MultiPHP Manager" umstellen.');
}

define('WEBATZE', true);
define('APP_DIR', __DIR__);
define('BASE_DIR', dirname(__DIR__));
define('STORAGE_DIR', BASE_DIR . '/storage');
define('WEBATZE_VERSION', '1.0.0');

// ----------------------------------------------------------------------
// Klassenlader – kein Composer nötig
// ----------------------------------------------------------------------
spl_autoload_register(static function (string $class): void {
    // Eigener Code:  WebAtze\Core\Router  ->  app/Core/Router.php
    if (str_starts_with($class, 'WebAtze\\')) {
        $relative = str_replace('\\', '/', substr($class, 8));
        $file = APP_DIR . '/' . $relative . '.php';
        if (is_file($file)) {
            require $file;
        }
        return;
    }

});

// Mitgelieferte Bibliothek für SFTP (phpseclib, reines PHP – läuft ohne
// zusätzliche PHP-Erweiterung auf jedem Hosting). Sie liegt fertig im
// Paket; auf dem Server muss nichts nachinstalliert werden.
if (is_file(APP_DIR . '/vendor/autoload.php')) {
    require APP_DIR . '/vendor/autoload.php';
}

require APP_DIR . '/Support/helpers.php';

// ----------------------------------------------------------------------
// Konfiguration laden
// ----------------------------------------------------------------------
\WebAtze\Core\Config::load(APP_DIR . '/config.php');

// ----------------------------------------------------------------------
// PHP-Grundeinstellungen
// ----------------------------------------------------------------------
mb_internal_encoding('UTF-8');
date_default_timezone_set('Europe/Zurich');

$isProduction = \WebAtze\Core\Config::get('env', 'production') === 'production';

// Auf dem Server niemals Fehler im Browser zeigen – sie verraten Pfade und Struktur.
ini_set('display_errors', $isProduction ? '0' : '1');
ini_set('log_errors', '1');
ini_set('error_log', STORAGE_DIR . '/logs/php-error.log');
error_reporting(E_ALL);

\WebAtze\Core\Logger::register();
