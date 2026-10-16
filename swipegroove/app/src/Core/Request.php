<?php
declare(strict_types=1);

namespace SwipeGroove\Core;

final class Request
{
    public string $method;
    public string $path;        // Pfad ohne Basis-Pfad, beginnt mit '/'
    public string $fullPath;    // Pfad inkl. Basis-Pfad
    /** @var array<string,mixed> */
    public array $query;
    /** @var array<string,mixed>|null */
    private ?array $json = null;
    private bool $jsonParsed = false;
    public string $rawBody = '';
    /** @var array<string,string> */
    public array $headers = [];
    public string $ip;
    public ?object $user = null;
    public ?string $sessionId = null;
    public ?string $sessionToken = null;
    public array $attributes = [];

    public static function fromGlobals(string $basePath): self
    {
        $r = new self();
        $r->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?? '/');
        $path = rawurldecode($path);
        $r->fullPath = $path;
        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
        }
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }
        // index.php im Pfad tolerieren (falls ohne mod_rewrite aufgerufen)
        if (str_starts_with($path, '/index.php')) {
            $path = substr($path, strlen('/index.php')) ?: '/';
        }
        $r->path = preg_replace('#/+#', '/', $path) ?? '/';
        if (strlen($r->path) > 1) {
            $r->path = rtrim($r->path, '/') ?: '/';
        }
        $r->query = $_GET;
        $r->rawBody = (string) file_get_contents('php://input');
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($k, 5)));
                $r->headers[$name] = (string) $v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $r->headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        $r->ip = self::clientIp();
        return $r;
    }

    private static function clientIp(): string
    {
        // GoDaddy/cPanel: REMOTE_ADDR ist verlässlich; Proxy-Header nur als Zusatzinfo.
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function isJson(): bool
    {
        return str_contains(strtolower($this->header('content-type') ?? ''), 'application/json');
    }

    /** @return array<string,mixed> */
    public function json(): array
    {
        if (!$this->jsonParsed) {
            $this->jsonParsed = true;
            $decoded = json_decode($this->rawBody, true);
            $this->json = is_array($decoded) ? $decoded : [];
        }
        return $this->json ?? [];
    }

    /** Liest einen Wert aus JSON-Body, Formulardaten oder Query. */
    public function input(string $key, mixed $default = null): mixed
    {
        $json = $this->json();
        if (array_key_exists($key, $json)) {
            return $json[$key];
        }
        if (array_key_exists($key, $_POST)) {
            return $_POST[$key];
        }
        return $this->query[$key] ?? $default;
    }

    public function str(string $key, string $default = ''): string
    {
        $v = $this->input($key, $default);
        if (is_array($v) || $v === null) {
            return $default;
        }
        return trim((string) $v);
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->input($key, $default);
        return is_numeric($v) ? (int) $v : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $v = $this->input($key, $default);
        if (is_bool($v)) {
            return $v;
        }
        if (is_string($v)) {
            return in_array(strtolower($v), ['1', 'true', 'on', 'yes'], true);
        }
        return (bool) $v;
    }

    /** @return array<int|string,mixed> */
    public function arr(string $key): array
    {
        $v = $this->input($key, []);
        return is_array($v) ? $v : [];
    }

    public function queryStr(string $key, string $default = ''): string
    {
        $v = $this->query[$key] ?? $default;
        return is_array($v) ? $default : trim((string) $v);
    }

    public function queryInt(string $key, int $default = 0): int
    {
        $v = $this->query[$key] ?? null;
        return is_numeric($v) ? (int) $v : $default;
    }

    public function userId(): int
    {
        return $this->user ? (int) $this->user->id : 0;
    }
}
