<?php

declare(strict_types=1);

/**
 * Mogli – liefert das eingesetzte Grafikpaket an das Spiel.
 *
 *   GET assets.php -> { ok: true, pack: {…} }
 *
 * WARUM ES DIESE DATEI GIBT
 * Das Paket liegt in data/, und data/ ist per .htaccess für den direkten Abruf
 * gesperrt – dort liegen auch die Bestenliste und die Ratenbegrenzung, die
 * niemanden etwas angehen. Das Spiel braucht das Paket aber. Diese Datei ist
 * das einzige Fenster darauf, und sie gibt ausschliesslich `pack` heraus, nie
 * den Rest der Ablage.
 *
 * Geschrieben wird hier nichts. Wer etwas ändern will, geht über admin.php.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

const DATA_DIR = __DIR__ . '/data';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// HEAD gehört dazu: so fragen Zwischenspeicher und Überwachungsdienste nach,
// ob sich etwas geändert hat, ohne das ganze Paket zu holen.
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'HEAD') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$file  = DATA_DIR . '/assets.json';
$empty = ['version' => 1];

$pack = $empty;
$stamp = 0;
if (is_file($file)) {
    $raw = @file_get_contents($file);
    if ($raw !== false && $raw !== '') {
        $parsed = json_decode($raw, true);
        if (is_array($parsed) && is_array($parsed['pack'] ?? null)) {
            $pack  = $parsed['pack'];
            $stamp = (int) ($parsed['updated'] ?? 0);
        }
    }
}

/*
 * Ein Paket kann mehrere Megabyte gross sein und ändert sich fast nie. Ohne
 * ETag lüde jeder Besucher es bei jedem Aufruf erneut – auf dem Handy die
 * teuerste Sekunde des ganzen Spiels. Mit ETag antwortet der Server beim
 * zweiten Mal mit 304 und null Bytes Rumpf.
 */
$etag = '"' . substr(hash('sha256', (string) json_encode($pack)), 0, 24) . '"';
header('ETag: ' . $etag);
header('Cache-Control: public, max-age=60, must-revalidate');
if ($stamp > 0) {
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $stamp) . ' GMT');
}

$sent = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
if ($sent !== '' && trim($sent) === $etag) {
    http_response_code(304);
    exit;
}

if ($method === 'HEAD') {
    exit;
}

echo json_encode(['ok' => true, 'pack' => $pack], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
