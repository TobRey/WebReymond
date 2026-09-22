<?php
// YouGBT – gemeinsame Grundlagen: Konstanten, Speicherort, Hilfsfunktionen.
declare(strict_types=1);

if (PHP_VERSION_ID < 80000) {
    http_response_code(500);
    exit('YouGBT benötigt PHP 8.0 oder neuer.');
}

const YG_VERSION = '1.1.0';
const YG_APP_DIR = __DIR__ . '/..';
const YG_GUARD = "<?php http_response_code(404); exit; ?>\n";

// Zeiten sind nur für automatisierte lokale Tests per Umgebungsvariable verkürzbar (nicht übers Web).
define('YG_ANSWER_SECONDS', (int) (getenv('YOUGBT_TEST_ANSWER_S') ?: 60));
const YG_ANSWER_GRACE_MS = 2500;      // Netzwerk-Toleranz für späte Abgaben
define('YG_REVEAL_SECONDS', (int) (getenv('YOUGBT_TEST_REVEAL_S') ?: 45)); // Auflösung, bis automatisch weitergeht
const YG_SPIN_MS = 6500;               // Dauer der Roulette-Animation
const YG_MAX_ANSWER = 1500;
const YG_MAX_CHAT = 200;
const YG_JOB_STALE_MS = 150000;        // hängende KI-Jobs dürfen danach neu gestartet werden
const YG_LEFT_AFTER_MS = 300000;       // 5 Min. ohne jeden Kontakt => gilt als ausgeschieden
const YG_ROOM_TTL_S = 6 * 3600;        // inaktive Räume werden danach gelöscht
const YG_FINAL_TTL_S = 2 * 3600;
const YG_HINT_PENALTY = 10;            // auf 100er-Skala; Spezialrunde: halbiert
const YG_JOKER_THRESHOLD = 75;

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/config.php';

function yg_now_ms(): int
{
    return (int) floor(microtime(true) * 1000);
}

function yg_rand_hex(int $bytes): string
{
    return bin2hex(random_bytes($bytes));
}

function yg_rand_code(int $len = 5): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out;
}

/** Entfernt Steuerzeichen, normalisiert Leerraum, begrenzt Länge (Unicode-sicher). */
function yg_clean_text(string $s, int $max, bool $multiline = false): string
{
    if (!mb_check_encoding($s, 'UTF-8')) {
        $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
    }
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    $s = $multiline
        ? preg_replace('/[\x00-\x09\x0B-\x1F\x7F\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $s)
        : preg_replace('/[\x00-\x1F\x7F\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', ' ', $s);
    $s = (string) $s;
    if ($multiline) {
        $s = preg_replace("/\n{4,}/", "\n\n\n", $s);
    } else {
        $s = preg_replace('/\s+/u', ' ', $s);
    }
    $s = trim((string) $s);
    if (mb_strlen($s) > $max) {
        $s = mb_substr($s, 0, $max);
    }
    return $s;
}

function yg_client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function yg_json_out(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}

final class YgError extends Exception
{
    public string $errCode;
    public int $status;
    public array $extra;

    public function __construct(string $errCode, int $status = 400, array $extra = [])
    {
        parent::__construct($errCode);
        $this->errCode = $errCode;
        $this->status = $status;
        $this->extra = $extra;
    }
}

/** Basis-URL-Pfad der App (z. B. "/games/yougbt/"), aus dem tatsächlich aufgerufenen Skript abgeleitet. */
function yg_base_path(): string
{
    $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php');
    $dir = str_replace('\\', '/', dirname($script));
    $dir = rtrim($dir, '/') . '/';
    // nur sichere Pfadzeichen ausgeben
    return preg_replace('#[^A-Za-z0-9/_.~%\-]#', '', $dir) ?: '/';
}

function yg_security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: SAMEORIGIN');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; font-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'self'; form-action 'self'");
}

/** Ressourcenschonende Bereinigung ohne Cronjob: höchstens alle 10 Minuten, nur bei Anfragen. */
function yg_maybe_cleanup(): void
{
    $marker = yg_data_dir() . '/cleanup.stamp';
    $last = @filemtime($marker) ?: 0;
    if (time() - $last < 600) {
        return;
    }
    // Nur ein Prozess räumt auf
    $fh = @fopen(yg_data_dir() . '/locks/cleanup.lock', 'c');
    if (!$fh || !flock($fh, LOCK_EX | LOCK_NB)) {
        return;
    }
    @touch($marker);
    $now = time();
    foreach (glob(yg_data_dir() . '/rooms/*.php') ?: [] as $file) {
        $age = $now - (int) @filemtime($file);
        if ($age > YG_ROOM_TTL_S) {
            @unlink($file);
            continue;
        }
        if ($age > YG_FINAL_TTL_S) {
            $room = yg_read_guarded($file);
            if (is_array($room) && ($room['phase'] ?? '') === 'final') {
                @unlink($file);
            }
        }
    }
    foreach (glob(yg_data_dir() . '/locks/*.lock') ?: [] as $file) {
        if ($now - (int) @filemtime($file) > YG_ROOM_TTL_S + 3600) {
            @unlink($file);
        }
    }
    foreach (glob(yg_data_dir() . '/rate/*.php') ?: [] as $file) {
        if ($now - (int) @filemtime($file) > 3600) {
            @unlink($file);
        }
    }
    foreach (glob(yg_data_dir() . '/*/.tmp-*') ?: [] as $file) {
        if ($now - (int) @filemtime($file) > 600) {
            @unlink($file);
        }
    }
    flock($fh, LOCK_UN);
    fclose($fh);
}

/** Einfaches, dateibasiertes Rate-Limit pro Schlüssel (z. B. IP + Aktion). */
function yg_rate_limit(string $key, int $max, int $windowSec): void
{
    $file = yg_data_dir() . '/rate/' . hash('sha256', $key) . '.php';
    yg_with_lock('rate-' . substr(hash('sha256', $key), 0, 16), function () use ($file, $max, $windowSec) {
        $now = time();
        $data = yg_read_guarded($file);
        $hits = is_array($data) ? array_values(array_filter($data, fn($t) => is_int($t) && $t > $now - $windowSec)) : [];
        if (count($hits) >= $max) {
            throw new YgError('rate_limited', 429);
        }
        $hits[] = $now;
        yg_write_guarded($file, $hits);
    });
}
