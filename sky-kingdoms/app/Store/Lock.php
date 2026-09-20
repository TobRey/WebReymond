<?php

declare(strict_types=1);

namespace SkyKingdoms\Store;

/**
 * Dateisperre auf Basis von flock().
 *
 * Damit werden Lese-Ändere-Schreibe-Zyklen serialisiert: Zwei gleichzeitige
 * Upgrade-Anfragen desselben Spielers können niemals denselben Rohstoff
 * doppelt ausgeben. Die Sperre ist immer zeitlich begrenzt – ein hängender
 * Prozess darf niemals alle weiteren blockieren.
 */
final class Lock
{
    /** @var array<string,array{handle:resource,count:int}> */
    private static array $held = [];

    private function __construct(private string $file, private bool $reentrant)
    {
    }

    /**
     * Exklusive Sperre holen. Gibt null zurück, wenn sie innerhalb von
     * $timeoutMs nicht zu bekommen war.
     */
    public static function acquire(string $file, int $timeoutMs = 10000): ?self
    {
        if (isset(self::$held[$file])) {
            self::$held[$file]['count']++;

            return new self($file, true);
        }

        $dir = \dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new StoreException('Sperrordner konnte nicht angelegt werden: ' . $dir);
        }

        $handle = @fopen($file, 'c+b');
        if ($handle === false) {
            throw new StoreException('Sperrdatei konnte nicht geöffnet werden: ' . basename($file));
        }

        $deadline = microtime(true) + ($timeoutMs / 1000);
        $waited   = 0;
        do {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                self::$held[$file] = ['handle' => $handle, 'count' => 1];

                return new self($file, false);
            }
            // 5 ms, danach ansteigend bis 50 ms – schont die CPU auf Shared Hosting.
            usleep($waited < 20 ? 5000 : 50000);
            $waited++;
        } while (microtime(true) < $deadline);

        fclose($handle);

        return null;
    }

    /** Sperre freigeben. Mehrfaches Aufrufen ist ungefährlich. */
    public function release(): void
    {
        if (!isset(self::$held[$this->file])) {
            return;
        }

        self::$held[$this->file]['count']--;
        if (self::$held[$this->file]['count'] > 0) {
            return;
        }

        $handle = self::$held[$this->file]['handle'];
        unset(self::$held[$this->file]);
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }

    public function __destruct()
    {
        $this->release();
    }
}
