<?php
declare(strict_types=1);

namespace SwipeGroove\Core;

/**
 * Laufzeit-Einstellungen in der Datenbank (vom Admin änderbar, überleben Updates).
 */
final class Settings
{
    /** @var array<string,mixed>|null */
    private ?array $cache = null;

    public const DEFAULTS = [
        'operation_mode' => 'setup',          // setup | closed_beta | public
        'registration_open' => true,
        'playback_mode' => 'off',             // off | connect
        'premium_price_chf' => '2.00',
        'premium_cover_quota' => 50,
        'cover_model' => 'gpt-image-1-mini',
        'cover_quality' => 'medium',
        'cover_price_table' => [
            // USD pro Bild 1024x1024 (Stand Oktober 2026, Drittquellen – im Admin anpassbar)
            'gpt-image-1-mini' => ['low' => 0.005, 'medium' => 0.011, 'high' => 0.036],
            'gpt-image-1.5' => ['low' => 0.009, 'medium' => 0.034, 'high' => 0.133],
            'gpt-image-1' => ['low' => 0.011, 'medium' => 0.042, 'high' => 0.167],
        ],
        'setup_key_hash' => null,
        'owner_registered' => false,
        'spotify_cache_ttl_minutes' => 30,
        'spotify_items_cache_ttl_minutes' => 15,
        'max_parallel_cover_jobs' => 2,
        'legal_operator_name' => '',
        'legal_operator_address' => '',
        'legal_operator_email' => '',
        'legal_operator_country' => '',
    ];

    public function __construct(private Db $db)
    {
    }

    private function load(): void
    {
        if ($this->cache !== null) {
            return;
        }
        $this->cache = self::DEFAULTS;
        try {
            foreach ($this->db->all('SELECT `key`, `value` FROM settings') as $row) {
                $this->cache[$row['key']] = json_decode((string) $row['value'], true);
            }
        } catch (\Throwable) {
            // Tabelle existiert noch nicht (vor Migration)
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->load();
        return array_key_exists($key, $this->cache) ? $this->cache[$key] : $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->load();
        $json = json_encode($value, JSON_UNESCAPED_UNICODE);
        $this->db->run(
            'INSERT INTO settings (`key`, `value`, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = VALUES(updated_at)',
            [$key, $json, $this->db->now()]
        );
        $this->cache[$key] = $value;
    }

    public function bool(string $key): bool
    {
        return (bool) $this->get($key, false);
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->get($key, $default);
        return is_numeric($v) ? (int) $v : $default;
    }

    public function str(string $key, string $default = ''): string
    {
        $v = $this->get($key, $default);
        return is_scalar($v) ? (string) $v : $default;
    }

    public function reload(): void
    {
        $this->cache = null;
    }
}
