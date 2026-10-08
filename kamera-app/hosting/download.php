<?php
/** Liefert die Kamera-Brücke und die Anleitung als Download aus. */
declare(strict_types=1);

$files = [
    'mac'    => ['download/Kamera-Bruecke-Mac.zip', 'application/zip'],
    'win'    => ['download/Kamera-Bruecke-Windows.zip', 'application/zip'],
    'py'     => ['download/kamera-bruecke.py', 'text/x-python'],
    'readme' => ['download/LIESMICH.txt', 'text/plain; charset=utf-8'],
    'ios'    => ['download/Kamera-Link-iOS-Quellcode.zip', 'application/zip'],
];

$key = isset($_GET['f']) ? (string) $_GET['f'] : '';
if (!isset($files[$key])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Unbekannte Datei.');
}
[$rel, $mime] = $files[$key];
$path = __DIR__ . '/' . $rel;
if (!is_file($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Datei fehlt auf dem Server. Bitte das ZIP vollständig entpacken.');
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: ' . ($key === 'readme' ? 'inline' : 'attachment') . '; filename="' . basename($path) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-cache');
readfile($path);
