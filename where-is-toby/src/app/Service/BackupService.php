<?php
declare(strict_types=1);

namespace App\Service;

use App\Core\HttpException;
use App\Core\Json;
use App\Core\Logger;
use App\Core\Validator;

/**
 * Sicherung und Wiederherstellung aller Spieldaten.
 * Bevorzugt ZIP (Erweiterung "zip"), sonst ein einzelnes JSON-Archiv.
 */
final class BackupService
{
    public function __construct(private string $storageRoot, private string $uploadRoot)
    {
    }

    private function backupDir(): string
    {
        $dir = $this->storageRoot . '/backups';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        return $dir;
    }

    /** @return array{file:string,size:int,format:string,entries:int} */
    public function create(bool $includeUploads = false, string $label = 'manuell'): array
    {
        $stamp = gmdate('Ymd_His');
        $label = preg_replace('~[^a-zA-Z0-9_\-]~', '', $label) ?: 'backup';
        $entries = 0;

        if (class_exists(\ZipArchive::class)) {
            $name = 'wit_backup_' . $stamp . '_' . $label . '.zip';
            $path = $this->backupDir() . '/' . $name;
            $zip = new \ZipArchive();
            if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Sicherungsdatei konnte nicht angelegt werden.');
            }
            $zip->addFromString('BACKUP_INFO.json', Json::encode([
                'created' => gmdate('c'),
                'version' => WIT_VERSION,
                'schema'  => WIT_SCHEMA_VERSION,
                'uploads' => $includeUploads,
            ], true));
            $entries += $this->addTree($zip, $this->storageRoot, 'storage', ['backups', 'sessions', 'cache']);
            if ($includeUploads) {
                $entries += $this->addTree($zip, $this->uploadRoot, 'uploads', []);
            }
            $zip->close();
            Logger::info('Sicherung erstellt', ['file' => $name, 'entries' => $entries]);
            return ['file' => $name, 'size' => (int)filesize($path), 'format' => 'zip', 'entries' => $entries];
        }

        // Rueckfall: JSON-Archiv (nur Datenverzeichnisse)
        $name = 'wit_backup_' . $stamp . '_' . $label . '.json';
        $path = $this->backupDir() . '/' . $name;
        $payload = ['created' => gmdate('c'), 'version' => WIT_VERSION, 'files' => []];
        foreach ($this->walk($this->storageRoot, ['backups', 'sessions', 'cache']) as $file) {
            if (!str_ends_with($file, '.json')) {
                continue;
            }
            $relative = ltrim(str_replace($this->storageRoot, '', $file), '/');
            $payload['files'][$relative] = (string)file_get_contents($file);
            $entries++;
        }
        file_put_contents($path, Json::encode($payload));
        Logger::info('Sicherung erstellt (JSON)', ['file' => $name, 'entries' => $entries]);
        return ['file' => $name, 'size' => (int)filesize($path), 'format' => 'json', 'entries' => $entries];
    }

    /** @return array<int,array{file:string,size:int,created:string}> */
    public function listBackups(): array
    {
        $out = [];
        foreach (glob($this->backupDir() . '/wit_backup_*') ?: [] as $file) {
            $out[] = [
                'file'    => basename($file),
                'size'    => (int)filesize($file),
                'created' => gmdate('c', (int)filemtime($file)),
            ];
        }
        usort($out, static fn(array $a, array $b): int => strcmp($b['file'], $a['file']));
        return $out;
    }

    public function path(string $file): string
    {
        $file = Validator::filename($file);
        $path = $this->backupDir() . '/' . $file;
        if (!is_file($path)) {
            throw HttpException::notFound('Sicherung nicht gefunden.');
        }
        return $path;
    }

    public function delete(string $file): bool
    {
        return @unlink($this->path($file));
    }

    /** @return array{restored:int,skipped:int,safety:string} */
    public function restore(string $file): array
    {
        $path = $this->path($file);
        $safety = $this->create(false, 'vorRestore');
        $restored = 0;
        $skipped = 0;

        if (str_ends_with($path, '.zip')) {
            if (!class_exists(\ZipArchive::class)) {
                throw HttpException::badRequest('Zum Wiederherstellen wird die PHP-Erweiterung zip benoetigt.');
            }
            $zip = new \ZipArchive();
            if ($zip->open($path) !== true) {
                throw HttpException::badRequest('Die Sicherungsdatei konnte nicht geoeffnet werden.');
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string)$zip->getNameIndex($i);
                if ($name === '' || str_ends_with($name, '/') || str_contains($name, '..')) {
                    $skipped++;
                    continue;
                }
                $target = $this->targetFor($name);
                if ($target === null) {
                    $skipped++;
                    continue;
                }
                $contents = $zip->getFromIndex($i);
                if ($contents === false) {
                    $skipped++;
                    continue;
                }
                $dir = dirname($target);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0750, true);
                }
                if (@file_put_contents($target, $contents) !== false) {
                    $restored++;
                } else {
                    $skipped++;
                }
            }
            $zip->close();
        } else {
            $payload = Json::decode((string)file_get_contents($path)) ?? [];
            foreach ((array)($payload['files'] ?? []) as $relative => $contents) {
                $target = $this->targetFor('storage/' . ltrim((string)$relative, '/'));
                if ($target === null) {
                    $skipped++;
                    continue;
                }
                $dir = dirname($target);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0750, true);
                }
                if (@file_put_contents($target, (string)$contents) !== false) {
                    $restored++;
                } else {
                    $skipped++;
                }
            }
        }

        Logger::warning('Sicherung wiederhergestellt', ['file' => $file, 'restored' => $restored, 'skipped' => $skipped]);
        return ['restored' => $restored, 'skipped' => $skipped, 'safety' => $safety['file']];
    }

    /** Wandelt einen Archivpfad in einen sicheren Zielpfad um. */
    private function targetFor(string $name): ?string
    {
        $name = str_replace('\\', '/', $name);
        if (str_contains($name, '..') || str_starts_with($name, '/')) {
            return null;
        }
        if (str_starts_with($name, 'storage/')) {
            $relative = substr($name, 8);
            if (str_starts_with($relative, 'sessions/') || $relative === '') {
                return null;
            }
            return $this->storageRoot . '/' . $relative;
        }
        if (str_starts_with($name, 'uploads/')) {
            $relative = substr($name, 8);
            if (preg_match('~\.(php|phtml|phar|cgi|pl|py|sh)$~i', $relative)) {
                return null;
            }
            return $this->uploadRoot . '/' . $relative;
        }
        return null;
    }

    private function addTree(\ZipArchive $zip, string $root, string $prefix, array $skipDirs): int
    {
        $count = 0;
        foreach ($this->walk($root, $skipDirs) as $file) {
            $relative = ltrim(str_replace($root, '', $file), '/');
            if ($relative === '' || str_ends_with($relative, '.lock') || str_ends_with($relative, '.tmp')) {
                continue;
            }
            $zip->addFile($file, $prefix . '/' . $relative);
            $count++;
        }
        return $count;
    }

    /** @return \Generator<string> */
    private function walk(string $root, array $skipDirs): \Generator
    {
        if (!is_dir($root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static function (\SplFileInfo $current) use ($root, $skipDirs): bool {
                    if ($current->isDir()) {
                        $relative = trim(str_replace($root, '', $current->getPathname()), '/');
                        foreach ($skipDirs as $skip) {
                            if ($relative === $skip || str_starts_with($relative, $skip . '/')) {
                                return false;
                            }
                        }
                    }
                    return true;
                }
            )
        );
        foreach ($iterator as $item) {
            if ($item instanceof \SplFileInfo && $item->isFile()) {
                yield $item->getPathname();
            }
        }
    }

    /** Alte Sicherungen aufraeumen (aelteste zuerst). */
    public function prune(int $keep = 12): int
    {
        $backups = $this->listBackups();
        $removed = 0;
        foreach (array_slice($backups, $keep) as $backup) {
            if ($this->delete($backup['file'])) {
                $removed++;
            }
        }
        return $removed;
    }
}
