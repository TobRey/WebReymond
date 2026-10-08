<?php
/**
 * Kamera-Link – Web-Version für das Webhosting.
 * Funktioniert in jedem Unterordner: Alle Pfade in der App sind relativ.
 * Hinweis: Das Hosting kann die Kamera im lokalen WLAN nicht erreichen –
 * diese Seite öffnet deshalb die lokale Kamera-Brücke.
 */
declare(strict_types=1);

$dir = __DIR__;
$template = @file_get_contents($dir . '/app.html');
if ($template === false) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Die Datei app.html fehlt. Bitte das ZIP vollständig entpacken.');
}
$version = trim((string) @file_get_contents($dir . '/VERSION'));
if ($version === '' || !preg_match('/^[0-9A-Za-z.\-]+$/', $version)) {
    $version = '1';
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');

echo strtr($template, ['{{MODE}}' => 'hosted', '{{VERSION}}' => $version]);
