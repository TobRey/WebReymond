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
 |  Lokale Konfiguration
 |  Quelle 1: app/config.local.php (Installer oder Ersteinrichtung)
 |  Quelle 2: storage/settings/config.local.php (falls app/ nicht beschreibbar war)
 --------------------------------------------------------------- */
$local = [];
foreach ([WIT_APP . '/config.local.php', WIT_ROOT . '/storage/settings/config.local.php'] as $candidate) {
    if (is_file($candidate)) {
        $loaded = require $candidate;
        if (is_array($loaded) && ($loaded['installed'] ?? false)) {
            $local = $loaded;
            break;
        }
    }
}

/* Paket ohne Installationsassistent: beim ersten Aufruf selbst einrichten */
define('WIT_AUTO_SETUP_FAILED', (static function () use (&$local): bool {
    if (($local['installed'] ?? false) || is_file(WIT_ROOT . '/install.php')) {
        return false;
    }
    $config = \App\Service\AutoSetup::run(WIT_ROOT);
    if ($config === null) {
        return true;
    }
    $local = $config;
    return false;
})());

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
