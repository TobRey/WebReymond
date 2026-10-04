<?php
declare(strict_types=1);

/*
 * Dateibasierter Speicher für Räume.
 *
 * - Jeder Raum ist eine eigene Datei. Sie beginnt mit einer PHP-Sperre,
 *   damit sie selbst ohne .htaccess (z. B. auf nginx) nie ausgeliefert wird.
 * - Schreibzugriffe laufen unter einer exklusiven Dateisperre (flock) und
 *   werden atomar über eine Temp-Datei + rename ersetzt.
 */

if (!defined('GH_APP')) {
    http_response_code(404);
    exit;
}

const GH_FILE_GUARD = "<?php http_response_code(404); exit; ?>\n";
const GH_CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
const GH_CODE_LENGTH = 5;

function gh_valid_code(string $code): bool
{
    return (bool) preg_match('/^[' . GH_CODE_ALPHABET . ']{' . GH_CODE_LENGTH . '}$/', $code);
}

function gh_normalize_code(string $code): string
{
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
}

function gh_room_path(string $code): string
{
    return gh_data_dir() . '/rooms/' . $code . '.php';
}

function gh_lock_path(string $code): string
{
    return gh_data_dir() . '/rooms/' . $code . '.lock';
}

function gh_decode_room(string $raw): ?array
{
    if (str_starts_with($raw, GH_FILE_GUARD)) {
        $raw = substr($raw, strlen(GH_FILE_GUARD));
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function gh_write_room(array $room): void
{
    $path = gh_room_path($room['code']);
    $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $json = json_encode($room, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false || file_put_contents($tmp, GH_FILE_GUARD . $json) === false) {
        @unlink($tmp);
        throw new RuntimeException('Spielstand konnte nicht gespeichert werden.');
    }
    @chmod($tmp, 0640);
    if (!@rename($tmp, $path)) {
        // Manche Windows-/Netzlaufwerke können nicht über bestehende Dateien umbenennen.
        @unlink($path);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Spielstand konnte nicht gespeichert werden.');
        }
    }
}

function gh_room_exists(string $code): bool
{
    return gh_valid_code($code) && is_file(gh_room_path($code));
}

/**
 * Raum exklusiv sperren, lesen, verändern und speichern.
 * $fn bekommt den Raum per Referenz. Gibt $fn false zurück, wird nicht gespeichert.
 * Rückgabe: [Ergebnis von $fn, Raum] oder null, wenn der Raum fehlt.
 */
function gh_with_room(string $code, callable $fn): ?array
{
    if (!gh_valid_code($code)) {
        return null;
    }
    $lock = fopen(gh_lock_path($code), 'c');
    if ($lock === false) {
        throw new RuntimeException('Sperrdatei konnte nicht geöffnet werden.');
    }
    try {
        flock($lock, LOCK_EX);
        $path = gh_room_path($code);
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        $room = $raw === false ? null : gh_decode_room($raw);
        if ($room === null) {
            return null;
        }
        $before = $room;
        $result = $fn($room);
        if ($result !== false && $room !== $before) {
            $room['updated'] = time();
            $room['version'] = ($room['version'] ?? 0) + 1;
            gh_write_room($room);
        }
        return [$result, $room];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function gh_create_room_file(array $room): bool
{
    $lock = fopen(gh_lock_path($room['code']), 'c');
    if ($lock === false) {
        return false;
    }
    try {
        flock($lock, LOCK_EX);
        if (is_file(gh_room_path($room['code']))) {
            return false;
        }
        gh_write_room($room);
        return true;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function gh_random_code(): string
{
    $code = '';
    $max = strlen(GH_CODE_ALPHABET) - 1;
    for ($i = 0; $i < GH_CODE_LENGTH; $i++) {
        $code .= GH_CODE_ALPHABET[random_int(0, $max)];
    }
    return $code;
}

/** Alte Räume gelegentlich aufräumen. */
function gh_cleanup_rooms(): void
{
    $hours = max(1, (int) gh_config()['room_lifetime_hours']);
    $limit = time() - $hours * 3600;
    $dir = gh_data_dir() . '/rooms';
    foreach (glob($dir . '/*.php') ?: [] as $file) {
        if (@filemtime($file) < $limit) {
            @unlink($file);
            @unlink(substr($file, 0, -4) . '.lock');
        }
    }
    foreach (glob($dir . '/*.tmp') ?: [] as $file) {
        if (@filemtime($file) < time() - 600) {
            @unlink($file);
        }
    }
}

/** Einfache Begrenzung, wie viele Räume eine IP pro Stunde erstellen darf. */
function gh_rate_limit_ok(): bool
{
    $max = (int) gh_config()['max_rooms_per_hour'];
    if ($max <= 0) {
        return true;
    }
    $file = gh_data_dir() . '/ratelimit.php';
    $fh = fopen($file, 'c+');
    if ($fh === false) {
        return true;
    }
    try {
        flock($fh, LOCK_EX);
        $raw = stream_get_contents($fh);
        $data = $raw ? (gh_decode_room($raw) ?? []) : [];
        $now = time();
        $ipKey = hash('sha256', gh_client_ip() . '|gh');
        foreach ($data as $k => $stamps) {
            $data[$k] = array_values(array_filter((array) $stamps, fn($t) => $t > $now - 3600));
            if (!$data[$k]) {
                unset($data[$k]);
            }
        }
        $count = count($data[$ipKey] ?? []);
        if ($count >= $max) {
            return false;
        }
        $data[$ipKey][] = $now;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, GH_FILE_GUARD . json_encode($data));
        return true;
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}
