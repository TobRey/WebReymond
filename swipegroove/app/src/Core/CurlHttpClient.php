<?php
declare(strict_types=1);

namespace SwipeGroove\Core;

final class CurlHttpClient implements HttpClientInterface
{
    public function request(string $method, string $url, array $headers = [], ?string $body = null, array $options = []): HttpResponse
    {
        $ch = curl_init();
        $hdrs = [];
        foreach ($headers as $k => $v) {
            $hdrs[] = $k . ': ' . $v;
        }
        $hdrs[] = 'User-Agent: SwipeGroove/' . SG_VERSION;
        $respHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $hdrs,
            CURLOPT_TIMEOUT => $options['timeout'] ?? 25,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$respHeaders): int {
                $len = strlen($line);
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return $len;
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $result = curl_exec($ch);
        if ($result === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('HTTP-Verbindung fehlgeschlagen: ' . $err);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return new HttpResponse($status, $respHeaders, (string) $result);
    }
}
