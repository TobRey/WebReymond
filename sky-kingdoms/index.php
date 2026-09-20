<?php
/**
 * Sky Kingdoms – Haupteinstieg.
 *
 * Diese Datei ist der einzige Einstiegspunkt für alle Seiten ausserhalb von
 * api/, admin/ und install/. Sie funktioniert in jedem Ordner, mit und ohne
 * mod_rewrite: Seiten werden über ?p=... angesteuert.
 */

declare(strict_types=1);

define('SK_ENTRY_DEPTH', 0);
require __DIR__ . '/app/bootstrap.php';

\SkyKingdoms\Http\Router::web();
