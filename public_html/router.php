<?php
/**
 * Nur für den eingebauten PHP-Server beim Entwickeln:
 *   php -S 127.0.0.1:8080 -t public_html public_html/router.php
 *
 * Auf dem echten Hosting macht das die .htaccess. Diese Datei wird beim
 * Bauen des Auslieferungspakets nicht mitgenommen.
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$file = __DIR__ . $path;

if ($path !== '/' && is_file($file) && !str_contains($path, '..')) {
    return false;
}
require __DIR__ . '/index.php';
