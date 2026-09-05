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

        $data = is_file($file)
            ? json_decode((string) file_get_contents($file), true)
            : null;

        if (!is_array($data)) {
            $data = self::fallback();
        }

        // Die Überlagerung der Plugins kommt zuletzt und gewinnt.
        //
        // Sie steht bewusst nicht in assets/manifest.json: Die schreibt
        // Vite bei jedem Build vollständig neu, und das nächste
        // Hauptpaket würde ein installiertes Plugin damit stillschweigend
        // wieder auslöschen. So überleben beide einander in beide
        // Richtungen.
        return self::$manifest = array_merge($data, self::overlay());
    }

    /**
     * Was Plugins zusätzlich mitbringen.
     *
     * @return array<string, string>
     */
    private static function overlay(): array
    {
        $datei = STORAGE_DIR . '/plugins/editor/plugin.json';

        if (!is_file($datei)) {
            return [];
        }

        $daten = json_decode((string) file_get_contents($datei), true);
        $dateien = is_array($daten) ? ($daten['files'] ?? null) : null;

        if (!is_array($dateien)) {
            return [];
        }

        $raus = [];

        foreach ($dateien as $name => $pfad) {
            if (is_string($name) && is_string($pfad) && $pfad !== '') {
                $raus[$name] = $pfad;
            }
        }

        return $raus;
    }

    /**
     * Adresse einer gebauten Datei, z.B. asset('app.css').
     *
     * Gibt es die Datei nicht, kommt eine leere Adresse zurück statt
     * einer, die ins Leere zeigt. Das ist der Unterschied zwischen
     * "der Editor ist nicht installiert" und zwei stillen 404ern, bei
     * denen niemand erfährt, warum nichts passiert – und genau dieser
     * Fall tritt jetzt regelmässig ein, weil der Editor als eigenes
     * Plugin ausgeliefert wird.
     */
    public static function url(string $logicalName): string
    {
        $manifest = self::manifest();
        $file = $manifest[$logicalName] ?? $logicalName;

        return '/assets/' . ltrim((string) $file, '/');
    }

    /**
     * Dasselbe, aber leer, wenn es die Datei nicht gibt.
     *
     * Für alles, was fehlen darf: Ein <script src=""> wird gar nicht
     * erst geschrieben, statt einen Fehler zu holen.
     */
    public static function urlIfExists(string $logicalName): string
    {
        return self::exists($logicalName) ? self::url($logicalName) : '';
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

    /**
     * Den Zwischenspeicher vergessen.
     *
     * Nach dem Installieren eines Plugins steht die neue Datei da, aber
     * dieser Prozess kennt noch den Stand von vorher. Ohne das hier
     * bliebe der Editor bis zum nächsten Aufruf unsichtbar – und der
     * Betreiber würde annehmen, die Installation sei fehlgeschlagen.
     */
    public static function forget(): void
    {
        self::$manifest = null;
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
