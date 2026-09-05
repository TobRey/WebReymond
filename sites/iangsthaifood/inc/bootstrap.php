<?php
/**
 * Gemeinsame Grundlagen für alle PHP-Seiten dieser Website.
 * Wird eingebunden, nie direkt aufgerufen.
 */

declare(strict_types=1);

if (!defined('IANG_WURZEL')) {
    define('IANG_WURZEL', dirname(__DIR__));
}

/** Konfiguration aus data/config.php lesen (einmal pro Aufruf). */
function cfg(string $schluessel, string $standard = ''): string
{
    static $conf = null;
    if ($conf === null) {
        $datei = IANG_WURZEL . '/data/config.php';
        $conf = is_readable($datei) ? (array) require $datei : [];
    }
    return isset($conf[$schluessel]) ? (string) $conf[$schluessel] : $standard;
}

/** Ausgabe escapen. Jede Zeichenkette, die im HTML landet, geht hier durch. */
function h(?string $text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Läuft die Seite über HTTPS? */
function ist_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (($_SERVER['SERVER_PORT'] ?? '') === '443') {
        return true;
    }
    return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

/** Cookie mit den immer gleichen Schutzangaben setzen. */
function cookie_setzen(string $name, string $wert, int $tage): void
{
    if (headers_sent()) {
        return;
    }
    setcookie($name, $wert, [
        'expires'  => $tage > 0 ? time() + $tage * 86400 : time() - 3600,
        'path'     => '/',
        'secure'   => ist_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** Ordner unterhalb von data/ anlegen, falls er fehlt. */
function datenordner(string $unterordner): string
{
    $pfad = IANG_WURZEL . '/data/' . trim($unterordner, '/');
    if (!is_dir($pfad)) {
        @mkdir($pfad, 0770, true);
    }
    return $pfad;
}

/**
 * Kennung des Absenders für die Zählung der Versuche.
 * Gespeichert wird nur ein Streuwert, nie die IP-Adresse selbst.
 */
function absender_kennung(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unbekannt');
    return substr(hash_hmac('sha256', $ip, cfg('support_token')), 0, 32);
}

/**
 * Versuche begrenzen. Gibt true zurück, wenn der Versuch erlaubt ist.
 * $merken = false zählt nur nach, ohne den Versuch zu buchen.
 */
function versuche_erlaubt(string $topf, string $kennung, int $max, int $fenster, bool $merken = true, string $unterordner = 'support'): bool
{
    $ordner = datenordner($unterordner);
    $datei = $ordner . '/' . preg_replace('/[^a-z0-9_-]/i', '', $topf) . '-'
        . preg_replace('/[^a-f0-9]/', '', $kennung) . '.json';

    $jetzt = time();
    $zeiten = [];
    if (is_readable($datei)) {
        $roh = json_decode((string) file_get_contents($datei), true);
        if (is_array($roh)) {
            foreach ($roh as $t) {
                if (is_int($t) && $t > $jetzt - $fenster) {
                    $zeiten[] = $t;
                }
            }
        }
    }

    if (count($zeiten) >= $max) {
        return false;
    }

    if ($merken) {
        $zeiten[] = $jetzt;
        @file_put_contents($datei, json_encode($zeiten), LOCK_EX);
        @chmod($datei, 0660);
    }

    return true;
}

/** Signierter Zeitstempel für die Zeitfalle im Formular. */
function zeitmarke(): string
{
    $t = (string) time();
    return $t . '.' . hash_hmac('sha256', $t, cfg('support_token'));
}

/** Zeitmarke prüfen: echt, nicht zu alt und nicht zu schnell abgeschickt. */
function zeitmarke_gueltig(string $wert, int $mindestens = 3, int $hoechstens = 86400): bool
{
    $teile = explode('.', $wert, 2);
    if (count($teile) !== 2 || !ctype_digit($teile[0])) {
        return false;
    }
    $soll = hash_hmac('sha256', $teile[0], cfg('support_token'));
    if (!hash_equals($soll, $teile[1])) {
        return false;
    }
    $alter = time() - (int) $teile[0];
    return $alter >= $mindestens && $alter <= $hoechstens;
}

/** Steuerzeichen entfernen, Länge begrenzen. */
function saubere_eingabe(?string $wert, int $maxLaenge = 200): string
{
    $wert = (string) $wert;
    $wert = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $wert) ?? '';
    $wert = trim($wert);
    if (function_exists('mb_substr')) {
        return mb_substr($wert, 0, $maxLaenge, 'UTF-8');
    }
    return substr($wert, 0, $maxLaenge);
}

/** Eine Zeile für einen E-Mail-Kopf: Zeilenumbrüche raus, sonst schleust jemand Empfänger ein. */
function kopfzeile_sauber(string $wert): string
{
    return trim(str_replace(["\r", "\n", "\0"], ' ', $wert));
}

/** Länge in Zeichen, auch ohne mbstring brauchbar. */
function zeichen(string $wert): int
{
    return function_exists('mb_strlen') ? mb_strlen($wert, 'UTF-8') : strlen($wert);
}

/**
 * E-Mail verschicken. Kopfzeilen sind bereinigt, Reply-To nur bei
 * einer Adresse, die die Prüfung besteht.
 */
function mail_senden(string $an, string $betreff, string $text, string $antwortAn = ''): bool
{
    $an = kopfzeile_sauber($an);
    if ($an === '' || !filter_var($an, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $von = kopfzeile_sauber(cfg('mail_from', 'website@iangsthaifood.com'));
    if (!filter_var($von, FILTER_VALIDATE_EMAIL)) {
        $von = 'website@' . preg_replace('/[^a-z0-9.-]/i', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    }

    $kopf = [
        'From: Iang\'s Thai Food <' . $von . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];

    $antwortAn = kopfzeile_sauber($antwortAn);
    if ($antwortAn !== '' && filter_var($antwortAn, FILTER_VALIDATE_EMAIL)) {
        $kopf[] = 'Reply-To: ' . $antwortAn;
    }

    $betreff = kopfzeile_sauber($betreff);
    $text = str_replace("\r\n", "\n", $text);

    return @mail($an, $betreff, $text, implode("\r\n", $kopf), '-f' . $von);
}

/** JSON-Anfrage an WebAtze. Gibt [ok, daten, fehlertext] zurück. */
function webatze_anfrage(string $pfad, array $daten): array
{
    if (!function_exists('curl_init')) {
        return [false, [], 'cURL steht auf diesem Server nicht zur Verfügung.'];
    }

    $url = rtrim(cfg('assistant_url', 'https://web-atze.com'), '/') . $pfad;
    $rumpf = json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $rumpf,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-WebAtze-Token: ' . cfg('support_token'),
            'Content-Length: ' . strlen((string) $rumpf),
        ],
    ]);

    $antwort = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $fehler = curl_error($ch);
    curl_close($ch);

    if ($antwort === false) {
        return [false, [], $fehler !== '' ? $fehler : 'Keine Verbindung.'];
    }
    if ($status < 200 || $status >= 300) {
        return [false, [], 'Der Server hat mit ' . $status . ' geantwortet.'];
    }

    $json = json_decode((string) $antwort, true);
    if (!is_array($json)) {
        return [false, [], 'Unlesbare Antwort.'];
    }

    return [!empty($json['ok']), $json, (string) ($json['error'] ?? '')];
}
