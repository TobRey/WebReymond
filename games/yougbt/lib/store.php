<?php
// YouGBT – sichere dateibasierte Speicherung.
// Jede Datei beginnt mit einer PHP-Sperrzeile: Selbst wenn ein Server .htaccess ignoriert,
// liefert ein direkter Aufruf nur einen leeren 404 statt Inhalt.
declare(strict_types=1);

/**
 * Speicherort: bevorzugt außerhalb des Webroots (wird bei der Ersteinrichtung angelegt und in
 * storage-location.php vermerkt), sonst der gesperrte Ordner data/ innerhalb der App.
 */
function yg_data_dir(bool $reset = false): string
{
    static $dir = null;
    if ($reset) {
        $dir = null;
    }
    if ($dir !== null) {
        return $dir;
    }
    $dir = realpath(YG_APP_DIR . '/data') ?: (YG_APP_DIR . '/data');
    $pointer = YG_APP_DIR . '/storage-location.php';
    if (is_file($pointer)) {
        $p = include $pointer;
        if (is_string($p) && $p !== '' && is_dir($p) && is_writable($p)) {
            $dir = $p;
        }
    }
    foreach (['rooms', 'locks', 'rate'] as $sub) {
        if (!is_dir($dir . '/' . $sub)) {
            @mkdir($dir . '/' . $sub, 0750, true);
        }
    }
    return $dir;
}

function yg_data_is_external(): bool
{
    $inside = realpath(YG_APP_DIR . '/data');
    return $inside === false || yg_data_dir() !== $inside;
}

/** Versucht bei der Einrichtung, einen Speicherordner außerhalb des Webroots anzulegen. */
function yg_try_external_storage(): ?string
{
    $docRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $app = realpath(YG_APP_DIR);
    if (!$docRoot || !$app) {
        return null;
    }
    $parent = dirname($docRoot);
    // Ziel darf weder im Webroot noch in der App liegen
    if ($parent === '' || $parent === $docRoot || str_starts_with($docRoot, $parent . DIRECTORY_SEPARATOR) === false) {
        return null;
    }
    if (!@is_dir($parent) || !@is_writable($parent)) {
        return null;
    }
    $target = $parent . DIRECTORY_SEPARATOR . 'yougbt-data-' . substr(hash('sha256', $app), 0, 12);
    if (!is_dir($target) && !@mkdir($target, 0750, true)) {
        return null;
    }
    $real = realpath($target);
    if (!$real || str_starts_with($real, $docRoot . DIRECTORY_SEPARATOR) || !is_writable($real)) {
        return null;
    }
    // Schreibtest
    $probe = $real . '/probe.php';
    if (@file_put_contents($probe, YG_GUARD . 'ok') === false) {
        return null;
    }
    @unlink($probe);
    $pointer = YG_APP_DIR . '/storage-location.php';
    $code = "<?php\n// Von der YouGBT-Einrichtung erzeugt: Speicherort außerhalb des Webroots.\nreturn " . var_export($real, true) . ";\n";
    if (@file_put_contents($pointer, $code, LOCK_EX) === false) {
        return null;
    }
    return $real;
}

function yg_read_guarded(string $file): mixed
{
    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') {
        return null;
    }
    if (str_starts_with($raw, YG_GUARD)) {
        $raw = substr($raw, strlen(YG_GUARD));
    }
    $data = json_decode($raw, true);
    return $data;
}

/** Atomar schreiben: temporäre Datei im selben Ordner + rename(). */
function yg_write_guarded(string $file, mixed $data): void
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        throw new YgError('storage_error', 500);
    }
    $dir = dirname($file);
    $tmp = $dir . '/.tmp-' . yg_rand_hex(6);
    if (@file_put_contents($tmp, YG_GUARD . $json) === false) {
        throw new YgError('storage_error', 500);
    }
    @chmod($tmp, 0640);
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        throw new YgError('storage_error', 500);
    }
}

/** Führt $fn unter exklusiver Dateisperre aus. */
function yg_with_lock(string $name, callable $fn): mixed
{
    $name = preg_replace('/[^A-Za-z0-9_-]/', '', $name);
    $lockFile = yg_data_dir() . '/locks/' . $name . '.lock';
    $fh = @fopen($lockFile, 'c');
    if (!$fh) {
        throw new YgError('storage_error', 500);
    }
    $start = microtime(true);
    while (!flock($fh, LOCK_EX | LOCK_NB)) {
        if (microtime(true) - $start > 10) {
            fclose($fh);
            throw new YgError('busy', 503);
        }
        usleep(20000);
    }
    try {
        return $fn();
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

function yg_room_file(string $code): string
{
    return yg_data_dir() . '/rooms/' . $code . '.php';
}

function yg_valid_code(string $code): bool
{
    return (bool) preg_match('/^[A-HJ-NP-Z2-9]{5}$/', $code);
}

function yg_load_room(string $code): ?array
{
    if (!yg_valid_code($code)) {
        return null;
    }
    $r = yg_read_guarded(yg_room_file($code));
    return is_array($r) ? $r : null;
}

function yg_save_room(array $room): void
{
    yg_write_guarded(yg_room_file($room['code']), $room);
}

/** Lesen-Ändern-Schreiben eines Raums unter Sperre. $fn bekommt &$room und liefert ein Ergebnis. */
function yg_mutate_room(string $code, callable $fn): mixed
{
    if (!yg_valid_code($code)) {
        throw new YgError('room_not_found', 404);
    }
    return yg_with_lock('room-' . $code, function () use ($code, $fn) {
        $room = yg_load_room($code);
        if (!$room) {
            throw new YgError('room_not_found', 404);
        }
        $before = $room['v'] ?? 0;
        $result = $fn($room);
        if (($room['_dirty'] ?? false) === true) {
            unset($room['_dirty']);
            $room['v'] = $before + 1;
            $room['updated'] = yg_now_ms();
            yg_save_room($room);
        }
        return [$result, $room];
    });
}
