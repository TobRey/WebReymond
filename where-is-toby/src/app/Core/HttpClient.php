<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Ausgehende HTTPS-Aufrufe (KI-Anbieter) mit Timeout, Retry und Fehlerbehandlung.
 * Nutzt cURL, faellt notfalls auf Streams zurueck.
 */
final class HttpClient
{
    public function __construct(
        private int $timeout = 30,
        private int $retries = 2,
        private int $connectTimeout = 10
    ) {
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int,body:string,error:string,duration:float}
     */
    public function postJson(string $url, array $payload, array $headers = []): array
    {
        $body = Json::encode($payload);
        $headers['Content-Type'] = 'application/json';
        $headers['Accept'] = 'application/json';
        return $this->request('POST', $url, $body, $headers);
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int,body:string,error:string,duration:float}
     */
    public function request(string $method, string $url, ?string $body, array $headers = []): array
    {
        if (!preg_match('~^https?://~i', $url)) {
            return ['status' => 0, 'body' => '', 'error' => 'Ungueltige API-URL.', 'duration' => 0.0];
        }

        $attempt = 0;
        $lastError = '';
        $start = microtime(true);

        while ($attempt <= $this->retries) {
            $attempt++;
            $result = $this->send($method, $url, $body, $headers);

            if ($result['status'] >= 200 && $result['status'] < 300) {
                $result['duration'] = microtime(true) - $start;
                return $result;
            }

            // Nur bei Netzwerkfehlern, 429 und 5xx erneut versuchen
            $retryable = $result['status'] === 0 || $result['status'] === 429 || $result['status'] >= 500;
            $lastError = $result['error'] !== '' ? $result['error'] : ('HTTP ' . $result['status']);
            if (!$retryable || $attempt > $this->retries) {
                $result['duration'] = microtime(true) - $start;
                $result['error'] = $lastError;
                return $result;
            }
            usleep((int)(250000 * (2 ** ($attempt - 1)))); // 0,25s / 0,5s / 1s
        }

        return ['status' => 0, 'body' => '', 'error' => $lastError, 'duration' => microtime(true) - $start];
    }

    /** @return array{status:int,body:string,error:string,duration:float} */
    private function send(string $method, string $url, ?string $body, array $headers): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return ['status' => 0, 'body' => '', 'error' => 'cURL konnte nicht initialisiert werden.', 'duration' => 0.0];
            }
            $headerLines = [];
            foreach ($headers as $name => $value) {
                $headerLines[] = $name . ': ' . $value;
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_HTTPHEADER     => $headerLines,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT      => 'WhereIsToby/' . WIT_VERSION,
            ]);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            $response = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = $response === false ? (string)curl_error($ch) : '';
            curl_close($ch);
            return [
                'status'   => $status,
                'body'     => is_string($response) ? $response : '',
                'error'    => $error,
                'duration' => 0.0,
            ];
        }

        // Fallback ohne cURL
        $headerLines = '';
        foreach ($headers as $name => $value) {
            $headerLines .= $name . ': ' . $value . "\r\n";
        }
        $context = stream_context_create([
            'http' => [
                'method'        => $method,
                'header'        => $headerLines,
                'content'       => $body ?? '',
                'timeout'       => $this->timeout,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $response = @file_get_contents($url, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m)) {
                $status = (int)$m[1];
            }
        }
        return [
            'status'   => $status,
            'body'     => is_string($response) ? $response : '',
            'error'    => $response === false ? 'Verbindung fehlgeschlagen.' : '',
            'duration' => 0.0,
        ];
    }
}
