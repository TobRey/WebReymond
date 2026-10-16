<?php
declare(strict_types=1);

namespace SwipeGroove\Spotify;

use SwipeGroove\Core\App;
use SwipeGroove\Core\Crypto;

/**
 * Spotify OAuth 2.0 Authorization Code mit PKCE. Es werden nie Spotify-Passwörter verarbeitet.
 */
final class SpotifyAuth
{
    public const AUTH_URL = 'https://accounts.spotify.com/authorize';
    public const TOKEN_URL = 'https://accounts.spotify.com/api/token';

    public const BASE_SCOPES = [
        'playlist-read-private',
        'playlist-read-collaborative',
        'playlist-modify-public',
        'playlist-modify-private',
        'ugc-image-upload',
    ];
    public const PLAYBACK_SCOPES = [
        'user-read-playback-state',
        'user-modify-playback-state',
    ];

    public const BROWSER_COOKIE = 'sg_oauth';
    /** Zuletzt erzeugte Browser-Nonce (für Tests). */
    public ?string $lastNonce = null;

    public function __construct(private App $app)
    {
    }

    public function clientId(): string
    {
        return $this->app->config?->string('spotify.client_id') ?? '';
    }

    /** Basis der Accounts-API (nur für lokale Tests überschreibbar; Standard: accounts.spotify.com). */
    public function accountsBase(): string
    {
        $v = rtrim($this->app->config?->string('spotify.accounts_base_url') ?? '', '/');
        return $v !== '' ? $v : 'https://accounts.spotify.com';
    }

    public function isConfigured(): bool
    {
        return $this->clientId() !== '';
    }

    public function redirectUri(): string
    {
        return $this->app->url->to('/auth/spotify/callback');
    }

    /** @return list<string> */
    public function scopes(): array
    {
        $scopes = self::BASE_SCOPES;
        if ($this->app->settings()->str('playback_mode') === 'connect') {
            $scopes = array_merge($scopes, self::PLAYBACK_SCOPES);
        }
        return $scopes;
    }

    /**
     * Erzeugt State + PKCE-Verifier und liefert die Autorisierungs-URL.
     */
    public function startUrl(string $purpose, ?int $userId, bool $setupKeyOk, string $lang, ?string $redirectTo = null): string
    {
        $db = $this->app->db();
        $state = Crypto::randomToken(32);
        $verifier = Crypto::randomToken(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        // Browser-Bindung: Nonce im Cookie, Hash in der DB – verhindert Login-CSRF/Session-Fixation über fremde Callback-URLs
        $nonce = Crypto::randomToken(32);
        $this->lastNonce = $nonce;
        $this->setBrowserCookie($nonce);
        $db->insert('oauth_states', [
            'state' => $state,
            'code_verifier' => $verifier,
            'browser_hash' => Crypto::hash($nonce),
            'purpose' => $purpose,
            'user_id' => $userId,
            'setup_key_ok' => $setupKeyOk ? 1 : 0,
            'lang' => $lang,
            'redirect_to' => $redirectTo ? mb_substr($redirectTo, 0, 255) : null,
            'created_at' => $db->now(),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 600),
        ]);
        $params = [
            'client_id' => $this->clientId(),
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
            'scope' => implode(' ', $this->scopes()),
            'code_challenge_method' => 'S256',
            'code_challenge' => $challenge,
            'show_dialog' => $purpose === 'reconnect' ? 'true' : 'false',
        ];
        return $this->accountsBase() . '/authorize?' . http_build_query($params);
    }

    /**
     * Löst den State auf; gültig nur mit passender Browser-Nonce (Cookie) – sonst null.
     * @return array<string,mixed>|null
     */
    public function consumeState(string $state, ?string $browserNonce): ?array
    {
        $db = $this->app->db();
        if (!preg_match('/^[A-Za-z0-9\-_]{40,60}$/', $state)) {
            return null;
        }
        $row = $db->one('SELECT * FROM oauth_states WHERE state = ? AND expires_at > ?', [$state, $db->now()]);
        if ($row) {
            $db->run('DELETE FROM oauth_states WHERE state = ?', [$state]);
        }
        if (!$row || !$browserNonce || !hash_equals((string) $row['browser_hash'], Crypto::hash($browserNonce))) {
            return null;
        }
        $this->setBrowserCookie('', true);
        return $row;
    }

    private function setBrowserCookie(string $value, bool $clear = false): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        setcookie(self::BROWSER_COOKIE, $value, [
            'expires' => $clear ? time() - 3600 : time() + 600,
            'path' => $this->app->url->basePath() ?: '/',
            'secure' => str_starts_with($this->app->url->base(), 'https://'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * Tauscht den Code gegen Tokens.
     * @return array{access_token:string, refresh_token:?string, expires_in:int, scope:string}
     */
    public function exchangeCode(string $code, string $verifier): array
    {
        $body = http_build_query([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
            'client_id' => $this->clientId(),
            'code_verifier' => $verifier,
        ]);
        return $this->tokenRequest($body);
    }

    /** @return array{access_token:string, refresh_token:?string, expires_in:int, scope:string} */
    public function refresh(string $refreshToken): array
    {
        $body = http_build_query([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $this->clientId(),
        ]);
        return $this->tokenRequest($body);
    }

    private function tokenRequest(string $body): array
    {
        $start = microtime(true);
        try {
            $resp = $this->app->http->request('POST', $this->accountsBase() . '/api/token', [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ], $body);
        } catch (\Throwable $e) {
            $this->log('POST', 'accounts/token', 0, $start, $e->getMessage());
            throw new SpotifyException(SpotifyException::UNAVAILABLE, 0, 'Spotify-Token-Endpunkt nicht erreichbar');
        }
        $data = $resp->json();
        $this->log('POST', 'accounts/token', $resp->status, $start, $resp->ok() ? null : (string) ($data['error'] ?? 'error'));
        if (!$resp->ok() || empty($data['access_token'])) {
            $err = (string) ($data['error'] ?? '');
            if ($err === 'invalid_grant') {
                throw new SpotifyException(SpotifyException::REVOKED, $resp->status, 'Spotify-Zugriff widerrufen oder abgelaufen', 0, (string) ($data['error_description'] ?? ''));
            }
            if ($resp->status === 429) {
                throw new SpotifyException(SpotifyException::RATE_LIMITED, 429, 'Rate-Limit', (int) ($resp->header('retry-after') ?? 5));
            }
            throw new SpotifyException(SpotifyException::UNKNOWN, $resp->status, 'Token-Fehler: ' . $err, 0, (string) ($data['error_description'] ?? ''));
        }
        return [
            'access_token' => (string) $data['access_token'],
            'refresh_token' => isset($data['refresh_token']) ? (string) $data['refresh_token'] : null,
            'expires_in' => (int) ($data['expires_in'] ?? 3600),
            'scope' => (string) ($data['scope'] ?? ''),
        ];
    }

    private function log(string $method, string $endpoint, int $status, float $start, ?string $error): void
    {
        try {
            $this->app->db()->insert('integration_log', [
                'service' => 'spotify', 'method' => $method, 'endpoint' => $endpoint, 'status' => $status,
                'duration_ms' => (int) ((microtime(true) - $start) * 1000), 'user_id' => null,
                'error' => $error ? mb_substr($error, 0, 255) : null, 'created_at' => $this->app->db()->now(),
            ]);
        } catch (\Throwable) {
        }
    }
}
