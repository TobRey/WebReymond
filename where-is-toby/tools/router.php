<?php
/**
 * Router fuer den PHP-Entwicklungsserver (nur fuer lokale Tests).
 * Auf dem Produktivserver uebernimmt .htaccess diese Aufgabe.
 *
 * Unterstuetzt auch Installationen in einem Unterordner: Dazu wird der laengste
 * Pfadanfang gesucht, unter dem eine index.php liegt.
 */
$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$root = getcwd();
$file = $root . $path;

/* Anwendungsordner bestimmen (leer = direkt im Serververzeichnis) */
$prefix = '';
$segments = array_values(array_filter(explode('/', trim($path, '/')), static fn($s) => $s !== ''));
for ($count = count($segments); $count > 0; $count--) {
    $candidate = '/' . implode('/', array_slice($segments, 0, $count));
    if (is_file($root . $candidate . '/index.php') && is_dir($root . $candidate . '/app')) {
        $prefix = $candidate;
        break;
    }
}

/* Programm- und Datenverzeichnisse sind niemals direkt erreichbar. Geprueft wird
   der Pfad ab dem Anwendungsordner - genau wie bei der Regel in der .htaccess. */
$relative = substr($path, strlen($prefix)) ?: '/';
if (preg_match('~^/(app|storage|uploads)/~', $relative)) {
    http_response_code(403);
    echo 'Kein Zugriff.';
    return true;
}

if ($path !== '/' && $path !== $prefix . '/' && is_file($file) && !is_dir($file)) {
    if (str_ends_with($path, '.php')) {
        require $file;
        return true;
    }
    return false; // statische Datei ausliefern
}

$_SERVER['SCRIPT_NAME'] = $prefix . '/index.php';
require $root . $prefix . '/index.php';
return true;
