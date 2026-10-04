<?php
declare(strict_types=1);

/*
 * JSON-API für das Spiel. Alle Aufrufe per POST mit JSON-Body:
 *   { "action": "...", "code": "ABCDE", "token": "...", ... }
 */

define('GH_APP', true);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

function gh_respond(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

try {
    require __DIR__ . '/lib/bootstrap.php';

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        gh_respond(['ok' => false, 'error' => 'Nur POST erlaubt.'], 405);
    }
    $raw = file_get_contents('php://input', false, null, 0, 8192);
    $in = json_decode($raw ?: '', true);
    if (!is_array($in)) {
        gh_respond(['ok' => false, 'error' => 'Ungültige Anfrage.'], 400);
    }

    $action = (string) ($in['action'] ?? '');
    $code = gh_normalize_code((string) ($in['code'] ?? ''));
    $token = preg_replace('/[^a-f0-9]/', '', (string) ($in['token'] ?? '')) ?? '';

    if ($action !== 'create' && !gh_valid_code($code)) {
        gh_respond(['ok' => false, 'error' => 'Der Raumcode hat 5 Zeichen (Buchstaben und Ziffern).', 'code' => 'bad_code'], 400);
    }

    switch ($action) {
        case 'create':
            $res = gh_action_create();
            [$me, $room] = gh_mutate($res['code'], $res['token'], fn() => null);
            gh_respond(['ok' => true, 'token' => $res['token'], 'state' => gh_view($room, $me)]);

        case 'join':
            $res = gh_action_join($code, $token);
            [$me, $room] = gh_mutate($code, $res['token'], fn() => null);
            gh_respond(['ok' => true, 'token' => $res['token'], 'takeover' => !empty($res['takeover']), 'state' => gh_view($room, $me)]);

        case 'state':
            [$me, $room] = gh_mutate($code, $token, fn() => null);
            if (!empty($room['job'])) {
                gh_run_pending_job($code);
                [$me, $room] = gh_mutate($code, $token, fn() => null);
            }
            gh_respond(['ok' => true, 'state' => gh_view($room, $me)]);

        case 'answer':
            gh_action_answer($code, $token, (string) ($in['answer'] ?? ''));
            break;

        case 'next':
            gh_action_next($code, $token);
            break;

        case 'restart':
            gh_action_restart($code, $token);
            break;

        case 'rename':
            gh_action_rename($code, $token);
            break;

        default:
            gh_respond(['ok' => false, 'error' => 'Unbekannte Aktion.'], 400);
    }

    // Offene Claude-Aufgaben erledigt der nächste "state"-Abruf, damit die
    // Oberfläche sofort reagiert (z. B. Trommelwirbel statt Lade-Spinner).
    [$me, $room] = gh_mutate($code, $token, fn() => null);
    gh_respond(['ok' => true, 'state' => gh_view($room, $me)]);
} catch (GhError $e) {
    gh_respond(['ok' => false, 'error' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
} catch (Throwable $e) {
    if (function_exists('gh_log')) {
        gh_log('Serverfehler: ' . get_class($e) . ' – ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    }
    gh_respond(['ok' => false, 'error' => 'Ups, auf dem Server ist etwas schiefgelaufen. Bitte gleich nochmals versuchen.', 'code' => 'server_error'], 500);
}
