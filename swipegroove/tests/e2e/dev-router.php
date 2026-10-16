<?php
/**
 * Router für den PHP-Entwicklungsserver mit Unterordner-Emulation:
 *   php -S 127.0.0.1:8080 -t <docroot> tests/e2e/dev-router.php
 * <docroot> enthält einen Symlink "groove" auf das Projektverzeichnis.
 * Statische Dateien werden direkt ausgeliefert, alles andere geht an index.php
 * (entspricht der .htaccess-Regel). Geschützte Ordner werden wie in der .htaccess blockiert.
 */
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$docroot = $_SERVER['DOCUMENT_ROOT'];
if (preg_match('#^/groove/(app|config|storage|migrations|cron|lang|tests|frontend|vendor)(/|$)#', $path)) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}
$file = $docroot . $path;
if ($path !== '/groove/' && $path !== '/groove' && is_file($file) && !str_ends_with($file, '.php')) {
    return false; // statisch ausliefern
}
$_SERVER['SCRIPT_NAME'] = '/groove/index.php';
$_SERVER['SCRIPT_FILENAME'] = $docroot . '/groove/index.php';
require $docroot . '/groove/index.php';
