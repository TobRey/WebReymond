<?php
/**
 * Einstiegspunkt des Installationsassistenten.
 * Erreichbar unter <deine-adresse>/install/
 */

declare(strict_types=1);

define('SK_ENTRY_DEPTH', 1);
require __DIR__ . '/../app/bootstrap.php';

(new \SkyKingdoms\Http\Controllers\InstallController())->handle();
