<?php
// YouGBT – JSON-API. Alle Aktionen per POST mit JSON-Body und Header "X-YouGBT: 1".
// Der Header erzwingt bei fremden Seiten einen CORS-Preflight, der hier nie erlaubt wird (CSRF-Schutz);
// Spieler-Tokens liegen nicht in Cookies, sondern werden explizit mitgeschickt.
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/game.php';

ini_set('display_errors', '0');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_SERVER['HTTP_X_YOUGBT'] ?? '') !== '1') {
        throw new YgError('bad_request', 400);
    }
    $raw = file_get_contents('php://input', false, null, 0, 20000);
    $in = json_decode((string) $raw, true);
    if (!is_array($in)) {
        throw new YgError('bad_request', 400);
    }
    $action = (string) ($in['a'] ?? '');
    yg_maybe_cleanup();
    $out = yg_dispatch($action, $in);
    yg_json_out(['ok' => true] + $out);
} catch (YgError $e) {
    yg_json_out(['ok' => false, 'error' => $e->errCode] + $e->extra, $e->status);
} catch (Throwable $e) {
    error_log('YouGBT: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    yg_json_out(['ok' => false, 'error' => 'server_error'], 500);
}

function yg_in_str(array $in, string $k, int $max = 100): string
{
    $v = $in[$k] ?? '';
    return is_string($v) ? mb_substr($v, 0, $max) : '';
}

function yg_dispatch(string $action, array $in): array
{
    $ip = yg_client_ip();
    switch ($action) {
        case 'status':
            return ['configured' => yg_is_configured()];

        case 'create':
            yg_rate_limit('create|' . $ip, 12, 600);
            $solo = !empty($in['solo']);
            $r = yg_create_room((array) ($in['settings'] ?? []), yg_in_str($in, 'name', 60), $solo);
            return $r;

        case 'join':
            yg_rate_limit('join|' . $ip, 30, 600);
            return yg_join(strtoupper(yg_in_str($in, 'code', 10)), yg_in_str($in, 'name', 60));

        case 'peek':
            // Nur für die Einladungsseite: existiert der Raum und ist Beitritt möglich? Keine Spielerdaten.
            yg_rate_limit('peek|' . $ip, 60, 300);
            $room = yg_load_room(strtoupper(yg_in_str($in, 'code', 10)));
            if (!$room || $room['solo']) {
                throw new YgError('room_not_found', 404);
            }
            return ['joinable' => $room['phase'] === 'lobby' && count(yg_active_ids($room)) < $room['max_players'], 'phase' => $room['phase']];
    }

    // Ab hier: Spieler-Aktionen mit Token
    $code = strtoupper(yg_in_str($in, 'code', 10));
    $pid = yg_in_str($in, 'pid', 20);
    $token = yg_in_str($in, 'token', 80);
    $room = yg_load_room($code);
    if (!$room) {
        throw new YgError('room_not_found', 404);
    }
    yg_auth($room, $pid, $token);
    $now = yg_now_ms();

    switch ($action) {
        case 'state':
            return yg_action_state($code, $pid, $room, (int) ($in['v'] ?? 0), $now);
        case 'advance':
            return yg_action_advance($code, $pid);
        default:
            [$res, $room] = yg_mutate_room($code, function (array &$room) use ($action, $pid, $in) {
                $now = yg_now_ms();
                $room['players'][$pid]['seen'] = $now;
                $r = yg_player_action($room, $action, $pid, $in, $now);
                yg_tick($room, $now);
                return $r;
            });
            // Letzte Abgabe o. Ä. hat einen KI-Schritt fällig gemacht: direkt ausführen
            if (yg_is_due($room, yg_now_ms()) && in_array($action, ['submit', 'start', 'retry', 'ready'], true)) {
                return yg_action_advance($code, $pid);
            }
            return ['state' => yg_view($room, $pid, yg_now_ms())] + (is_array($res) ? $res : []);
    }
}

function yg_join(string $code, string $name): array
{
    $name = yg_valid_name($name);
    [$res] = yg_mutate_room($code, function (array &$room) use ($name) {
        if ($room['solo']) {
            throw new YgError('room_not_found', 404);
        }
        if ($room['phase'] !== 'lobby') {
            throw new YgError('game_running', 409);
        }
        if (count(yg_active_ids($room)) >= $room['max_players']) {
            throw new YgError('room_full', 409);
        }
        foreach ($room['players'] as $p) {
            if (mb_strtolower($p['name']) === mb_strtolower($name)) {
                throw new YgError('name_taken', 409);
            }
        }
        if (count($room['players']) >= 30) {
            throw new YgError('room_full', 409);
        }
        $now = yg_now_ms();
        [$player, $token] = yg_new_player($name, $now);
        $room['players'][$player['id']] = $player;
        yg_sys_chat($room, 'joined', $name);
        $room['_dirty'] = true;
        return ['code' => $room['code'], 'token' => $token, 'pid' => $player['id']];
    });
    return $res;
}

/** Polling: niemals KI-Aufrufe. Schreibt nur, wenn sich wirklich etwas ändert (Anwesenheit/Übergänge). */
function yg_action_state(string $code, string $pid, array $room, int $clientV, int $now): array
{
    $copy = $room;
    yg_tick($copy, $now);
    $needWrite = !empty($copy['_dirty']) || ($now - (int) $room['players'][$pid]['seen'] > 10000);
    if ($needWrite) {
        [, $room] = yg_mutate_room($code, function (array &$room) use ($pid) {
            $now = yg_now_ms();
            $wasOnline = $now - $room['players'][$pid]['seen'] < 25000;
            $room['players'][$pid]['seen'] = $now;
            // Rückkehr nach Verbindungsabbruch sichtbar machen
            if (!$wasOnline) {
                $room['_dirty'] = true;
            }
            yg_tick($room, $now);
            if (empty($room['_dirty'])) {
                // nur Anwesenheit gespeichert, ohne Versionssprung
                yg_save_room($room);
            }
        });
    }
    if ($clientV > 0 && $clientV === (int) $room['v']) {
        return ['same' => true, 'now' => yg_now_ms(), 'due' => yg_is_due($room, yg_now_ms()), 'busy' => yg_job_running($room, yg_now_ms())];
    }
    return ['state' => yg_view($room, $pid, yg_now_ms())];
}

/** Führt fällige Übergänge aus; ggf. genau EIN KI-Job pro Phase (Sperre + Job-ID verhindern Doppelaufrufe). */
function yg_action_advance(string $code, string $pid): array
{
    [$job] = yg_mutate_room($code, function (array &$room) use ($pid) {
        $now = yg_now_ms();
        yg_tick($room, $now);
        return yg_claim_job($room, $now);
    });
    if (is_array($job)) {
        yg_run_job($code, $job);
    }
    $room = yg_load_room($code);
    return ['state' => yg_view($room, $pid, yg_now_ms())];
}

function yg_player_action(array &$room, string $action, string $pid, array $in, int $now): ?array
{
    $me = $room['players'][$pid];
    $isHost = $room['host_id'] === $pid;
    $active = $me['status'] === 'active';
    $phase = $room['phase'];

    switch ($action) {
        case 'settings':
            if (!$isHost || $phase !== 'lobby') {
                throw new YgError('forbidden', 403);
            }
            $s = yg_sanitize_settings((array) ($in['settings'] ?? []), $room);
            if ($room['solo']) {
                $s['max_players'] = 1;
            } elseif ($s['max_players'] < count(yg_active_ids($room))) {
                throw new YgError('max_below_players');
            }
            $room = array_merge($room, $s);
            $room['_dirty'] = true;
            return null;

        case 'start':
            if (!$isHost || $phase !== 'lobby') {
                throw new YgError('forbidden', 403);
            }
            if (!$room['solo'] && count(yg_active_ids($room)) < 2) {
                throw new YgError('need_players');
            }
            if (!yg_is_configured()) {
                throw new YgError('not_configured', 503);
            }
            yg_sys_chat($room, 'start');
            yg_begin_round($room, $now);
            return null;

        case 'submit':
            if (!$active || $phase !== 'answering') {
                throw new YgError('not_answering', 409);
            }
            if (yg_in_str($in, 'key', 30) !== $room['q']['key']) {
                throw new YgError('phase_changed', 409);
            }
            if (isset($room['answers'][$pid])) {
                throw new YgError('already_submitted', 409);
            }
            if ($room['q']['deadline'] !== null && $now > (int) $room['q']['deadline'] + YG_ANSWER_GRACE_MS) {
                throw new YgError('too_late', 409);
            }
            $text = yg_clean_text(is_string($in['answer'] ?? null) ? $in['answer'] : '', YG_MAX_ANSWER, true);
            if ($text === '') {
                throw new YgError('answer_empty');
            }
            $joker = !empty($in['joker']);
            if ($joker) {
                if (yg_is_special($room['round'])) {
                    throw new YgError('joker_special');
                }
                if ($me['joker_used']) {
                    throw new YgError('joker_used');
                }
                $room['players'][$pid]['joker_used'] = true;
            }
            $room['answers'][$pid] = ['text' => $text, 'at' => $now, 'joker' => $joker];
            $room['_dirty'] = true;
            return null;

        case 'hint':
            if (!$active || $phase !== 'answering' || yg_in_str($in, 'key', 30) !== $room['q']['key']) {
                throw new YgError('not_answering', 409);
            }
            if (isset($room['answers'][$pid])) {
                throw new YgError('already_submitted', 409);
            }
            // Idempotent: wiederholtes Klicken liefert denselben Hinweis, kostet nichts extra
            if (empty($room['hints'][$pid])) {
                if ($me['hint_used']) {
                    throw new YgError('hint_used');
                }
                $room['players'][$pid]['hint_used'] = true;
                $room['hints'][$pid] = true;
                $room['_dirty'] = true;
            }
            return null;

        case 'ready':
            if ($phase !== 'reveal' || !$active) {
                return null;
            }
            if (!empty($in['force']) && $isHost) {
                foreach (yg_active_ids($room) as $id) {
                    $room['players'][$id]['ready'] = true;
                }
            } else {
                $room['players'][$pid]['ready'] = true;
            }
            $room['_dirty'] = true;
            return null;

        case 'retry':
            if (!$isHost || $phase !== 'stalled') {
                throw new YgError('forbidden', 403);
            }
            $room['phase'] = $room['stalled']['from'] ?? 'generating';
            $room['stalled'] = null;
            $room['job'] = null;
            $room['_dirty'] = true;
            return null;

        case 'end':
            if (!$isHost || in_array($phase, ['lobby', 'final'], true)) {
                throw new YgError('forbidden', 403);
            }
            // Laufende, noch nicht gutgeschriebene Spezialrunden-Punkte verfallen nicht
            foreach ($room['pending'] as $id => $sum) {
                if (isset($room['players'][$id]) && $room['players'][$id]['status'] === 'active') {
                    $room['players'][$id]['score'] += $sum;
                }
            }
            $room['pending'] = [];
            yg_finish($room, 'ended');
            return null;

        case 'leave':
            if ($active) {
                $room['players'][$pid]['status'] = 'left';
                unset($room['answers'][$pid], $room['pending'][$pid]);
                yg_sys_chat($room, 'left', $me['name']);
                $room['_dirty'] = true;
            }
            return null;

        case 'chat':
            if ($room['solo'] || !$active) {
                throw new YgError('forbidden', 403);
            }
            if ($phase === 'answering') {
                throw new YgError('chat_paused', 409);
            }
            $text = yg_clean_text(is_string($in['text'] ?? null) ? $in['text'] : '', YG_MAX_CHAT);
            if ($text === '') {
                throw new YgError('chat_empty');
            }
            $times = array_values(array_filter($me['chat_times'] ?? [], fn($t) => $t > $now - 15000));
            if (count($times) >= 5 || ($times && $now - end($times) < 1200)) {
                throw new YgError('chat_slow', 429);
            }
            if (mb_strtolower($text) === mb_strtolower((string) ($me['last_chat'] ?? '')) && $times) {
                throw new YgError('chat_repeat', 429);
            }
            $times[] = $now;
            $room['players'][$pid]['chat_times'] = $times;
            $room['players'][$pid]['last_chat'] = $text;
            $room['chat_seq']++;
            $room['chat'][] = ['n' => $room['chat_seq'], 'pid' => $pid, 'name' => $me['name'], 'text' => $text, 't' => $now];
            $room['chat'] = array_slice($room['chat'], -80);
            $room['_dirty'] = true;
            return null;
    }
    throw new YgError('bad_request', 400);
}
