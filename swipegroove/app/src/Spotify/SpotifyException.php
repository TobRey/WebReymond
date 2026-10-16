<?php
declare(strict_types=1);

namespace SwipeGroove\Spotify;

/**
 * Fehler der Spotify-API, mit maschinenlesbarem Grund für ehrliche UI-Zustände.
 */
final class SpotifyException extends \RuntimeException
{
    public const NOT_CONNECTED = 'not_connected';
    public const REVOKED = 'revoked';          // Refresh-Token ungültig/abgelaufen → erneut verbinden
    public const RATE_LIMITED = 'rate_limited';
    public const PREMIUM_REQUIRED = 'premium_required';
    public const FORBIDDEN = 'forbidden';
    public const NOT_FOUND = 'not_found';
    public const BAD_REQUEST = 'bad_request';
    public const UNAVAILABLE = 'unavailable';  // 5xx / Netzwerk
    public const UNKNOWN = 'unknown';

    public function __construct(
        public readonly string $reason,
        public readonly int $status = 0,
        string $message = '',
        public readonly int $retryAfter = 0,
        public readonly ?string $spotifyMessage = null,
    ) {
        parent::__construct($message !== '' ? $message : 'Spotify: ' . $reason, $status);
    }

    public function errorCode(): string
    {
        return 'spotify.' . $this->reason;
    }
}
