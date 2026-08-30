<?php

declare(strict_types=1);

namespace WebAtze\Core;

use RuntimeException;

/**
 * Zugriff auf die Konfiguration aus app/config.php.
 *
 * Fehlt die Datei, läuft WebAtze im Einrichtungsmodus: dann führt jede
 * Adresse zu install.php, statt mit einem unverständlichen Fehler abzubrechen.
 */
final class Config
{
    private static array $values = [];
    private static bool $installed = false;

    public static function load(string $file): void
    {
        if (!is_file($file)) {
            self::$installed = false;
            self::$values = self::defaults();
            return;
        }

        $loaded = require $file;
        if (!is_array($loaded)) {
            throw new RuntimeException('app/config.php muss ein Array zurückgeben.');
        }

        self::$values = array_replace_recursive(self::defaults(), $loaded);
        self::$installed = true;
    }

    /** Nur für Tests: Werte direkt setzen. */
    public static function setAll(array $values, bool $installed = true): void
    {
        self::$values = array_replace_recursive(self::defaults(), $values);
        self::$installed = $installed;
    }

    public static function isInstalled(): bool
    {
        return self::$installed
            && self::get('app_key') !== ''
            && self::get('db.database') !== '';
    }

    public static function get(string $path, mixed $default = null): mixed
    {
        return array_get(self::$values, $path, $default);
    }

    public static function set(string $path, mixed $value): void
    {
        $segments = explode('.', $path);
        $ref = &self::$values;
        foreach ($segments as $segment) {
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $ref[$segment] = [];
            }
            $ref = &$ref[$segment];
        }
        $ref = $value;
    }

    public static function isProduction(): bool
    {
        return self::get('env', 'production') === 'production';
    }

    /** Basisadresse ohne Schrägstrich am Ende. */
    public static function url(string $path = ''): string
    {
        $base = rtrim((string) self::get('app_url', ''), '/');
        if ($path === '') {
            return $base;
        }
        return $base . '/' . ltrim($path, '/');
    }

    private static function defaults(): array
    {
        return [
            'app_url' => '',
            'env' => 'production',
            'default_locale' => 'de',
            'db' => [
                'driver' => 'mysql',
                'host' => 'localhost',
                'port' => 3306,
                'database' => '',
                'username' => '',
                'password' => '',
                'charset' => 'utf8mb4',
                'path' => STORAGE_DIR . '/webatze.sqlite',
            ],
            'app_key' => '',
            'crypto_key' => '',
            'create_path' => 'create',
            'login_max_attempts' => 5,
            'login_lockout_seconds' => 900,
            'anthropic' => [
                'api_key' => '',
                'model_plan' => 'claude-opus-5',
                'model_content' => 'claude-sonnet-5',
                'timeout' => 120,
            ],
            'mail' => [
                'to' => '',
                'from' => '',
                'from_name' => 'WebAtze',
            ],
            'preview_ttl_hours' => 24,
            'worker_budget_seconds' => 50,
        ];
    }
}
