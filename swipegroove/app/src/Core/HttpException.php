<?php
declare(strict_types=1);

namespace SwipeGroove\Core;

/**
 * Fehler mit HTTP-Status und übersetzbarem Fehlercode.
 */
class HttpException extends \RuntimeException
{
    /** @param array<string,mixed> $extra */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        ?string $message = null,
        public readonly array $extra = [],
        public readonly array $messageParams = [],
    ) {
        parent::__construct($message ?? $errorCode, $status);
    }

    public static function badRequest(string $code = 'error.bad_request', array $extra = [], array $params = []): self
    {
        return new self(400, $code, null, $extra, $params);
    }

    public static function unauthorized(string $code = 'error.unauthorized'): self
    {
        return new self(401, $code);
    }

    public static function forbidden(string $code = 'error.forbidden', array $extra = []): self
    {
        return new self(403, $code, null, $extra);
    }

    public static function notFound(string $code = 'error.not_found'): self
    {
        return new self(404, $code);
    }

    public static function conflict(string $code = 'error.conflict', array $extra = []): self
    {
        return new self(409, $code, null, $extra);
    }

    public static function tooMany(string $code = 'error.rate_limited', int $retryAfter = 60): self
    {
        return new self(429, $code, null, ['retry_after' => $retryAfter]);
    }

    public static function validation(array $fields): self
    {
        return new self(422, 'error.validation', null, ['fields' => $fields]);
    }
}
