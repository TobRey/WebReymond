<?php

declare(strict_types=1);

namespace SkyKingdoms\Store;

/**
 * Dateibasierter Dokumentspeicher – der vollständige Ersatz für eine Datenbank.
 *
 * Eigenschaften, auf die es ankommt:
 *  - Schreiben ist atomar (temporäre Datei + rename). Ein abgebrochener
 *    Schreibvorgang kann keinen halben Spielstand hinterlassen.
 *  - Lese-Ändere-Schreibe läuft unter exklusiver Sperre (siehe update()).
 *  - Jeder Schlüssel wird streng geprüft: kein Path Traversal, keine
 *    Sonderzeichen, keine Ausbrüche aus dem Datenordner.
 *  - Von jeder Datei existiert die vorherige Fassung als .bak. Ist eine Datei
 *    beschädigt, wird automatisch die letzte gute Fassung verwendet.
 */
final class Store
{
    private string $root;

    /** @var array<string,array<mixed>|null> Lesecache innerhalb einer Anfrage */
    private array $cache = [];

    private int $lockTimeoutMs = 10000;

    public function __construct(string $root)
    {
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
    }

    public function root(): string
    {
        return $this->root;
    }

    public function setLockTimeout(int $ms): void
    {
        $this->lockTimeoutMs = max(200, $ms);
    }

    // =================================================================
    // Pfade
    // =================================================================

    /**
     * Schlüssel in einen absoluten Pfad übersetzen und dabei absichern.
     * Erlaubt sind nur a-z, A-Z, 0-9, Punkt, Bindestrich, Unterstrich und "/".
     *
     * Jede Datendatei endet auf .php und beginnt mit einem exit-Wächter. Ruft
     * jemand die Datei direkt im Browser auf, führt der Server sie als PHP aus –
     * und sie gibt nichts aus. Dieser Schutz wirkt auch dann, wenn .htaccess
     * nicht beachtet wird (etwa bei nginx oder AllowOverride None).
     */
    public function path(string $key): string
    {
        return $this->safeBase($key) . '.php';
    }

    /** Pfad ohne die schützende .php-Endung (nur intern verwendet). */
    private function safeBase(string $key): string
    {
        $key = trim($key);
        if ($key === '' || strlen($key) > 300) {
            throw new StoreException('Ungültiger Datenschlüssel.');
        }
        if (!preg_match('#^[A-Za-z0-9][A-Za-z0-9._/\-]*$#', $key)) {
            throw new StoreException('Unerlaubte Zeichen im Datenschlüssel.');
        }
        if (str_contains($key, '..') || str_contains($key, '//')) {
            throw new StoreException('Unerlaubter Datenschlüssel.');
        }
        if (str_ends_with(strtolower($key), '.php')) {
            throw new StoreException('Unerlaubte Dateiendung im Datenschlüssel.');
        }

        return $this->root . '/' . $key;
    }

    // =================================================================
    // Lesen
    // =================================================================

    /** Dokument lesen. Fehlt es, kommt $default zurück. */
    public function read(string $key, ?array $default = null): ?array
    {
        if (array_key_exists($key, $this->cache)) {
            $cached = $this->cache[$key];

            return $cached === null ? $default : $cached;
        }

        $file = $this->path($key);
        $data = $this->readFile($file);

        if ($data === null && is_file($file . '.bak.php')) {
            $data = $this->readFile($file . '.bak.php');
            if ($data !== null) {
                // Beschädigte Hauptdatei: letzte gute Fassung zurückspielen.
                $this->writeFile($file, $data, false);
            }
        }

        $this->cache[$key] = $data;

        return $data ?? $default;
    }

    /** Dokument lesen und bei Fehlen eine Ausnahme werfen. */
    public function readOrFail(string $key): array
    {
        $data = $this->read($key);
        if ($data === null) {
            throw new StoreException('Datensatz nicht gefunden: ' . $key);
        }

        return $data;
    }

    public function exists(string $key): bool
    {
        return is_file($this->path($key));
    }

    // =================================================================
    // Schreiben
    // =================================================================

    /** Dokument vollständig schreiben (atomar). */
    public function write(string $key, array $data, bool $backup = true): void
    {
        $this->writeFile($this->path($key), $data, $backup);
        $this->cache[$key] = $data;
    }

    /**
     * Lese-Ändere-Schreibe unter exklusiver Sperre.
     *
     * $mutator erhält das aktuelle Dokument (oder $default) und gibt das neue
     * zurück. Gibt der Mutator null zurück, wird nichts geschrieben.
     *
     * @param callable(array):?array $mutator
     */
    public function update(string $key, callable $mutator, ?array $default = null): ?array
    {
        $file = $this->path($key);
        $lock = Lock::acquire($file . '.lock.php', $this->lockTimeoutMs);
        if ($lock === null) {
            throw new StoreException('Daten sind gerade in Bearbeitung. Bitte erneut versuchen.');
        }

        try {
            unset($this->cache[$key]);                 // immer frisch von der Platte lesen
            $current = $this->read($key, $default) ?? [];
            $result  = $mutator($current);
            if ($result === null) {
                return null;
            }
            $this->write($key, $result);

            return $result;
        } finally {
            $lock->release();
        }
    }

    /** Sperre über mehrere Schritte halten (z. B. Kauf über zwei Dokumente). */
    public function lock(string $key): ?Lock
    {
        return Lock::acquire($this->path($key) . '.lock.php', $this->lockTimeoutMs);
    }

    /**
     * Dokument nur anlegen, wenn es noch nicht existiert – ohne Sperre und
     * ohne Wettlauf (exklusives Erstellen durch das Dateisystem).
     * Damit werden eindeutige Benutzernamen und E-Mail-Adressen abgesichert.
     */
    public function claim(string $key, array $data): bool
    {
        $file = $this->path($key);
        $this->ensureDir(\dirname($file));

        $handle = @fopen($file, 'xb');
        if ($handle === false) {
            return false;
        }

        $json = self::GUARD . $this->encode($data);
        $ok   = fwrite($handle, $json) === strlen($json);
        fflush($handle);
        fclose($handle);
        @chmod($file, 0640);

        if (!$ok) {
            @unlink($file);

            return false;
        }

        $this->cache[$key] = $data;

        return true;
    }

    public function delete(string $key): bool
    {
        $file = $this->path($key);
        unset($this->cache[$key]);
        @unlink($file . '.bak.php');
        @unlink($file . '.lock.php');

        return !is_file($file) || @unlink($file);
    }

    // =================================================================
    // Verzeichnisse
    // =================================================================

    /** @return string[] Dateinamen (ohne Pfad und ohne Schutz-Endung) */
    public function listFiles(string $dirKey, string $suffix = '.json'): array
    {
        $dir = $this->dirPath($dirKey);
        if (!is_dir($dir)) {
            return [];
        }

        $out = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || !str_ends_with($entry, '.php')) {
                continue;
            }
            $name = substr($entry, 0, -4);
            if (!str_ends_with($name, $suffix)) {
                continue;
            }
            if (str_contains($name, '.bak') || str_contains($name, '.lock') || str_contains($name, '.tmp')) {
                continue;
            }
            $out[] = $name;
        }
        sort($out);

        return $out;
    }

    /** Ordnerpfad (Ordner tragen keine Schutz-Endung). */
    private function dirPath(string $dirKey): string
    {
        return $this->safeBase($dirKey);
    }

    /** @return string[] Unterordner in einem Datenordner */
    public function listDirs(string $dirKey): array
    {
        $dir = $this->dirPath($dirKey);
        if (!is_dir($dir)) {
            return [];
        }

        $out = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (is_dir($dir . '/' . $entry)) {
                $out[] = $entry;
            }
        }
        sort($out);

        return $out;
    }

    public function ensureDirKey(string $dirKey): void
    {
        $this->ensureDir($this->dirPath($dirKey));
    }

    /** Ordner samt Inhalt löschen (nur innerhalb des Datenordners). */
    public function deleteTree(string $dirKey): void
    {
        $dir = $this->dirPath($dirKey);
        if (!is_dir($dir)) {
            return;
        }
        $this->cache = [];

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }

    // =================================================================
    // Protokolle (eine JSON-Zeile je Eintrag)
    // =================================================================

    /** Zeile anhängen – für Audit-Log und Ereignisse. */
    public function append(string $key, array $record): void
    {
        $file = $this->path($key);
        $this->ensureDir(\dirname($file));

        $line   = $this->encode($record) . "\n";
        $isNew  = !is_file($file);
        $handle = @fopen($file, 'ab');
        if ($handle === false) {
            throw new StoreException('Protokoll konnte nicht geschrieben werden.');
        }
        if (flock($handle, LOCK_EX)) {
            if ($isNew && ftell($handle) === 0) {
                fwrite($handle, self::GUARD);
            }
            fwrite($handle, $line);
            fflush($handle);
            flock($handle, LOCK_UN);
        }
        fclose($handle);
        @chmod($file, 0640);
    }

    /**
     * Die letzten Zeilen eines Protokolls lesen (neueste zuerst).
     * Liest höchstens $maxBytes vom Dateiende – auch riesige Protokolle
     * belasten den Server damit nicht.
     *
     * @return array<int,array<mixed>>
     */
    public function tail(string $key, int $limit = 100, int $offset = 0, int $maxBytes = 262144): array
    {
        $file = $this->path($key);
        if (!is_file($file)) {
            return [];
        }

        $size   = (int) filesize($file);
        $start  = max(0, $size - $maxBytes);
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return [];
        }
        if ($start > 0) {
            fseek($handle, $start);
            fgets($handle); // angeschnittene erste Zeile verwerfen
        }

        $lines = [];
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line !== '' && !str_starts_with($line, '<?php')) {
                $lines[] = $line;
            }
        }
        fclose($handle);

        $lines = array_reverse($lines);
        $slice = array_slice($lines, $offset, $limit);

        $out = [];
        foreach ($slice as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }

        return $out;
    }

    // =================================================================
    // Intern
    // =================================================================

    public function clearCache(?string $key = null): void
    {
        if ($key === null) {
            $this->cache = [];
        } else {
            unset($this->cache[$key]);
        }
    }

    /** Wächterzeile, die jede Datendatei einleitet. */
    public const GUARD = "<?php exit; /* Sky Kingdoms Spieldaten */ ?>\n";

    private function readFile(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }

        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return null;
        }

        $data = json_decode(self::stripGuard($raw), true);

        return is_array($data) ? $data : null;
    }

    /** Wächterzeile entfernen (verträgt auch Dateien ohne Wächter). */
    public static function stripGuard(string $raw): string
    {
        if (!str_starts_with($raw, '<?php')) {
            return $raw;
        }
        $pos = strpos($raw, "\n");

        return $pos === false ? '' : substr($raw, $pos + 1);
    }

    private function writeFile(string $file, array $data, bool $backup): void
    {
        $this->ensureDir(\dirname($file));

        $json = self::GUARD . $this->encode($data);
        $tmp  = $file . '.tmp' . bin2hex(random_bytes(4)) . '.php';

        $handle = @fopen($tmp, 'wb');
        if ($handle === false) {
            throw new StoreException('Datei konnte nicht geschrieben werden: ' . basename($file));
        }

        $written = fwrite($handle, $json);
        $flushed = fflush($handle);
        if (function_exists('fsync')) {
            @fsync($handle);
        }
        fclose($handle);

        if ($written !== strlen($json) || $flushed === false) {
            @unlink($tmp);
            throw new StoreException('Schreibvorgang unvollständig – vermutlich ist kein Speicherplatz frei.');
        }

        // Vorherige Fassung als .bak sichern (ohne Daten zu kopieren).
        if ($backup && is_file($file)) {
            $bak = $file . '.bak.php';
            @unlink($bak);
            if (!@link($file, $bak)) {
                @copy($file, $bak);
            }
        }

        @chmod($tmp, 0640);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new StoreException('Datei konnte nicht ersetzt werden: ' . basename($file));
        }
    }

    private function encode(array $data): string
    {
        // JSON_HEX_TAG wandelt < und > um. Dadurch kann in einer Datendatei niemals
        // ein <?php-Block entstehen, auch nicht durch selbst gewählte Spielernamen.
        $json = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_HEX_TAG
        );
        if ($json === false) {
            throw new StoreException('Daten konnten nicht als JSON gespeichert werden: ' . json_last_error_msg());
        }

        return $json;
    }

    private function ensureDir(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }
        if (!@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new StoreException('Ordner konnte nicht angelegt werden: ' . $dir);
        }
    }
}
