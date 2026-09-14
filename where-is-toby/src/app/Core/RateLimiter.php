<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Dateibasiertes Rate-Limiting (Token-Bucket je Schluessel).
 */
final class RateLimiter
{
    public function __construct(private string $dir)
    {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0750, true);
        }
    }

    /**
     * @return array{allowed:bool,remaining:int,retryAfter:int}
     */
    public function hit(string $key, int $limit, int $windowSeconds): array
    {
        $file = $this->pathFor($key);
        $now = time();
        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            // Wenn kein Limit gespeichert werden kann, lieber durchlassen als das Spiel blockieren
            Logger::warning('Rate-Limit-Datei nicht beschreibbar', ['key' => $key]);
            return ['allowed' => true, 'remaining' => $limit, 'retryAfter' => 0];
        }
        try {
            flock($handle, LOCK_EX);
            $raw = stream_get_contents($handle) ?: '';
            $data = Json::decode($raw) ?? [];
            $start = (int)($data['start'] ?? 0);
            $count = (int)($data['count'] ?? 0);
            if ($start === 0 || ($now - $start) >= $windowSeconds) {
                $start = $now;
                $count = 0;
            }
            $count++;
            $allowed = $count <= $limit;
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, Json::encode(['start' => $start, 'count' => $count]));
            fflush($handle);
            return [
                'allowed'    => $allowed,
                'remaining'  => max(0, $limit - $count),
                'retryAfter' => $allowed ? 0 : max(1, $windowSeconds - ($now - $start)),
            ];
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function enforce(string $key, int $limit, int $windowSeconds): void
    {
        $result = $this->hit($key, $limit, $windowSeconds);
        if (!$result['allowed']) {
            Logger::security('Rate-Limit ueberschritten', ['key' => $key]);
            throw HttpException::tooMany('Zu viele Anfragen. Bitte ' . $result['retryAfter'] . ' Sekunden warten.');
        }
    }

    public function reset(string $key): void
    {
        $file = $this->pathFor($key);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /** Aufraeumen alter Limit-Dateien. */
    public function gc(int $olderThan = 86400): int
    {
        $removed = 0;
        foreach (glob($this->dir . '/*.rl') ?: [] as $file) {
            if (filemtime($file) < time() - $olderThan) {
                @unlink($file);
                $removed++;
            }
        }
        return $removed;
    }

    private function pathFor(string $key): string
    {
        return $this->dir . '/' . hash('sha256', $key) . '.rl';
    }
}
