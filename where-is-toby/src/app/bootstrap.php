<?php
/**
 * WHERE IS TOBY? - Bootstrap
 * Laedt Autoloader, Konfiguration, Fehlerbehandlung und Basisdienste.
 */
declare(strict_types=1);

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('WHERE IS TOBY? benoetigt mindestens PHP 8.1 (empfohlen: PHP 8.4).');
}

define('WIT_START', microtime(true));
define('WIT_VERSION', '1.0.0');
define('WIT_SCHEMA_VERSION', 3);
define('WIT_ROOT', dirname(__DIR__));
define('WIT_APP', WIT_ROOT . '/app');
define('WIT_PUBLIC_ASSETS', WIT_ROOT . '/assets');

mb_internal_encoding('UTF-8');
date_default_timezone_set('UTC');

require_once WIT_APP . '/Core/Autoloader.php';
\App\Core\Autoloader::register(WIT_APP);

/* ---------------------------------------------------------------
 |  Lokale Konfiguration (wird vom Installer erzeugt)
 --------------------------------------------------------------- */
$localConfigFile = WIT_APP . '/config.local.php';
$local = is_file($localConfigFile) ? (require $localConfigFile) : [];
if (!is_array($local)) {
    $local = [];
}

define('WIT_INSTALLED', (bool)($local['installed'] ?? false));
define('WIT_STORAGE', rtrim((string)($local['storage_path'] ?? (WIT_ROOT . '/storage')), '/'));
define('WIT_UPLOADS', rtrim((string)($local['uploads_path'] ?? (WIT_ROOT . '/uploads')), '/'));
define('WIT_APP_KEY', (string)($local['app_key'] ?? ''));
define('WIT_BASE_PATH', rtrim((string)($local['base_path'] ?? \App\Core\Environment::detectBasePath()), '/'));
define('WIT_DEBUG', (bool)($local['debug'] ?? false));

\App\Core\Logger::configure(WIT_STORAGE . '/logs');
\App\Core\ErrorHandler::register(WIT_DEBUG);

/* ---------------------------------------------------------------
 |  Container (bewusst schlank gehalten, keine externe Library)
 --------------------------------------------------------------- */
return \App\Core\Container::boot();
