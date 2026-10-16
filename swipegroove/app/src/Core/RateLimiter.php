<?php
declare(strict_types=1);

namespace SwipeGroove\Core;

/**
 * Datenbankgestütztes Rate-Limit mit festen Zeitfenstern.
 */
final class RateLimiter
{
    public function __construct(private Db $db)
    {
    }

    /**
     * @return array{allowed:bool, remaining:int, retry_after:int}
     */
    public function hit(string $key, int $limit, int $windowSeconds): array
    {
        $now = time();
        $windowStart = $now - ($now % $windowSeconds);
        $bucket = hash('sha256', $key) . ':' . $windowStart;
        $this->db->run(
            'INSERT INTO rate_limits (bucket, window_start, hits, expires_at) VALUES (?, ?, 1, ?)
             ON DUPLICATE KEY UPDATE hits = hits + 1',
            [$bucket, $windowStart, $windowStart + $windowSeconds * 2]
        );
        $hits = (int) $this->db->value('SELECT hits FROM rate_limits WHERE bucket = ?', [$bucket]);
        $allowed = $hits <= $limit;
        return [
            'allowed' => $allowed,
            'remaining' => max(0, $limit - $hits),
            'retry_after' => $allowed ? 0 : ($windowStart + $windowSeconds - $now),
        ];
    }

    public function enforce(string $key, int $limit, int $windowSeconds, string $code = 'error.rate_limited'): void
    {
        $r = $this->hit($key, $limit, $windowSeconds);
        if (!$r['allowed']) {
            throw HttpException::tooMany($code, max(1, $r['retry_after']));
        }
    }

    public function cleanup(): int
    {
        return $this->db->exec('DELETE FROM rate_limits WHERE expires_at < ?', [time()]);
    }
}
