<?php

declare(strict_types=1);

namespace SkyKingdoms\Core;

use SkyKingdoms\Store\StoreException;

/**
 * Rate-Limiting ohne Datenbank: ein kleines Zählerdokument je Aktion und
 * Kennung (IP-Adresse oder Konto). Grenzen stehen in config/game.php.
 */
final class RateLimit
{
    /**
     * Versuch zählen und prüfen.
     *
     * @return array{allowed:bool,remaining:int,retry_after:int}
     */
    public static function attempt(string $action, string $identity): array
    {
        [$limit, $window] = self::rule($action);
        if ($limit <= 0 || !App::isInstalled()) {
            return ['allowed' => true, 'remaining' => $limit, 'retry_after' => 0];
        }

        $key = self::key($action, $identity);
        $now = time();

        try {
            $state = App::store()->update($key, static function (array $current) use ($now, $window): array {
                $start = (int) ($current['start'] ?? 0);
                $count = (int) ($current['count'] ?? 0);

                if ($start === 0 || $now - $start >= $window) {
                    return ['start' => $now, 'count' => 1];
                }

                return ['start' => $start, 'count' => $count + 1];
            }, ['start' => 0, 'count' => 0]);
        } catch (StoreException $e) {
            Logger::warn('Rate-Limit konnte nicht geschrieben werden', ['fehler' => $e->getMessage()]);

            return ['allowed' => true, 'remaining' => $limit, 'retry_after' => 0];
        }

        $count     = (int) ($state['count'] ?? 1);
        $start     = (int) ($state['start'] ?? $now);
        $remaining = max(0, $limit - $count);
        $retry     = max(0, $window - ($now - $start));

        self::sweep($action);

        return [
            'allowed'     => $count <= $limit,
            'remaining'   => $remaining,
            'retry_after' => $retry,
        ];
    }

    /** Nur nachsehen, ohne zu zählen. */
    public static function peek(string $action, string $identity): array
    {
        [$limit, $window] = self::rule($action);
        if ($limit <= 0 || !App::isInstalled()) {
            return ['allowed' => true, 'remaining' => $limit, 'retry_after' => 0];
        }

        $state = App::store()->read(self::key($action, $identity), ['start' => 0, 'count' => 0]);
        $start = (int) ($state['start'] ?? 0);
        $count = (int) ($state['count'] ?? 0);
        $now   = time();

        if ($start === 0 || $now - $start >= $window) {
            return ['allowed' => true, 'remaining' => $limit, 'retry_after' => 0];
        }

        return [
            'allowed'     => $count < $limit,
            'remaining'   => max(0, $limit - $count),
            'retry_after' => max(0, $window - ($now - $start)),
        ];
    }

    /** Zähler zurücksetzen – z. B. nach erfolgreicher Anmeldung. */
    public static function clear(string $action, string $identity): void
    {
        if (!App::isInstalled()) {
            return;
        }
        try {
            App::store()->delete(self::key($action, $identity));
        } catch (StoreException) {
            // egal – der Zähler läuft ohnehin ab
        }
    }

    /** Kennung der Anfrage: IP-Adresse, ergänzt um einen optionalen Zusatz. */
    public static function identity(string $extra = ''): string
    {
        return Audit::clientIp() . ($extra === '' ? '' : '|' . $extra);
    }

    /** @return array{0:int,1:int} [Versuche, Zeitfenster] */
    private static function rule(string $action): array
    {
        $rules = (array) App::config('rate_limits', []);
        $rule  = $rules[$action] ?? null;
        if (!is_array($rule) || count($rule) < 2) {
            return [0, 0];
        }

        return [(int) $rule[0], (int) $rule[1]];
    }

    private static function key(string $action, string $identity): string
    {
        $action = preg_replace('/[^a-z0-9_]/', '', strtolower($action)) ?: 'unbekannt';

        return 'limits/' . $action . '/' . substr(hash('sha256', $identity . '|' . App::key()), 0, 24) . '.json';
    }

    /** Alte Zählerdateien gelegentlich aufräumen (begrenzt und günstig). */
    private static function sweep(string $action): void
    {
        if (random_int(1, 50) !== 1) {
            return;
        }

        $action = preg_replace('/[^a-z0-9_]/', '', strtolower($action)) ?: 'unbekannt';
        $dir    = 'limits/' . $action;
        [, $window] = self::rule($action);
        $maxAge = max(3600, $window * 4);

        try {
            $store = App::store();
            $files = array_slice($store->listFiles($dir), 0, 200);
            foreach ($files as $file) {
                $path = $store->path($dir . '/' . $file);
                if (is_file($path) && time() - (int) filemtime($path) > $maxAge) {
                    @unlink($path);
                }
            }
        } catch (StoreException) {
            // Aufräumen ist optional
        }
    }
}
