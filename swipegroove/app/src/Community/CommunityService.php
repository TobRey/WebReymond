<?php
declare(strict_types=1);

namespace SwipeGroove\Community;

use SwipeGroove\Core\App;
use SwipeGroove\Core\HttpException;
use SwipeGroove\Users\UserRepository;

/**
 * Soziale Funktionen inkl. aller Sichtbarkeits- und Blockierungsregeln (serverseitig durchgesetzt).
 */
final class CommunityService
{
    public function __construct(private App $app)
    {
    }

    private function users(): UserRepository
    {
        return $this->app->service(UserRepository::class);
    }

    private function notify(): NotificationService
    {
        return $this->app->service(NotificationService::class);
    }

    // ------------------------------------------------------------------ Beziehungen

    public function isBlocked(int $a, int $b): bool
    {
        return (bool) $this->app->db()->value('SELECT 1 FROM blocks WHERE (blocker_id = ? AND blocked_id = ?) OR (blocker_id = ? AND blocked_id = ?) LIMIT 1', [$a, $b, $b, $a]);
    }

    public function hasBlocked(int $blocker, int $blocked): bool
    {
        return (bool) $this->app->db()->value('SELECT 1 FROM blocks WHERE blocker_id = ? AND blocked_id = ?', [$blocker, $blocked]);
    }

    public function areFriends(int $a, int $b): bool
    {
        [$lo, $hi] = $a < $b ? [$a, $b] : [$b, $a];
        return (bool) $this->app->db()->value('SELECT 1 FROM friendships WHERE user_low = ? AND user_high = ? AND status = \'accepted\'', [$lo, $hi]);
    }

    public function isFollowing(int $follower, int $followed): bool
    {
        return (bool) $this->app->db()->value('SELECT 1 FROM follows WHERE follower_id = ? AND followed_id = ?', [$follower, $followed]);
    }

    /** @return array<string,mixed> Beziehung aus Sicht des Betrachters */
    public function relationship(int $viewerId, int $targetId): array
    {
        $db = $this->app->db();
        [$lo, $hi] = $viewerId < $targetId ? [$viewerId, $targetId] : [$targetId, $viewerId];
        $fr = $db->one('SELECT * FROM friendships WHERE user_low = ? AND user_high = ?', [$lo, $hi]);
        $friendStatus = 'none';
        if ($fr) {
            if ($fr['status'] === 'accepted') {
                $friendStatus = 'friends';
            } else {
                $friendStatus = (int) $fr['requester_id'] === $viewerId ? 'requested' : 'incoming';
            }
        }
        return [
            'following' => $this->isFollowing($viewerId, $targetId),
            'followed_by' => $this->isFollowing($targetId, $viewerId),
            'friend_status' => $friendStatus,
            'blocked' => $this->hasBlocked($viewerId, $targetId),
            'can_message' => $this->canMessage($viewerId, $targetId)['allowed'],
        ];
    }

    /**
     * Liefert das Profil eines Nutzers oder 404 bei Blockierung/Nichtexistenz.
     */
    public function visibleUser(string $username, ?object $viewer): object
    {
        $target = $this->users()->findByUsername($username);
        if (!$target || $target->status !== 'active') {
            if ($target && $viewer && $viewer->role === 'admin') {
                return $target;
            }
            throw HttpException::notFound('error.user_not_found');
        }
        if ($viewer && (int) $viewer->id !== (int) $target->id && $this->hasBlocked((int) $target->id, (int) $viewer->id)) {
            throw HttpException::notFound('error.user_not_found');
        }
        return $target;
    }

    public function canSeeFull(?object $viewer, object $target): bool
    {
        if ($viewer && (int) $viewer->id === (int) $target->id) {
            return true;
        }
        return !(bool) $target->is_private;
    }

    public function profile(string $username, ?object $viewer): array
    {
        $target = $this->visibleUser($username, $viewer);
        $out = $this->users()->publicProfile($target, $viewer, true);
        $out['relationship'] = $viewer && (int) $viewer->id !== (int) $target->id ? $this->relationship((int) $viewer->id, (int) $target->id) : null;
        $out['content_visible'] = $this->canSeeFull($viewer, $target);
        $out['published_count'] = $out['content_visible']
            ? (int) $this->app->db()->value('SELECT COUNT(*) FROM published_playlists WHERE user_id = ? AND is_published = 1', [$target->id])
            : null;
        return $out;
    }

    public function follow(int $viewerId, string $username): array
    {
        $target = $this->visibleUser($username, $this->users()->find($viewerId));
        $tid = (int) $target->id;
        if ($tid === $viewerId) {
            throw HttpException::badRequest('community.self');
        }
        if ($this->isBlocked($viewerId, $tid)) {
            throw HttpException::forbidden('community.blocked');
        }
        $db = $this->app->db();
        $n = $db->exec('INSERT IGNORE INTO follows (follower_id, followed_id, created_at) VALUES (?,?,?)', [$viewerId, $tid, $db->now()]);
        if ($n > 0) {
            $this->notify()->notify($tid, 'follow', $viewerId, 'user', $viewerId);
        }
        return $this->relationship($viewerId, $tid);
    }

    public function unfollow(int $viewerId, string $username): array
    {
        $target = $this->users()->findByUsername($username) ?? throw HttpException::notFound('error.user_not_found');
        $this->app->db()->run('DELETE FROM follows WHERE follower_id = ? AND followed_id = ?', [$viewerId, $target->id]);
        return $this->relationship($viewerId, (int) $target->id);
    }

    public function friendRequest(int $viewerId, string $username): array
    {
        $target = $this->visibleUser($username, $this->users()->find($viewerId));
        $tid = (int) $target->id;
        if ($tid === $viewerId) {
            throw HttpException::badRequest('community.self');
        }
        if ($this->isBlocked($viewerId, $tid)) {
            throw HttpException::forbidden('community.blocked');
        }
        $db = $this->app->db();
        [$lo, $hi] = $viewerId < $tid ? [$viewerId, $tid] : [$tid, $viewerId];
        $fr = $db->one('SELECT * FROM friendships WHERE user_low = ? AND user_high = ?', [$lo, $hi]);
        if ($fr) {
            if ($fr['status'] === 'accepted') {
                throw HttpException::conflict('community.already_friends');
            }
            if ((int) $fr['requester_id'] !== $viewerId) {
                // Gegenseitige Anfrage → annehmen
                return $this->friendAccept($viewerId, $username);
            }
            throw HttpException::conflict('community.request_pending');
        }
        $this->app->limiter()->enforce('friendreq:' . $viewerId, 50, 86400);
        $db->insert('friendships', ['user_low' => $lo, 'user_high' => $hi, 'requester_id' => $viewerId, 'status' => 'pending', 'created_at' => $db->now()]);
        $this->notify()->notify($tid, 'friend_request', $viewerId, 'user', $viewerId);
        return $this->relationship($viewerId, $tid);
    }

    public function friendAccept(int $viewerId, string $username): array
    {
        $target = $this->users()->findByUsername($username) ?? throw HttpException::notFound('error.user_not_found');
        $tid = (int) $target->id;
        $db = $this->app->db();
        [$lo, $hi] = $viewerId < $tid ? [$viewerId, $tid] : [$tid, $viewerId];
        $n = $db->exec('UPDATE friendships SET status = \'accepted\', responded_at = ? WHERE user_low = ? AND user_high = ? AND status = \'pending\' AND requester_id = ?', [$db->now(), $lo, $hi, $tid]);
        if ($n === 0) {
            throw HttpException::notFound('community.no_request');
        }
        $this->notify()->notify($tid, 'friend_accepted', $viewerId, 'user', $viewerId);
        return $this->relationship($viewerId, $tid);
    }

    /** Ablehnen (Empfänger) oder Zurückziehen (Absender) */
    public function friendDecline(int $viewerId, string $username): array
    {
        $target = $this->users()->findByUsername($username) ?? throw HttpException::notFound('error.user_not_found');
        $tid = (int) $target->id;
        [$lo, $hi] = $viewerId < $tid ? [$viewerId, $tid] : [$tid, $viewerId];
        $this->app->db()->run('DELETE FROM friendships WHERE user_low = ? AND user_high = ? AND status = \'pending\'', [$lo, $hi]);
        return $this->relationship($viewerId, $tid);
    }

    public function friendRemove(int $viewerId, string $username): array
    {
        $target = $this->users()->findByUsername($username) ?? throw HttpException::notFound('error.user_not_found');
        $tid = (int) $target->id;
        [$lo, $hi] = $viewerId < $tid ? [$viewerId, $tid] : [$tid, $viewerId];
        $this->app->db()->run('DELETE FROM friendships WHERE user_low = ? AND user_high = ?', [$lo, $hi]);
        return $this->relationship($viewerId, $tid);
    }

    public function block(int $viewerId, string $username): array
    {
        $target = $this->users()->findByUsername($username) ?? throw HttpException::notFound('error.user_not_found');
        $tid = (int) $target->id;
        if ($tid === $viewerId) {
            throw HttpException::badRequest('community.self');
        }
        $db = $this->app->db();
        $db->transaction(function () use ($db, $viewerId, $tid): void {
            $db->run('INSERT IGNORE INTO blocks (blocker_id, blocked_id, created_at) VALUES (?,?,?)', [$viewerId, $tid, $db->now()]);
            $db->run('DELETE FROM follows WHERE (follower_id = ? AND followed_id = ?) OR (follower_id = ? AND followed_id = ?)', [$viewerId, $tid, $tid, $viewerId]);
            [$lo, $hi] = $viewerId < $tid ? [$viewerId, $tid] : [$tid, $viewerId];
            $db->run('DELETE FROM friendships WHERE user_low = ? AND user_high = ?', [$lo, $hi]);
        });
        return $this->relationship($viewerId, $tid);
    }

    public function unblock(int $viewerId, string $username): array
    {
        $target = $this->users()->findByUsername($username) ?? throw HttpException::notFound('error.user_not_found');
        $this->app->db()->run('DELETE FROM blocks WHERE blocker_id = ? AND blocked_id = ?', [$viewerId, $target->id]);
        return $this->relationship($viewerId, (int) $target->id);
    }

    /** @return list<array<string,mixed>> */
    public function blockedUsers(int $viewerId): array
    {
        $rows = $this->app->db()->all('SELECT u.* FROM blocks b JOIN users u ON u.id = b.blocked_id WHERE b.blocker_id = ? ORDER BY b.created_at DESC', [$viewerId]);
        return array_map(fn(array $u) => $this->users()->card($u), $rows);
    }

    /** @return array{incoming:list<array<string,mixed>>, outgoing:list<array<string,mixed>>} */
    public function friendRequests(int $viewerId): array
    {
        $db = $this->app->db();
        $rows = $db->all('SELECT f.requester_id, f.created_at, u.* FROM friendships f JOIN users u ON u.id = IF(f.user_low = ?, f.user_high, f.user_low) WHERE f.status = \'pending\' AND (f.user_low = ? OR f.user_high = ?) AND u.status = \'active\'', [$viewerId, $viewerId, $viewerId]);
        $in = [];
        $out = [];
        foreach ($rows as $r) {
            $card = $this->users()->card($r) + ['requested_at' => $r['created_at']];
            if ((int) $r['requester_id'] === $viewerId) {
                $out[] = $card;
            } else {
                $in[] = $card;
            }
        }
        return ['incoming' => $in, 'outgoing' => $out];
    }

    /**
     * Listen (Follower/Following/Freunde) – bei privaten Profilen nur Follower sichtbar.
     * @return list<array<string,mixed>>
     */
    public function userList(string $username, string $kind, ?object $viewer, int $offset, int $limit): array
    {
        $target = $this->visibleUser($username, $viewer);
        if ($kind !== 'followers' && !$this->canSeeFull($viewer, $target)) {
            throw HttpException::forbidden('community.private');
        }
        $db = $this->app->db();
        $sql = match ($kind) {
            'followers' => 'SELECT u.* FROM follows f JOIN users u ON u.id = f.follower_id WHERE f.followed_id = ? AND u.status = \'active\' ORDER BY f.created_at DESC',
            'following' => 'SELECT u.* FROM follows f JOIN users u ON u.id = f.followed_id WHERE f.follower_id = ? AND u.status = \'active\' ORDER BY f.created_at DESC',
            'friends' => 'SELECT u.* FROM friendships f JOIN users u ON u.id = IF(f.user_low = ?, f.user_high, f.user_low) WHERE f.status = \'accepted\' AND (f.user_low = ? OR f.user_high = ?) AND u.status = \'active\' ORDER BY f.responded_at DESC',
            default => throw HttpException::notFound(),
        };
        $params = $kind === 'friends' ? [$target->id, $target->id, $target->id] : [$target->id];
        $sql .= ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
        $rows = $db->all($sql, $params);
        $viewerId = $viewer ? (int) $viewer->id : 0;
        return array_values(array_filter(array_map(function (array $u) use ($viewerId) {
            if ($viewerId && $this->hasBlocked((int) $u['id'], $viewerId)) {
                return null;
            }
            return $this->users()->card($u);
        }, $rows)));
    }

    /** @return list<array<string,mixed>> */
    public function searchUsers(string $q, ?object $viewer, int $limit = 20): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], mb_substr($q, 0, 40)) . '%';
        $rows = $this->app->db()->all(
            'SELECT * FROM users WHERE status = \'active\' AND deleted_at IS NULL AND (username LIKE ? OR display_name LIKE ?) ORDER BY (username = ?) DESC, username ASC LIMIT ' . (int) $limit,
            [$like, $like, strtolower($q)]
        );
        $viewerId = $viewer ? (int) $viewer->id : 0;
        $out = [];
        foreach ($rows as $u) {
            if ($viewerId && $this->hasBlocked((int) $u['id'], $viewerId)) {
                continue;
            }
            $out[] = $this->users()->card($u);
        }
        return $out;
    }

    // ------------------------------------------------------------------ Nachrichten-Berechtigung

    /** @return array{allowed:bool, mode:string} mode = direct|request|denied */
    public function canMessage(int $senderId, int $recipientId): array
    {
        if ($senderId === $recipientId) {
            return ['allowed' => false, 'mode' => 'denied'];
        }
        if ($this->isBlocked($senderId, $recipientId)) {
            return ['allowed' => false, 'mode' => 'denied'];
        }
        $recipient = $this->users()->find($recipientId);
        if (!$recipient || $recipient->status !== 'active') {
            return ['allowed' => false, 'mode' => 'denied'];
        }
        if ($this->areFriends($senderId, $recipientId)) {
            return (bool) $recipient->msg_from_friends ? ['allowed' => true, 'mode' => 'direct'] : ['allowed' => false, 'mode' => 'denied'];
        }
        return (bool) $recipient->msg_requests_allowed ? ['allowed' => true, 'mode' => 'request'] : ['allowed' => false, 'mode' => 'denied'];
    }

    // ------------------------------------------------------------------ Veröffentlichte Playlists

    /** @return array<string,mixed>|null Zeile inkl. Besitzer, oder null wenn unsichtbar */
    public function visiblePublished(int $publishedId, ?object $viewer, bool $allowOwnerUnpublished = false): ?array
    {
        $row = $this->app->db()->one('SELECT p.*, u.username, u.display_name, u.avatar_path, u.is_private, u.status AS owner_status FROM published_playlists p JOIN users u ON u.id = p.user_id WHERE p.id = ?', [$publishedId]);
        if (!$row) {
            return null;
        }
        $viewerId = $viewer ? (int) $viewer->id : 0;
        $isOwner = $viewerId === (int) $row['user_id'];
        if ($isOwner) {
            return (int) $row['is_published'] === 1 || $allowOwnerUnpublished ? $row : null;
        }
        if ((int) $row['is_published'] !== 1 || $row['owner_status'] !== 'active' || (int) $row['is_private'] === 1) {
            return null;
        }
        if ($viewerId && $this->isBlocked($viewerId, (int) $row['user_id'])) {
            return null;
        }
        return $row;
    }

    public function presentPublished(array $row, ?object $viewer, bool $withTracks = false): array
    {
        $viewerId = $viewer ? (int) $viewer->id : 0;
        $db = $this->app->db();
        $out = [
            'id' => (int) $row['id'],
            'spotify_playlist_id' => $row['spotify_playlist_id'],
            'name' => $row['name'],
            'description' => $row['description'],
            'image_url' => $row['image_url'],
            'track_count' => (int) $row['track_count'],
            'like_count' => (int) $row['like_count'],
            'comment_count' => (int) $row['comment_count'],
            'published_at' => $row['published_at'],
            'updated_at' => $row['updated_at'],
            'spotify_url' => 'https://open.spotify.com/playlist/' . $row['spotify_playlist_id'],
            'owner' => $this->users()->card(['id' => $row['user_id'], 'username' => $row['username'], 'display_name' => $row['display_name'], 'avatar_path' => $row['avatar_path'], 'is_private' => $row['is_private']]),
            'liked' => $viewerId ? (bool) $db->value('SELECT 1 FROM playlist_likes WHERE published_playlist_id = ? AND user_id = ?', [$row['id'], $viewerId]) : false,
            'is_owner' => $viewerId === (int) $row['user_id'],
        ];
        if ($withTracks) {
            $out['tracks'] = array_map(static fn(array $t) => [
                'position' => (int) $t['position'], 'id' => $t['track_id'], 'name' => $t['name'], 'artists' => $t['artists'], 'album_name' => $t['album_name'],
                'image_url' => $t['image_url'], 'duration_ms' => $t['duration_ms'] !== null ? (int) $t['duration_ms'] : null,
                'spotify_url' => $t['track_id'] ? 'https://open.spotify.com/track/' . $t['track_id'] : null,
            ], $db->all('SELECT * FROM published_playlist_tracks WHERE published_playlist_id = ? ORDER BY position ASC', [$row['id']]));
        }
        return $out;
    }

    public function playlist(int $publishedId, ?object $viewer): array
    {
        $row = $this->visiblePublished($publishedId, $viewer);
        if (!$row) {
            throw HttpException::notFound('community.playlist_not_found');
        }
        return $this->presentPublished($row, $viewer, true);
    }

    /** @return list<array<string,mixed>> */
    public function userPlaylists(string $username, ?object $viewer): array
    {
        $target = $this->visibleUser($username, $viewer);
        if (!$this->canSeeFull($viewer, $target)) {
            return [];
        }
        $rows = $this->app->db()->all('SELECT p.*, u.username, u.display_name, u.avatar_path, u.is_private FROM published_playlists p JOIN users u ON u.id = p.user_id WHERE p.user_id = ? AND p.is_published = 1 ORDER BY p.published_at DESC', [$target->id]);
        return array_map(fn(array $r) => $this->presentPublished($r, $viewer), $rows);
    }

    public function like(int $viewerId, int $publishedId, bool $like): array
    {
        $viewer = $this->users()->find($viewerId);
        $row = $this->visiblePublished($publishedId, $viewer);
        if (!$row) {
            throw HttpException::notFound('community.playlist_not_found');
        }
        $db = $this->app->db();
        if ($like) {
            $n = $db->exec('INSERT IGNORE INTO playlist_likes (published_playlist_id, user_id, created_at) VALUES (?,?,?)', [$publishedId, $viewerId, $db->now()]);
            if ($n > 0) {
                $db->run('UPDATE published_playlists SET like_count = like_count + 1 WHERE id = ?', [$publishedId]);
                $this->notify()->notify((int) $row['user_id'], 'like', $viewerId, 'playlist', $publishedId, ['name' => $row['name']]);
                if ($viewer && !(bool) $viewer->is_private) {
                    $db->insert('activities', ['user_id' => $viewerId, 'type' => 'liked', 'published_playlist_id' => $publishedId, 'created_at' => $db->now()]);
                }
            }
        } else {
            $n = $db->exec('DELETE FROM playlist_likes WHERE published_playlist_id = ? AND user_id = ?', [$publishedId, $viewerId]);
            if ($n > 0) {
                $db->run('UPDATE published_playlists SET like_count = GREATEST(0, CAST(like_count AS SIGNED) - 1) WHERE id = ?', [$publishedId]);
                $db->run('DELETE FROM activities WHERE user_id = ? AND type = \'liked\' AND published_playlist_id = ?', [$viewerId, $publishedId]);
            }
        }
        $fresh = $this->visiblePublished($publishedId, $viewer);
        return $this->presentPublished($fresh ?? $row, $viewer);
    }

    /** Likes-Liste: private Nutzer werden nicht aufgeführt (Zahl bleibt). */
    public function likes(int $publishedId, ?object $viewer): array
    {
        $row = $this->visiblePublished($publishedId, $viewer);
        if (!$row) {
            throw HttpException::notFound('community.playlist_not_found');
        }
        $rows = $this->app->db()->all('SELECT u.* FROM playlist_likes l JOIN users u ON u.id = l.user_id WHERE l.published_playlist_id = ? AND u.is_private = 0 AND u.status = \'active\' ORDER BY l.created_at DESC LIMIT 200', [$publishedId]);
        $viewerId = $viewer ? (int) $viewer->id : 0;
        $out = [];
        foreach ($rows as $u) {
            if ($viewerId && $this->hasBlocked((int) $u['id'], $viewerId)) {
                continue;
            }
            $out[] = $this->users()->card($u);
        }
        return ['users' => $out, 'total' => (int) $row['like_count']];
    }

    // ------------------------------------------------------------------ Kommentare

    private function presentComment(array $c, ?object $viewer, array $playlistRow): array
    {
        $viewerId = $viewer ? (int) $viewer->id : 0;
        return [
            'id' => (int) $c['id'],
            'body' => $c['body'],
            'author' => $this->users()->card(['id' => $c['user_id'], 'username' => $c['username'], 'display_name' => $c['display_name'], 'avatar_path' => $c['avatar_path'], 'is_private' => $c['is_private']]),
            'created_at' => $c['created_at'],
            'updated_at' => $c['updated_at'],
            'can_edit' => $viewerId === (int) $c['user_id'],
            'can_delete' => $viewerId === (int) $c['user_id'] || $viewerId === (int) $playlistRow['user_id'] || ($viewer && $viewer->role === 'admin'),
        ];
    }

    public function comments(int $publishedId, ?object $viewer, int $before = 0, int $limit = 30): array
    {
        $row = $this->visiblePublished($publishedId, $viewer);
        if (!$row) {
            throw HttpException::notFound('community.playlist_not_found');
        }
        $sql = 'SELECT c.*, u.username, u.display_name, u.avatar_path, u.is_private FROM playlist_comments c JOIN users u ON u.id = c.user_id WHERE c.published_playlist_id = ? AND c.deleted_at IS NULL';
        $params = [$publishedId];
        if ($before > 0) {
            $sql .= ' AND c.id < ?';
            $params[] = $before;
        }
        $sql .= ' ORDER BY c.id DESC LIMIT ' . ($limit + 1);
        $rows = $this->app->db()->all($sql, $params);
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $viewerId = $viewer ? (int) $viewer->id : 0;
        $out = [];
        foreach ($rows as $c) {
            if ($viewerId && $this->hasBlocked((int) $c['user_id'], $viewerId)) {
                continue;
            }
            $out[] = $this->presentComment($c, $viewer, $row);
        }
        return ['comments' => $out, 'next_cursor' => $more && $rows ? (int) end($rows)['id'] : null];
    }

    public function addComment(int $viewerId, int $publishedId, string $body): array
    {
        $viewer = $this->users()->find($viewerId);
        $row = $this->visiblePublished($publishedId, $viewer);
        if (!$row) {
            throw HttpException::notFound('community.playlist_not_found');
        }
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > 1000) {
            throw HttpException::validation(['body' => 'validation.comment_length']);
        }
        $this->app->limiter()->enforce('comment:' . $viewerId, 30, 600);
        $db = $this->app->db();
        $id = $db->insert('playlist_comments', ['published_playlist_id' => $publishedId, 'user_id' => $viewerId, 'body' => $body, 'created_at' => $db->now()]);
        $db->run('UPDATE published_playlists SET comment_count = comment_count + 1 WHERE id = ?', [$publishedId]);
        $this->notify()->notify((int) $row['user_id'], 'comment', $viewerId, 'playlist', $publishedId, ['name' => $row['name'], 'excerpt' => mb_substr($body, 0, 80)]);
        if ($viewer && !(bool) $viewer->is_private) {
            $db->insert('activities', ['user_id' => $viewerId, 'type' => 'commented', 'published_playlist_id' => $publishedId, 'created_at' => $db->now()]);
        }
        $c = $db->one('SELECT c.*, u.username, u.display_name, u.avatar_path, u.is_private FROM playlist_comments c JOIN users u ON u.id = c.user_id WHERE c.id = ?', [$id]);
        return $this->presentComment($c ?? [], $viewer, $row);
    }

    public function editComment(int $viewerId, int $commentId, string $body): array
    {
        $db = $this->app->db();
        $c = $db->one('SELECT * FROM playlist_comments WHERE id = ? AND deleted_at IS NULL', [$commentId]);
        if (!$c || (int) $c['user_id'] !== $viewerId) {
            throw HttpException::notFound('community.comment_not_found');
        }
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > 1000) {
            throw HttpException::validation(['body' => 'validation.comment_length']);
        }
        $db->update('playlist_comments', ['body' => $body, 'updated_at' => $db->now()], 'id = ?', [$commentId]);
        $viewer = $this->users()->find($viewerId);
        $row = $db->one('SELECT * FROM published_playlists WHERE id = ?', [$c['published_playlist_id']]);
        $fresh = $db->one('SELECT c.*, u.username, u.display_name, u.avatar_path, u.is_private FROM playlist_comments c JOIN users u ON u.id = c.user_id WHERE c.id = ?', [$commentId]);
        return $this->presentComment($fresh ?? [], $viewer, $row ?? []);
    }

    /** Löschen durch Autor, Playlist-Besitzer oder Admin. */
    public function deleteComment(object $viewer, int $commentId): void
    {
        $db = $this->app->db();
        $c = $db->one('SELECT c.*, p.user_id AS owner_id FROM playlist_comments c JOIN published_playlists p ON p.id = c.published_playlist_id WHERE c.id = ? AND c.deleted_at IS NULL', [$commentId]);
        if (!$c) {
            throw HttpException::notFound('community.comment_not_found');
        }
        $vid = (int) $viewer->id;
        if ($vid !== (int) $c['user_id'] && $vid !== (int) $c['owner_id'] && $viewer->role !== 'admin') {
            throw HttpException::forbidden();
        }
        $db->update('playlist_comments', ['deleted_at' => $db->now(), 'deleted_by' => $vid], 'id = ?', [$commentId]);
        $db->run('UPDATE published_playlists SET comment_count = GREATEST(0, CAST(comment_count AS SIGNED) - 1) WHERE id = ?', [$c['published_playlist_id']]);
    }

    // ------------------------------------------------------------------ Feed

    /** @return array{items:list<array<string,mixed>>, next_cursor:?int} */
    public function feed(?object $viewer, int $before = 0, int $limit = 20): array
    {
        $db = $this->app->db();
        $viewerId = $viewer ? (int) $viewer->id : 0;
        $sql = 'SELECT a.id AS activity_id, a.type AS activity_type, a.created_at AS activity_at, a.user_id AS actor_id,
                       au.username AS actor_username, au.display_name AS actor_display_name, au.avatar_path AS actor_avatar, au.is_private AS actor_private,
                       p.*, u.username, u.display_name, u.avatar_path, u.is_private
                FROM activities a
                JOIN users au ON au.id = a.user_id AND au.status = \'active\' AND au.is_private = 0
                JOIN published_playlists p ON p.id = a.published_playlist_id AND p.is_published = 1
                JOIN users u ON u.id = p.user_id AND u.status = \'active\' AND u.is_private = 0
                WHERE 1=1';
        $params = [];
        if ($before > 0) {
            $sql .= ' AND a.id < ?';
            $params[] = $before;
        }
        $sql .= ' ORDER BY a.id DESC LIMIT ' . ($limit * 2 + 1);
        $rows = $db->all($sql, $params);
        $items = [];
        $seen = [];
        $last = null;
        foreach ($rows as $r) {
            $last = (int) $r['activity_id'];
            if ($viewerId && ($this->isBlocked($viewerId, (int) $r['user_id']) || $this->isBlocked($viewerId, (int) $r['actor_id']))) {
                continue;
            }
            $key = $r['activity_type'] . ':' . $r['id'] . ':' . $r['actor_id'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $items[] = [
                'id' => (int) $r['activity_id'],
                'type' => $r['activity_type'],
                'created_at' => $r['activity_at'],
                'actor' => $this->users()->card(['id' => $r['actor_id'], 'username' => $r['actor_username'], 'display_name' => $r['actor_display_name'], 'avatar_path' => $r['actor_avatar'], 'is_private' => $r['actor_private']]),
                'playlist' => $this->presentPublished($r, $viewer),
            ];
            if (count($items) >= $limit) {
                break;
            }
        }
        return ['items' => $items, 'next_cursor' => count($rows) > count($items) && $last ? $last : null];
    }

    // ------------------------------------------------------------------ Meldungen

    public function report(int $reporterId, string $targetType, int $targetId, string $reason, string $details): int
    {
        if (!in_array($targetType, ['user', 'comment', 'message', 'playlist'], true)) {
            throw HttpException::badRequest();
        }
        if (!in_array($reason, ['spam', 'harassment', 'hate', 'inappropriate', 'impersonation', 'other'], true)) {
            throw HttpException::badRequest('report.invalid_reason');
        }
        $this->app->limiter()->enforce('report:' . $reporterId, 20, 86400);
        $db = $this->app->db();
        $targetUser = match ($targetType) {
            'user' => $targetId,
            'comment' => (int) $db->value('SELECT user_id FROM playlist_comments WHERE id = ?', [$targetId]),
            'message' => (int) $db->value('SELECT m.sender_id FROM messages m JOIN conversation_members cm ON cm.conversation_id = m.conversation_id AND cm.user_id = ? WHERE m.id = ?', [$reporterId, $targetId]),
            'playlist' => (int) $db->value('SELECT user_id FROM published_playlists WHERE id = ?', [$targetId]),
        };
        if (!$targetUser) {
            throw HttpException::notFound();
        }
        return $db->insert('reports', [
            'reporter_id' => $reporterId, 'target_type' => $targetType, 'target_id' => $targetId, 'target_user_id' => $targetUser,
            'reason' => $reason, 'details' => mb_substr(trim($details), 0, 1000) ?: null, 'status' => 'open', 'created_at' => $db->now(),
        ]);
    }
}
