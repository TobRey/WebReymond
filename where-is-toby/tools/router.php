<?php
/**
 * Router fuer den PHP-Entwicklungsserver (nur fuer lokale Tests).
 * Auf dem Produktivserver uebernimmt .htaccess diese Aufgabe.
 */
$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$root = getcwd();
$file = $root . $path;

if (preg_match('~^/(app|storage|uploads)/~', $path)) {
    http_response_code(403);
    echo 'Kein Zugriff.';
    return true;
}
if ($path !== '/' && is_file($file)) {
    if (str_ends_with($path, '.php')) {
        require $file;
        return true;
    }
    return false; // statische Datei ausliefern
}
require $root . '/index.php';
return true;
