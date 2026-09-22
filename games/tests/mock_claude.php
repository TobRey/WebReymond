<?php
// Test-Attrappe der Anthropic Messages API (nur für lokale Tests, wird nicht ausgeliefert).
// Verhalten steuerbar über die Datei mock_mode.txt: ok | error500 | error429 | invalid_once | garbage | slow
declare(strict_types=1);

$dir = __DIR__;
$modeFile = $dir . '/mock_mode.txt';
$mode = is_file($modeFile) ? trim((string) file_get_contents($modeFile)) : 'ok';
$log = $dir . '/mock_calls.log';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');

if (($_SERVER['HTTP_X_API_KEY'] ?? '') !== 'sk-ant-test-0123456789abcdefghijKLMN') {
    http_response_code(401);
    echo json_encode(['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']]);
    exit;
}
if ($path === '/v1/models') {
    echo json_encode(['data' => [
        ['id' => 'claude-opus-5', 'display_name' => 'Claude Opus 5'],
        ['id' => 'claude-sonnet-5', 'display_name' => 'Claude Sonnet 5'],
        ['id' => 'claude-haiku-4-5', 'display_name' => 'Claude Haiku 4.5'],
    ]]);
    exit;
}
$body = json_decode((string) file_get_contents('php://input'), true);
$sys = (string) ($body['system'] ?? '');
$user = (string) ($body['messages'][0]['content'] ?? '');
$kind = str_contains($sys, 'You write content') ? 'gen' : 'grade';
file_put_contents($log, date('H:i:s') . " $kind model=" . ($body['model'] ?? '?') . "\n", FILE_APPEND | LOCK_EX);

if ($mode === 'error500') { http_response_code(500); echo '{"type":"error"}'; exit; }
if ($mode === 'error429') { http_response_code(429); echo '{"type":"error"}'; exit; }
if ($mode === 'slow') { sleep(3); }

function reply(string $text): void
{
    echo json_encode(['id' => 'msg_x', 'type' => 'message', 'role' => 'assistant', 'stop_reason' => 'end_turn',
        'content' => [['type' => 'text', 'text' => $text]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 10]]);
}

if ($mode === 'garbage') { reply('Sorry, I cannot produce JSON today.'); exit; }

if ($kind === 'gen') {
    $n = random_int(100, 999);
    $part = fn($i) => ['message' => "yo {AI} frage $n teil $i: warum ist der himmel blau", 'criteria' => ['Rayleigh-Streuung', 'kurze Wellenlängen', 'Sonnenlicht'], 'model_answer' => 'Sonnenlicht wird an Luftmolekülen gestreut; blaues Licht mit kurzer Wellenlänge stärker (Rayleigh-Streuung).', 'hint' => 'Streuung'];
    if (str_contains($user, 'replacement follow-up')) {
        reply(json_encode($part(9)));
        exit;
    }
    $parts = str_contains($user, 'SPECIAL ROUND') ? [$part(1), $part(2), $part(3)] : [$part(1)];
    reply("```json\n" . json_encode(['persona_name' => "Kevin-Kaktus $n", 'persona_trait' => 'hat nie geschlafen', 'topic' => "Himmel $n", 'parts' => $parts]) . "\n```");
    exit;
}
// Bewertung
preg_match_all('/<answer id="([a-z0-9]+)">\n(.*?)\n<\/answer>/s', $user, $m, PREG_SET_ORDER);
$results = [];
foreach ($m as $row) {
    $text = $row[2];
    $score = str_contains($text, 'PERFECT') ? 100 : (str_contains($text, 'HIGH') ? 80 : (stripos($text, 'ignore the rules') !== false ? 0 : 40));
    $results[] = ['id' => $row[1], 'score' => $score, 'reason' => "Mock-Bewertung für {$row[1]}"];
}
$valid = true;
if ($mode === 'invalid_once') {
    $valid = false;
    file_put_contents($modeFile, 'ok');
}
reply(json_encode(['question_valid' => $valid, 'invalid_reason' => $valid ? '' : 'mehrdeutig', 'results' => $results]));
