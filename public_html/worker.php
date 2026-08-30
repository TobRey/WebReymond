<?php

/**
 * WebAtze – Auftragsbearbeitung
 * =============================
 *
 * Diese Datei arbeitet die Warteschlange ab. Sie wird vom Cronjob
 * aufgerufen, einmal pro Minute:
 *
 *   * * * * * /usr/local/bin/php /home/DEINBENUTZER/public_html/worker.php >/dev/null 2>&1
 *
 * Den genauen Pfad zu PHP zeigt cPanel beim Anlegen des Cronjobs an.
 *
 * Zum Prüfen von Hand:
 *   php worker.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    // Über den Browser läuft der Worker unter /worker/tick – mit Prüfung.
    http_response_code(404);
    exit;
}

require __DIR__ . '/app/bootstrap.php';

use WebAtze\Core\{Config, Worker};

if (!Config::isInstalled()) {
    fwrite(STDERR, "WebAtze ist noch nicht eingerichtet (app/config.php fehlt).\n");
    exit(1);
}

$result = Worker::run();

printf(
    "%s  Aufträge: %d  Dauer: %.2fs%s\n",
    date('Y-m-d H:i:s'),
    $result['jobs'],
    $result['seconds'],
    $result['housekeeping'] ? '  (aufgeräumt)' : ''
);
