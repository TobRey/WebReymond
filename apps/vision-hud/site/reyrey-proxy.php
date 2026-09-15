<?php
/**
 * Vermittler zwischen ReyRey und der Claude-API.
 *
 * WOZU: Ohne diese Datei müsste der API-Schlüssel im Browser liegen – und wer
 * das Gerät in die Hand bekommt, liest ihn dort aus. Hier bleibt der Schlüssel
 * auf dem Server; der Browser schickt nur die Frage.
 *
 * EINRICHTEN
 *   1. Im cPanel-Dateimanager diese Datei öffnen und unten bei API_KEY den
 *      eigenen Schlüssel eintragen (console.anthropic.com → API Keys).
 *   2. Datei speichern. Rechte auf 600 setzen (Rechtsklick → Berechtigungen).
 *   3. Im HUD unter „System → KI-Dienst“ die Betriebsart „Proxy“ wählen und
 *      als Adresse eintragen:  reyrey-proxy.php
 *
 * BESSER: Den Schlüssel ausserhalb von public_html ablegen und hier nur
 * einlesen – dann liegt er auch dann nicht offen, wenn PHP einmal ausfällt:
 *   $key = trim(@file_get_contents('/home/DEINBENUTZER/reyrey.key'));
 *
 * Diese Datei kommt bewusst ohne Composer und ohne SDK aus: Auf einem
 * gewöhnlichen Webhosting-Paket gibt es keinen Kommandozeilenzugang, um
 * Abhängigkeiten zu installieren.
 */

declare(strict_types=1);

// --------------------------------------------------------------------------
// Einstellungen
// --------------------------------------------------------------------------

const API_KEY = '';                       // <-- hier eintragen
const KEY_FILE = '';                      // oder: absoluter Pfad zu einer Schlüsseldatei
const API_URL = 'https://api.anthropic.com/v1/messages';
const API_VERSION = '2023-06-01';

/** Nur diese Modelle dürfen angefragt werden. */
const ALLOWED_MODELS = ['claude-opus-5', 'claude-sonnet-5', 'claude-haiku-4-5'];

/** Obergrenze je Anfrage – verhindert, dass ein fremder Aufruf Kosten treibt. */
const MAX_TOKENS_CAP = 1024;
const MAX_BODY_BYTES = 64 * 1024;

/** Leer lassen = gleiche Domain. Sonst z. B. 'https://example.tld'. */
const ALLOW_ORIGIN = '';

// --------------------------------------------------------------------------

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
if (ALLOW_ORIGIN !== '') {
    header('Access-Control-Allow-Origin: ' . ALLOW_ORIGIN);
    header('Access-Control-Allow-Headers: content-type');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function fail(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['error' => ['message' => $message]], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, 'Nur POST.');
}

$key = API_KEY;
if ($key === '' && KEY_FILE !== '' && is_readable(KEY_FILE)) {
    $key = trim((string) file_get_contents(KEY_FILE));
}
if ($key === '') {
    fail(500, 'Es ist kein API-Schlüssel hinterlegt. Siehe Kommentar in reyrey-proxy.php.');
}

$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > MAX_BODY_BYTES) {
    fail(413, 'Anfrage zu gross.');
}

$body = json_decode($raw, true);
if (!is_array($body) || !isset($body['messages']) || !is_array($body['messages'])) {
    fail(400, 'Ungültige Anfrage.');
}

// Nur bekannte Felder weiterreichen – nichts, was der Browser sonst erfindet.
$model = is_string($body['model'] ?? null) ? $body['model'] : ALLOWED_MODELS[0];
if (!in_array($model, ALLOWED_MODELS, true)) {
    fail(400, 'Dieses Modell ist nicht freigegeben.');
}

$payload = [
    'model' => $model,
    'max_tokens' => min((int) ($body['max_tokens'] ?? 400), MAX_TOKENS_CAP),
    'messages' => $body['messages'],
];
if (isset($body['system']) && is_string($body['system'])) {
    $payload['system'] = $body['system'];
}

$curl = curl_init(API_URL);
curl_setopt_array($curl, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 40,
    CURLOPT_HTTPHEADER => [
        'content-type: application/json',
        'x-api-key: ' . $key,
        'anthropic-version: ' . API_VERSION,
    ],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
]);

$response = curl_exec($curl);
$status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
$error = curl_error($curl);
curl_close($curl);

if ($response === false) {
    fail(502, 'Die API ist nicht erreichbar: ' . $error);
}

http_response_code($status ?: 502);
echo $response;
