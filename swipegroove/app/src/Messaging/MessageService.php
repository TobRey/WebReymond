<?php
declare(strict_types=1);

namespace SwipeGroove\Messaging;

use SwipeGroove\Community\CommunityService;
use SwipeGroove\Community\NotificationService;
use SwipeGroove\Core\App;
use SwipeGroove\Core\HttpException;
use SwipeGroove\Users\UserRepository;

/**
 * Private Einzelchats mit Nachrichtenanfragen und inkrementellem Polling.
 * Keine Ende-zu-Ende-Verschlüsselung (Transport über HTTPS, Speicherung serverseitig).
 */
final class MessageService
{
    public function __construct(private App $app)
    {
    }

    private function community(): CommunityService
    {
        return $this->app->service(CommunityService::class);
    }

    private function users(): UserRepository
    {
        return $this->app->service(UserRepository::class);
    }

    /** Konversation laden und Mitgliedschaft prüfen. */
    public function conversationFor(int $userId, int $conversationId): array
    {
        $row = $this->app->db()->one(
            'SELECT c.*, cm.last_read_message_id, cm.unread_count FROM conversations c JOIN conversation_members cm ON cm.conversation_id = c.id AND cm.user_id = ? WHERE c.id = ?',
            [$userId, $conversationId]
        );
        if (!$row) {
            throw HttpException::notFound('messages.not_found');
        }
        return $row;
    }

    private function otherUserId(array $conv, int $userId): int
    {
        return (int) $conv['user_low'] === $userId ? (int) $conv['user_high'] : (int) $conv['user_low'];
    }

    /** Startet (oder findet) eine Konversation mit einem Nutzer. */
    public function start(int $userId, string $username): array
    {
        $target = $this->community()->visibleUser($username, $this->users()->find($userId));
        $tid = (int) $target->id;
        $perm = $this->community()->canMessage($userId, $tid);
        $db = $this->app->db();
        [$lo, $hi] = $userId < $tid ? [$userId, $tid] : [$tid, $userId];
        $existing = $db->one('SELECT * FROM conversations WHERE user_low = ? AND user_high = ?', [$lo, $hi]);
        if ($existing) {
            if ($existing['status'] === 'declined' && (int) $existing['initiator_id'] === $userId) {
                throw HttpException::forbidden('messages.declined');
            }
            return $this->present($this->conversationFor($userId, (int) $existing['id']), $userId);
        }
        if (!$perm['allowed']) {
            throw HttpException::forbidden('messages.not_allowed');
        }
        $id = $db->transaction(function () use ($db, $lo, $hi, $userId, $perm): int {
            $id = $db->insert('conversations', [
                'user_low' => $lo, 'user_high' => $hi, 'initiator_id' => $userId,
                'status' => $perm['mode'] === 'direct' ? 'accepted' : 'pending', 'created_at' => $db->now(),
            ]);
            $db->insert('conversation_members', ['conversation_id' => $id, 'user_id' => $lo]);
            $db->insert('conversation_members', ['conversation_id' => $id, 'user_id' => $hi]);
            return $id;
        });
        return $this->present($this->conversationFor($userId, $id), $userId);
    }

    public function present(array $conv, int $userId): array
    {
        $other = $this->users()->find($this->otherUserId($conv, $userId));
        $last = $conv['last_message_id'] ? $this->app->db()->one('SELECT * FROM messages WHERE id = ?', [$conv['last_message_id']]) : null;
        return [
            'id' => (int) $conv['id'],
            'status' => $conv['status'],
            'is_initiator' => (int) $conv['initiator_id'] === $userId,
            'other' => $other ? $this->users()->card($other) : ['id' => 0, 'username' => '', 'display_name' => '—', 'avatar_url' => null, 'is_private' => true, 'is_owner' => false],
            'unread_count' => (int) ($conv['unread_count'] ?? 0),
            'last_message' => $last ? $this->presentMessage($last, $userId) : null,
            'last_message_at' => $conv['last_message_at'],
            'created_at' => $conv['created_at'],
        ];
    }

    public function presentMessage(array $m, int $userId): array
    {
        $deleted = $m['deleted_at'] !== null;
        return [
            'id' => (int) $m['id'],
            'conversation_id' => (int) $m['conversation_id'],
            'sender_id' => (int) $m['sender_id'],
            'mine' => (int) $m['sender_id'] === $userId,
            'body' => $deleted ? '' : $m['body'],
            'link' => !$deleted && $m['link_type'] ? ['type' => $m['link_type'], 'ref' => $m['link_ref']] : null,
            'deleted' => $deleted,
            'created_at' => $m['created_at'],
        ];
    }

    /** @return list<array<string,mixed>> */
    public function list(int $userId): array
    {
        $rows = $this->app->db()->all(
            'SELECT c.*, cm.last_read_message_id, cm.unread_count FROM conversations c JOIN conversation_members cm ON cm.conversation_id = c.id AND cm.user_id = ?
             WHERE NOT (c.status = \'declined\' AND c.initiator_id <> ?) AND NOT (c.status = \'pending\' AND c.last_message_id IS NULL AND c.initiator_id <> ?)
             ORDER BY COALESCE(c.last_message_at, c.created_at) DESC LIMIT 200',
            [$userId, $userId, $userId]
        );
        $out = [];
        foreach ($rows as $c) {
            $other = $this->otherUserId($c, $userId);
            if ($this->community()->hasBlocked($other, $userId) && $c['status'] !== 'accepted') {
                continue;
            }
            $out[] = $this->present($c, $userId);
        }
        return $out;
    }

    /**
     * Nachrichten einer Konversation: entweder ältere (before) oder neuere (after) ab einer ID.
     * @return array{messages:list<array<string,mixed>>, has_more:bool}
     */
    public function messages(int $userId, int $conversationId, int $before = 0, int $after = 0, int $limit = 40): array
    {
        $this->conversationFor($userId, $conversationId);
        $db = $this->app->db();
        if ($after > 0) {
            $rows = $db->all('SELECT * FROM messages WHERE conversation_id = ? AND id > ? ORDER BY id ASC LIMIT ' . (int) $limit, [$conversationId, $after]);
            return ['messages' => array_map(fn($m) => $this->presentMessage($m, $userId), $rows), 'has_more' => count($rows) === $limit];
        }
        $sql = 'SELECT * FROM messages WHERE conversation_id = ?';
        $params = [$conversationId];
        if ($before > 0) {
            $sql .= ' AND id < ?';
            $params[] = $before;
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . ($limit + 1);
        $rows = $db->all($sql, $params);
        $more = count($rows) > $limit;
        $rows = array_reverse(array_slice($rows, 0, $limit));
        return ['messages' => array_map(fn($m) => $this->presentMessage($m, $userId), $rows), 'has_more' => $more];
    }

    public function send(int $userId, int $conversationId, string $body, ?array $link): array
    {
        $conv = $this->conversationFor($userId, $conversationId);
        $other = $this->otherUserId($conv, $userId);
        if ($conv['status'] === 'declined') {
            throw HttpException::forbidden('messages.declined');
        }
        if ($conv['status'] === 'pending' && (int) $conv['initiator_id'] === $userId && $conv['last_message_id'] !== null) {
            throw HttpException::forbidden('messages.request_pending');
        }
        if ($this->community()->isBlocked($userId, $other)) {
            throw HttpException::forbidden('community.blocked');
        }
        $otherUser = $this->users()->find($other);
        if (!$otherUser || $otherUser->status !== 'active') {
            throw HttpException::forbidden('messages.not_allowed');
        }
        if ($conv['status'] === 'accepted' && $this->community()->areFriends($userId, $other) && !(bool) $otherUser->msg_from_friends) {
            // Freunde: Einstellung "Nachrichten von Freunden" gilt auch für bestehende Chats
            throw HttpException::forbidden('messages.not_allowed');
        }
        $body = trim($body);
        if ($body === '' && $link === null) {
            throw HttpException::validation(['body' => 'validation.message_empty']);
        }
        if (mb_strlen($body) > 2000) {
            throw HttpException::validation(['body' => 'validation.message_length']);
        }
        $this->app->limiter()->enforce('msg:' . $userId, 60, 60);
        $this->app->limiter()->enforce('msg-hour:' . $userId, 600, 3600);
        $linkType = null;
        $linkRef = null;
        if ($link !== null) {
            $linkType = (string) ($link['type'] ?? '');
            $linkRef = (string) ($link['ref'] ?? '');
            if (!in_array($linkType, ['profile', 'playlist'], true) || !preg_match('/^[A-Za-z0-9_.\-]{1,64}$/', $linkRef)) {
                throw HttpException::badRequest('messages.invalid_link');
            }
        }
        $db = $this->app->db();
        $id = $db->transaction(function () use ($db, $conversationId, $userId, $other, $body, $linkType, $linkRef, $conv): int {
            $now = $db->now();
            if ($conv['status'] === 'pending' && (int) $conv['initiator_id'] !== $userId) {
                // Antwort des Empfängers gilt als Annahme der Anfrage
                $db->update('conversations', ['status' => 'accepted'], 'id = ?', [$conversationId]);
            }
            $id = $db->insert('messages', ['conversation_id' => $conversationId, 'sender_id' => $userId, 'body' => $body, 'link_type' => $linkType, 'link_ref' => $linkRef, 'created_at' => $now]);
            $db->update('conversations', ['last_message_id' => $id, 'last_message_at' => $now], 'id = ?', [$conversationId]);
            $db->run('UPDATE conversation_members SET unread_count = unread_count + 1 WHERE conversation_id = ? AND user_id = ?', [$conversationId, $other]);
            $db->run('UPDATE conversation_members SET last_read_message_id = ? WHERE conversation_id = ? AND user_id = ?', [$id, $conversationId, $userId]);
            return $id;
        });
        $type = $conv['status'] === 'pending' ? 'message_request' : 'message';
        $this->app->service(NotificationService::class)->notify($other, $type, $userId, 'conversation', $conversationId, ['excerpt' => mb_substr($body, 0, 80)]);
        $m = $db->one('SELECT * FROM messages WHERE id = ?', [$id]);
        return $this->presentMessage($m ?? [], $userId);
    }

    public function markRead(int $userId, int $conversationId, int $upToMessageId = 0): void
    {
        $this->conversationFor($userId, $conversationId);
        $db = $this->app->db();
        $max = (int) $db->value('SELECT MAX(id) FROM messages WHERE conversation_id = ?', [$conversationId]);
        $upTo = $upToMessageId > 0 ? min($upToMessageId, $max) : $max;
        $db->run('UPDATE conversation_members SET last_read_message_id = GREATEST(last_read_message_id, ?), unread_count = (SELECT COUNT(*) FROM messages WHERE conversation_id = ? AND id > GREATEST(last_read_message_id, ?) AND sender_id <> ?) WHERE conversation_id = ? AND user_id = ?',
            [$upTo, $conversationId, $upTo, $userId, $conversationId, $userId]);
        $db->run('UPDATE notifications SET read_at = ? WHERE user_id = ? AND ref_type = \'conversation\' AND ref_id = ? AND read_at IS NULL', [$db->now(), $userId, $conversationId]);
    }

    public function respond(int $userId, int $conversationId, bool $accept): array
    {
        $conv = $this->conversationFor($userId, $conversationId);
        if ($conv['status'] !== 'pending' || (int) $conv['initiator_id'] === $userId) {
            throw HttpException::conflict('messages.no_request');
        }
        $this->app->db()->update('conversations', ['status' => $accept ? 'accepted' : 'declined'], 'id = ?', [$conversationId]);
        return $this->present($this->conversationFor($userId, $conversationId), $userId);
    }

    /**
     * Inkrementelles Update für Polling: Zähler + neue Nachrichten der offenen Konversation.
     */
    public function updates(int $userId, ?int $conversationId, int $afterMessageId): array
    {
        $db = $this->app->db();
        $out = [
            'unread_messages' => (int) $db->value('SELECT COALESCE(SUM(unread_count),0) FROM conversation_members WHERE user_id = ?', [$userId]),
            'unread_notifications' => (int) $db->value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$userId]),
            'pending_friend_requests' => (int) $db->value('SELECT COUNT(*) FROM friendships WHERE status = \'pending\' AND requester_id <> ? AND (user_low = ? OR user_high = ?)', [$userId, $userId, $userId]),
            'latest_conversation_at' => $db->value('SELECT MAX(c.last_message_at) FROM conversations c JOIN conversation_members cm ON cm.conversation_id = c.id WHERE cm.user_id = ?', [$userId]),
            'messages' => [],
            'conversation_status' => null,
            'server_time' => gmdate('c'),
        ];
        if ($conversationId) {
            try {
                $conv = $this->conversationFor($userId, $conversationId);
                $out['conversation_status'] = $conv['status'];
                $out['messages'] = $this->messages($userId, $conversationId, 0, $afterMessageId, 50)['messages'];
            } catch (HttpException) {
                $out['conversation_status'] = 'gone';
            }
        }
        return $out;
    }
}
