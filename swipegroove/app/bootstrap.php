<?php
declare(strict_types=1);

/**
 * Bootstrap: Autoloader, Fehlerbehandlung, Grundkonstanten.
 */

if (PHP_VERSION_ID < 80200) {
    http_response_code(500);
    echo 'SwipeGroove benötigt PHP 8.2 oder neuer (empfohlen 8.4). / SwipeGroove requires PHP 8.2+ (8.4 recommended).';
    exit;
}

define('SG_ROOT', dirname(__DIR__));
define('SG_APP', __DIR__);
define('SG_VERSION', '1.2.0');

spl_autoload_register(static function (string $class): void {
    $prefix = 'SwipeGroove\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = SG_APP . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
if (is_dir(SG_ROOT . '/storage/logs')) {
    ini_set('error_log', SG_ROOT . '/storage/logs/php-error.log');
}
date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');
