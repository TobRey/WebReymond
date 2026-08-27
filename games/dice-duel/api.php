<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/Store.php';
require_once __DIR__ . '/lib/Game.php';
require_once __DIR__ . '/lib/Content.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

/** @param array<string, mixed> $payload */
function respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $message, int $status = 400): never
{
    respond(['ok' => false, 'error' => $message], $status);
}

/** @return array<string, mixed> */
function input(): array
{
    static $data = null;
    if ($data !== null) {
        return $data;
    }
    $raw = file_get_contents('php://input');
    $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    $data = is_array($decoded) ? $decoded : $_POST;
    return $data;
}

function param(string $key, string $default = ''): string
{
    $data = input();
    $value = $data[$key] ?? $_GET[$key] ?? $default;
    return is_scalar($value) ? trim((string) $value) : $default;
}

function cleanName(string $name): string
{
    $name = preg_replace('/\s+/u', ' ', strip_tags($name)) ?? '';
    $name = trim($name);
    if (function_exists('mb_substr')) {
        $name = mb_substr($name, 0, 16);
    } else {
        $name = substr($name, 0, 16);
    }
    return $name;
}

function normalizeCode(string $code): string
{
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
}

function makeCode(Store $store): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // ohne I, O, 0, 1
    for ($attempt = 0; $attempt < 40; $attempt++) {
        $code = '';
        for ($i = 0; $i < 5; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        if (!$store->exists($code)) {
            return $code;
        }
    }
    fail('Konnte keinen freien Raumcode erzeugen. Bitte erneut versuchen.', 500);
}

/**
 * @param array<string, mixed> $room
 */
function slotForToken(array $room, string $token): ?int
{
    if ($token === '') {
        return null;
    }
    $hash = Game::hashToken($token);
    foreach ($room['players'] as $player) {
        if (hash_equals((string) $player['token'], $hash)) {
            return (int) $player['slot'];
        }
    }
    return null;
}

$store = new Store();
$action = param('action');
if ($action === '') {
    $action = (string) ($_GET['action'] ?? '');
}

switch ($action) {
    /* ------------------------------------------------------------- create */
    case 'create': {
        $name = cleanName(param('name'));
        if ($name === '') {
            fail('Bitte gib einen Namen ein.');
        }
        if (random_int(1, 12) === 1) {
            $store->gc();
        }
        $token = bin2hex(random_bytes(16));
        $code = makeCode($store);
        $room = Game::newRoom($code, $name, $token);
        if (!$store->create($room)) {
            fail('Raum konnte nicht angelegt werden.', 500);
        }
        respond(['ok' => true, 'code' => $code, 'token' => $token, 'slot' => 0, 'state' => Game::view($room, 0)]);
    }

    /* --------------------------------------------------------------- join */
    case 'join': {
        $name = cleanName(param('name'));
        $code = normalizeCode(param('code'));
        if ($name === '') {
            fail('Bitte gib einen Namen ein.');
        }
        if ($code === '' || !$store->exists($code)) {
            fail('Diesen Raumcode gibt es nicht.', 404);
        }
        $token = bin2hex(random_bytes(16));
        $joinError = null;
        $room = $store->mutate($code, function (array $room) use ($name, $token, &$joinError): ?array {
            if (count($room['players']) >= 2) {
                $joinError = 'Dieser Raum ist bereits voll.';
                return null;
            }
            $room['players'][] = Game::newPlayer($name, $token, 1);
            Game::startMatch($room);
            return $room;
        });
        if ($joinError !== null) {
            fail($joinError, 409);
        }
        if ($room === null) {
            fail('Raum nicht gefunden.', 404);
        }
        respond(['ok' => true, 'code' => $code, 'token' => $token, 'slot' => 1, 'state' => Game::view($room, 1)]);
    }

    /* -------------------------------------------------------------- state */
    case 'state': {
        $code = normalizeCode(param('code'));
        $token = param('token');
        $known = (int) (param('v', '0') ?: '0');
        $wait = param('wait', '0') === '1';

        $room = $store->read($code);
        if ($room === null) {
            fail('Raum nicht gefunden.', 404);
        }
        $slot = slotForToken($room, $token);
        if ($slot === null) {
            fail('Ungültiges Token.', 403);
        }

        // Heartbeat: höchstens alle 5 Sekunden schreiben, um IO zu sparen.
        if (time() - (int) $room['players'][$slot]['lastSeen'] > 5) {
            $updated = $store->mutate($code, static function (array $r) use ($slot): array {
                $r['players'][$slot]['lastSeen'] = time();
                return $r;
            });
            if ($updated !== null) {
                $room = $updated;
            }
        }

        // Long-Poll: bis zu 9 Sekunden auf eine Aenderung warten.
        if ($wait && (int) $room['version'] === $known) {
            $deadline = microtime(true) + 9.0;
            while (microtime(true) < $deadline) {
                usleep(300000);
                if (connection_aborted()) {
                    exit;
                }
                $fresh = $store->read($code);
                if ($fresh !== null && (int) $fresh['version'] !== $known) {
                    $room = $fresh;
                    break;
                }
            }
        }

        if ((int) $room['version'] === $known) {
            respond(['ok' => true, 'unchanged' => true, 'version' => (int) $room['version']]);
        }
        respond(['ok' => true, 'state' => Game::view($room, $slot)]);
    }

    /* ------------------------------------------------------------- action */
    case 'select':
    case 'roll':
    case 'buy':
    case 'rematch': {
        $code = normalizeCode(param('code'));
        $token = param('token');
        if (!$store->exists($code)) {
            fail('Raum nicht gefunden.', 404);
        }

        $error = null;
        $slotOut = null;
        $data = input();

        $room = $store->mutate($code, function (array $room) use ($action, $token, $data, &$error, &$slotOut): ?array {
            $slot = slotForToken($room, $token);
            if ($slot === null) {
                $error = 'Ungültiges Token.';
                return null;
            }
            $slotOut = $slot;
            $room['players'][$slot]['lastSeen'] = time();

            if ($action === 'select') {
                $result = Game::selectTarget($room, $slot, (int) ($data['index'] ?? -1));
            } elseif ($action === 'roll') {
                $dice = $data['dice'] ?? [];
                $dice = is_array($dice) ? array_values(array_filter(array_map(
                    static fn($d): string => is_scalar($d) ? (string) $d : '',
                    $dice
                ))) : [];
                $result = Game::roll($room, $slot, $dice);
            } elseif ($action === 'buy') {
                $result = Game::buy(
                    $room,
                    $slot,
                    (string) ($data['kind'] ?? ''),
                    (string) ($data['id'] ?? '')
                );
            } else {
                $votes = $room['rematchVotes'] ?? [];
                if (($room['status'] ?? '') !== 'finished') {
                    $error = 'Das Match läuft noch.';
                    return null;
                }
                if (!in_array($slot, $votes, true)) {
                    $votes[] = $slot;
                }
                $room['rematchVotes'] = array_values($votes);
                if (count($room['rematchVotes']) >= 2 && count($room['players']) === 2) {
                    foreach ($room['players'] as $i => $p) {
                        $room['players'][$i] = array_merge(
                            Game::newPlayer((string) $p['name'], '', (int) $p['slot']),
                            // Token bleibt der bereits gehashte Wert des Spielers.
                            ['token' => (string) $p['token'], 'lastSeen' => time()]
                        );
                    }
                    Game::startMatch($room);
                }
                $result = ['ok' => true];
            }

            if (!$result['ok']) {
                $error = $result['error'] ?? 'Aktion nicht möglich.';
                return null;
            }
            return $room;
        });

        if ($room === null) {
            fail($error ?? 'Raum nicht gefunden.', 404);
        }
        if ($error !== null) {
            respond(['ok' => false, 'error' => $error, 'state' => Game::view($room, $slotOut ?? 0)], 200);
        }
        respond(['ok' => true, 'state' => Game::view($room, $slotOut ?? 0)]);
    }

    default:
        fail('Unbekannte Aktion.', 404);
}
