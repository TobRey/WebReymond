<?php
declare(strict_types=1);

namespace SwipeGroove\Users;

use SwipeGroove\Core\App;
use SwipeGroove\Core\HttpException;

final class UserRepository
{
    public function __construct(private App $app)
    {
    }

    public function find(int $id): ?object
    {
        $row = $this->app->db()->one('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL', [$id]);
        return $row ? (object) $row : null;
    }

    public function findByUsername(string $username): ?object
    {
        $row = $this->app->db()->one('SELECT * FROM users WHERE username = ? AND deleted_at IS NULL', [strtolower($username)]);
        return $row ? (object) $row : null;
    }

    public function findBySpotifyId(string $spotifyId): ?object
    {
        $row = $this->app->db()->one('SELECT * FROM users WHERE spotify_user_id = ?', [$spotifyId]);
        return $row ? (object) $row : null;
    }

    public function isOwner(int $userId): bool
    {
        return (bool) $this->app->db()->value('SELECT 1 FROM platform_owner WHERE user_id = ?', [$userId]);
    }

    public function ownerId(): ?int
    {
        $v = $this->app->db()->value('SELECT user_id FROM platform_owner WHERE id = 1');
        return $v === null ? null : (int) $v;
    }

    public static function validateUsername(string $username): ?string
    {
        $u = strtolower(trim($username));
        if (strlen($u) < 3 || strlen($u) > 32) {
            return 'validation.username_length';
        }
        if (!preg_match('/^[a-z0-9](?:[a-z0-9_.]*[a-z0-9])?$/', $u)) {
            return 'validation.username_chars';
        }
        if (in_array($u, ['admin', 'swipegroove', 'spotify', 'api', 'install', 'setup', 'login', 'logout', 'me', 'system', 'support'], true)) {
            return 'validation.username_reserved';
        }
        return null;
    }

    public static function validateDisplayName(string $name): ?string
    {
        $n = trim($name);
        $len = mb_strlen($n);
        if ($len < 1 || $len > 60) {
            return 'validation.display_name_length';
        }
        if (preg_match('/[\x00-\x1F\x7F]/u', $n)) {
            return 'validation.display_name_chars';
        }
        return null;
    }

    public function usernameTaken(string $username, ?int $exceptUserId = null): bool
    {
        $sql = 'SELECT 1 FROM users WHERE username = ?';
        $params = [strtolower($username)];
        if ($exceptUserId) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptUserId;
        }
        return (bool) $this->app->db()->value($sql, $params);
    }

    /** Generiert einen freien Benutzernamen aus dem Spotify-Anzeigenamen. */
    public function suggestUsername(string $base): string
    {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $base) ?: '') ?? '');
        if (strlen($slug) < 3) {
            $slug = 'groover';
        }
        $slug = substr($slug, 0, 24);
        $candidate = $slug;
        $i = 0;
        while ($this->usernameTaken($candidate) || self::validateUsername($candidate) !== null) {
            $i++;
            $candidate = $slug . ($i < 10 ? random_int(10, 99) : random_int(100, 9999));
            if ($i > 50) {
                $candidate = $slug . bin2hex(random_bytes(3));
            }
        }
        return $candidate;
    }

    /**
     * Öffentliche Darstellung eines Nutzers. Private Profile liefern nur Anzeigename, Bild und Follower.
     * @param object $user  Datensatz
     * @param object|null $viewer  angemeldeter Betrachter
     */
    public function publicProfile(object $user, ?object $viewer, bool $full = false): array
    {
        $isSelf = $viewer && (int) $viewer->id === (int) $user->id;
        $isAdminViewer = $viewer && $viewer->role === 'admin';
        $private = (bool) $user->is_private && !$isSelf;
        $db = $this->app->db();
        $out = [
            'id' => (int) $user->id,
            'username' => $user->username,
            'display_name' => $user->display_name,
            'avatar_url' => $this->imageUrl($user->avatar_path),
            'is_private' => (bool) $user->is_private,
            'is_owner' => $this->isOwner((int) $user->id),
            'is_admin' => $user->role === 'admin',
            'follower_count' => (int) $db->value('SELECT COUNT(*) FROM follows WHERE followed_id = ?', [$user->id]),
        ];
        if ($full && !$private) {
            $out['banner_url'] = $this->imageUrl($user->banner_path);
            $out['background_url'] = $this->imageUrl($user->background_path);
            $out['bio'] = $user->bio;
            $out['following_count'] = (int) $db->value('SELECT COUNT(*) FROM follows WHERE follower_id = ?', [$user->id]);
            $out['friend_count'] = (int) $db->value('SELECT COUNT(*) FROM friendships WHERE status = \'accepted\' AND (user_low = ? OR user_high = ?)', [$user->id, $user->id]);
            $out['created_at'] = $user->created_at;
        }
        if ($full && $isSelf) {
            $out['bio'] = $user->bio;
            $out['banner_url'] = $this->imageUrl($user->banner_path);
            $out['background_url'] = $this->imageUrl($user->background_path);
        }
        if ($isAdminViewer) {
            $out['status'] = $user->status;
        }
        return $out;
    }

    public function imageUrl(?string $path): ?string
    {
        if (!$path) {
            return null;
        }
        return $this->app->url->path('/uploads/' . ltrim($path, '/'));
    }

    /** Kompakte Darstellung für Listen (Follower, Kommentare, Chats). */
    public function card(object|array $user): array
    {
        $u = is_array($user) ? (object) $user : $user;
        return [
            'id' => (int) $u->id,
            'username' => $u->username,
            'display_name' => $u->display_name,
            'avatar_url' => $this->imageUrl($u->avatar_path ?? null),
            'is_private' => (bool) ($u->is_private ?? 0),
            'is_owner' => $this->isOwner((int) $u->id),
        ];
    }

    public function requireActive(int $id): object
    {
        $u = $this->find($id);
        if (!$u || $u->status !== 'active') {
            throw HttpException::notFound('error.user_not_found');
        }
        return $u;
    }
}
