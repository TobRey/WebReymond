<?php
declare(strict_types=1);

namespace SwipeGroove\Http\Controllers;

use SwipeGroove\Auth\AccountService;
use SwipeGroove\Auth\SessionManager;
use SwipeGroove\Billing\SubscriptionService;
use SwipeGroove\Core\App;
use SwipeGroove\Core\HttpException;
use SwipeGroove\Core\I18n;
use SwipeGroove\Core\Request;
use SwipeGroove\Core\Response;
use SwipeGroove\Spotify\TokenStore;
use SwipeGroove\Users\ImageService;
use SwipeGroove\Users\UserRepository;

final class MeController
{
    public function __construct(private App $app)
    {
    }

    /** Vollständige Darstellung des eigenen Kontos (keine Tokens). */
    public function payload(object $user): array
    {
        $users = $this->app->service(UserRepository::class);
        $fresh = $users->find((int) $user->id) ?? $user;
        $subs = $this->app->service(SubscriptionService::class);
        $db = $this->app->db();
        return [
            'id' => (int) $fresh->id,
            'username' => $fresh->username,
            'display_name' => $fresh->display_name,
            'bio' => $fresh->bio,
            'avatar_url' => $users->imageUrl($fresh->avatar_path),
            'banner_url' => $users->imageUrl($fresh->banner_path),
            'background_url' => $users->imageUrl($fresh->background_path),
            'is_private' => (bool) $fresh->is_private,
            'lang' => $fresh->lang,
            'role' => $fresh->role,
            'is_admin' => $fresh->role === 'admin',
            'is_owner' => $users->isOwner((int) $fresh->id),
            'status' => $fresh->status,
            'onboarding_done' => (bool) $fresh->onboarding_done,
            'msg_from_friends' => (bool) $fresh->msg_from_friends,
            'msg_requests_allowed' => (bool) $fresh->msg_requests_allowed,
            'playback_enabled' => (bool) $fresh->playback_enabled,
            'premium' => $subs->summary($fresh),
            'spotify' => $this->app->service(TokenStore::class)->status((int) $fresh->id),
            'unread_messages' => (int) $db->value('SELECT COALESCE(SUM(unread_count),0) FROM conversation_members WHERE user_id = ?', [$fresh->id]),
            'unread_notifications' => (int) $db->value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$fresh->id]),
            'pending_friend_requests' => (int) $db->value('SELECT COUNT(*) FROM friendships WHERE status = \'pending\' AND requester_id <> ? AND (user_low = ? OR user_high = ?)', [$fresh->id, $fresh->id, $fresh->id]),
            'created_at' => $fresh->created_at,
        ];
    }

    public function me(Request $r): array
    {
        return $this->payload($r->user);
    }

    public function onboarding(Request $r): array
    {
        $users = $this->app->service(UserRepository::class);
        $username = strtolower($r->str('username'));
        $display = $r->str('display_name');
        $lang = I18n::normalize($r->str('lang', $r->user->lang));
        $fields = [];
        if (($e = UserRepository::validateUsername($username)) !== null) {
            $fields['username'] = $e;
        } elseif ($users->usernameTaken($username, $r->userId())) {
            $fields['username'] = 'validation.username_taken';
        }
        if (($e = UserRepository::validateDisplayName($display)) !== null) {
            $fields['display_name'] = $e;
        }
        if ($fields) {
            throw HttpException::validation($fields);
        }
        $db = $this->app->db();
        $db->update('users', ['username' => $username, 'display_name' => $display, 'lang' => $lang, 'onboarding_done' => 1, 'updated_at' => $db->now()], 'id = ?', [$r->userId()]);
        $this->app->service(SessionManager::class)->setLangCookie($lang);
        return $this->payload($r->user);
    }

    public function update(Request $r): array
    {
        $users = $this->app->service(UserRepository::class);
        $json = $r->json();
        $data = [];
        $fields = [];
        if (array_key_exists('username', $json)) {
            $u = strtolower(trim((string) $json['username']));
            if (($e = UserRepository::validateUsername($u)) !== null) {
                $fields['username'] = $e;
            } elseif ($users->usernameTaken($u, $r->userId())) {
                $fields['username'] = 'validation.username_taken';
            } else {
                $data['username'] = $u;
            }
        }
        if (array_key_exists('display_name', $json)) {
            $d = trim((string) $json['display_name']);
            if (($e = UserRepository::validateDisplayName($d)) !== null) {
                $fields['display_name'] = $e;
            } else {
                $data['display_name'] = $d;
            }
        }
        if (array_key_exists('bio', $json)) {
            $bio = trim((string) $json['bio']);
            if (mb_strlen($bio) > 500) {
                $fields['bio'] = 'validation.bio_length';
            } else {
                $data['bio'] = $bio === '' ? null : $bio;
            }
        }
        if (array_key_exists('lang', $json)) {
            $data['lang'] = I18n::normalize((string) $json['lang']);
        }
        foreach (['is_private', 'msg_from_friends', 'msg_requests_allowed', 'playback_enabled'] as $flag) {
            if (array_key_exists($flag, $json)) {
                $data[$flag] = (bool) $json[$flag] ? 1 : 0;
            }
        }
        if ($fields) {
            throw HttpException::validation($fields);
        }
        if ($data) {
            $data['updated_at'] = $this->app->db()->now();
            $this->app->db()->update('users', $data, 'id = ?', [$r->userId()]);
            if (isset($data['lang'])) {
                $this->app->service(SessionManager::class)->setLangCookie($data['lang']);
                $this->app->i18n->setLang($data['lang']);
            }
        }
        return $this->payload($r->user);
    }

    /** Sprache für nicht angemeldete Besucher (Cookie). */
    public function setLangGuest(Request $r): array
    {
        $lang = I18n::normalize($r->str('lang'));
        $this->app->service(SessionManager::class)->setLangCookie($lang);
        if ($r->user) {
            $this->app->db()->update('users', ['lang' => $lang, 'updated_at' => $this->app->db()->now()], 'id = ?', [$r->userId()]);
        }
        return ['lang' => $lang, 'i18n' => $this->app->i18n->bundle($lang)];
    }

    public function uploadImage(Request $r): array
    {
        $this->app->limiter()->enforce('img-upload:' . $r->userId(), 30, 3600);
        $kind = (string) ($r->attributes['kind'] ?? '');
        $bytes = ImageService::uploadedBytes('image');
        $crop = ImageService::cropFromInput($r->input('crop'));
        $this->app->service(ImageService::class)->storeUserImage($r->userId(), $kind, $bytes, $crop);
        return $this->payload($r->user);
    }

    public function deleteImage(Request $r): array
    {
        $this->app->service(ImageService::class)->deleteUserImage($r->userId(), (string) ($r->attributes['kind'] ?? ''));
        return $this->payload($r->user);
    }

    public function sessions(Request $r): array
    {
        $rows = $this->app->db()->all('SELECT id, user_agent, created_at, last_seen_at FROM sessions WHERE user_id = ? ORDER BY last_seen_at DESC', [$r->userId()]);
        return ['sessions' => array_map(fn(array $s) => [
            'current' => $s['id'] === $r->sessionId, 'user_agent' => $s['user_agent'], 'created_at' => $s['created_at'], 'last_seen_at' => $s['last_seen_at'],
        ], $rows)];
    }

    public function disconnectSpotify(Request $r): array
    {
        $this->app->service(TokenStore::class)->disconnect($r->userId());
        $this->app->db()->run('DELETE FROM playlist_cache WHERE user_id = ?', [$r->userId()]);
        return $this->payload($r->user);
    }

    /** Datenexport (JSON) – ohne Tokens. */
    public function export(Request $r): Response
    {
        $this->app->limiter()->enforce('export:' . $r->userId(), 5, 3600);
        $db = $this->app->db();
        $uid = $r->userId();
        $data = [
            'exported_at' => gmdate('c'),
            'profile' => $this->payload($r->user),
            'published_playlists' => $db->all('SELECT spotify_playlist_id, name, description, is_published, published_at, like_count, comment_count FROM published_playlists WHERE user_id = ?', [$uid]),
            'follows' => $db->all('SELECT u.username, f.created_at FROM follows f JOIN users u ON u.id = f.followed_id WHERE f.follower_id = ?', [$uid]),
            'followers' => $db->all('SELECT u.username, f.created_at FROM follows f JOIN users u ON u.id = f.follower_id WHERE f.followed_id = ?', [$uid]),
            'friends' => $db->all('SELECT u.username, fr.status, fr.created_at FROM friendships fr JOIN users u ON u.id = IF(fr.user_low = ?, fr.user_high, fr.user_low) WHERE fr.user_low = ? OR fr.user_high = ?', [$uid, $uid, $uid]),
            'likes' => $db->all('SELECT p.name, p.spotify_playlist_id, l.created_at FROM playlist_likes l JOIN published_playlists p ON p.id = l.published_playlist_id WHERE l.user_id = ?', [$uid]),
            'comments' => $db->all('SELECT c.body, c.created_at, c.updated_at, p.name AS playlist FROM playlist_comments c JOIN published_playlists p ON p.id = c.published_playlist_id WHERE c.user_id = ? AND c.deleted_at IS NULL', [$uid]),
            'messages_sent' => $db->all('SELECT m.body, m.created_at, m.conversation_id FROM messages m WHERE m.sender_id = ? AND m.deleted_at IS NULL ORDER BY m.id', [$uid]),
            'hidden_suggestions' => $db->all('SELECT spotify_playlist_id, track_id, created_at FROM swipe_hidden WHERE user_id = ?', [$uid]),
            'swipe_actions' => $db->all('SELECT spotify_playlist_id, track_id, action, status, created_at FROM swipe_actions WHERE user_id = ? ORDER BY id DESC LIMIT 5000', [$uid]),
            'cover_jobs' => $db->all('SELECT prompt, style, model, quality, status, created_at FROM cover_jobs WHERE user_id = ?', [$uid]),
            'subscription' => $db->one('SELECT status, current_period_start, current_period_end, cancel_at_period_end FROM subscriptions WHERE user_id = ?', [$uid]),
            'notifications' => $db->all('SELECT type, created_at, read_at FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 1000', [$uid]),
        ];
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $resp = Response::text($json);
        $resp->headers['Content-Type'] = 'application/json; charset=utf-8';
        $resp->headers['Content-Disposition'] = 'attachment; filename="swipegroove-export-' . $r->user->username . '.json"';
        $resp->headers['Cache-Control'] = 'no-store';
        return $resp;
    }

    public function deleteAccount(Request $r): array
    {
        if ($r->str('confirm') !== $r->user->username) {
            throw HttpException::badRequest('account.confirm_mismatch');
        }
        $this->app->service(AccountService::class)->deleteAccount($r->userId(), $r);
        $this->app->service(SessionManager::class)->logout($r);
        return ['ok' => true];
    }
}
