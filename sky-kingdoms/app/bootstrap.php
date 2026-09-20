<?php
/**
 * Sky Kingdoms – Start jeder Anfrage.
 *
 * Jeder Einstiegspunkt (index.php, api/, admin/, install/) definiert vorher
 * SK_ENTRY_DEPTH – die Anzahl Ordner zwischen sich und dem Projektordner –
 * und bindet danach diese Datei ein. Alles Weitere passiert hier.
 */

declare(strict_types=1);

if (PHP_VERSION_ID < 80200) {
    http_response_code(500);
    exit('Sky Kingdoms benötigt PHP 8.2 oder neuer. Gefunden: ' . PHP_VERSION);
}

define('SK_ROOT', dirname(__DIR__));
define('SK_VERSION', '1.0.0');
defined('SK_ENTRY_DEPTH') || define('SK_ENTRY_DEPTH', 0);
define('SK_START', microtime(true));

// ---------------------------------------------------------------------
// Autoloader (PSR-4 für den Namensraum SkyKingdoms\ auf app/)
// ---------------------------------------------------------------------
spl_autoload_register(static function (string $class): void {
    $prefix = 'SkyKingdoms\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $relative = str_replace('\\', DIRECTORY_SEPARATOR, $relative);
    $file = SK_ROOT . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require_once SK_ROOT . '/app/Core/helpers.php';

\SkyKingdoms\Core\App::boot();
