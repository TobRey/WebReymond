<?php

declare(strict_types=1);

namespace WebAtzeKit;

/**
 * Die Ablage des Kundenbackends.
 *
 * Alles steht in einer einzigen Datei: site.php. Keine Datenbank, kein
 * Rahmenwerk – eine Website, die man in zehn Jahren noch bearbeiten kann,
 * auch wenn niemand mehr weiss, wie sie entstanden ist.
 *
 * Zwei Vorkehrungen sind nicht verhandelbar:
 *
 *   Geschrieben wird nie direkt. Erst entsteht eine Datei daneben, dann
 *   wird umbenannt – ein Umbenennen ist auf demselben Dateisystem
 *   unteilbar. Bricht der Vorgang mittendrin ab (Stromausfall, volles
 *   Kontingent), steht die alte Fassung noch vollständig da statt einer
 *   halben.
 *
 *   Vor jedem Schreiben wird eine Sicherung angelegt. Wer sich vertippt,
 *   holt sich den letzten Stand zurück.
 */
final class Store
{
    private const GUARD = "<?php exit; ?>\n";
    private const KEEP_BACKUPS = 20;

    private string $file;
    private string $backupDir;
    private ?array $cache = null;

    public function __construct(string $dataDir)
    {
        $this->file = rtrim($dataDir, '/') . '/site.php';
        $this->backupDir = rtrim($dataDir, '/') . '/backups';
    }

    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        return $this->cache = self::readGuarded($this->file);
    }

    public function save(array $site): bool
    {
        $this->backup();

        $ok = self::writeGuarded($this->file, $site);
        if ($ok) {
            $this->cache = $site;
        }

        return $ok;
    }

    /** Alle Seiten. */
    public function pages(): array
    {
        return $this->all()['pages'] ?? [];
    }

    /** Eine Seite über ihren Pfad. */
    public function page(string $path): ?array
    {
        foreach ($this->pages() as $page) {
            if ((string) ($page['path'] ?? '') === $path) {
                return $page;
            }
        }
        return null;
    }

    /** Einen Abschnitt über seine Kennung – samt der Seite, auf der er steht. */
    public function section(int $id): ?array
    {
        foreach ($this->pages() as $pageIndex => $page) {
            foreach ($page['sections'] ?? [] as $index => $section) {
                if ((int) ($section['id'] ?? 0) === $id) {
                    return [
                        'section' => $section,
                        'page' => $page,
                        'pageIndex' => $pageIndex,
                        'index' => $index,
                    ];
                }
            }
        }
        return null;
    }

    /**
     * Einen Abschnitt ändern.
     *
     * Der Rückruf bekommt den Abschnitt und gibt ihn geändert zurück.
     * Alles andere bleibt unangetastet – schon deshalb, weil hier gar
     * nichts anderes erreichbar ist.
     */
    public function updateSection(int $id, callable $change): bool
    {
        $found = $this->section($id);
        if ($found === null) {
            return false;
        }

        $site = $this->all();
        $section = $change($found['section']);

        if (!is_array($section)) {
            return false;
        }

        $site['pages'][$found['pageIndex']]['sections'][$found['index']] = $section;

        return $this->save($site);
    }

    /** Eine Seite ändern. */
    public function updatePage(string $path, callable $change): bool
    {
        $site = $this->all();

        foreach ($site['pages'] ?? [] as $index => $page) {
            if ((string) ($page['path'] ?? '') !== $path) {
                continue;
            }

            $updated = $change($page);
            if (!is_array($updated)) {
                return false;
            }

            $site['pages'][$index] = $updated;
            return $this->save($site);
        }

        return false;
    }

    // ------------------------------------------------------------------
    // Sicherungen
    // ------------------------------------------------------------------

    private function backup(): void
    {
        if (!is_file($this->file)) {
            return;
        }

        if (!is_dir($this->backupDir)) {
            @mkdir($this->backupDir, 0750, true);
            @file_put_contents($this->backupDir . '/.htaccess', "Require all denied\n");
        }

        $target = $this->backupDir . '/site-' . date('Ymd-His') . '.php';
        @copy($this->file, $target);

        // Nur die letzten paar behalten – sonst füllt sich das Kontingent.
        $files = glob($this->backupDir . '/site-*.php') ?: [];
        if (count($files) > self::KEEP_BACKUPS) {
            sort($files);
            foreach (array_slice($files, 0, count($files) - self::KEEP_BACKUPS) as $old) {
                @unlink($old);
            }
        }
    }

    /** Vorhandene Sicherungen, neueste zuerst. */
    public function backups(): array
    {
        $files = glob($this->backupDir . '/site-*.php') ?: [];
        rsort($files);

        $out = [];
        foreach ($files as $file) {
            $out[] = [
                'name' => basename($file),
                'time' => (int) filemtime($file),
                'bytes' => (int) filesize($file),
            ];
        }
        return $out;
    }

    /** Eine Sicherung zurückholen. */
    public function restore(string $name): bool
    {
        // Nur der reine Dateiname, damit über den Namen kein Pfad
        // hinausführt.
        $name = basename($name);
        if (!preg_match('/^site-\d{8}-\d{6}\.php$/', $name)) {
            return false;
        }

        $file = $this->backupDir . '/' . $name;
        if (!is_file($file)) {
            return false;
        }

        $data = self::readGuarded($file);
        if ($data === []) {
            return false;
        }

        return $this->save($data);
    }

    // ------------------------------------------------------------------

    public static function readGuarded(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $raw = (string) file_get_contents($path);
        $end = strpos($raw, '?>');
        if ($end !== false) {
            $raw = substr($raw, $end + 2);
        }

        $data = json_decode(trim($raw), true);
        return is_array($data) ? $data : [];
    }

    public static function writeGuarded(string $path, array $data): bool
    {
        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($json === false) {
            return false;
        }

        return self::writeAtomic($path, self::GUARD . $json);
    }

    /** Erst daneben schreiben, dann umbenennen. */
    public static function writeAtomic(string $path, string $content): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return false;
        }

        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
            return false;
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }

        @chmod($path, 0640);
        return true;
    }
}
