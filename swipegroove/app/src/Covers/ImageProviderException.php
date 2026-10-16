<?php
declare(strict_types=1);

namespace SwipeGroove\Covers;

final class ImageProviderException extends \RuntimeException
{
    public function __construct(
        public readonly string $reason, // content_policy | rate_limited | auth | unavailable | invalid | unknown
        string $message = '',
        public readonly int $status = 0,
        public readonly bool $billed = false,
    ) {
        parent::__construct($message !== '' ? $message : $reason);
    }
}
