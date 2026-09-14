<?php
declare(strict_types=1);

namespace App\Service\Ai;

final class AiResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $text = '',
        public readonly string $error = '',
        public readonly string $provider = '',
        public readonly string $model = '',
        public readonly float $duration = 0.0,
        public readonly int $status = 0
    ) {
    }

    public static function success(string $text, string $provider, string $model, float $duration): self
    {
        return new self(true, $text, '', $provider, $model, $duration);
    }

    public static function failure(string $error, string $provider = '', int $status = 0): self
    {
        return new self(false, '', $error, $provider, '', 0.0, $status);
    }
}
