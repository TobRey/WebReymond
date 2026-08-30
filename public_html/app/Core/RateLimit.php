<?php

declare(strict_types=1);

namespace WebAtze\Core;

/**
 * Begrenzt, wie oft etwas in einem Zeitfenster passieren darf.
 *
 * Wird an drei Stellen gebraucht:
 *   - Anmeldung (gegen Durchprobieren)
 *   - Kontaktformular (gegen Werbemüll)
 *   - KI-Befehle (gegen versehentliche Kostenlawinen)
 */
final class RateLimit
{
    /**
     * Einen Versuch zählen.
     * Gibt false zurück, wenn das Fenster bereits ausgeschöpft ist.
     */
    public static function hit(string $bucket, int $maxHits, int $windowSeconds): bool
    {
        $bucket = self::normalise($bucket);
        $now = time();

        return Db::transaction(static function () use ($bucket, $maxHits, $windowSeconds, $now): bool {
            $row = Db::first('SELECT hits, reset_at FROM rate_limits WHERE bucket = :b', ['b' => $bucket]);

            if ($row === null || strtotime((string) $row['reset_at']) <= $now) {
                $resetAt = date('Y-m-d H:i:s', $now + $windowSeconds);
                if ($row === null) {
                    Db::insert('rate_limits', ['bucket' => $bucket, 'hits' => 1, 'reset_at' => $resetAt]);
                } else {
                    Db::update('rate_limits', ['hits' => 1, 'reset_at' => $resetAt], 'bucket = :b', ['b' => $bucket]);
                }
                return true;
            }

            $hits = (int) $row['hits'];
            if ($hits >= $maxHits) {
                return false;
            }

            Db::update('rate_limits', ['hits' => $hits + 1], 'bucket = :b', ['b' => $bucket]);
            return true;
        });
    }

    /** Nur nachschauen, ohne zu zählen. */
    public static function tooMany(string $bucket, int $maxHits): bool
    {
        $row = Db::first(
            'SELECT hits, reset_at FROM rate_limits WHERE bucket = :b',
            ['b' => self::normalise($bucket)]
        );
        if ($row === null || strtotime((string) $row['reset_at']) <= time()) {
            return false;
        }
        return (int) $row['hits'] >= $maxHits;
    }

    /** Sekunden bis zur Freigabe. */
    public static function retryAfter(string $bucket): int
    {
        $resetAt = Db::value(
            'SELECT reset_at FROM rate_limits WHERE bucket = :b',
            ['b' => self::normalise($bucket)]
        );
        if (!is_string($resetAt)) {
            return 0;
        }
        return max(0, strtotime($resetAt) - time());
    }

    public static function clear(string $bucket): void
    {
        Db::delete('rate_limits', 'bucket = :b', ['b' => self::normalise($bucket)]);
    }

    /** Abgelaufene Einträge entfernen (läuft im Worker). */
    public static function purgeExpired(): int
    {
        return Db::delete('rate_limits', 'reset_at < :now', ['now' => Db::now()]);
    }

    private static function normalise(string $bucket): string
    {
        return mb_substr(preg_replace('/[^A-Za-z0-9:._\-]/', '_', $bucket) ?? '', 0, 180);
    }
}
