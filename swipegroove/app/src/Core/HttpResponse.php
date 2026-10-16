<?php
declare(strict_types=1);

namespace SwipeGroove\Core;

final class HttpResponse
{
    /** @param array<string,string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** @return array<string,mixed> */
    public function json(): array
    {
        $d = json_decode($this->body, true);
        return is_array($d) ? $d : [];
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
