<?php
// Gibt die Karten aus dem Editor an das Spiel heraus. Öffentlich lesbar –
// die Karten SIND das Spiel. Geschrieben wird nur über admin.php.
//
// Muster wie assets.php: eine flache JSON-Datei, kein SQL, und bei jedem
// Fehler eine gültige JSON-Antwort, damit der Client sauber zurückfallen kann.

declare(strict_types=1);

const DATA_DIR = __DIR__ . '/data';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$file = DATA_DIR . '/maps.json';
if (!is_file($file)) {
    echo json_encode(['ok' => true, 'maps' => []]);
    exit;
}

$raw = file_get_contents($file);
$data = is_string($raw) ? json_decode($raw, true) : null;
$maps = is_array($data) && is_array($data['maps'] ?? null) ? $data['maps'] : [];

echo json_encode(['ok' => true, 'maps' => $maps]);
