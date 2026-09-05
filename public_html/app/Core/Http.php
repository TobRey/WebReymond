<?php

declare(strict_types=1);

namespace WebAtze\Core;

/**
 * Ausgehende Anfragen ins Internet – mit Schutz vor SSRF.
 *
 * SSRF heisst: jemand gibt beim "alte Website übernehmen" eine Adresse an,
 * die auf den Server selbst oder ins interne Netz zeigt (z.B. 127.0.0.1 oder
 * die Metadaten-Adresse eines Cloud-Anbieters) und liest so Dinge aus, die
 * er nicht sehen darf. Deshalb wird jede Zieladresse vorher aufgelöst und
 * gegen private Bereiche geprüft – auch bei jeder Weiterleitung.
 */
final class Http
{
    private const MAX_REDIRECTS = 4;
    private const MAX_BYTES = 5_000_000;

    /**
     * @return array{ok:bool,status:int,body:string,headers:array<string,string>,url:string,error:string}
     */
    public static function get(string $url, int $timeout = 15, array $extraHeaders = []): array
    {
        $result = [
            'ok' => false, 'status' => 0, 'body' => '', 'headers' => [], 'url' => $url, 'error' => '',
        ];

        $current = $url;

        for ($redirect = 0; $redirect <= self::MAX_REDIRECTS; $redirect++) {
            $check = self::assertPublicUrl($current);
            if ($check !== null) {
                $result['error'] = $check;
                return $result;
            }

            $response = self::request($current, $timeout, $extraHeaders);
            if ($response['error'] !== '') {
                return array_merge($result, $response, ['url' => $current]);
            }

            $status = $response['status'];
            $location = $response['headers']['location'] ?? '';

            if (in_array($status, [301, 302, 303, 307, 308], true) && $location !== '') {
                $current = self::resolveUrl($current, $location);
                continue;
            }

            return [
                'ok' => $status >= 200 && $status < 300,
                'status' => $status,
                'body' => $response['body'],
                'headers' => $response['headers'],
                'url' => $current,
                'error' => '',
            ];
        }

        $result['error'] = 'Zu viele Weiterleitungen.';
        return $result;
    }

    /** JSON an eine Adresse senden (für die Claude-API). */
    /**
     * Einen fertigen Rumpf unverändert schicken.
     *
     * Gebraucht wird das dort, wo eine Unterschrift genau die
     * gesendeten Bytes deckt (Domain\Bridge). Würde der Rumpf hier noch
     * einmal erzeugt, hinge die Gültigkeit der Unterschrift daran, dass
     * zwei Stellen json_encode mit denselben Schaltern aufrufen - und
     * das hält genau so lange, bis jemand einen davon ändert.
     *
     * @return array{ok:bool, status:int, body:string, error:string}
     */
    public static function postRaw(
        string $url,
        string $body,
        array $headers = [],
        int $timeout = 60
    ): array {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'WebAtze/' . WEBATZE_VERSION,
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return [
                'ok' => false,
                'status' => 0,
                'body' => '',
                'error' => $error ?: 'Verbindung fehlgeschlagen.',
            ];
        }

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'body' => (string) $raw,
            'error' => $status >= 400 ? 'Die Gegenseite antwortet mit ' . $status . '.' : '',
        ];
    }

    public static function postJson(string $url, array $payload, array $headers = [], int $timeout = 120): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
            CURLOPT_USERAGENT => 'WebAtze/' . WEBATZE_VERSION,
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return ['ok' => false, 'status' => 0, 'data' => [], 'error' => $error ?: 'Verbindung fehlgeschlagen.'];
        }

        $decoded = json_decode((string) $raw, true);

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'data' => is_array($decoded) ? $decoded : [],
            'raw' => (string) $raw,
            'error' => '',
        ];
    }

    /**
     * Prüft, ob eine Adresse öffentlich erreichbar ist.
     * Gibt null zurück, wenn alles in Ordnung ist, sonst den Grund.
     */
    public static function assertPublicUrl(string $url): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return 'Die Adresse ist unvollständig.';
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return 'Nur http und https sind erlaubt.';
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (!in_array($port, [80, 443, 8080, 8443], true)) {
            return 'Dieser Port ist nicht erlaubt.';
        }

        $host = $parts['host'];

        // Namen wie "localhost" gar nicht erst auflösen
        if (preg_match('/^(localhost|.*\.local|.*\.internal|.*\.localdomain)$/i', $host)) {
            return 'Adressen im lokalen Netz sind nicht erlaubt.';
        }

        $addresses = self::resolveHost($host);
        if ($addresses === []) {
            return 'Der Name konnte nicht aufgelöst werden.';
        }

        foreach ($addresses as $ip) {
            if (!self::isPublicIp($ip)) {
                return 'Diese Adresse zeigt in ein privates Netz und wird aus Sicherheitsgründen nicht abgerufen.';
            }
        }

        return null;
    }

    public static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            // Zusätzlich zu den Standardbereichen: Cloud-Metadaten und CGNAT
            $long = ip2long($ip);
            if ($long === false) {
                return false;
            }
            $blocked = [
                ['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8],
                ['169.254.0.0', 16], ['172.16.0.0', 12], ['192.0.0.0', 24], ['192.0.2.0', 24],
                ['192.168.0.0', 16], ['198.18.0.0', 15], ['198.51.100.0', 24],
                ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4],
            ];
            foreach ($blocked as [$net, $bits]) {
                $netLong = ip2long($net);
                if ($netLong === false) {
                    continue;
                }
                $mask = $bits === 0 ? 0 : (-1 << (32 - $bits)) & 0xFFFFFFFF;
                if (($long & $mask) === ($netLong & $mask)) {
                    return false;
                }
            }
            return true;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return (bool) filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
        }

        return false;
    }

    /** @return list<string> */
    public static function resolveHost(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $addresses = [];

        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $addresses = array_merge($addresses, $v4);
        }

        $records = @dns_get_record($host, DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ipv6'])) {
                    $addresses[] = (string) $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($addresses));
    }

    /** Relative Weiterleitungsziele zu einer vollständigen Adresse machen. */
    public static function resolveUrl(string $base, string $relative): string
    {
        if (preg_match('#^https?://#i', $relative)) {
            return $relative;
        }

        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $root = $scheme . '://' . $host . $port;

        if (str_starts_with($relative, '//')) {
            return $scheme . ':' . $relative;
        }
        if (str_starts_with($relative, '/')) {
            return $root . $relative;
        }

        $path = $parts['path'] ?? '/';
        $dir = rtrim(str_contains($path, '/') ? substr($path, 0, strrpos($path, '/') + 1) : '/', '/');

        return $root . $dir . '/' . ltrim($relative, '/');
    }

    /** @return array{status:int,body:string,headers:array<string,string>,error:string} */
    private static function request(string $url, int $timeout, array $extraHeaders): array
    {
        $headers = [];
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            // Weiterleitungen bewusst selbst verfolgen, damit jedes Ziel geprüft wird.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; WebAtze/' . WEBATZE_VERSION . '; +https://webatze.ch)',
            CURLOPT_HTTPHEADER => array_merge(['Accept-Language: de,en;q=0.8'], $extraHeaders),
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                $length = strlen($line);
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }
                return $length;
            },
            CURLOPT_BUFFERSIZE => 65536,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static function ($res, $downloadSize, $downloaded): int {
                return $downloaded > self::MAX_BYTES ? 1 : 0;
            },
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($body === false) {
            $message = $errno === CURLE_ABORTED_BY_CALLBACK
                ? 'Die Seite ist zu gross (mehr als 5 MB).'
                : ($error ?: 'Die Seite konnte nicht geladen werden.');
            return ['status' => $status, 'body' => '', 'headers' => $headers, 'error' => $message];
        }

        return ['status' => $status, 'body' => (string) $body, 'headers' => $headers, 'error' => ''];
    }
}
