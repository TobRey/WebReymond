<?php

/**
 * WebAtze – Front-Controller
 * ==========================
 *
 * Jede Anfrage landet hier (siehe .htaccess). Von hier aus geht es weiter
 * an die zuständige Klasse in app/Http.
 */

declare(strict_types=1);

// Der eingebaute PHP-Server kennt keine .htaccess. Damit ein lokaler
// Probelauf ("php -S 127.0.0.1:8080 -t public_html index.php") dieselben
// Adressen liefert wie später der echte Server, werden vorhandene
// Dateien hier direkt durchgereicht.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    $real = realpath($file);
    if ($real !== false
        && is_file($real)
        && str_starts_with($real, __DIR__ . DIRECTORY_SEPARATOR)
        && !str_starts_with($real, __DIR__ . '/app')
        && !str_starts_with($real, __DIR__ . '/storage')
        && !str_ends_with($real, '.php')
    ) {
        return false;
    }
}

require __DIR__ . '/app/bootstrap.php';

use WebAtze\Core\Kernel;

Kernel::handle();
