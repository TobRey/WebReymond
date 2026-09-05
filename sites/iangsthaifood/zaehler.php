<?php
/**
 * Nimmt die Meldung eines Seitenaufrufs entgegen und schreibt sie in eine
 * Datei unter data/stats/. Gespeichert werden nur Seitenpfad, Tag und Anzahl.
 * Keine IP-Adresse, kein Cookie, keine Kennung.
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/zaehlung.php';

header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo "nur POST\n";
    exit;
}

$roh = (string) file_get_contents('php://input');
$daten = json_decode($roh, true);
$pfad = is_array($daten) ? (string) ($daten['p'] ?? '') : (string) ($_POST['p'] ?? '');

if ($pfad === '') {
    http_response_code(400);
    echo "kein Pfad\n";
    exit;
}

if (!zaehlung_ist_automat()) {
    zaehlung_buchen($pfad);
}

wochenbericht_wenn_faellig();

http_response_code(204);
