<?php

declare(strict_types=1);

namespace SkyKingdoms\Core;

use SkyKingdoms\Store\Store;

/**
 * Anwendungskern: Konfiguration, Datenspeicher, Fehlerbehandlung.
 *
 * Reihenfolge der Konfiguration (später schlägt früher):
 *   1. config/game.php + config/balance.php  (mit der Auslieferung)
 *   2. config/config.php                     (vom Installer geschrieben)
 *   3. storage/data/meta/settings.json       (im Adminbereich änderbar)
 *   4. storage/data/meta/balance.json        (im Adminbereich änderbar)
 */
final class App
{
    public const MODE_HTML = 'html';
    public const MODE_JSON = 'json';

    private static array $config    = [];
    private static array $balance   = [];
    private static array $installed = [];
    private static ?Store $store    = null;
    private static string $mode     = self::MODE_HTML;
    private static bool $booted     = false;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        self::$config  = require SK_ROOT . '/config/game.php';
        self::$balance = require SK_ROOT . '/config/balance.php';

        $configFile = SK_ROOT . '/config/config.php';
        if (is_file($configFile)) {
            $loaded = require $configFile;
            if (is_array($loaded)) {
                self::$installed = $loaded;
                self::$config    = array_replace_recursive(self::$config, $loaded['settings'] ?? []);
                self::$config['app_url']       = $loaded['app_url'] ?? '';
                self::$config['app_key']       = $loaded['app_key'] ?? '';
                self::$config['asset_version'] = (string) ($loaded['installed_at'] ?? SK_VERSION);
            }
        }

        date_default_timezone_set((string) (self::$config['timezone'] ?? 'UTC'));
        mb_internal_encoding('UTF-8');

        self::registerErrorHandling();

        if (self::isInstalled()) {
            self::loadRuntimeSettings();
        }
    }

    // =================================================================
    // Konfiguration
    // =================================================================

    /** Wert per Punktschreibweise lesen: App::config('mail.transport'). */
    public static function config(string $key, mixed $default = null): mixed
    {
        return self::dig(self::$config, $key, $default);
    }

    /** Balancewert per Punktschreibweise lesen; leerer Schlüssel gibt alles zurück. */
    public static function balance(string $key = '', mixed $default = null): mixed
    {
        if ($key === '') {
            return self::$balance;
        }

        return self::dig(self::$balance, $key, $default);
    }

    /** Nur zur Laufzeit setzen (Tests, Adminvorschau) – wird nicht gespeichert. */
    public static function setConfig(string $key, mixed $value): void
    {
        $parts = explode('.', $key);
        $ref    = &self::$config;
        foreach ($parts as $part) {
            if (!isset($ref[$part]) || !is_array($ref[$part])) {
                $ref[$part] = [];
            }
            $ref = &$ref[$part];
        }
        $ref = $value;
    }

    public static function setBalance(array $balance): void
    {
        self::$balance = $balance;
    }

    /** Einstellungen dauerhaft speichern (Adminbereich). */
    public static function saveSettings(array $patch): void
    {
        $store    = self::store();
        $current  = $store->read('meta/settings.json', []) ?? [];
        $merged   = array_replace_recursive($current, $patch);
        $store->write('meta/settings.json', $merged);
        self::$config = array_replace_recursive(self::$config, $merged);
    }

    /** Balancewerte dauerhaft speichern (Adminbereich). */
    public static function saveBalance(array $patch): void
    {
        $store   = self::store();
        $current = $store->read('meta/balance.json', []) ?? [];
        $merged  = array_replace_recursive($current, $patch);
        $store->write('meta/balance.json', $merged);
        self::$balance = array_replace_recursive(self::$balance, $merged);
    }

    // =================================================================
    // Installation & Datenspeicher
    // =================================================================

    public static function isInstalled(): bool
    {
        return !empty(self::$installed['installed']);
    }

    /**
     * Frisch geschriebene Installationskonfiguration sofort übernehmen,
     * ohne die Anfrage neu zu starten (wird vom Installer benutzt).
     */
    public static function applyInstallation(array $config): void
    {
        self::$installed = $config;
        self::$config    = array_replace_recursive(self::$config, $config['settings'] ?? []);
        self::$config['app_url']       = $config['app_url'] ?? '';
        self::$config['asset_version'] = (string) ($config['installed_at'] ?? SK_VERSION);
        self::$store = null;
    }

    public static function installedValue(string $key, mixed $default = null): mixed
    {
        return self::$installed[$key] ?? $default;
    }

    /** Geheimer Schlüssel der Installation (für HMAC von Tokens). */
    public static function key(): string
    {
        $key = (string) (self::$installed['app_key'] ?? '');

        return $key !== '' ? $key : 'sky-kingdoms-nicht-installiert';
    }

    /** Absoluter Pfad des Datenordners. */
    public static function dataDir(): string
    {
        $configured = (string) (self::$installed['data_dir'] ?? 'storage/data');
        $isAbsolute = str_starts_with($configured, '/')
            || (bool) preg_match('#^[A-Za-z]:[\\\\/]#', $configured);

        return $isAbsolute ? rtrim($configured, '/') : SK_ROOT . '/' . trim($configured, '/');
    }

    public static function store(): Store
    {
        if (self::$store === null) {
            self::$store = new Store(self::dataDir());
        }

        return self::$store;
    }

    /** Für Tests: eigenen Speicher einhängen. */
    public static function setStore(?Store $store): void
    {
        self::$store = $store;
    }

    private static function loadRuntimeSettings(): void
    {
        try {
            $store = self::store();
            $settings = $store->read('meta/settings.json');
            if (is_array($settings)) {
                self::$config = array_replace_recursive(self::$config, $settings);
            }
            $balance = $store->read('meta/balance.json');
            if (is_array($balance)) {
                self::$balance = array_replace_recursive(self::$balance, $balance);
            }
        } catch (\Throwable $e) {
            Logger::error('Laufzeiteinstellungen konnten nicht geladen werden', ['fehler' => $e->getMessage()]);
        }
    }

    // =================================================================
    // Betriebsart & Fehlerbehandlung
    // =================================================================

    public static function setMode(string $mode): void
    {
        self::$mode = $mode === self::MODE_JSON ? self::MODE_JSON : self::MODE_HTML;
    }

    public static function mode(): string
    {
        return self::$mode;
    }

    public static function isDebug(): bool
    {
        return (bool) self::config('debug', false);
    }

    private static function registerErrorHandling(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');

        set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
            if (!(error_reporting() & $severity)) {
                return true; // mit @ unterdrückt
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler(static function (\Throwable $e): void {
            self::handleThrowable($e);
        });

        register_shutdown_function(static function (): void {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                Logger::error('Schwerer Fehler', $error);
            }
        });
    }

    public static function handleThrowable(\Throwable $e): void
    {
        Logger::error($e->getMessage(), [
            'typ'   => $e::class,
            'datei' => basename($e->getFile()),
            'zeile' => $e->getLine(),
        ]);

        if (!headers_sent()) {
            http_response_code(500);
        }

        $detail = self::isDebug()
            ? $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')'
            : 'Unerwarteter Fehler. Der Vorfall wurde protokolliert.';

        if (self::$mode === self::MODE_JSON) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode(['ok' => false, 'error' => $detail], JSON_UNESCAPED_UNICODE);

            return;
        }

        echo '<!doctype html><html lang="de"><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Fehler</title>'
            . '<style>body{font-family:system-ui,sans-serif;background:#0f1724;color:#e8eefc;'
            . 'display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;padding:24px}'
            . 'div{max-width:32rem;background:#182338;border-radius:18px;padding:28px;box-shadow:0 20px 60px #0006}'
            . 'h1{margin:0 0 12px;font-size:1.25rem}p{margin:0;line-height:1.6;color:#aebbd4}</style>'
            . '<div><h1>Da ist etwas schiefgelaufen</h1><p>' . e($detail) . '</p></div>';
    }

    private static function dig(array $source, string $key, mixed $default): mixed
    {
        if (isset($source[$key])) {
            return $source[$key];
        }

        $value = $source;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }

        return $value;
    }
}
