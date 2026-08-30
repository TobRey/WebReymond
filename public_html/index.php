<?php

/**
 * WebAtze – Front-Controller
 * ==========================
 *
 * Jede Anfrage landet hier (siehe .htaccess). Von hier aus geht es weiter
 * an die zuständige Klasse in app/Http.
 */

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use WebAtze\Core\Kernel;

Kernel::handle();
