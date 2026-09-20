<?php
/**
 * JSON-Schnittstelle des Spiels.
 *
 * Erreichbar als  <adresse>/api/?a=state
 * Mit mod_rewrite zusätzlich als  <adresse>/api/state
 */

declare(strict_types=1);

define('SK_ENTRY_DEPTH', 1);
require __DIR__ . '/../app/bootstrap.php';

(new \SkyKingdoms\Http\Controllers\ApiController())->handle();
