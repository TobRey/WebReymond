<?php
declare(strict_types=1);

namespace App\Core;

class HttpException extends \RuntimeException
{
    public function __construct(private int $statusCode = 500, string $message = 'Fehler', ?\Throwable $previous = null)
    {
        parent::__construct($message, $statusCode, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public static function notFound(string $message = 'Seite nicht gefunden.'): self
    {
        return new self(404, $message);
    }

    public static function forbidden(string $message = 'Kein Zugriff.'): self
    {
        return new self(403, $message);
    }

    public static function badRequest(string $message = 'Ungueltige Anfrage.'): self
    {
        return new self(400, $message);
    }

    public static function tooMany(string $message = 'Zu viele Anfragen. Bitte kurz warten.'): self
    {
        return new self(429, $message);
    }

    public static function unauthorized(string $message = 'Anmeldung erforderlich.'): self
    {
        return new self(401, $message);
    }
}
