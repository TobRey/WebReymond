<?php
/**
 * AI Groove – minimaler KI-Proxy.
 *
 * Zweck: Manche Audio-Provider erlauben keine direkten Browser-Aufrufe (CORS).
 * Dieser Proxy leitet genau eine Anfrage weiter und verwendet dabei ausschliesslich
 * den Key, den der Browser des jeweiligen Nutzers in diesem einen Request mitschickt.
 *
 * Der Key wird:
 *   - nicht gespeichert
 *   - nicht geloggt
 *   - nicht an Dritte weitergegeben
 *   - aus allen Fehlermeldungen entfernt
 */

declare(strict_types=1);

require __DIR__ . '/config.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

// --- Vorpruefungen -----------------------------------------------------------
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'OPTIONS') {
    header('Allow: POST');
    http_response_code(204);
    exit;
}

if ($method === 'GET') {
    // Kleiner Statusendpunkt fuer die Diagnose in den Einstellungen.
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'        => true,
        'service'   => 'ai-groove-proxy',
        'php'       => PHP_VERSION,
        'curl'      => function_exists('curl_init'),
        'providers' => array_map(
            static fn(array $p): array => ['label' => $p['label'], 'ops' => array_keys($p['ops'])],
            aig_providers()
        ),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method !== 'POST') {
    header('Allow: POST, GET');
    aig_fail(405, 'method_not_allowed', 'Nur POST wird unterstützt.');
}

aig_check_origin();
aig_rate_limit();

// --- Key ---------------------------------------------------------------------
$key = trim((string) ($_SERVER['HTTP_X_AIG_KEY'] ?? ''));
if ($key === '' || strlen($key) > 512 || preg_match('/[\r\n\0]/', $key)) {
    aig_fail(401, 'missing_key', 'Es wurde kein gültiger API-Key übermittelt.');
}

// --- Body --------------------------------------------------------------------
$body = aig_read_json(AIG_MAX_INPUT_AUDIO * 2);

$providerId = (string) ($body['provider'] ?? '');
$op         = (string) ($body['op'] ?? 'text-to-audio');

$providers = aig_providers();
if (!isset($providers[$providerId])) {
    aig_fail(400, 'unknown_provider', 'Dieser KI-Anbieter wird nicht unterstützt.');
}
$provider = $providers[$providerId];
if (!isset($provider['ops'][$op])) {
    aig_fail(400, 'unsupported_operation', 'Dieser Anbieter unterstützt die gewünschte Funktion nicht.');
}
$endpoint = $provider['ops'][$op];

$prompt = trim((string) ($body['prompt'] ?? ''));
if ($prompt === '' || mb_strlen($prompt) > 2000) {
    aig_fail(400, 'bad_prompt', 'Der Prompt fehlt oder ist zu lang.');
}

$duration = (float) ($body['duration'] ?? 6.0);
$duration = max(1.0, min(190.0, $duration));

$outputFormat = in_array(($body['format'] ?? 'mp3'), ['mp3', 'wav'], true) ? (string) $body['format'] : 'mp3';
$seed = isset($body['seed']) ? max(0, min(4294967294, (int) $body['seed'])) : 0;
$strength = isset($body['strength']) ? max(0.01, min(1.0, (float) $body['strength'])) : 0.7;

// --- Optionales Referenz-Audio (Audio-to-Audio) ------------------------------
$audioBytes = null;
$audioName  = 'input.wav';
if (!empty($body['audio'])) {
    $b64 = (string) $body['audio'];
    if (strlen($b64) > AIG_MAX_INPUT_AUDIO * 1.4) {
        aig_fail(413, 'audio_too_large', 'Das Referenz-Sample ist zu gross (max. 10 MB).');
    }
    $decoded = base64_decode($b64, true);
    unset($b64, $body['audio']);
    if ($decoded === false || strlen($decoded) < 64) {
        aig_fail(400, 'bad_audio', 'Das Referenz-Sample konnte nicht gelesen werden.');
    }
    if (strlen($decoded) > AIG_MAX_INPUT_AUDIO) {
        aig_fail(413, 'audio_too_large', 'Das Referenz-Sample ist zu gross (max. 10 MB).');
    }
    $audioBytes = $decoded;
    $mime = (string) ($body['audioMime'] ?? 'audio/wav');
    $audioName = match (true) {
        str_contains($mime, 'mpeg') => 'input.mp3',
        str_contains($mime, 'ogg')  => 'input.ogg',
        str_contains($mime, 'mp4'), str_contains($mime, 'aac') => 'input.m4a',
        str_contains($mime, 'flac') => 'input.flac',
        default                     => 'input.wav',
    };
}

if ($op === 'audio-to-audio' && $audioBytes === null) {
    aig_fail(400, 'missing_audio', 'Für diese Funktion wird ein Ausgangs-Sample benötigt.');
}

// --- Payload je nach Anbieter zusammenbauen ----------------------------------
$headers = ['Accept: audio/*'];
if ($provider['auth'] === 'bearer') {
    $headers[] = 'Authorization: Bearer ' . $key;
} else {
    $headers[] = 'xi-api-key: ' . $key;
}

$boundary = '----AIGroove' . bin2hex(random_bytes(12));
$payload  = '';
$contentType = '';

if ($endpoint['encode'] === 'multipart') {
    $fields = [
        'prompt'        => $prompt,
        'output_format' => $outputFormat,
        'duration'      => (string) round($duration, 2),
    ];
    if ($seed > 0) {
        $fields['seed'] = (string) $seed;
    }
    if ($op === 'audio-to-audio') {
        $fields['strength'] = (string) round($strength, 3);
    }
    if (!empty($body['model']) && is_string($body['model']) && preg_match('/^[A-Za-z0-9._\-]{1,64}$/', $body['model'])) {
        $fields['model'] = $body['model'];
    }

    foreach ($fields as $name => $value) {
        $payload .= "--{$boundary}\r\n";
        $payload .= "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n";
        $payload .= $value . "\r\n";
    }
    if ($audioBytes !== null) {
        $payload .= "--{$boundary}\r\n";
        $payload .= "Content-Disposition: form-data; name=\"audio\"; filename=\"{$audioName}\"\r\n";
        $payload .= "Content-Type: application/octet-stream\r\n\r\n";
        $payload .= $audioBytes . "\r\n";
    }
    $payload .= "--{$boundary}--\r\n";
    $contentType = 'multipart/form-data; boundary=' . $boundary;
} else {
    $json = [
        'text'             => $prompt,
        'duration_seconds' => round($duration, 2),
        'prompt_influence' => 0.4,
    ];
    $payload = json_encode($json, JSON_UNESCAPED_UNICODE) ?: '{}';
    $contentType = 'application/json';
}
unset($audioBytes);

$headers[] = 'Content-Type: ' . $contentType;
$headers[] = 'Content-Length: ' . strlen($payload);

// --- Weiterleiten ------------------------------------------------------------
[$status, $respHeaders, $respBody, $netError] = aig_forward($endpoint['url'], $headers, $payload);
unset($payload, $headers);

// Key ab hier nur noch zum Bereinigen der Ausgabe verwenden.
if ($netError !== null) {
    aig_fail(502, 'provider_unreachable', 'Der KI-Anbieter ist momentan nicht erreichbar: ' . aig_scrub($netError, $key));
}

$respType = 'application/octet-stream';
foreach ($respHeaders as $h) {
    if (stripos($h, 'content-type:') === 0) {
        $respType = trim(substr($h, 13));
    }
}

if ($status >= 200 && $status < 300 && str_starts_with($respType, 'audio/')) {
    header('Content-Type: ' . preg_replace('/[^\x20-\x7E]/', '', $respType));
    header('Content-Length: ' . strlen($respBody));
    header('X-AIG-Provider: ' . $providerId);
    echo $respBody;
    exit;
}

// --- Fehlerbehandlung --------------------------------------------------------
$message = match (true) {
    $status === 401 || $status === 403 => 'Der API-Key wurde vom Anbieter abgelehnt. Bitte in „KI verbinden“ prüfen.',
    $status === 402                    => 'Das Guthaben beim KI-Anbieter reicht nicht aus.',
    $status === 429                    => 'Der KI-Anbieter hat das Limit gedrosselt. Bitte kurz warten.',
    $status >= 500                     => 'Der KI-Anbieter meldet einen Serverfehler.',
    default                            => 'Die KI-Anfrage wurde abgelehnt.',
};

$detail = '';
if ($respBody !== '' && !str_starts_with($respType, 'audio/')) {
    $parsed = json_decode(substr($respBody, 0, 8000), true);
    if (is_array($parsed)) {
        foreach (['message', 'error', 'detail', 'name', 'errors'] as $field) {
            if (!empty($parsed[$field])) {
                $detail = is_string($parsed[$field]) ? $parsed[$field] : json_encode($parsed[$field]);
                break;
            }
        }
        if ($detail === '' && isset($parsed['detail']['message'])) {
            $detail = (string) $parsed['detail']['message'];
        }
    } else {
        $detail = substr(strip_tags($respBody), 0, 300);
    }
}

aig_fail(
    $status >= 400 && $status < 600 ? $status : 502,
    'provider_error',
    $message,
    $detail !== '' ? ['detail' => aig_scrub(mb_substr($detail, 0, 300), $key)] : []
);

/**
 * Fuehrt die HTTP-Anfrage aus. Bevorzugt cURL, faellt sonst auf Streams zurueck.
 *
 * @return array{0:int,1:string[],2:string,3:?string} [status, headers, body, netError]
 */
function aig_forward(string $url, array $headers, string $payload): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $respHeaders = [];
        $size = 0;
        $body = '';

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT        => AIG_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS      => defined('CURLPROTO_HTTPS') ? CURLPROTO_HTTPS : 2,
            CURLOPT_USERAGENT      => 'AI-Groove/1.0',
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$respHeaders): int {
                $trim = trim($line);
                if ($trim !== '') {
                    $respHeaders[] = $trim;
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION  => function ($ch, string $chunk) use (&$body, &$size): int {
                $size += strlen($chunk);
                if ($size > AIG_MAX_RESPONSE) {
                    return 0; // bricht den Transfer ab
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);

        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = ($ok === false && $status === 0) ? curl_error($ch) : null;
        curl_close($ch);

        if ($err !== null && $err !== '') {
            return [0, [], '', $err];
        }
        return [$status, $respHeaders, $body, null];
    }

    // Fallback ohne cURL.
    if (!ini_get('allow_url_fopen')) {
        return [0, [], '', 'Weder cURL noch allow_url_fopen sind auf diesem Hosting verfügbar.'];
    }

    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", $headers),
            'content'       => $payload,
            'timeout'       => AIG_TIMEOUT,
            'ignore_errors' => true,
            'follow_location' => 0,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);

    $body = @file_get_contents($url, false, $ctx, 0, AIG_MAX_RESPONSE);
    if ($body === false) {
        return [0, [], '', 'Verbindung zum Anbieter fehlgeschlagen.'];
    }
    $respHeaders = $http_response_header ?? [];
    $status = 0;
    foreach ($respHeaders as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
            $status = (int) $m[1];
        }
    }
    return [$status, $respHeaders, $body, null];
}
