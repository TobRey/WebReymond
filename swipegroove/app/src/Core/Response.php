<?php
declare(strict_types=1);

namespace SwipeGroove\Core;

final class Response
{
    public int $status = 200;
    /** @var array<string,string> */
    public array $headers = [];
    public string $body = '';
    /** @var callable|null */
    private $streamer = null;

    public static function json(mixed $data, int $status = 200): self
    {
        $r = new self();
        $r->status = $status;
        $r->headers['Content-Type'] = 'application/json; charset=utf-8';
        $r->headers['Cache-Control'] = 'no-store';
        $r->body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
        return $r;
    }

    public static function html(string $html, int $status = 200): self
    {
        $r = new self();
        $r->status = $status;
        $r->headers['Content-Type'] = 'text/html; charset=utf-8';
        $r->body = $html;
        return $r;
    }

    public static function text(string $text, int $status = 200): self
    {
        $r = new self();
        $r->status = $status;
        $r->headers['Content-Type'] = 'text/plain; charset=utf-8';
        $r->body = $text;
        return $r;
    }

    public static function redirect(string $url, int $status = 302): self
    {
        $r = new self();
        $r->status = $status;
        $r->headers['Location'] = $url;
        $r->headers['Cache-Control'] = 'no-store';
        return $r;
    }

    public static function empty(int $status = 204): self
    {
        $r = new self();
        $r->status = $status;
        return $r;
    }

    public static function file(string $path, string $contentType, ?string $downloadName = null, int $maxAge = 0): self
    {
        $r = new self();
        $r->headers['Content-Type'] = $contentType;
        $r->headers['Content-Length'] = (string) filesize($path);
        $r->headers['X-Content-Type-Options'] = 'nosniff';
        $r->headers['Cache-Control'] = $maxAge > 0 ? 'private, max-age=' . $maxAge : 'no-store';
        if ($downloadName !== null) {
            $r->headers['Content-Disposition'] = 'attachment; filename="' . rawurlencode($downloadName) . '"';
        }
        $r->streamer = static function () use ($path): void {
            readfile($path);
        };
        return $r;
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $k => $v) {
            header($k . ': ' . $v);
        }
        if ($this->streamer !== null) {
            ($this->streamer)();
            return;
        }
        echo $this->body;
    }
}
