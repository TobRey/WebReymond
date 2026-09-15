<?php
declare(strict_types=1);

namespace App\Service;

use App\Core\Crypto;
use App\Repository\CaseRepository;
use App\Repository\JsonStore;
use App\Repository\SettingsRepository;
use App\Repository\UserRepository;

/**
 * Automatische Ersteinrichtung ohne Installationsassistent.
 *
 * Wird verwendet, wenn das Paket ohne install.php ausgeliefert wurde: Beim ersten
 * Seitenaufruf legt die Anwendung Verzeichnisse, Schluessel, Einstellungen, das
 * Administratorkonto und die mitgelieferten Faelle selbst an.
 *
 * Bewusst ohne Abhaengigkeit von den WIT_*-Pfadkonstanten, weil sie zu diesem
 * Zeitpunkt noch nicht feststehen.
 */
final class AutoSetup
{
    public const DEFAULT_ADMIN_USER = 'tobi';
    public const DEFAULT_ADMIN_PASSWORD = 'Marihuana420!!';

    /**
     * Fuehrt die Einrichtung aus und liefert die Konfiguration zurueck.
     *
     * @return array{installed:bool,storage_path:string,uploads_path:string,app_key:string,base_path:string,debug:bool,auto_setup:bool}|null
     *         null, wenn die Einrichtung nicht moeglich war (fehlende Schreibrechte)
     */
    public static function run(string $root): ?array
    {
        $storagePath = $root . '/storage';
        $uploadsPath = $root . '/uploads';

        // Daten nach Moeglichkeit ausserhalb des Webverzeichnisses ablegen
        $outside = self::outsideStoragePath($root);
        if ($outside !== null) {
            if (is_dir($outside) && is_writable($outside)) {
                $storagePath = $outside;
            } elseif (!is_dir($outside) && is_writable(dirname($outside)) && @mkdir($outside, 0750, true)) {
                $storagePath = $outside;
            }
        }

        if (!self::prepareDirectories($storagePath, $uploadsPath)) {
            return null;
        }
        self::writeGuards($storagePath, $uploadsPath);
        self::ensureHtaccess($root);

        $config = [
            'installed'    => true,
            'installed_at' => gmdate('c'),
            'storage_path' => $storagePath,
            'uploads_path' => $uploadsPath,
            'app_key'      => Crypto::generateKey(),
            'base_path'    => \App\Core\Environment::resolveBasePath(null),
            'debug'        => false,
            'auto_setup'   => true,
        ];

        $store = new JsonStore($storagePath, $storagePath . '/backups');

        /* Einstellungen */
        $settings = SettingsRepository::defaults();
        $settings['admin']['must_change_password'] = true;
        $store->write('settings/settings.json', $settings);

        /* Administratorkonto mit Startzugangsdaten */
        $users = new UserRepository($store);
        if (!$users->adminExists()) {
            $users->create(self::DEFAULT_ADMIN_USER, self::DEFAULT_ADMIN_PASSWORD, '', 'admin', [
                'agent_name'           => 'Special Agent ' . ucfirst(self::DEFAULT_ADMIN_USER),
                'must_change_password' => true,
                'age_confirmed'        => true,
            ]);
        }

        /* Mitgelieferte Faelle einspielen */
        $cases = new CaseRepository($store);
        foreach (glob($root . '/app/Data/cases/*.json') ?: [] as $seed) {
            $data = json_decode((string)file_get_contents($seed), true);
            if (!is_array($data) || !isset($data['id'])) {
                continue;
            }
            if (!$cases->exists((string)$data['id'])) {
                $cases->save($data, false);
            }
        }

        /* Einrichtung protokollieren und sperren */
        @file_put_contents($storagePath . '/settings/install.lock', json_encode([
            'installed_at' => gmdate('c'),
            'version'      => defined('WIT_VERSION') ? WIT_VERSION : '1.0.0',
            'php'          => PHP_VERSION,
            'mode'         => 'auto_setup',
        ], JSON_PRETTY_PRINT));

        /* Konfiguration ablegen: bevorzugt in app/, sonst im Datenverzeichnis unter dem
           Webordner - nur dort findet der Bootstrap sie beim naechsten Aufruf wieder. */
        if (!self::writeConfig($root . '/app/config.local.php', $config)) {
            $fallbackDir = $root . '/storage/settings';
            if (!is_dir($fallbackDir)) {
                @mkdir($fallbackDir, 0750, true);
            }
            if (!self::writeConfig($fallbackDir . '/config.local.php', $config)) {
                return null;
            }
        }

        return $config;
    }

    /**
     * Liefert ein Datenverzeichnis neben dem Webordner - aber nur, wenn es dadurch
     * wirklich ausserhalb des oeffentlich ausgelieferten Bereichs liegt.
     *
     * Liegt die Anwendung in einem Unterordner von public_html, waere das
     * Nachbarverzeichnis weiterhin per URL erreichbar. In dem Fall bleibt es beim
     * mitgelieferten Ordner "storage", der ueber .htaccess gesperrt ist.
     */
    private static function outsideStoragePath(string $root): ?string
    {
        $parent = dirname($root);
        if ($parent === $root || $parent === '' || $parent === '.') {
            return null;
        }

        $documentRoot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
        $documentRoot = $documentRoot !== '' ? (realpath($documentRoot) ?: $documentRoot) : '';
        if ($documentRoot !== '') {
            $real = realpath($parent) ?: $parent;
            $documentRoot = rtrim(str_replace('\\', '/', $documentRoot), '/');
            $real = rtrim(str_replace('\\', '/', $real), '/');
            if ($real === $documentRoot || str_starts_with($real . '/', $documentRoot . '/')) {
                return null;
            }
        }

        return $parent . '/wit_data';
    }

    /** Legt alle benoetigten Verzeichnisse an. */
    private static function prepareDirectories(string $storagePath, string $uploadsPath): bool
    {
        $directories = [
            $storagePath,
            $storagePath . '/users', $storagePath . '/cases', $storagePath . '/cases/_versions',
            $storagePath . '/progress', $storagePath . '/sessions', $storagePath . '/logs',
            $storagePath . '/backups', $storagePath . '/settings', $storagePath . '/cache',
            $storagePath . '/cache/ratelimit', $storagePath . '/cache/audio', $storagePath . '/media',
            $uploadsPath, $uploadsPath . '/media', $uploadsPath . '/thumbs',
        ];
        foreach ($directories as $directory) {
            if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
                return false;
            }
        }
        return is_writable($storagePath) && is_writable($storagePath . '/settings');
    }

    /**
     * Stellt die .htaccess im Programmordner wieder her, falls sie fehlt.
     *
     * Manche Dateimanager und Upload-Werkzeuge uebertragen Dateien mit einem Punkt am
     * Anfang nicht. Ohne diese Datei leitet Apache nichts an den Front-Controller weiter
     * und jede Unterseite endet im Nichts. Die Vorlage liegt deshalb zusaetzlich unter
     * einem gewoehnlichen Namen im Paket.
     *
     * @return bool true, wenn die Datei neu angelegt wurde
     */
    public static function ensureHtaccess(string $root): bool
    {
        $target = $root . '/.htaccess';
        if (is_file($target)) {
            return false;
        }
        $template = $root . '/app/Data/htaccess.dist';
        if (!is_file($template) || !is_writable($root)) {
            return false;
        }
        $contents = @file_get_contents($template);
        if ($contents === false || @file_put_contents($target, $contents) === false) {
            return false;
        }
        @chmod($target, 0644);
        return true;
    }

    /** Schreibt die Zugriffssperren fuer Daten- und Uploadverzeichnis. */
    private static function writeGuards(string $storagePath, string $uploadsPath): void
    {
        $deny = "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n"
            . "php_flag engine off\n";
        $guard = '<?php http_response_code(403); exit("Kein Zugriff.");';

        if (!is_file($storagePath . '/.htaccess')) {
            @file_put_contents($storagePath . '/.htaccess', $deny);
        }
        if (!is_file($storagePath . '/index.php')) {
            @file_put_contents($storagePath . '/index.php', $guard);
        }
        if (!is_file($uploadsPath . '/.htaccess')) {
            @file_put_contents(
                $uploadsPath . '/.htaccess',
                $deny . "RemoveHandler .php .phtml .phar\nAddType text/plain .php .phtml .phar\n"
            );
        }
        if (!is_file($uploadsPath . '/index.php')) {
            @file_put_contents($uploadsPath . '/index.php', $guard);
        }
    }

    private static function writeConfig(string $file, array $config): bool
    {
        $directory = dirname($file);
        if (!is_dir($directory) || !is_writable($directory)) {
            return false;
        }
        $contents = "<?php\n"
            . "/**\n * Lokale Konfiguration - automatisch beim ersten Aufruf erzeugt.\n"
            . " * Enthaelt Geheimnisse und darf nicht oeffentlich erreichbar sein.\n */\n"
            . 'return ' . var_export($config, true) . ";\n";
        if (@file_put_contents($file, $contents) === false) {
            return false;
        }
        @chmod($file, 0640);
        return true;
    }

    /** Meldung, wenn die automatische Einrichtung an Schreibrechten scheitert. */
    public static function failureMessage(string $root): string
    {
        return 'Die automatische Einrichtung konnte keine Dateien anlegen. '
            . 'Bitte im Dateimanager fuer die Ordner "storage", "uploads" und "app" '
            . 'die Rechte 755 setzen (Dateien 644) und die Seite neu laden. '
            . 'Zielverzeichnis: ' . basename($root) . '/storage';
    }
}
