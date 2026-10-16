<?php
declare(strict_types=1);

namespace SwipeGroove\Spotify;

use SwipeGroove\Core\App;

/**
 * Verschlüsselte Ablage der Spotify-Tokens, inkl. Refresh unter Zeilensperre
 * (Spotify rotiert Refresh-Tokens; parallele Refreshes würden sich gegenseitig ungültig machen).
 */
final class TokenStore
{
    public function __construct(private App $app)
    {
    }

    public function account(int $userId): ?array
    {
        return $this->app->db()->one('SELECT * FROM spotify_accounts WHERE user_id = ?', [$userId]);
    }

    /** @param array{access_token:string, refresh_token:?string, expires_in:int, scope:string} $tokens */
    public function store(int $userId, string $spotifyUserId, array $tokens, ?string $displayName, ?string $avatarUrl, bool $newAuthorization): void
    {
        $db = $this->app->db();
        $crypto = $this->app->crypto();
        $existing = $this->account($userId);
        $data = [
            'spotify_user_id' => $spotifyUserId,
            'spotify_display_name' => $displayName !== null ? mb_substr($displayName, 0, 191) : ($existing['spotify_display_name'] ?? null),
            'spotify_avatar_url' => $avatarUrl !== null ? mb_substr($avatarUrl, 0, 500) : ($existing['spotify_avatar_url'] ?? null),
            'access_token_enc' => $crypto->encrypt($tokens['access_token']),
            'token_expires_at' => gmdate('Y-m-d H:i:s', time() + max(60, $tokens['expires_in'] - 60)),
            'scopes' => mb_substr($tokens['scope'], 0, 500),
            'status' => 'connected',
            'last_error' => null,
            'updated_at' => $db->now(),
        ];
        if ($tokens['refresh_token'] !== null) {
            $data['refresh_token_enc'] = $crypto->encrypt($tokens['refresh_token']);
        }
        if ($newAuthorization) {
            $data['authorized_at'] = $db->now();
        }
        if ($existing) {
            $db->update('spotify_accounts', $data, 'user_id = ?', [$userId]);
        } else {
            $data['user_id'] = $userId;
            $data['authorized_at'] = $db->now();
            $db->insert('spotify_accounts', $data);
        }
    }

    public function markRevoked(int $userId, string $error): void
    {
        $db = $this->app->db();
        $db->run('UPDATE spotify_accounts SET status = ?, last_error = ?, access_token_enc = NULL, updated_at = ? WHERE user_id = ?',
            ['revoked', mb_substr($error, 0, 255), $db->now(), $userId]);
    }

    public function disconnect(int $userId): void
    {
        $db = $this->app->db();
        $db->run('UPDATE spotify_accounts SET status = ?, access_token_enc = NULL, refresh_token_enc = NULL, updated_at = ? WHERE user_id = ?',
            ['disconnected', $db->now(), $userId]);
    }

    /**
     * Liefert ein gültiges Access-Token; erneuert es bei Bedarf unter Sperre.
     */
    public function accessToken(int $userId, bool $forceRefresh = false, ?string $failedToken = null): string
    {
        $db = $this->app->db();
        $crypto = $this->app->crypto();
        $acc = $this->account($userId);
        if (!$acc || in_array($acc['status'], ['disconnected'], true) || empty($acc['refresh_token_enc'])) {
            throw new SpotifyException(SpotifyException::NOT_CONNECTED, 0, 'Spotify nicht verbunden');
        }
        if ($acc['status'] === 'revoked') {
            throw new SpotifyException(SpotifyException::REVOKED, 0, 'Spotify-Zugriff widerrufen');
        }
        if (!$forceRefresh && $acc['access_token_enc'] && strtotime((string) $acc['token_expires_at']) > time() + 30) {
            $tok = $crypto->decrypt((string) $acc['access_token_enc']);
            if ($tok !== null) {
                return $tok;
            }
        }
        // Refresh unter Zeilensperre
        try {
            return $this->refreshLocked($userId, $forceRefresh, $failedToken);
        } catch (SpotifyException $e) {
            if ($e->reason === SpotifyException::REVOKED) {
                // Ausserhalb der (zurückgerollten) Transaktion persistent markieren
                $db->run('UPDATE spotify_accounts SET status = ?, last_error = ?, access_token_enc = NULL, updated_at = ? WHERE user_id = ?',
                    ['revoked', mb_substr($e->spotifyMessage ?: $e->getMessage(), 0, 255), $db->now(), $userId]);
            }
            throw $e;
        }
    }

    private function refreshLocked(int $userId, bool $forceRefresh, ?string $failedToken): string
    {
        $db = $this->app->db();
        $crypto = $this->app->crypto();
        return $db->transaction(function () use ($db, $crypto, $userId, $forceRefresh, $failedToken): string {
            $acc = $db->one('SELECT * FROM spotify_accounts WHERE user_id = ? FOR UPDATE', [$userId]);
            if (!$acc) {
                throw new SpotifyException(SpotifyException::NOT_CONNECTED);
            }
            // Ein anderer Prozess hat soeben erneuert?
            if (!$forceRefresh && $acc['access_token_enc'] && strtotime((string) $acc['token_expires_at']) > time() + 30) {
                $tok = $crypto->decrypt((string) $acc['access_token_enc']);
                if ($tok !== null) {
                    return $tok;
                }
            }
            if ($forceRefresh && $acc['last_refresh_at'] && strtotime((string) $acc['last_refresh_at']) > time() - 20 && $acc['access_token_enc']) {
                // Ein paralleler Prozess hat gerade erneuert – nur verwenden, wenn es nicht das gescheiterte Token ist
                $tok = $crypto->decrypt((string) $acc['access_token_enc']);
                if ($tok !== null && $tok !== $failedToken) {
                    return $tok;
                }
            }
            $refresh = $crypto->decrypt((string) $acc['refresh_token_enc']);
            if ($refresh === null) {
                throw new SpotifyException(SpotifyException::REVOKED, 0, 'Refresh-Token nicht lesbar');
            }
            $tokens = $this->app->service(SpotifyAuth::class)->refresh($refresh);
            $data = [
                'access_token_enc' => $crypto->encrypt($tokens['access_token']),
                'token_expires_at' => gmdate('Y-m-d H:i:s', time() + max(60, $tokens['expires_in'] - 60)),
                'last_refresh_at' => $db->now(),
                'status' => 'connected',
                'last_error' => null,
                'updated_at' => $db->now(),
            ];
            if ($tokens['refresh_token'] !== null) {
                $data['refresh_token_enc'] = $crypto->encrypt($tokens['refresh_token']);
            }
            if ($tokens['scope'] !== '') {
                $data['scopes'] = mb_substr($tokens['scope'], 0, 500);
            }
            $db->update('spotify_accounts', $data, 'user_id = ?', [$userId]);
            return $tokens['access_token'];
        });
    }

    public function hasScope(int $userId, string $scope): bool
    {
        $acc = $this->account($userId);
        if (!$acc) {
            return false;
        }
        return in_array($scope, explode(' ', (string) $acc['scopes']), true);
    }

    /** Verbindungsstatus für die UI (ohne Tokens). */
    public function status(int $userId): array
    {
        $acc = $this->account($userId);
        if (!$acc) {
            return ['status' => 'disconnected', 'scopes' => [], 'authorized_at' => null];
        }
        $authorizedAt = $acc['authorized_at'] ? strtotime((string) $acc['authorized_at']) : null;
        return [
            'status' => $acc['status'],
            'scopes' => $acc['scopes'] ? explode(' ', (string) $acc['scopes']) : [],
            'authorized_at' => $acc['authorized_at'],
            // Spotify verlangt seit 2026 nach 6 Monaten eine erneute Anmeldung
            'reauth_due_at' => $authorizedAt ? gmdate('c', $authorizedAt + 182 * 86400) : null,
            'display_name' => $acc['spotify_display_name'],
            'last_error' => $acc['last_error'],
        ];
    }
}
