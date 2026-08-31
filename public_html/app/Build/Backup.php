<?php

declare(strict_types=1);

namespace WebAtze\Build;

use Throwable;
use WebAtze\Core\{Config, Db, Logger, Settings};
use ZipArchive;

/**
 * Tägliche Sicherungen – von den Kundenseiten und von WebAtze selbst.
 *
 * Eine Sicherung, die von Hand angestossen werden muss, wird irgendwann
 * vergessen. Diese hier läuft mit der stündlichen Pflege mit: Einmal am
 * Tag wird WebAtze gesichert, und bei jedem Durchgang zusätzlich die
 * Kundenseite, deren Sicherung am längsten zurückliegt. So verteilt
 * sich die Arbeit über den Tag, statt zu einer Uhrzeit alles auf
 * einmal zu verlangen – auf einem gemieteten Hosting ist das der
 * Unterschied zwischen "läuft" und "Zeitlimit".
 *
 * Aufbewahrt werden die letzten 14 Tage, dazu je der Monatserste der
 * letzten sechs Monate. Alles andere wird gelöscht. Zwei Wochen decken
 * den Normalfall ab ("gestern war noch alles gut"), die Monatsstände
 * den seltenen ("das ist schon seit Februar falsch").
 */
final class Backup
{
    /** So viele Tagesstände bleiben liegen. */
    private const KEEP_DAILY = 14;

    /** Dazu je der Erste eines Monats, so viele Monate zurück. */
    private const KEEP_MONTHLY = 6;

    /** Grösser als das wird eine einzelne Sicherung nicht. */
    private const MAX_BYTES = 200 * 1024 * 1024;

    // ------------------------------------------------------------- Anstoss

    /**
     * Der stündliche Anstoss aus der Pflege.
     *
     * @return array{eigen:bool, projekt:string, geloescht:int}
     */
    public static function tick(): array
    {
        $result = ['eigen' => false, 'projekt' => '', 'geloescht' => 0];

        if (!Config::get('backups_enabled', true)) {
            return $result;
        }

        try {
            $result['eigen'] = self::daily();
            $result['projekt'] = self::nextProject();
            $result['geloescht'] = self::prune();
        } catch (Throwable $e) {
            // Eine misslungene Sicherung darf den Worker nicht anhalten.
            Logger::warning('Sicherung fehlgeschlagen: ' . $e->getMessage());
        }

        return $result;
    }

    // -------------------------------------------------------- WebAtze selbst

    /**
     * Einmal am Tag: die eigene Datenbank und die hochgeladenen Dateien.
     *
     * Die gebauten Websites sind nicht dabei – sie entstehen aus der
     * Datenbank neu. Was nur einmal existiert, sind die Daten und die
     * Logos, die der Kunde geschickt hat.
     */
    public static function daily(): bool
    {
        $today = date('Y-m-d');

        if (Settings::get('backup_self_on', '') === $today) {
            return false;
        }

        Settings::put('backup_self_on', $today);

        $dir = STORAGE_DIR . '/backups/webatze';
        ensure_dir($dir);

        $path = $dir . '/webatze-' . $today . '.zip';

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            Logger::warning('Sicherung: Das Archiv liess sich nicht anlegen.', ['pfad' => $path]);
            return false;
        }

        $files = 0;

        $zip->addFromString('datenbank.sql', self::dumpDatabase());
        $files++;

        $zip->addFromString('LIESMICH.txt', self::readme($today));
        $files++;

        foreach (['uploads', 'assets'] as $folder) {
            $source = STORAGE_DIR . '/' . $folder;
            if (is_dir($source)) {
                $files += self::addTree($zip, $source, $folder);
            }
        }

        $zip->close();

        self::record('self', null, $path, $files, 'Datenbank und hochgeladene Dateien');

        return true;
    }

    /**
     * Die Datenbank als SQL.
     *
     * mysqldump gibt es auf einem gemieteten Hosting nicht. Also lesen
     * wir die Tabellen selbst aus und schreiben INSERT-Zeilen. Das ist
     * langsamer, läuft dafür überall und auch auf SQLite – wo die Tests
     * laufen, wird also dasselbe geprüft wie im Betrieb.
     */
    public static function dumpDatabase(): string
    {
        $out = "-- Sicherung der WebAtze-Datenbank\n"
            . '-- ' . date('d.m.Y H:i') . "\n"
            . "--\n"
            . "-- Passwörter stehen hier nur als Prüfwert, die FTP-Zugänge\n"
            . "-- nur verschlüsselt. Der Schlüssel dazu liegt in app/config.php\n"
            . "-- und ist absichtlich nicht Teil dieser Datei: Wer die Sicherung\n"
            . "-- in die Hand bekommt, hat damit noch keinen Zugang.\n\n";

        foreach (self::tables() as $table) {
            $out .= "\n-- ---- " . $table . " ----\n";

            $rows = Db::all('SELECT * FROM ' . Db::quoteIdentifier($table));

            if ($rows === []) {
                $out .= "-- (leer)\n";
                continue;
            }

            foreach ($rows as $row) {
                $columns = [];
                $values = [];

                foreach ($row as $column => $value) {
                    $columns[] = Db::quoteIdentifier((string) $column);
                    $values[] = self::literal($value);
                }

                $out .= 'INSERT INTO ' . Db::quoteIdentifier($table)
                    . ' (' . implode(', ', $columns) . ') VALUES ('
                    . implode(', ', $values) . ");\n";
            }
        }

        return $out;
    }

    /** @return array<int, string> */
    private static function tables(): array
    {
        $names = [];

        if (Db::isSqlite()) {
            $rows = Db::all("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name");
            foreach ($rows as $row) {
                $name = (string) $row['name'];
                if (!str_starts_with($name, 'sqlite_')) {
                    $names[] = $name;
                }
            }

            return $names;
        }

        foreach (Db::all('SHOW TABLES') as $row) {
            $names[] = (string) reset($row);
        }

        sort($names);

        return $names;
    }

    /** Ein Wert so, wie er in einer SQL-Zeile stehen darf. */
    private static function literal(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return "'" . str_replace(
            ["\\", "'", "\r", "\n", "\0"],
            ["\\\\", "''", '\r', '\n', ''],
            (string) $value
        ) . "'";
    }

    // ----------------------------------------------------------- Kundenseiten

    /**
     * Die Kundenseite sichern, deren Sicherung am längsten her ist.
     *
     * @return string Slug der gesicherten Website, sonst ''
     */
    public static function nextProject(): string
    {
        $since = date('Y-m-d H:i:s', time() - 20 * 3600);

        $project = Db::first(
            "SELECT p.* FROM projects p
             WHERE p.status IN ('done', 'published')
               AND NOT EXISTS (
                   SELECT 1 FROM backups b
                   WHERE b.project_id = p.id AND b.created_at > :since
               )
             ORDER BY p.id ASC LIMIT 1",
            ['since' => $since]
        );

        if ($project === null) {
            return '';
        }

        return self::project($project) ? (string) $project['slug'] : '';
    }

    /**
     * Eine Kundenseite sichern.
     *
     * Gesichert wird die gebaute Website und – wenn ein Zugang
     * hinterlegt ist – zusätzlich der Ordner "data" vom Server des
     * Kunden. Der ist der eigentliche Grund für die Übung: Dort liegen
     * die Anfragen aus dem Kontaktformular und alles, was der Kunde in
     * seinem Bearbeitungsbereich geändert hat. Nur das existiert bloss
     * einmal; der Rest lässt sich neu bauen.
     */
    public static function project(array $project): bool
    {
        $slug = (string) $project['slug'];
        $dist = STORAGE_DIR . '/projects/' . $slug . '/dist';

        if (!is_dir($dist)) {
            return false;
        }

        $dir = STORAGE_DIR . '/backups/projects/' . $slug;
        ensure_dir($dir);

        $path = $dir . '/' . $slug . '-' . date('Y-m-d') . '.zip';

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return false;
        }

        $files = self::addTree($zip, $dist, 'website');
        $note = 'Gebaute Website';

        $live = self::fetchRemoteData($project);

        foreach ($live as $relative => $content) {
            $zip->addFromString('live-data/' . $relative, $content);
            $files++;
        }

        if ($live !== []) {
            $note .= ' und ' . count($live) . ' Dateien vom Server des Kunden';
        }

        $zip->close();

        self::record('project', (int) $project['id'], $path, $files, $note);

        return true;
    }

    /**
     * Den Ordner "data" vom Server des Kunden holen.
     *
     * Schlägt es fehl, ist das kein Fehler der Sicherung: Vielleicht
     * ist die Seite noch nicht hochgeladen, vielleicht liegen keine
     * Zugangsdaten vor. Dann bleibt eben die gebaute Website übrig.
     *
     * @return array<string, string>
     */
    private static function fetchRemoteData(array $project): array
    {
        $target = Db::first(
            'SELECT * FROM deploy_targets WHERE project_id = :p ORDER BY id DESC LIMIT 1',
            ['p' => (int) $project['id']]
        );

        if ($target === null) {
            return [];
        }

        try {
            return FtpDeployer::fetchDirectory($target, 'data', 25.0);
        } catch (Throwable $e) {
            Logger::info('Sicherung: der Datenordner liess sich nicht holen.', [
                'projekt' => (string) $project['slug'],
            ]);
            return [];
        }
    }

    // -------------------------------------------------------------- Aufräumen

    /**
     * Alte Sicherungen löschen.
     *
     * Behalten wird nach der Regel oben: die letzten 14 Tage, dazu der
     * Monatserste der letzten sechs Monate. Gelöscht wird sowohl die
     * Datei als auch der Eintrag – eine Liste, die auf nicht mehr
     * vorhandene Dateien zeigt, ist schlimmer als keine Liste.
     */
    public static function prune(): int
    {
        $removed = 0;

        $groups = [];

        foreach (Db::all('SELECT * FROM backups ORDER BY created_at DESC') as $row) {
            $key = (string) $row['scope'] . ':' . (string) ($row['project_id'] ?? '0');
            $groups[$key][] = $row;
        }

        foreach ($groups as $rows) {
            $daily = 0;
            $monthly = 0;

            foreach ($rows as $row) {
                $day = substr((string) $row['created_at'], 0, 10);
                $isFirst = str_ends_with($day, '-01');

                $keep = false;

                if ($daily < self::KEEP_DAILY) {
                    $daily++;
                    $keep = true;
                } elseif ($isFirst && $monthly < self::KEEP_MONTHLY) {
                    $monthly++;
                    $keep = true;
                }

                if ($keep) {
                    continue;
                }

                $path = (string) $row['path'];

                if ($path !== '' && is_file($path) && self::insideBackups($path)) {
                    @unlink($path);
                }

                Db::delete('backups', 'id = :id', ['id' => (int) $row['id']]);
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Eine Sicherung, die schon läuft, darf nichts ausserhalb ihres
     * eigenen Ordners löschen. Der Pfad kommt zwar aus unserer eigenen
     * Datenbank – aber "kommt von uns" ist keine Prüfung.
     */
    private static function insideBackups(string $path): bool
    {
        $real = realpath($path);
        $root = realpath(STORAGE_DIR . '/backups');

        return $real !== false && $root !== false && str_starts_with($real, $root . DIRECTORY_SEPARATOR);
    }

    // ------------------------------------------------------------- Übersicht

    /** @return array<int, array<string, mixed>> */
    public static function listAll(int $limit = 100): array
    {
        return Db::all(
            'SELECT b.*, p.name AS project_name, p.slug AS project_slug
             FROM backups b LEFT JOIN projects p ON p.id = b.project_id
             ORDER BY b.created_at DESC, b.id DESC LIMIT ' . max(1, min(500, $limit))
        );
    }

    /** Der Pfad einer Sicherung – oder null, wenn sie nicht (mehr) da ist. */
    public static function pathFor(int $id): ?string
    {
        $row = Db::first('SELECT path FROM backups WHERE id = :id', ['id' => $id]);

        if ($row === null) {
            return null;
        }

        $path = (string) $row['path'];

        return is_file($path) && self::insideBackups($path) ? $path : null;
    }

    // -------------------------------------------------------------- Kleinkram

    private static function record(string $scope, ?int $projectId, string $path, int $files, string $note): void
    {
        Db::insert('backups', [
            'scope' => $scope,
            'project_id' => $projectId,
            'path' => $path,
            'bytes' => is_file($path) ? (int) filesize($path) : 0,
            'files' => $files,
            'note' => mb_substr($note, 0, 250),
            'created_at' => Db::now(),
        ]);
    }

    /** Einen Ordner ins Archiv legen. */
    private static function addTree(ZipArchive $zip, string $directory, string $prefix): int
    {
        $files = 0;
        $bytes = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $bytes += (int) $file->getSize();

            if ($bytes > self::MAX_BYTES) {
                $zip->addFromString(
                    $prefix . '/ABGESCHNITTEN.txt',
                    "Diese Sicherung wurde bei " . round(self::MAX_BYTES / 1048576) . " MB abgebrochen.\n"
                    . "Es sind mehr Dateien da, als in ein Archiv passen.\n"
                );
                break;
            }

            $relative = ltrim(str_replace($directory, '', $file->getPathname()), '/\\');
            $zip->addFile($file->getPathname(), $prefix . '/' . str_replace('\\', '/', $relative));
            $files++;
        }

        return $files;
    }

    private static function readme(string $day): string
    {
        return "Sicherung von WebAtze vom " . $day . "\n"
            . str_repeat('=', 40) . "\n\n"
            . "datenbank.sql   Alle Tabellen als INSERT-Zeilen.\n"
            . "uploads/        Was hochgeladen wurde: Logos, Bilder.\n"
            . "assets/         Erzeugte Bilder der Projekte.\n\n"
            . "So spielst du sie zurück:\n\n"
            . "  1. In cPanel eine leere Datenbank anlegen.\n"
            . "  2. install.php aufrufen – das legt die Tabellen an.\n"
            . "  3. datenbank.sql in phpMyAdmin einspielen.\n"
            . "  4. Die Ordner nach storage/ zurückkopieren.\n\n"
            . "Nicht dabei ist app/config.php mit den Zugangsdaten und dem\n"
            . "Schlüssel. Das ist Absicht: Diese Datei gehört nicht in eine\n"
            . "Sicherung, die kopiert und weitergegeben wird. Verwahre sie\n"
            . "getrennt – ohne sie lassen sich die FTP-Zugänge in der\n"
            . "Datenbank nicht entschlüsseln.\n";
    }
}
