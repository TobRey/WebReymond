<?php
declare(strict_types=1);

/**
 * Migration 002 – Social-Redesign:
 *  - Registrierung öffnen (Betriebsmodus «public»), sofern der Inhaber bereits eingerichtet ist
 *  - Lesbare Dateirechte für bestehende Uploads (früher 0640/0750 → Bilder luden nicht)
 */
return static function (\SwipeGroove\Core\Db $db): void {
    $now = $db->now();
    $set = static function (string $key, mixed $value) use ($db, $now): void {
        $db->run(
            'INSERT INTO settings (`key`, `value`, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = VALUES(updated_at)',
            [$key, json_encode($value), $now]
        );
    };
    $mode = $db->value('SELECT `value` FROM settings WHERE `key` = ?', ['operation_mode']);
    if ($mode !== null && json_decode((string) $mode, true) === 'closed_beta') {
        $set('operation_mode', 'public');
    }
    $set('registration_open', true);
    \SwipeGroove\Users\ImageService::repairPermissions(dirname(__DIR__) . '/uploads');
};
