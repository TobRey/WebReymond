<?php
/**
 * AI Groove – gemeinsame Hilfsfunktionen fuer den KI-Proxy.
 *
 * Grundsaetze:
 *  - Es wird NIEMALS ein API-Key gespeichert, geloggt oder zwischengespeichert.
 *  - Der Key des Nutzers lebt ausschliesslich fuer die Dauer eines Requests im Speicher.
 *  - Fehlermeldungen werden bereinigt, damit kein Secret nach aussen gelangt.
 */

declare(strict_types=1);

// Direkter Aufruf ist nie vorgesehen. Zusaetzlich zur .htaccess-Regel wird der
// Zugriff hier hart abgewiesen (falls .htaccess auf dem Hosting ignoriert wird).
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Forbidden');
}

if (!defined('AIG_API')) {
    define('AIG_API', true);
}

// Keine PHP-Warnungen in die Antwort schreiben (koennten Header/Payload enthalten).
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
error_reporting(E_ALL);

/** Maximale Groesse eines hochgeladenen Referenz-Samples in Bytes (roh, nicht Base64). */
const AIG_MAX_INPUT_AUDIO = 10 * 1024 * 1024;

/** Maximale Groesse einer Provider-Antwort in Bytes. */
const AIG_MAX_RESPONSE = 48 * 1024 * 1024;

/** Zeitfenster fuer das Rate-Limit in Sekunden. */
const AIG_RATE_WINDOW = 600;

/** Maximale Anzahl Proxy-Anfragen pro Zeitfenster und IP. */
const AIG_RATE_MAX = 60;

/** Netzwerk-Timeout gegenueber dem Provider in Sekunden. */
const AIG_TIMEOUT = 180;

/**
 * Registry der unterstuetzten Anbieter.
 *
 * Jeder Eintrag definiert ausschliesslich fest verdrahtete Ziel-URLs.
 * Es ist bewusst NICHT moeglich, eine beliebige URL vom Client aus anzusteuern
 * (Schutz gegen SSRF / offenen Proxy).
 */
function aig_providers(): array
{
    return [
        'stability' => [
            'label' => 'Stability AI – Stable Audio 2',
            'auth'  => 'bearer',
            'ops'   => [
                'text-to-audio'  => [
                    'url'    => 'https://api.stability.ai/v2beta/audio/stable-audio-2/text-to-audio',
                    'encode' => 'multipart',
                ],
                'audio-to-audio' => [
                    'url'    => 'https://api.stability.ai/v2beta/audio/stable-audio-2/audio-to-audio',
                    'encode' => 'multipart',
                ],
            ],
        ],
        'elevenlabs' => [
            'label' => 'ElevenLabs – Sound Generation',
            'auth'  => 'xi-api-key',
            'ops'   => [
                'text-to-audio' => [
                    'url'    => 'https://api.elevenlabs.io/v1/sound-generation',
                    'encode' => 'json',
                ],
            ],
        ],
    ];
}

/**
 * Entfernt moegliche Secrets aus einem Text, bevor er ausgeliefert wird.
 */
function aig_scrub(string $text, string ...$secrets): string
{
    foreach ($secrets as $secret) {
        $secret = trim($secret);
        if ($secret !== '' && strlen($secret) >= 8) {
            $text = str_replace($secret, '[entfernt]', $text);
        }
    }
    // Typische Key-Muster zusaetzlich maskieren.
    $text = preg_replace('/\b(sk-[A-Za-z0-9\-_]{8,}|xi-[A-Za-z0-9\-_]{8,})\b/', '[entfernt]', $text) ?? $text;
    $text = preg_replace('/(Bearer\s+)[A-Za-z0-9\-\._~\+\/=]{8,}/i', '$1[entfernt]', $text) ?? $text;
    return $text;
}

/**
 * Sendet eine JSON-Fehlerantwort und beendet die Ausfuehrung.
 */
function aig_fail(int $status, string $code, string $message, array $extra = []): never
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8', true, $status);
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode(
        ['error' => array_merge(['code' => $code, 'message' => $message], $extra)],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

/**
 * Erlaubt nur gleiche Herkunft. Verhindert, dass fremde Seiten den Proxy verwenden.
 */
function aig_check_origin(): void
{
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

    if ($origin === '') {
        // Manche Browser senden bei same-origin fetch kein Origin – das ist zulaessig.
        return;
    }
    $parsed = parse_url($origin);
    $originHost = $parsed['host'] ?? '';
    if ($originHost === '') {
        aig_fail(403, 'forbidden_origin', 'Anfrage von unbekannter Herkunft.');
    }
    if (isset($parsed['port'])) {
        $originHost .= ':' . $parsed['port'];
    }
    if (strcasecmp($originHost, $host) !== 0) {
        aig_fail(403, 'forbidden_origin', 'Anfrage von unbekannter Herkunft.');
    }
}

/**
 * Verzeichnis fuer Rate-Limit-Zaehler. Liegt ausserhalb des Webroots (Systemtemp).
 */
function aig_rate_dir(): ?string
{
    $dir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'aigroove-rl';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return is_dir($dir) && is_writable($dir) ? $dir : null;
}

/**
 * Einfaches dateibasiertes Rate-Limit pro IP (keine Datenbank noetig).
 * Speichert ausschliesslich einen Hash der IP und Zeitstempel – keine Keys, keine Prompts.
 */
function aig_rate_limit(): void
{
    $dir = aig_rate_dir();
    if ($dir === null) {
        return; // Kein Temp-Verzeichnis: Proxy funktioniert weiterhin, nur ohne Limit.
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $file = $dir . DIRECTORY_SEPARATOR . substr(hash('sha256', 'aig|' . $ip), 0, 32) . '.txt';

    $now = time();
    $fh = @fopen($file, 'c+');
    if ($fh === false) {
        return;
    }
    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        return;
    }

    $raw = stream_get_contents($fh) ?: '';
    $stamps = array_values(array_filter(
        array_map('intval', preg_split('/\s+/', trim($raw)) ?: []),
        static fn(int $t): bool => $t > $now - AIG_RATE_WINDOW
    ));

    if (count($stamps) >= AIG_RATE_MAX) {
        flock($fh, LOCK_UN);
        fclose($fh);
        $retry = AIG_RATE_WINDOW - ($now - (int) ($stamps[0] ?? $now));
        header('Retry-After: ' . max(1, $retry));
        aig_fail(429, 'rate_limited', 'Zu viele KI-Anfragen. Bitte kurz warten und erneut versuchen.');
    }

    $stamps[] = $now;
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, implode(' ', $stamps));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);

    // Gelegentlich alte Zaehlerdateien aufraeumen (kein Cronjob noetig).
    if (random_int(1, 50) === 1) {
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.txt') ?: [] as $old) {
            if (@filemtime($old) < $now - (AIG_RATE_WINDOW * 4)) {
                @unlink($old);
            }
        }
    }
}

/**
 * Liest den JSON-Body der Anfrage mit Groessenbegrenzung.
 */
function aig_read_json(int $maxBytes): array
{
    $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
    if ($raw === false) {
        aig_fail(400, 'bad_request', 'Anfrage konnte nicht gelesen werden.');
    }
    if (strlen($raw) > $maxBytes) {
        aig_fail(413, 'payload_too_large', 'Die Anfrage ist zu gross.');
    }
    $data = json_decode($raw, true);
    unset($raw);
    if (!is_array($data)) {
        aig_fail(400, 'bad_request', 'Ungültige Anfrage.');
    }
    return $data;
}
