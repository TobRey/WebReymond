<?php

declare(strict_types=1);

namespace WebAtze\Core;

/**
 * Findet die gebauten Frontend-Dateien.
 *
 * Der Frontend-Build (Vite) legt assets/manifest.json an. Darin steht,
 * welche Datei nach dem Bauen wie heisst – die Namen enthalten einen
 * Prüfwert, damit Browser eine geänderte Datei sicher neu laden.
 */
final class Assets
{
    private static ?array $manifest = null;

    public static function manifest(): array
    {
        if (self::$manifest !== null) {
            return self::$manifest;
        }

        $file = BASE_DIR . '/assets/manifest.json';
        if (!is_file($file)) {
            return self::$manifest = self::fallback();
        }

        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            return self::$manifest = self::fallback();
        }

        return self::$manifest = $data;
    }

    /** Adresse einer gebauten Datei, z.B. asset('app.css'). */
    public static function url(string $logicalName): string
    {
        $manifest = self::manifest();
        $file = $manifest[$logicalName] ?? $logicalName;
        return '/assets/' . ltrim((string) $file, '/');
    }

    public static function exists(string $logicalName): bool
    {
        $manifest = self::manifest();
        if (!isset($manifest[$logicalName])) {
            return false;
        }
        return is_file(BASE_DIR . '/assets/' . ltrim((string) $manifest[$logicalName], '/'));
    }

    /**
     * Inhalt einer gebauten Datei direkt einbetten.
     * Für das kritische CSS: es steht dann sofort im HTML und die Seite
     * erscheint ohne zweiten Ladevorgang fertig gestaltet.
     */
    public static function inline(string $logicalName): string
    {
        $manifest = self::manifest();
        $file = BASE_DIR . '/assets/' . ltrim((string) ($manifest[$logicalName] ?? $logicalName), '/');

        if (!is_file($file)) {
            return '';
        }
        return (string) file_get_contents($file);
    }

    /** Ohne Build-Ergebnis: die Quelldateien direkt verwenden (Entwicklung). */
    private static function fallback(): array
    {
        return [
            'app.css' => 'app.css',
            'app.js' => 'app.js',
            'critical.css' => 'critical.css',
            'admin.css' => 'admin.css',
            'admin.js' => 'admin.js',
        ];
    }
}
