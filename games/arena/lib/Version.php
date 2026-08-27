<?php
declare(strict_types=1);

/**
 * Cache-Verwaltung ohne .htaccess.
 *
 * Das Spiel besteht aus rund dreissig ES-Modulen, die sich gegenseitig mit
 * relativen Pfaden laden. Eine Versionsnummer an main.js hilft da nichts:
 * Der Browser holt main.js neu, nimmt die importierten Dateien aber weiter
 * aus dem Cache. Ein Upload wirkt dann teilweise oder gar nicht - genau das
 * Bild, wenn im Admin geaenderte Werte im Spiel nicht ankommen.
 *
 * Deshalb bekommt jedes Modul über eine Import Map seine eigene
 * Versionsnummer aus dem Aenderungsdatum der Datei.
 */
final class Version
{
    /** Neuestes Aenderungsdatum aller Skripte und Stile. */
    public static function stamp(string $root): string
    {
        $newest = 0;
        foreach (self::files($root) as $file) {
            $time = (int) @filemtime($file);
            if ($time > $newest) {
                $newest = $time;
            }
        }
        return (string) ($newest ?: time());
    }

    /**
     * Import Map: bildet jeden Modulpfad auf denselben Pfad mit
     * Versionsnummer ab. Browser ohne Import-Map-Unterstützung laden die
     * Dateien wie bisher - dann greift wenigstens die Kopfzeile unten.
     *
     * @return array<string, string>
     */
    public static function importMap(string $root, string $base = 'assets/js/'): array
    {
        $map = [];
        $dir = $root . '/' . rtrim($base, '/');
        if (!is_dir($dir)) {
            return $map;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'js') {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $map['./' . $relative] = './' . $relative . '?v=' . (int) $file->getMTime();
        }
        ksort($map);
        return $map;
    }

    /** Verhindert, dass der Browser die Seite mit alten Inhalten wiederverwendet. */
    public static function noStore(): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }

    /** @return list<string> */
    private static function files(string $root): array
    {
        $out = [];
        foreach (['assets/js', 'assets/css'] as $sub) {
            $dir = $root . '/' . $sub;
            if (!is_dir($dir)) {
                continue;
            }
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->isFile()) {
                    $out[] = $file->getPathname();
                }
            }
        }
        return $out;
    }
}
