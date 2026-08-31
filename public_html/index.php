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
// Probelauf ("php -S 127.0.0.1:8080 public_html/index.php") dieselben
// Adressen liefert wie später der echte Server, wird hier dieselbe Regel
// nachgebildet: Was als Datei existiert, wird direkt ausgeliefert.
//
// Genau so steht es in der .htaccess (RewriteCond -f). Auch install.php
// gehört dazu – wäre sie ausgenommen, liefe die Einrichtung lokal anders
// als auf dem Server, und das fiele erst dort auf.
//
// app/ und storage/ bleiben gesperrt. Der eingebaute Server kennt die
// .htaccess in diesen Ordnern nicht, deshalb steht die Sperre hier.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    $real = realpath($file);

    $gesperrt = static fn (string $path): bool
        => str_starts_with($path, __DIR__ . '/app')
        || str_starts_with($path, __DIR__ . '/storage');

    if ($real !== false
        && is_file($real)
        && str_starts_with($real, __DIR__ . DIRECTORY_SEPARATOR)
        && $real !== __FILE__
        && !$gesperrt($real)
    ) {
        return false;
    }

    if ($real !== false && $gesperrt($real)) {
        http_response_code(404);
        exit;
    }
}

require __DIR__ . '/app/bootstrap.php';

use WebAtze\Core\Kernel;

Kernel::handle();
