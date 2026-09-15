<?php
/**
 * Gemeinsame Hilfsfunktionen der Testskripte (HTTP, Sitzung, Pruefungen).
 */
declare(strict_types=1);

function section(string $title): void
{
    echo "\n\033[1m== " . $title . "\033[0m\n";
}

function check(string $label, bool $condition, string $detail = ''): bool
{
    if ($condition) {
        $GLOBALS['tests']['ok']++;
        echo "  \033[32m[OK]\033[0m   " . $label . "\n";
    } else {
        $GLOBALS['tests']['fail']++;
        $GLOBALS['tests']['messages'][] = $label . ($detail !== '' ? ' -> ' . $detail : '');
        echo "  \033[31m[FEHL]\033[0m " . $label . ($detail !== '' ? "\n         " . $detail : '') . "\n";
    }
    return $condition;
}

function newSession(): void
{
    @unlink($GLOBALS['jar']);
    $GLOBALS['jar'] = sys_get_temp_dir() . '/wit_cookies_' . bin2hex(random_bytes(4)) . '.txt';
}

/**
 * @return array{status:int,body:string,headers:string}
 */
function http(string $method, string $path, array|string|null $data = null, array $options = []): array
{
    $url = str_starts_with($path, 'http') ? $path : $GLOBALS['BASE'] . $path;
    $ch = curl_init($url);
    $headers = ['Accept: ' . ($options['json'] ?? false ? 'application/json' : 'text/html')];

    if (!empty($options['csrf'])) {
        $headers[] = 'X-CSRF-Token: ' . $options['csrf'];
    }
    if (!empty($options['jsonBody'])) {
        $headers[] = 'Content-Type: application/json';
        $data = json_encode($data);
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_COOKIEJAR      => $GLOBALS['jar'],
        CURLOPT_COOKIEFILE     => $GLOBALS['jar'],
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 60,
    ]);
    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($data) ? http_build_query($data) : $data);
    }
    $raw = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    return [
        'status'  => $status,
        'headers' => substr($raw, 0, $headerSize),
        'body'    => substr($raw, $headerSize),
    ];
}

function csrfFrom(string $body): string
{
    if (preg_match('~name="_csrf" value="([^"]+)"~', $body, $m)) {
        return $m[1];
    }
    if (preg_match('~"csrf":"([^"]+)"~', $body, $m)) {
        return str_replace('\\/', '/', $m[1]);
    }
    return '';
}

function api(string $path, array $payload = [], string $csrf = '', string $method = 'POST'): array
{
    $response = http($method, $path, $method === 'GET' ? null : $payload, ['json' => true, 'jsonBody' => $method !== 'GET', 'csrf' => $csrf]);
    $data = json_decode($response['body'], true);
    return is_array($data) ? $data + ['_status' => $response['status']] : ['_status' => $response['status'], '_raw' => $response['body']];
}

