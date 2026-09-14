<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Minimaler PSR-4 Autoloader fuer den Namespace App\.
 * Keine externen Abhaengigkeiten, kein Composer noetig.
 */
final class Autoloader
{
    private static string $base = '';

    public static function register(string $appDir): void
    {
        self::$base = rtrim($appDir, '/');
        spl_autoload_register([self::class, 'load'], true, false);
    }

    public static function load(string $class): void
    {
        if (!str_starts_with($class, 'App\\')) {
            return;
        }
        $relative = substr($class, 4);
        $relative = str_replace('\\', '/', $relative);
        if (!preg_match('~^[A-Za-z0-9_/]+$~', $relative)) {
            return;
        }
        $file = self::$base . '/' . $relative . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
}
