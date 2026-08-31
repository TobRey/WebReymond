<?php

/**
 * Gibt die Besuchszahlen heraus – aber nur, wer den Schlüssel hat.
 *
 * WebAtze holt sie einmal am Tag ab. Es kommen ausschliesslich Summen
 * heraus: Tag, Seite, Herkunft, Anzahl. Keine Adressen, keine Kennungen,
 * nichts, was auf eine Person zurückführt.
 *
 * Der Schlüssel wird zeitunabhängig verglichen, damit sich über die
 * Antwortdauer nichts erraten lässt.
 */

declare(strict_types=1);

$config = require __DIR__ . '/data/config.php';
$erwartet = (string) ($config['stats_token'] ?? '');

$gegeben = (string) ($_SERVER['HTTP_X_WEBATZE_TOKEN'] ?? $_GET['t'] ?? '');

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

if ($erwartet === '' || strlen($gegeben) < 20 || !hash_equals($erwartet, $gegeben)) {
    http_response_code(403);
    echo json_encode(['ok' => false]);
    exit;
}

$file = __DIR__ . '/data/zaehler.php';

$raw = is_file($file) ? (string) file_get_contents($file) : '';
$ende = strpos($raw, '?>');
$daten = $ende !== false ? json_decode(trim(substr($raw, $ende + 2)), true) : [];
$daten = is_array($daten) ? $daten : [];

// Nur Summen hinausgeben. Die Kennungen der Besucher bleiben hier –
// sie werden nur gezählt, nie weitergereicht.
$out = [];

foreach ((array) ($daten['tage'] ?? []) as $tag => $seiten) {
    foreach ((array) $seiten as $pfad => $herkuenfte) {
        foreach ((array) $herkuenfte as $herkunft => $werte) {
            $out[] = [
                'tag' => (string) $tag,
                'pfad' => (string) $pfad,
                'herkunft' => (string) $herkunft,
                'aufrufe' => (int) ($werte['aufrufe'] ?? 0),
                'besucher' => count((array) ($werte['besucher'] ?? [])),
            ];
        }
    }
}

echo json_encode(['ok' => true, 'zeilen' => $out], JSON_UNESCAPED_UNICODE);
