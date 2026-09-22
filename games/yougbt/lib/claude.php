<?php
// YouGBT – Anbindung an die Anthropic Messages API (serverseitig, per cURL; kein Composer nötig).
declare(strict_types=1);

const YG_API_VERSION = '2023-06-01';

function yg_api_base(): string
{
    // Nur für lokale Tests per Umgebungsvariable umbiegbar – nicht über das Web änderbar.
    $env = getenv('YOUGBT_API_BASE');
    return is_string($env) && $env !== '' ? rtrim($env, '/') : 'https://api.anthropic.com';
}

/** Low-level HTTP-Aufruf. Gibt [statusCode, decodedJson|null] zurück. */
function yg_http(string $method, string $path, string $apiKey, ?array $body = null, int $timeout = 55): array
{
    if (!function_exists('curl_init')) {
        throw new YgError('php_curl_missing', 500);
    }
    $ch = curl_init(yg_api_base() . $path);
    $headers = [
        'x-api-key: ' . $apiKey,
        'anthropic-version: ' . YG_API_VERSION,
        'content-type: application/json',
        'accept: application/json',
    ];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_errno($ch);
    curl_close($ch);
    if ($raw === false || $err) {
        return [0, null];
    }
    $json = json_decode((string) $raw, true);
    return [$status, is_array($json) ? $json : null];
}

/** Liste der für den Schlüssel verfügbaren Modelle (prüft zugleich den Schlüssel). */
function yg_list_models(string $apiKey): array
{
    [$status, $json] = yg_http('GET', '/v1/models?limit=100', $apiKey, null, 20);
    if ($status === 401 || $status === 403) {
        throw new YgError('api_key_invalid', 400);
    }
    if ($status !== 200 || !isset($json['data']) || !is_array($json['data'])) {
        throw new YgError('api_unreachable', 502);
    }
    $out = [];
    foreach ($json['data'] as $m) {
        $id = (string) ($m['id'] ?? '');
        if (yg_valid_model_id($id)) {
            $out[] = ['id' => $id, 'name' => yg_clean_text((string) ($m['display_name'] ?? $id), 80)];
        }
    }
    return $out;
}

/** Modellspezifische Parameter: Effort nur dort senden, wo er unterstützt wird. */
function yg_model_extras(string $model): array
{
    if (preg_match('/^claude-(opus|sonnet|fable)-(4-[6-9]|5)/', $model)) {
        return ['output_config' => ['effort' => 'low']];
    }
    return [];
}

/**
 * Ruft Claude auf und liefert den Textinhalt. Zählt gegen das Tageslimit.
 * Fehler werden als YgError mit neutralem Code geworfen – nie mit API-Details oder Schlüssel.
 */
function yg_claude_text(string $system, string $user, int $maxTokens): string
{
    $key = (string) yg_cfg('api_key');
    $model = (string) yg_cfg('model');
    if ($key === '' || !yg_valid_model_id($model)) {
        throw new YgError('not_configured', 503);
    }
    yg_reserve_ai_call();
    $body = [
        'model' => $model,
        'max_tokens' => $maxTokens,
        'system' => $system,
        'messages' => [['role' => 'user', 'content' => $user]],
    ] + yg_model_extras($model);

    [$status, $json] = yg_http('POST', '/v1/messages', $key, $body);
    if ($status === 400 && isset($body['output_config'])) {
        // Modell kennt "effort" nicht – einmal ohne Zusatzparameter wiederholen
        unset($body['output_config']);
        [$status, $json] = yg_http('POST', '/v1/messages', $key, $body);
    }
    if ($status !== 200 || !is_array($json)) {
        yg_note_ai_error();
        $code = match (true) {
            $status === 0 => 'ai_unreachable',
            $status === 401 || $status === 403 => 'ai_auth',
            $status === 429 => 'ai_rate',
            $status === 529 || $status >= 500 => 'ai_overloaded',
            default => 'ai_error',
        };
        throw new YgError($code, 502);
    }
    if (($json['stop_reason'] ?? '') === 'refusal') {
        throw new YgError('ai_refused', 502);
    }
    $text = '';
    foreach (($json['content'] ?? []) as $block) {
        if (is_array($block) && ($block['type'] ?? '') === 'text') {
            $text .= (string) ($block['text'] ?? '');
        }
    }
    if (trim($text) === '') {
        throw new YgError('ai_bad_output', 502);
    }
    return $text;
}

/** Extrahiert das erste JSON-Objekt aus einer (untrusted) Modellantwort. */
function yg_extract_json(string $text): ?array
{
    $text = trim($text);
    $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text) ?? $text;
    $start = strpos($text, '{');
    $end = strrpos($text, '}');
    if ($start === false || $end === false || $end <= $start) {
        return null;
    }
    $data = json_decode(substr($text, $start, $end - $start + 1), true);
    return is_array($data) ? $data : null;
}

/**
 * Ruft Claude auf, erwartet JSON und prüft es mit $validate (gibt bereinigtes Array oder null zurück).
 * Bei ungültiger Struktur wird genau einmal wiederholt.
 */
function yg_claude_json(string $system, string $user, int $maxTokens, callable $validate): array
{
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $text = yg_claude_text($system, $user, $maxTokens);
        $data = yg_extract_json($text);
        $clean = $data !== null ? $validate($data) : null;
        if (is_array($clean)) {
            return $clean;
        }
        $user .= "\n\nIMPORTANT: Your previous reply was not valid according to the required JSON schema. Reply with ONLY the JSON object.";
    }
    throw new YgError('ai_bad_output', 502);
}
