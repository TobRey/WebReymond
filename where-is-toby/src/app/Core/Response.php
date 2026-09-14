<?php
declare(strict_types=1);

namespace App\Core;

final class Response
{
    private array $headers = [];

    private function __construct(
        private string $body = '',
        private int $status = 200,
        private string $contentType = 'text/html; charset=utf-8'
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, 'text/html; charset=utf-8');
    }

    public static function json(array $data, int $status = 200): self
    {
        return new self(Json::encode($data), $status, 'application/json; charset=utf-8');
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($body, $status, 'text/plain; charset=utf-8');
    }

    public static function raw(string $body, string $contentType, int $status = 200): self
    {
        return new self($body, $status, $contentType);
    }

    public static function redirect(string $location, int $status = 302): self
    {
        $response = new self('', $status);
        $base = defined('WIT_BASE_PATH') ? WIT_BASE_PATH : '';
        if (str_starts_with($location, '/')) {
            $location = $base . $location;
        }
        // Nur interne Weiterleitungen zulassen (Open-Redirect-Schutz)
        if (preg_match('~^(https?:)?//~i', $location)) {
            $location = $base . '/';
        }
        $response->headers['Location'] = $location;
        return $response;
    }

    public static function download(string $body, string $filename, string $contentType = 'application/octet-stream'): self
    {
        $response = new self($body, 200, $contentType);
        $safe = preg_replace('~[^A-Za-z0-9._-]~', '_', $filename) ?? 'download.bin';
        $response->headers['Content-Disposition'] = 'attachment; filename="' . $safe . '"';
        return $response;
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            header('Content-Type: ' . $this->contentType);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }
        echo $this->body;
    }
}
