<?php

declare(strict_types=1);

namespace WebAtze\Build;

use RuntimeException;
use WebAtze\Core\{Audit, Db, Logger};
use ZipArchive;

/**
 * Packt eine fertige Website in ein ZIP.
 *
 * Das Paket wird immer erstellt – auch wenn die Website zusätzlich per
 * FTP hochgeladen wird. Es ist der Beleg, dass die Website dem Kunden
 * gehört und ohne uns weiterlebt.
 *
 * Jede Version bleibt erhalten. Wer eine Änderung rückgängig machen will,
 * lädt einfach das vorherige Paket herunter.
 */
final class ZipExporter
{
    /**
     * @return array{path:string, bytes:int, files:int, version:int}
     */
    public static function create(array $project): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException(
                'Die PHP-Erweiterung "zip" fehlt auf diesem Server. '
                . 'Ohne sie lässt sich kein Paket schnüren.'
            );
        }

        $slug = (string) $project['slug'];
        $source = STORAGE_DIR . '/projects/' . $slug . '/dist';

        if (!is_dir($source)) {
            throw new RuntimeException('Es gibt noch keine gebaute Website zum Packen.');
        }

        $version = (int) Db::value(
            'SELECT COALESCE(MAX(version), 0) + 1 FROM builds WHERE project_id = :p',
            ['p' => (int) $project['id']],
            1
        );

        $dir = ensure_dir(STORAGE_DIR . '/zips/' . $slug);
        $name = sprintf('%s-v%d-%s.zip', $slug, $version, date('Y-m-d'));
        $path = $dir . '/' . $name;

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Die ZIP-Datei konnte nicht angelegt werden.');
        }

        $files = self::addDirectory($zip, $source, '');

        // Eine Anleitung, die auch in einem Jahr noch verständlich ist.
        $zip->addFromString('ANLEITUNG.txt', self::readme($project, $version));
        $files++;

        $zip->close();

        if (!is_file($path)) {
            throw new RuntimeException('Die ZIP-Datei wurde nicht geschrieben.');
        }

        $bytes = (int) filesize($path);

        Db::insert('builds', [
            'project_id' => (int) $project['id'],
            'version' => $version,
            'zip_path' => $slug . '/' . $name,
            'zip_bytes' => $bytes,
            'files_count' => $files,
            'notes' => '',
            'created_at' => Db::now(),
        ]);

        Logger::info('Paket erstellt', ['projekt' => $slug, 'version' => $version, 'bytes' => $bytes]);

        return ['path' => $path, 'bytes' => $bytes, 'files' => $files, 'version' => $version];
    }

    /**
     * Verzeichnis rekursiv hinzufügen.
     *
     * Symlinks werden übersprungen: Ein Verweis, der aus dem Ordner
     * hinauszeigt, würde beim Packen fremde Dateien mitnehmen.
     */
    private static function addDirectory(ZipArchive $zip, string $directory, string $prefix): int
    {
        $count = 0;

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isLink()) {
                continue;
            }

            $relative = substr($item->getPathname(), strlen($directory) + 1);
            $relative = str_replace('\\', '/', $relative);

            // Zweite Sicherung gegen Ausbruch aus dem Verzeichnis
            if ($relative === '' || str_contains($relative, '..')) {
                continue;
            }

            $entry = $prefix === '' ? $relative : $prefix . '/' . $relative;

            if ($item->isDir()) {
                $zip->addEmptyDir($entry);
            } elseif ($item->isFile()) {
                $zip->addFile($item->getPathname(), $entry);
                $count++;
            }
        }

        return $count;
    }

    /** Was der Kunde im Paket vorfindet. */
    private static function readme(array $project, int $version): string
    {
        $name = (string) $project['name'];
        $domain = (string) ($project['domain'] ?? '');
        $date = date('d.m.Y');

        $domainLine = $domain !== ''
            ? "Vorgesehene Adresse: {$domain}\n"
            : '';

        return <<<TEXT
        {$name} – Website
        =============================================

        Version {$version}, erstellt am {$date}
        {$domainLine}
        WAS IST DAS HIER?
        -----------------
        Die vollständige Website. Alles, was hier drin liegt, gehört
        zusammen und gehört Ihnen. Es braucht keine Installation, keine
        Datenbank und kein besonderes Hosting.


        WIE KOMMT DIE WEBSITE INS NETZ?
        -------------------------------
        1. Bei Ihrem Hosting-Anbieter anmelden und den Dateimanager öffnen.
        2. In das Verzeichnis der Website wechseln. Es heisst je nach
           Anbieter public_html, httpdocs, www oder web.
        3. Den GESAMTEN Inhalt dieses Pakets dorthin hochladen –
           nicht den Ordner selbst, sondern das, was darin liegt.
        4. Fertig. Die Website ist unter Ihrer Adresse erreichbar.

        Wichtig: Die Datei index.html muss direkt im Verzeichnis liegen,
        nicht in einem Unterordner. Sonst zeigt der Browser eine
        Dateiliste statt der Website.


        WAS LIEGT WO?
        -------------
        index.html          Startseite
        *.html              die weiteren Seiten
        assets/css/         das Aussehen
        assets/js/          kleine Hilfen wie das Menü auf dem Handy
        assets/img/         alle Bilder
        .htaccess           Servereinstellungen (nicht löschen)
        robots.txt          Hinweise für Suchmaschinen
        sitemap.xml         Seitenverzeichnis für Suchmaschinen
        404.html            erscheint bei einer falschen Adresse


        ETWAS ÄNDERN
        ------------
        Texte und Bilder stehen direkt in den HTML-Dateien und lassen sich
        mit jedem Texteditor ändern. Farben und Schriften stehen gesammelt
        am Anfang von assets/css/site.css – eine Änderung dort wirkt auf
        der ganzen Website.

        Wer lieber nicht in Dateien schreibt, meldet sich einfach bei uns.


        BILDER AUSTAUSCHEN
        ------------------
        Neues Bild unter demselben Namen nach assets/img/ legen – fertig.
        Am besten in ähnlicher Grösse, damit das Layout stimmig bleibt.


        FRAGEN?
        -------
        Melden Sie sich. Wir helfen gerne weiter.
        TEXT;
    }

    /** Alle Pakete eines Projekts, neuestes zuerst. */
    public static function listFor(int $projectId): array
    {
        return Db::all(
            'SELECT * FROM builds WHERE project_id = :p ORDER BY version DESC',
            ['p' => $projectId]
        );
    }

    /**
     * Vollständiger Pfad zu einem Paket.
     * Gibt null zurück, wenn der Eintrag nicht zum Projekt gehört oder
     * die Datei fehlt – so lässt sich über die Adresse nichts erraten.
     */
    public static function pathFor(int $projectId, int $buildId): ?string
    {
        $build = Db::first(
            'SELECT * FROM builds WHERE id = :id AND project_id = :p',
            ['id' => $buildId, 'p' => $projectId]
        );

        if ($build === null) {
            return null;
        }

        $relative = (string) $build['zip_path'];
        if ($relative === '' || str_contains($relative, '..')) {
            return null;
        }

        $path = STORAGE_DIR . '/zips/' . $relative;

        return is_file($path) ? $path : null;
    }
}
