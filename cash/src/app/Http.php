<?php
declare(strict_types=1);

final class ApiError extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 400)
    {
        parent::__construct($message);
    }
}

final class Http
{
    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if ((string)($_SERVER['SERVER_PORT'] ?? '') === '443') {
            return true;
        }
        $proto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        return $proto === 'https';
    }

    public static function isLocalHost(): bool
    {
        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        $host = preg_replace('/:\d+$/', '', $host);
        return in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
    }

    /** Basis-Pfad der Anwendung, z. B. "/cash/". */
    public static function basePath(): string
    {
        $script = (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php');
        $dir = str_replace('\\', '/', dirname($script));
        $dir = rtrim($dir, '/');
        return $dir . '/';
    }

    public static function clientIp(): string
    {
        return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public static function securityHeaders(bool $html): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; font-src 'self'; manifest-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
        if (!$html) {
            header('Cache-Control: no-store, max-age=0');
            header('Pragma: no-cache');
        } else {
            header('Cache-Control: no-cache, must-revalidate');
        }
    }

    public static function forceHttps(): void
    {
        $cfg = cash_local_config();
        $force = $cfg['force_https'] ?? true;
        if ($force && !self::isHttps() && !self::isLocalHost() && PHP_SAPI !== 'cli') {
            $host = (string)($_SERVER['HTTP_HOST'] ?? '');
            if (preg_match('/^[a-z0-9.\-:\[\]]+$/i', $host)) {
                $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
                header('Location: https://' . $host . $uri, true, 301);
                exit;
            }
        }
    }

    public static function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }

    public static function body(int $maxBytes = 12_000_000): array
    {
        $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($len > $maxBytes) {
            throw new ApiError('Die Anfrage ist zu gross.', 413);
        }
        $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
        if ($raw === false || $raw === '') {
            return [];
        }
        if (strlen($raw) > $maxBytes) {
            throw new ApiError('Die Anfrage ist zu gross.', 413);
        }
        try {
            $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ApiError('Ungültige Anfrage.', 400);
        }
        if (!is_array($data)) {
            throw new ApiError('Ungültige Anfrage.', 400);
        }
        return $data;
    }

    /** Blockiert Anfragen von fremden Websites (zusätzlich zum CSRF-Token). */
    public static function checkSameOrigin(): void
    {
        $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($origin === '' || $origin === 'null') {
            if ($origin === 'null') {
                throw new ApiError('Anfrage abgelehnt.', 403);
            }
            return;
        }
        $host = parse_url($origin, PHP_URL_HOST);
        $port = parse_url($origin, PHP_URL_PORT);
        $originHost = strtolower((string)$host) . ($port ? ':' . $port : '');
        $reqHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        $reqHostNoDefault = preg_replace('/:(80|443)$/', '', $reqHost);
        if ($originHost !== $reqHost && $originHost !== $reqHostNoDefault) {
            throw new ApiError('Anfrage abgelehnt (fremde Herkunft).', 403);
        }
    }
}
