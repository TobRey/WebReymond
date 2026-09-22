<?php
// YouGBT – Konfiguration (API-Schlüssel, Modell, Limits) und Nutzungszähler.
declare(strict_types=1);

const YG_DEFAULT_MODEL = 'claude-haiku-4-5';

function yg_config_file(): string
{
    return yg_data_dir() . '/config.php';
}

function yg_config(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $c = yg_read_guarded(yg_config_file());
    $cache = is_array($c) ? $c : [];
    return $cache;
}

function yg_config_defaults(): array
{
    return [
        'api_key' => '',
        'model' => YG_DEFAULT_MODEL,
        'daily_limit' => 400,       // KI-Aufrufe pro Tag (alle Partien zusammen)
        'max_games' => 10,          // gleichzeitig laufende Partien
        'admin_hash' => '',
        'created' => 0,
    ];
}

function yg_cfg(string $key): mixed
{
    $c = yg_config() + yg_config_defaults();
    return $c[$key] ?? null;
}

function yg_is_configured(): bool
{
    $c = yg_config();
    return !empty($c['api_key']) && !empty($c['admin_hash']) && !empty($c['model']);
}

function yg_save_config(array $new): void
{
    yg_with_lock('config', function () use ($new) {
        $cur = yg_read_guarded(yg_config_file());
        $cur = is_array($cur) ? $cur : [];
        $merged = array_merge(yg_config_defaults(), $cur, $new);
        yg_write_guarded(yg_config_file(), $merged);
    });
}

function yg_valid_model_id(string $m): bool
{
    return (bool) preg_match('/^claude-[a-z0-9][a-z0-9.\-]{2,60}$/', $m);
}

/** Reserviert einen KI-Aufruf im Tageskontingent. Wirft 'ai_limit', wenn erschöpft. */
function yg_reserve_ai_call(): void
{
    $limit = (int) yg_cfg('daily_limit');
    yg_with_lock('usage', function () use ($limit) {
        $file = yg_data_dir() . '/usage.php';
        $u = yg_read_guarded($file);
        $today = gmdate('Y-m-d');
        if (!is_array($u) || ($u['day'] ?? '') !== $today) {
            $u = ['day' => $today, 'calls' => 0, 'errors' => 0];
        }
        if ($limit > 0 && $u['calls'] >= $limit) {
            throw new YgError('ai_limit', 429);
        }
        $u['calls']++;
        yg_write_guarded($file, $u);
    });
}

function yg_note_ai_error(): void
{
    yg_with_lock('usage', function () {
        $file = yg_data_dir() . '/usage.php';
        $u = yg_read_guarded($file);
        if (is_array($u) && ($u['day'] ?? '') === gmdate('Y-m-d')) {
            $u['errors'] = ($u['errors'] ?? 0) + 1;
            yg_write_guarded($file, $u);
        }
    });
}

function yg_usage_today(): array
{
    $u = yg_read_guarded(yg_data_dir() . '/usage.php');
    if (!is_array($u) || ($u['day'] ?? '') !== gmdate('Y-m-d')) {
        return ['day' => gmdate('Y-m-d'), 'calls' => 0, 'errors' => 0];
    }
    return $u;
}

/** Anzahl aktiver Partien (nicht beendet, in den letzten 20 Minuten benutzt). */
function yg_active_games(): int
{
    $n = 0;
    $cut = time() - 1200;
    foreach (glob(yg_data_dir() . '/rooms/*.php') ?: [] as $file) {
        if ((int) @filemtime($file) < $cut) {
            continue;
        }
        $room = yg_read_guarded($file);
        if (is_array($room) && ($room['phase'] ?? '') !== 'final') {
            $n++;
        }
    }
    return $n;
}
