<?php
declare(strict_types=1);

namespace SwipeGroove\Auth;

use SwipeGroove\Core\App;
use SwipeGroove\Core\HttpException;
use SwipeGroove\Core\Request;
use SwipeGroove\Spotify\TokenStore;
use SwipeGroove\Users\UserRepository;

/**
 * Registrierung/Anmeldung über Spotify-Identität. Genau ein Konto pro Spotify-Nutzer-ID,
 * genau ein Besitzer (transaktionssicher über platform_owner.id = 1).
 */
final class AccountService
{
    public function __construct(private App $app)
    {
    }

    public function ownerExists(): bool
    {
        return (bool) $this->app->db()->value('SELECT 1 FROM platform_owner WHERE id = 1');
    }

    public function registrationOpen(): bool
    {
        return $this->ownerExists() && $this->app->settings()->bool('registration_open');
    }

    public function verifySetupKey(string $key): bool
    {
        $hash = $this->app->settings()->get('setup_key_hash');
        if (!$hash || $this->ownerExists()) {
            return false;
        }
        return password_verify($key, (string) $hash);
    }

    /**
     * @param array<string,mixed> $profile  Antwort von GET /me
     * @param array{access_token:string, refresh_token:?string, expires_in:int, scope:string} $tokens
     * @param array<string,mixed> $state  oauth_states-Zeile
     * @return object angemeldeter Nutzer
     */
    public function loginOrRegister(array $profile, array $tokens, array $state, Request $request): object
    {
        $spotifyId = (string) ($profile['id'] ?? '');
        if ($spotifyId === '') {
            throw HttpException::badRequest('auth.profile_missing');
        }
        $db = $this->app->db();
        $users = $this->app->service(UserRepository::class);
        $displayName = trim((string) ($profile['display_name'] ?? '')) ?: 'Groover';
        $avatar = $profile['images'][0]['url'] ?? null;
        $store = $this->app->service(TokenStore::class);

        // Reconnect: angemeldeter Nutzer verbindet Spotify erneut – muss dieselbe Spotify-ID sein
        if (($state['purpose'] ?? '') === 'reconnect' && !empty($state['user_id'])) {
            $user = $users->find((int) $state['user_id']);
            if (!$user) {
                throw HttpException::badRequest('auth.state_invalid');
            }
            if ($user->spotify_user_id !== $spotifyId) {
                throw HttpException::forbidden('auth.spotify_account_mismatch');
            }
            $store->store((int) $user->id, $spotifyId, $tokens, $displayName, is_string($avatar) ? $avatar : null, true);
            return $user;
        }

        $existing = $users->findBySpotifyId($spotifyId);
        if ($existing) {
            if ($existing->deleted_at !== null) {
                throw HttpException::forbidden('auth.account_deleted');
            }
            $store->store((int) $existing->id, $spotifyId, $tokens, $displayName, is_string($avatar) ? $avatar : null, true);
            $this->app->service(SessionManager::class)->login((int) $existing->id, $request);
            return $existing;
        }

        // Neuer Nutzer
        $wantsOwner = !$this->ownerExists();
        if ($wantsOwner && empty($state['setup_key_ok'])) {
            throw HttpException::forbidden('auth.setup_required');
        }
        if (!$wantsOwner && !$this->registrationOpen()) {
            throw HttpException::forbidden('auth.registration_closed');
        }

        $lang = in_array($state['lang'] ?? 'de', ['de', 'en'], true) ? $state['lang'] : 'de';
        $userId = $db->transaction(function () use ($db, $users, $spotifyId, $displayName, $lang, $wantsOwner): int {
            $username = $users->suggestUsername($displayName);
            $now = $db->now();
            $id = $db->insert('users', [
                'spotify_user_id' => $spotifyId,
                'username' => $username,
                'display_name' => mb_substr($displayName, 0, 60),
                'lang' => $lang,
                'role' => $wantsOwner ? 'admin' : 'user',
                'status' => 'active',
                'onboarding_done' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($wantsOwner) {
                // Schlägt bei paralleler Erstregistrierung mit Duplicate-Key fehl → Rollback
                $db->run('INSERT INTO platform_owner (id, user_id, created_at) VALUES (1, ?, ?)', [$id, $now]);
            }
            return $id;
        });

        if ($wantsOwner) {
            $settings = $this->app->settings();
            $settings->set('setup_key_hash', null);
            $settings->set('owner_registered', true);
            $settings->set('operation_mode', 'closed_beta');
            $settings->set('registration_open', false);
            $this->app->service(\SwipeGroove\Admin\AuditLog::class)->record($userId, 'owner.registered', 'user', (string) $userId, [], $request);
        }

        $store->store($userId, $spotifyId, $tokens, $displayName, is_string($avatar) ? $avatar : null, true);
        $this->app->service(SessionManager::class)->login($userId, $request);
        return $users->find($userId) ?? throw new \RuntimeException('Nutzer nicht gefunden');
    }

    /** Löscht ein Konto vollständig (Datenschutz). */
    public function deleteAccount(int $userId, Request $request): void
    {
        $db = $this->app->db();
        $users = $this->app->service(UserRepository::class);
        if ($users->isOwner($userId)) {
            throw HttpException::forbidden('account.owner_cannot_delete');
        }
        $user = $users->find($userId);
        if (!$user) {
            return;
        }
        $this->app->service(\SwipeGroove\Users\ImageService::class)->deleteAllForUser($userId);
        $this->app->service(\SwipeGroove\Covers\CoverService::class)->deleteFilesForUser($userId);
        $db->transaction(function () use ($db, $userId): void {
            // Kaskaden löschen abhängige Zeilen (Sessions, Tokens, Nachrichten, Likes …)
            $db->run('DELETE FROM users WHERE id = ?', [$userId]);
        });
        $this->app->service(\SwipeGroove\Admin\AuditLog::class)->record($userId, 'account.deleted', 'user', (string) $userId, [], $request);
    }
}
