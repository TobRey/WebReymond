<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Gekapselter HTTP-Request inkl. JSON-Body-Parsing und Eingabefilterung.
 */
final class Request
{
    private array $query;
    private array $post;
    private array $json;
    private array $server;
    private array $files;

    private function __construct()
    {
        $this->query = $_GET ?? [];
        $this->post = $_POST ?? [];
        $this->server = $_SERVER ?? [];
        $this->files = $_FILES ?? [];
        $this->json = [];

        $contentType = strtolower((string)($this->server['CONTENT_TYPE'] ?? ''));
        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input');
            if (is_string($raw) && $raw !== '') {
                $this->json = Json::decode($raw) ?? [];
            }
        }
    }

    public static function capture(): self
    {
        return new self();
    }

    public function method(): string
    {
        $method = strtoupper((string)($this->server['REQUEST_METHOD'] ?? 'GET'));
        return preg_match('~^[A-Z]{3,7}$~', $method) ? $method : 'GET';
    }

    public function path(): string
    {
        $uri = (string)($this->server['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';
        $base = defined('WIT_BASE_PATH') ? WIT_BASE_PATH : '';
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }
        $path = '/' . trim(rawurldecode($path), '/');
        return $path === '/' ? '/' : rtrim($path, '/');
    }

    public function isPost(): bool
    {
        return $this->method() === 'POST';
    }

    public function wantsJson(): bool
    {
        $accept = (string)($this->server['HTTP_ACCEPT'] ?? '');
        return str_contains($accept, 'application/json')
            || strtolower((string)($this->server['HTTP_X_REQUESTED_WITH'] ?? '')) === 'fetch'
            || str_starts_with($this->path(), '/api/');
    }

    /** Liest einen Wert aus JSON-Body, POST oder Query (in dieser Reihenfolge). */
    public function input(string $key, mixed $default = null): mixed
    {
        foreach ([$this->json, $this->post, $this->query] as $source) {
            if (array_key_exists($key, $source)) {
                return $source[$key];
            }
        }
        return $default;
    }

    public function str(string $key, string $default = '', int $maxLength = 4000): string
    {
        $value = $this->input($key, $default);
        if (is_array($value) || is_object($value)) {
            return $default;
        }
        $value = (string)$value;
        $value = str_replace(["\0", "\r"], '', $value);
        return mb_substr($value, 0, $maxLength);
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->input($key, $default);
        return is_numeric($value) ? (int)$value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->input($key, $default);
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'on', 'yes', 'ja'], true);
        }
        return (bool)$value;
    }

    public function arr(string $key): array
    {
        $value = $this->input($key, []);
        return is_array($value) ? $value : [];
    }

    public function all(): array
    {
        return array_merge($this->query, $this->post, $this->json);
    }

    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;
        return is_array($file) ? $file : null;
    }

    public function header(string $name): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return (string)($this->server[$key] ?? '');
    }

    public function userAgent(): string
    {
        return mb_substr((string)($this->server['HTTP_USER_AGENT'] ?? ''), 0, 250);
    }

    public function ip(): string
    {
        return Environment::clientIp();
    }
}
