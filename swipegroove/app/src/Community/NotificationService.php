<?php
declare(strict_types=1);

namespace SwipeGroove\Community;

use SwipeGroove\Core\App;
use SwipeGroove\Users\UserRepository;

final class NotificationService
{
    public function __construct(private App $app)
    {
    }

    public function notify(int $userId, string $type, ?int $actorId, ?string $refType = null, ?int $refId = null, array $data = []): void
    {
        if ($actorId === $userId) {
            return;
        }
        $db = $this->app->db();
        // Doppelte Benachrichtigungen (z. B. erneutes Folgen) innerhalb eines Tages vermeiden
        if (in_array($type, ['follow', 'like', 'friend_request'], true) && $actorId) {
            $dup = $db->value('SELECT 1 FROM notifications WHERE user_id = ? AND type = ? AND actor_id = ? AND COALESCE(ref_id,0) = ? AND created_at > ?',
                [$userId, $type, $actorId, $refId ?? 0, gmdate('Y-m-d H:i:s', time() - 86400)]);
            if ($dup) {
                return;
            }
        }
        $db->insert('notifications', [
            'user_id' => $userId, 'type' => $type, 'actor_id' => $actorId, 'ref_type' => $refType, 'ref_id' => $refId,
            'data' => $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null, 'created_at' => $db->now(),
        ]);
    }

    /** @return array{notifications:list<array<string,mixed>>, next_cursor:?int} */
    public function list(int $userId, int $before = 0, int $limit = 30): array
    {
        $db = $this->app->db();
        $users = $this->app->service(UserRepository::class);
        $sql = 'SELECT n.*, u.username, u.display_name, u.avatar_path, u.is_private FROM notifications n LEFT JOIN users u ON u.id = n.actor_id WHERE n.user_id = ?';
        $params = [$userId];
        if ($before > 0) {
            $sql .= ' AND n.id < ?';
            $params[] = $before;
        }
        $sql .= ' ORDER BY n.id DESC LIMIT ' . ($limit + 1);
        $rows = $db->all($sql, $params);
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $out = [];
        foreach ($rows as $n) {
            $out[] = [
                'id' => (int) $n['id'],
                'type' => $n['type'],
                'actor' => $n['username'] ? $users->card(['id' => $n['actor_id'], 'username' => $n['username'], 'display_name' => $n['display_name'], 'avatar_path' => $n['avatar_path'], 'is_private' => $n['is_private']]) : null,
                'ref_type' => $n['ref_type'],
                'ref_id' => $n['ref_id'] !== null ? (int) $n['ref_id'] : null,
                'data' => $n['data'] ? json_decode((string) $n['data'], true) : null,
                'read' => $n['read_at'] !== null,
                'created_at' => $n['created_at'],
            ];
        }
        return ['notifications' => $out, 'next_cursor' => $more && $out ? end($out)['id'] : null];
    }

    public function markRead(int $userId, ?array $ids): int
    {
        $db = $this->app->db();
        if ($ids === null || $ids === []) {
            return $db->exec('UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL', [$db->now(), $userId]);
        }
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            return 0;
        }
        return $db->exec('UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', array_merge([$db->now(), $userId], $ids));
    }
}
