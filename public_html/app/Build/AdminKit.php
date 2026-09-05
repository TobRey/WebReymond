<?php

declare(strict_types=1);

namespace WebAtze\Build;

use WebAtze\Core\{Config, Db, Logger};

/**
 * Liefert den Bearbeitungsbereich beim Kunden mit.
 *
 * Er entsteht nur, wenn im Formular danach gefragt wurde. Wer ihn nicht
 * will, bekommt eine reine HTML-Website ohne eine einzige PHP-Datei
 * ausser dem Kontaktformular – schneller, weniger Angriffsfläche,
 * weniger, das kaputtgehen kann.
 *
 * Mitgeliefert werden:
 *   admin/          die Oberfläche
 *   admin/engine/   die Vorlagen von WebAtze, unverändert
 *   data/site.php   die Website als Daten – Grundlage für den Neubau
 *   data/admin.php  Zugang und Kennwort für den Assistenten
 *
 * Der Schlüssel zum Sprachmodell ist nirgends dabei. Der Assistent
 * fragt bei WebAtze an; nur dort liegt er.
 */
final class AdminKit
{
    /** Diese Vorlagen braucht der Bearbeitungsbereich zum Neubau. */
    private const ENGINE = [
        'Templates/Page.php',
        'Templates/Renderer.php',
        'Templates/Catalog.php',
        'Templates/Schema.php',
        'Templates/Icons.php',
        // Ohne diese beiden rendert der Kunde eine andere Seite als
        // WebAtze - Effekte fielen weg, freie Abschnitte blieben leer.
        // Das Vorlagenwerk muss auf beiden Seiten vollständig sein,
        // sonst ist "dieselbe Seite" eine Behauptung.
        'Templates/Effects.php',
        'Templates/Blocks.php',
    ];

    /**
     * @return int Anzahl geschriebener Dateien
     */
    public static function install(array $project, array $site, string $outputDir): int
    {
        if (empty($project['wants_admin'])) {
            return 0;
        }

        $adminDir = rtrim($outputDir, '/') . '/admin';
        $dataDir = rtrim($outputDir, '/') . '/data';
        $kit = APP_DIR . '/Kit/admin';

        if (!is_dir($kit)) {
            Logger::warning('Der Bearbeitungsbereich fehlt im Bausatz.', ['pfad' => $kit]);
            return 0;
        }

        ensure_dir($adminDir);
        ensure_dir($dataDir);

        $files = Pipeline::copyTree($kit, $adminDir);

        // --- Die Vorlagen ------------------------------------------------
        foreach (self::ENGINE as $relative) {
            $source = APP_DIR . '/' . $relative;
            if (!is_file($source)) {
                continue;
            }

            $target = $adminDir . '/engine/' . $relative;
            ensure_dir(dirname($target));
            write_file_atomic($target, (string) file_get_contents($source));
            $files++;
        }

        // Die Abschnittsvorlagen selbst
        $sections = APP_DIR . '/Templates/sections';
        if (is_dir($sections)) {
            $files += Pipeline::copyTree($sections, $adminDir . '/engine/Templates/sections');
        }

        // --- Die Website als Daten ---------------------------------------
        // Der Bearbeitungsbereich baut daraus dieselben Seiten wie hier.
        $site['domain'] = (string) ($project['domain'] ?? '');
        $site['critical_css'] = SiteBuilder::criticalCss();

        write_file_atomic(
            $dataDir . '/site.php',
            "<?php exit; ?>\n" . json_encode(
                $site,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            )
        );
        $files++;

        // --- Zugang -------------------------------------------------------
        // Nur beim ersten Mal: Ein vom Kunden geändertes Passwort darf ein
        // erneutes Bauen nicht zurücksetzen.
        if (!is_file($dataDir . '/admin.php')) {
            write_file_atomic($dataDir . '/admin.php', self::adminConfig($project));
            $files++;
        }

        // --- Absperrungen -------------------------------------------------
        write_file_atomic($adminDir . '/lib/.htaccess', self::denyAll());
        write_file_atomic($adminDir . '/views/.htaccess', self::denyAll());
        write_file_atomic($adminDir . '/engine/.htaccess', self::denyAll());
        $files += 3;

        return $files;
    }

    /**
     * Die Zugangsdatei.
     *
     * Sie enthält den Argon2id-Hash, nicht das Passwort. Selbst wer die
     * Datei in die Hand bekommt, hat damit noch keinen Zugang.
     */
    private static function adminConfig(array $project): string
    {
        $brief = json_decode((string) ($project['brief'] ?? '{}'), true) ?: [];

        $values = [
            'brand' => (string) $project['name'],
            'admin_username' => (string) ($project['admin_username'] ?? ''),
            'admin_password_hash' => (string) ($project['admin_password_hash'] ?? ''),
            'contact_email' => (string) ($brief['contact_email'] ?? ''),
            'assistant_url' => rtrim((string) Config::get('app_url', ''), '/'),
            'assistant_token' => (string) ($project['assistant_token'] ?? ''),
        ];

        return "<?php\n\n"
            . "// Zugang zum Bearbeitungsbereich.\n"
            . "//\n"
            . "// Das Passwort steht hier nicht – nur seine Prüfsumme. Wer die\n"
            . "// Datei liest, kann sich damit nicht anmelden.\n"
            . "//\n"
            . "// \"assistant_token\" weist diese Website beim Assistenten aus. Er\n"
            . "// gilt nur für sie und lässt sich jederzeit zurückziehen.\n\n"
            . 'return ' . var_export($values, true) . ";\n";
    }

    private static function denyAll(): string
    {
        return "# Diese Dateien werden eingebunden, nie direkt aufgerufen.\n"
            . "Require all denied\n"
            . "<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n";
    }
}
