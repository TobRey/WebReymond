<?php
declare(strict_types=1);

/**
 * SwipeGroove – Front Controller.
 * Alle Anfragen (ausser statische Dateien) laufen über diese Datei.
 */

require __DIR__ . '/app/bootstrap.php';

SwipeGroove\Core\App::create(__DIR__)->run();
