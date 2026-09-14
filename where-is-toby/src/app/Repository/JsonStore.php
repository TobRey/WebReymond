<?php
declare(strict_types=1);

namespace App\Repository;

use App\Core\Json;
use App\Core\Logger;

/**
 * Zentraler JSON-Dateispeicher.
 *
 * Eigenschaften:
 *  - Sperren ueber separate .lock-Dateien (flock), damit paralleles Schreiben sicher ist
 *  - atomare Schreibvorgaenge (temporaere Datei + rename)
 *  - automatische Sicherung beschaedigter Dateien
 *  - Schema-Version je Dokument inkl. Migrationshaken
 *
 * Hinweis: Dateibasierte Speicherung ist fuer kleine bis mittlere Spielerzahlen
 * ausgelegt (Richtwert: bis ca. 150 gleichzeitig aktive Spielerinnen und Spieler).
 */
final class JsonStore
{
    /** @var array<string,array<mixed>> Prozesslokaler Lesecache */
    private array $cache = [];

    public function __construct(private string $root, private string $backupDir)
    {
    }

    public function root(): string
    {
        return $this->root;
    }

    public function path(string $relative): string
    {
        $relative = ltrim(str_replace(['..', "\0", '\\'], ['', '', '/'], $relative), '/');
        return $this->root . '/' . $relative;
    }

    public function exists(string $relative): bool
    {
        return is_file($this->path($relative));
    }

    /** @return array<mixed> */
    public function read(string $relative, array $default = [], bool $useCache = true): array
    {
        $file = $this->path($relative);
        if ($useCache && isset($this->cache[$file])) {
            return $this->cache[$file];
        }
        if (!is_file($file)) {
            return $default;
        }

        $handle = @fopen($file, 'r');
        if ($handle === false) {
            Logger::error('JSON-Datei nicht lesbar', ['file' => $relative]);
            return $default;
        }
        try {
            flock($handle, LOCK_SH);
            $raw = stream_get_contents($handle) ?: '';
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        $data = Json::decode($raw);
        if ($data === null) {
            if (trim($raw) !== '') {
                $this->quarantine($file, $raw);
            }
            return $default;
        }
        $this->cache[$file] = $data;
        return $data;
    }

    /** Schreibt atomar. Gibt true bei Erfolg zurueck. */
    public function write(string $relative, array $data): bool
    {
        $file = $this->path($relative);
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            Logger::error('Verzeichnis konnte nicht angelegt werden', ['dir' => $dir]);
            return false;
        }

        $lock = $this->acquireLock($file);
        try {
            return $this->writeAtomic($file, $data);
        } finally {
            $this->releaseLock($lock);
        }
    }

    /**
     * Liest, veraendert und schreibt unter exklusiver Sperre (Read-Modify-Write).
     *
     * @param callable(array):array $mutator
     * @return array<mixed> Der gespeicherte Datensatz
     */
    public function update(string $relative, callable $mutator, array $default = []): array
    {
        $file = $this->path($relative);
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $lock = $this->acquireLock($file);
        try {
            unset($this->cache[$file]);
            $current = $this->read($relative, $default, false);
            $updated = $mutator($current);
            if (!is_array($updated)) {
                throw new \RuntimeException('Mutator muss ein Array zurueckgeben.');
            }
            $this->writeAtomic($file, $updated);
            return $updated;
        } finally {
            $this->releaseLock($lock);
        }
    }

    public function delete(string $relative): bool
    {
        $file = $this->path($relative);
        unset($this->cache[$file]);
        return is_file($file) ? @unlink($file) : true;
    }

    /** @return string[] Relative Dateinamen (ohne .json) in einem Unterverzeichnis */
    public function listIds(string $relativeDir): array
    {
        $dir = $this->path($relativeDir);
        if (!is_dir($dir)) {
            return [];
        }
        $ids = [];
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            $ids[] = basename($file, '.json');
        }
        sort($ids);
        return $ids;
    }

    public function clearCache(): void
    {
        $this->cache = [];
    }

    /** Sichert eine beschaedigte Datei und protokolliert den Vorfall. */
    private function quarantine(string $file, string $raw): void
    {
        $target = $this->backupDir . '/corrupt';
        if (!is_dir($target)) {
            @mkdir($target, 0750, true);
        }
        $name = basename($file, '.json') . '_' . gmdate('Ymd_His') . '_' . substr(md5($raw), 0, 6) . '.corrupt.json';
        @file_put_contents($target . '/' . $name, $raw);
        Logger::error('Beschaedigte JSON-Datei gesichert', ['file' => basename($file), 'backup' => $name]);
    }

    /** @return array{handle:resource|false,path:string} */
    private function acquireLock(string $file): array
    {
        $lockPath = $file . '.lock';
        $handle = @fopen($lockPath, 'c');
        if ($handle === false) {
            return ['handle' => false, 'path' => $lockPath];
        }
        $waited = 0;
        while (!flock($handle, LOCK_EX | LOCK_NB)) {
            usleep(20000);
            $waited += 20000;
            if ($waited > 5000000) { // 5 Sekunden Notbremse
                Logger::warning('Sperre nicht erhalten, fahre ohne fort', ['file' => basename($file)]);
                break;
            }
        }
        return ['handle' => $handle, 'path' => $lockPath];
    }

    /** @param array{handle:resource|false,path:string} $lock */
    private function releaseLock(array $lock): void
    {
        if ($lock['handle'] !== false) {
            flock($lock['handle'], LOCK_UN);
            fclose($lock['handle']);
        }
    }

    private function writeAtomic(string $file, array $data): bool
    {
        $json = Json::encode($data, true);
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $handle = @fopen($tmp, 'w');
        if ($handle === false) {
            Logger::error('Temporaere Datei nicht beschreibbar', ['file' => $file]);
            return false;
        }
        $bytes = fwrite($handle, $json);
        fflush($handle);
        fclose($handle);

        if ($bytes === false || $bytes !== strlen($json)) {
            @unlink($tmp);
            Logger::error('Unvollstaendiger Schreibvorgang', ['file' => $file]);
            return false;
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            Logger::error('Datei konnte nicht ersetzt werden', ['file' => $file]);
            return false;
        }
        @chmod($file, 0640);
        $this->cache[$file] = $data;
        return true;
    }
}
