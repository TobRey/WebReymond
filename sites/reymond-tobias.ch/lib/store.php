<?php
/**
 * Gemeinsame Grundlage: Pfade, Lesen und Schreiben von JSON-Dateien.
 *
 * Geschrieben wird immer atomar: erst in eine temporaere Datei, dann
 * umbenennen. Dadurch kann keine halb geschriebene Datei entstehen.
 * Vor jeder Aenderung wird eine Sicherungskopie abgelegt.
 */

if (defined('RT_STORE')) { return; }
define('RT_STORE', true);

define('RT_ROOT', dirname(__DIR__));
define('RT_DATA', RT_ROOT . '/data');
define('RT_PRIVATE', RT_DATA . '/private');
define('RT_BACKUPS', RT_DATA . '/backups');
define('RT_CONTENT', RT_ROOT . '/content');
define('RT_MEDIA', RT_CONTENT . '/media');

/**
 * Schutzzeile vor privaten Daten: Wird die Datei trotz Serverregeln direkt
 * aufgerufen, liefert PHP nur einen 404 und gibt den Inhalt nicht preis.
 */
define('RT_GUARD', "<?php http_response_code(404); exit; ?>\n");

/** Verzeichnis anlegen, falls es fehlt. */
function rt_dir($path, $mode = 0700)
{
    if (is_dir($path)) { return true; }
    return @mkdir($path, $mode, true);
}

/** Datei lesen und als Array zurueckgeben. Fehlt sie, kommt $fallback. */
function rt_read($path, $fallback = array())
{
    if (!is_file($path) || !is_readable($path)) { return $fallback; }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') { return $fallback; }
    if (strncmp($raw, '<?php', 5) === 0) {
        $nl = strpos($raw, "\n");
        $raw = ($nl === false) ? '' : substr($raw, $nl + 1);
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $fallback;
}

/**
 * Atomar schreiben.
 *
 * @param string $path   Zieldatei
 * @param array  $data   Inhalt
 * @param bool   $guard  true = private Datei mit Schutzzeile und Rechten 0600
 */
function rt_write($path, $data, $guard = true)
{
    if (!rt_dir(dirname($path), $guard ? 0700 : 0755)) { return false; }

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) { return false; }

    $body = ($guard ? RT_GUARD : '') . $json . "\n";
    $tmp  = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';

    $fh = @fopen($tmp, 'wb');
    if (!$fh) { return false; }
    $written = @fwrite($fh, $body);
    @fflush($fh);
    @fclose($fh);

    if ($written === false || $written !== strlen($body)) {
        @unlink($tmp);
        return false;
    }
    @chmod($tmp, $guard ? 0600 : 0644);

    if (!@rename($tmp, $path)) {
        // Manche Systeme benennen nicht ueber eine bestehende Datei um.
        @unlink($path);
        if (!@rename($tmp, $path)) { @unlink($tmp); return false; }
    }
    if (function_exists('opcache_invalidate')) { @opcache_invalidate($path, true); }
    return true;
}

/**
 * Sicherungskopie einer Datei ablegen, bevor sie ueberschrieben wird.
 *
 * Die Kopie bekommt die Endung .php und beginnt mit der Schutzzeile. Wird sie
 * je direkt aufgerufen, antwortet der Server mit 404 statt mit dem Inhalt –
 * auch dann, wenn die Serverregeln in data/.htaccess nicht greifen.
 */
function rt_backup($path, $label)
{
    if (!is_file($path)) { return null; }
    if (!rt_dir(RT_BACKUPS)) { return null; }

    $label = preg_replace('/[^a-z0-9_-]/i', '', (string) $label);
    if ($label === '') { $label = 'datei'; }

    $raw = @file_get_contents($path);
    if ($raw === false) { return null; }
    if (strncmp($raw, '<?php', 5) !== 0) { $raw = RT_GUARD . $raw; }

    $name = $label . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.php';
    $dest = RT_BACKUPS . '/' . $name;

    if (@file_put_contents($dest, $raw, LOCK_EX) === false) { return null; }
    @chmod($dest, 0600);

    rt_prune_backups($label, 40);
    return $name;
}

/** Nur die juengsten $keep Sicherungen je Bereich behalten. */
function rt_prune_backups($label, $keep = 40)
{
    $files = @glob(RT_BACKUPS . '/' . $label . '-*.php');
    if (!is_array($files) || count($files) <= $keep) { return; }
    usort($files, function ($a, $b) {
        return filemtime($a) <=> filemtime($b);
    });
    $drop = array_slice($files, 0, count($files) - $keep);
    foreach ($drop as $f) { @unlink($f); }
}

/**
 * Lesen, aendern, schreiben unter Sperre – fuer Zaehler und Zaehlwerte,
 * bei denen mehrere Aufrufe gleichzeitig eintreffen koennen.
 *
 * @param callable $mutator  bekommt das Array, gibt das neue Array zurueck
 */
function rt_update_locked($path, callable $mutator)
{
    if (!rt_dir(dirname($path))) { return false; }

    $fh = @fopen($path, 'c+b');
    if (!$fh) { return false; }
    if (!flock($fh, LOCK_EX)) { fclose($fh); return false; }

    $raw = '';
    while (!feof($fh)) {
        $chunk = fread($fh, 65536);
        if ($chunk === false) { break; }
        $raw .= $chunk;
    }
    if (strncmp($raw, '<?php', 5) === 0) {
        $nl = strpos($raw, "\n");
        $raw = ($nl === false) ? '' : substr($raw, $nl + 1);
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) { $data = array(); }

    $data = $mutator($data);
    if (!is_array($data)) { flock($fh, LOCK_UN); fclose($fh); return false; }

    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) { flock($fh, LOCK_UN); fclose($fh); return false; }

    rewind($fh);
    ftruncate($fh, 0);
    fwrite($fh, RT_GUARD . $json . "\n");
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    @chmod($path, 0600);
    return true;
}

/** Text fuer die Ausgabe in HTML entschaerfen. */
function rt_h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Zeichenkette auf eine Hoechstlaenge kuerzen und Steuerzeichen entfernen. */
function rt_clean($value, $max = 500, $allowNewlines = false)
{
    $value = is_string($value) ? $value : '';
    $value = str_replace(array("\r\n", "\r"), "\n", $value);
    if ($allowNewlines) {
        $value = preg_replace('/[^\P{C}\n]+/u', '', $value);
    } else {
        $value = preg_replace('/\p{C}+/u', ' ', $value);
    }
    if ($value === null) { $value = ''; }
    $value = trim($value);
    if (function_exists('mb_substr')) {
        if (mb_strlen($value, 'UTF-8') > $max) { $value = mb_substr($value, 0, $max, 'UTF-8'); }
    } elseif (strlen($value) > $max) {
        $value = substr($value, 0, $max);
    }
    return $value;
}

/** Zeichenlaenge, auch ohne mbstring-Erweiterung. */
function rt_len($value)
{
    $value = (string) $value;
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

/** Kleinschreibung, auch ohne mbstring-Erweiterung. */
function rt_lower($value)
{
    $value = (string) $value;
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

/** Zufaellige, sichere Zeichenkette. */
function rt_token($bytes = 32)
{
    return bin2hex(random_bytes($bytes));
}
