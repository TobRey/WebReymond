<?php

declare(strict_types=1);

use SkyKingdoms\Core\App;
use SkyKingdoms\Store\Store;

/**
 * Gemeinsame Hilfen für alle Testsuiten: eine frische Installation in einem
 * temporären Ordner, damit Tests nie echte Spielstände berühren.
 */
final class TestEnv
{
    private static ?string $dir = null;

    /** Frischen, leeren Datenspeicher einhängen. */
    public static function freshStore(): Store
    {
        self::cleanup();
        self::$dir = sys_get_temp_dir() . '/sk-test-' . bin2hex(random_bytes(6));
        mkdir(self::$dir, 0770, true);

        $store = new Store(self::$dir);
        App::setStore($store);
        App::setConfig('installed_for_tests', true);

        return $store;
    }

    public static function dir(): string
    {
        return (string) self::$dir;
    }

    public static function cleanup(): void
    {
        if (self::$dir === null || !is_dir(self::$dir)) {
            self::$dir = null;

            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::$dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            /** @var SplFileInfo $item */
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir(self::$dir);
        self::$dir = null;
    }
}
