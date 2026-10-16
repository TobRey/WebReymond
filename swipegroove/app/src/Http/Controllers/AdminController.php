<?php
declare(strict_types=1);

namespace SwipeGroove\Http\Controllers;

use SwipeGroove\Admin\AuditLog;
use SwipeGroove\Admin\CronRunner;
use SwipeGroove\Auth\SessionManager;
use SwipeGroove\Billing\StripeClient;
use SwipeGroove\Core\App;
use SwipeGroove\Core\Config;
use SwipeGroove\Core\Crypto;
use SwipeGroove\Core\HttpException;
use SwipeGroove\Core\Request;
use SwipeGroove\Covers\CoverService;
use SwipeGroove\Covers\OpenAiImageProvider;
use SwipeGroove\Spotify\SpotifyAuth;
use SwipeGroove\Users\UserRepository;

final class AdminController
{
    public function __construct(private App $app)
    {
    }

    private function log(Request $r, string $action, ?string $type, ?string $id, array $details = []): void
    {
        $this->app->service(AuditLog::class)->record($r->userId(), $action, $type, $id, $details, $r);
    }

    public function dashboard(Request $r): array
    {
        $db = $this->app->db();
        $since24 = gmdate('Y-m-d H:i:s', time() - 86400);
        $month = gmdate('Y-m-01 00:00:00');
        return [
            'users' => [
                'total' => (int) $db->value('SELECT COUNT(*) FROM users WHERE deleted_at IS NULL'),
                'active_7d' => (int) $db->value('SELECT COUNT(*) FROM users WHERE last_login_at > ?', [gmdate('Y-m-d H:i:s', time() - 7 * 86400)]),
                'suspended' => (int) $db->value('SELECT COUNT(*) FROM users WHERE status = \'suspended\''),
                'new_24h' => (int) $db->value('SELECT COUNT(*) FROM users WHERE created_at > ?', [$since24]),
            ],
            'reports_open' => (int) $db->value('SELECT COUNT(*) FROM reports WHERE status = \'open\''),
            'subscriptions' => [
                'active' => (int) $db->value('SELECT COUNT(*) FROM subscriptions WHERE status IN (\'active\',\'trialing\')'),
                'past_due' => (int) $db->value('SELECT COUNT(*) FROM subscriptions WHERE status = \'past_due\''),
                'canceled' => (int) $db->value('SELECT COUNT(*) FROM subscriptions WHERE status = \'canceled\''),
            ],
            'covers' => [
                'month_done' => (int) $db->value('SELECT COUNT(*) FROM cover_jobs WHERE created_at >= ? AND status IN (\'done\',\'applied\')', [$month]),
                'month_failed' => (int) $db->value('SELECT COUNT(*) FROM cover_jobs WHERE created_at >= ? AND status = \'failed\'', [$month]),
                'month_cost_usd' => round((float) $db->value('SELECT COALESCE(SUM(cost_estimate_usd),0) FROM cover_jobs WHERE created_at >= ? AND counts_against_quota = 1 AND status IN (\'done\',\'applied\',\'failed\')', [$month]), 4),
                'total_cost_usd' => round((float) $db->value('SELECT COALESCE(SUM(cost_estimate_usd),0) FROM cover_jobs WHERE status IN (\'done\',\'applied\') OR (status = \'failed\' AND counts_against_quota = 1)'), 4),
            ],
            'errors_24h' => (int) $db->value('SELECT COUNT(*) FROM error_log WHERE created_at > ?', [$since24]),
            'integrations' => $this->integrationStats(),
            'operation_mode' => $this->app->settings()->str('operation_mode'),
            'registration_open' => $this->app->settings()->bool('registration_open'),
            'last_cron_at' => $this->app->settings()->get('last_cron_at'),
            'version' => SG_VERSION,
            'php_version' => PHP_VERSION,
        ];
    }

    private function integrationStats(): array
    {
        $db = $this->app->db();
        $since = gmdate('Y-m-d H:i:s', time() - 86400);
        $out = [];
        foreach (['spotify', 'openai', 'stripe'] as $svc) {
            $row = $db->one('SELECT COUNT(*) AS total, SUM(status >= 400 OR status = 0) AS errors, SUM(status = 429) AS rate_limited, AVG(duration_ms) AS avg_ms FROM integration_log WHERE service = ? AND created_at > ?', [$svc, $since]);
            $out[$svc] = [
                'calls_24h' => (int) ($row['total'] ?? 0),
                'errors_24h' => (int) ($row['errors'] ?? 0),
                'rate_limited_24h' => (int) ($row['rate_limited'] ?? 0),
                'avg_ms' => (int) ($row['avg_ms'] ?? 0),
                'error_rate' => ($row['total'] ?? 0) > 0 ? round(((int) $row['errors']) / (int) $row['total'], 3) : 0,
            ];
        }
        return $out;
    }

    public function users(Request $r): array
    {
        $db = $this->app->db();
        $q = trim($r->queryStr('q'));
        $status = $r->queryStr('status');
        $sql = 'SELECT u.*, (SELECT 1 FROM platform_owner o WHERE o.user_id = u.id) AS is_owner, s.status AS sub_status FROM users u LEFT JOIN subscriptions s ON s.user_id = u.id WHERE u.deleted_at IS NULL';
        $params = [];
        if ($q !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], mb_substr($q, 0, 40)) . '%';
            $sql .= ' AND (u.username LIKE ? OR u.display_name LIKE ? OR u.spotify_user_id = ?)';
            array_push($params, $like, $like, $q);
        }
        if (in_array($status, ['active', 'suspended'], true)) {
            $sql .= ' AND u.status = ?';
            $params[] = $status;
        }
        $page = max(0, $r->queryInt('page'));
        $sql .= ' ORDER BY u.created_at DESC LIMIT 50 OFFSET ' . ($page * 50);
        return ['users' => array_map([$this, 'adminUser'], $db->all($sql, $params)), 'page' => $page];
    }

    private function adminUser(array $u): array
    {
        $users = $this->app->service(UserRepository::class);
        return [
            'id' => (int) $u['id'], 'username' => $u['username'], 'display_name' => $u['display_name'],
            'avatar_url' => $users->imageUrl($u['avatar_path']), 'role' => $u['role'], 'status' => $u['status'],
            'is_owner' => (bool) ($u['is_owner'] ?? 0), 'is_private' => (bool) $u['is_private'], 'lang' => $u['lang'],
            'created_at' => $u['created_at'], 'last_login_at' => $u['last_login_at'], 'suspended_reason' => $u['suspended_reason'],
            'premium_manual_until' => $u['premium_manual_until'], 'subscription_status' => $u['sub_status'] ?? null,
        ];
    }

    public function user(Request $r): array
    {
        $db = $this->app->db();
        $id = (int) $r->attributes['id'];
        $u = $db->one('SELECT u.*, (SELECT 1 FROM platform_owner o WHERE o.user_id = u.id) AS is_owner, s.status AS sub_status FROM users u LEFT JOIN subscriptions s ON s.user_id = u.id WHERE u.id = ?', [$id]);
        if (!$u) {
            throw HttpException::notFound('error.user_not_found');
        }
        $spotify = $db->one('SELECT status, authorized_at, last_refresh_at, last_error, scopes FROM spotify_accounts WHERE user_id = ?', [$id]);
        return [
            'user' => $this->adminUser($u),
            'spotify' => $spotify,
            'subscription' => $db->one('SELECT status, livemode, current_period_end, cancel_at_period_end, last_payment_error, stripe_customer_id FROM subscriptions WHERE user_id = ?', [$id]),
            'stats' => [
                'published' => (int) $db->value('SELECT COUNT(*) FROM published_playlists WHERE user_id = ? AND is_published = 1', [$id]),
                'comments' => (int) $db->value('SELECT COUNT(*) FROM playlist_comments WHERE user_id = ? AND deleted_at IS NULL', [$id]),
                'reports_against' => (int) $db->value('SELECT COUNT(*) FROM reports WHERE target_user_id = ?', [$id]),
                'covers' => (int) $db->value('SELECT COUNT(*) FROM cover_jobs WHERE user_id = ? AND status IN (\'done\',\'applied\')', [$id]),
            ],
        ];
    }

    public function suspend(Request $r): array
    {
        $id = (int) $r->attributes['id'];
        $users = $this->app->service(UserRepository::class);
        if ($users->isOwner($id) || $id === $r->userId()) {
            throw HttpException::forbidden('admin.cannot_suspend');
        }
        $db = $this->app->db();
        $db->update('users', ['status' => 'suspended', 'suspended_reason' => mb_substr($r->str('reason'), 0, 255) ?: null, 'updated_at' => $db->now()], 'id = ?', [$id]);
        $this->app->service(SessionManager::class)->logoutEverywhere($id);
        $db->run('UPDATE published_playlists SET is_published = 0 WHERE user_id = ?', [$id]);
        $this->log($r, 'user.suspend', 'user', (string) $id, ['reason' => $r->str('reason')]);
        return ['ok' => true];
    }

    public function unsuspend(Request $r): array
    {
        $id = (int) $r->attributes['id'];
        $db = $this->app->db();
        $db->update('users', ['status' => 'active', 'suspended_reason' => null, 'updated_at' => $db->now()], 'id = ?', [$id]);
        $this->log($r, 'user.unsuspend', 'user', (string) $id);
        return ['ok' => true];
    }

    public function grantPremium(Request $r): array
    {
        $id = (int) $r->attributes['id'];
        $days = $r->int('days');
        $db = $this->app->db();
        $until = $days > 0 ? gmdate('Y-m-d H:i:s', time() + $days * 86400) : null;
        $db->update('users', ['premium_manual_until' => $until, 'updated_at' => $db->now()], 'id = ?', [$id]);
        $this->log($r, 'user.premium_manual', 'user', (string) $id, ['days' => $days]);
        return ['premium_manual_until' => $until];
    }

    public function reports(Request $r): array
    {
        $db = $this->app->db();
        $status = $r->queryStr('status', 'open');
        $sql = 'SELECT rp.*, u.username AS reporter_username, t.username AS target_username FROM reports rp JOIN users u ON u.id = rp.reporter_id LEFT JOIN users t ON t.id = rp.target_user_id';
        $params = [];
        if (in_array($status, ['open', 'resolved', 'dismissed'], true)) {
            $sql .= ' WHERE rp.status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY rp.created_at DESC LIMIT 100';
        return ['reports' => $db->all($sql, $params)];
    }

    /** Gemeldeter Inhalt mit minimalem Kontext (Nachrichten: nur die gemeldete Nachricht + 2 davor). Zugriff wird protokolliert. */
    public function reportDetail(Request $r): array
    {
        $db = $this->app->db();
        $id = (int) $r->attributes['id'];
        $rep = $db->one('SELECT rp.*, u.username AS reporter_username, t.username AS target_username FROM reports rp JOIN users u ON u.id = rp.reporter_id LEFT JOIN users t ON t.id = rp.target_user_id WHERE rp.id = ?', [$id]);
        if (!$rep) {
            throw HttpException::notFound();
        }
        $content = null;
        switch ($rep['target_type']) {
            case 'comment':
                $content = $db->one('SELECT c.id, c.body, c.created_at, c.deleted_at, u.username FROM playlist_comments c JOIN users u ON u.id = c.user_id WHERE c.id = ?', [$rep['target_id']]);
                break;
            case 'message':
                $m = $db->one('SELECT * FROM messages WHERE id = ?', [$rep['target_id']]);
                if ($m) {
                    $ctx = $db->all('SELECT m.id, m.body, m.created_at, m.deleted_at, u.username FROM messages m JOIN users u ON u.id = m.sender_id WHERE m.conversation_id = ? AND m.id <= ? ORDER BY m.id DESC LIMIT 3', [$m['conversation_id'], $m['id']]);
                    $content = ['messages' => array_reverse($ctx)];
                    $this->log($r, 'moderation.view_message', 'message', (string) $m['id'], ['report_id' => $id]);
                }
                break;
            case 'playlist':
                $content = $db->one('SELECT id, name, description, image_url, is_published FROM published_playlists WHERE id = ?', [$rep['target_id']]);
                break;
            case 'user':
                $u = $db->one('SELECT id, username, display_name, bio, status FROM users WHERE id = ?', [$rep['target_id']]);
                $content = $u;
                break;
        }
        return ['report' => $rep, 'content' => $content];
    }

    public function resolveReport(Request $r): array
    {
        $db = $this->app->db();
        $id = (int) $r->attributes['id'];
        $status = $r->str('status') === 'dismissed' ? 'dismissed' : 'resolved';
        $db->update('reports', ['status' => $status, 'resolved_by' => $r->userId(), 'resolved_at' => $db->now(), 'resolution_note' => mb_substr($r->str('note'), 0, 500) ?: null], 'id = ?', [$id]);
        $this->log($r, 'report.' . $status, 'report', (string) $id, ['note' => $r->str('note')]);
        return ['ok' => true];
    }

    public function removeComment(Request $r): array
    {
        $db = $this->app->db();
        $id = (int) $r->attributes['id'];
        $c = $db->one('SELECT * FROM playlist_comments WHERE id = ? AND deleted_at IS NULL', [$id]);
        if ($c) {
            $db->update('playlist_comments', ['deleted_at' => $db->now(), 'deleted_by' => $r->userId()], 'id = ?', [$id]);
            $db->run('UPDATE published_playlists SET comment_count = GREATEST(0, CAST(comment_count AS SIGNED) - 1) WHERE id = ?', [$c['published_playlist_id']]);
        }
        $this->log($r, 'moderation.remove_comment', 'comment', (string) $id);
        return ['ok' => true];
    }

    public function removeMessage(Request $r): array
    {
        $db = $this->app->db();
        $id = (int) $r->attributes['id'];
        $db->run('UPDATE messages SET deleted_at = ?, body = \'\' WHERE id = ?', [$db->now(), $id]);
        $this->log($r, 'moderation.remove_message', 'message', (string) $id);
        return ['ok' => true];
    }

    public function unpublishPlaylist(Request $r): array
    {
        $db = $this->app->db();
        $id = (int) $r->attributes['id'];
        $db->run('UPDATE published_playlists SET is_published = 0, updated_at = ? WHERE id = ?', [$db->now(), $id]);
        $this->log($r, 'moderation.unpublish_playlist', 'playlist', (string) $id);
        return ['ok' => true];
    }

    public function subscriptions(Request $r): array
    {
        $rows = $this->app->db()->all('SELECT s.*, u.username FROM subscriptions s JOIN users u ON u.id = s.user_id ORDER BY s.updated_at DESC LIMIT 200');
        return ['subscriptions' => array_map(static fn(array $s) => [
            'user_id' => (int) $s['user_id'], 'username' => $s['username'], 'status' => $s['status'], 'livemode' => (bool) $s['livemode'],
            'current_period_end' => $s['current_period_end'], 'cancel_at_period_end' => (bool) $s['cancel_at_period_end'],
            'last_payment_error' => $s['last_payment_error'], 'customer' => $s['stripe_customer_id'] ? Crypto::mask((string) $s['stripe_customer_id']) : null, 'updated_at' => $s['updated_at'],
        ], $rows), 'events' => $this->app->db()->all('SELECT event_id, type, status, livemode, received_at, error FROM stripe_events ORDER BY received_at DESC LIMIT 50')];
    }

    public function errors(Request $r): array
    {
        return ['errors' => $this->app->db()->all('SELECT * FROM error_log ORDER BY id DESC LIMIT 200')];
    }

    public function integrations(Request $r): array
    {
        $cfg = $this->app->config;
        $db = $this->app->db();
        return [
            'stats' => $this->integrationStats(),
            'recent_errors' => $db->all('SELECT service, method, endpoint, status, error, created_at FROM integration_log WHERE status >= 400 OR status = 0 ORDER BY id DESC LIMIT 50'),
            'spotify' => [
                'configured' => $this->app->service(SpotifyAuth::class)->isConfigured(),
                'client_id' => Crypto::mask($cfg?->string('spotify.client_id')),
                'client_secret_set' => ($cfg?->string('spotify.client_secret') ?? '') !== '',
                'redirect_uri' => $this->app->service(SpotifyAuth::class)->redirectUri(),
                'scopes' => $this->app->service(SpotifyAuth::class)->scopes(),
                'connected_accounts' => (int) $db->value('SELECT COUNT(*) FROM spotify_accounts WHERE status = \'connected\''),
                'revoked_accounts' => (int) $db->value('SELECT COUNT(*) FROM spotify_accounts WHERE status = \'revoked\''),
                'playback_mode' => $this->app->settings()->str('playback_mode'),
            ],
            'openai' => [
                'configured' => $this->app->service(OpenAiImageProvider::class)->isConfigured(),
                'api_key' => Crypto::mask($cfg?->string('images.openai_api_key')),
                'model' => $this->app->settings()->str('cover_model'),
                'quality' => $this->app->settings()->str('cover_quality'),
                'models' => OpenAiImageProvider::MODELS,
                'qualities' => OpenAiImageProvider::QUALITIES,
                'price_table' => $this->app->settings()->get('cover_price_table'),
            ],
            'stripe' => [
                'configured' => $this->app->service(StripeClient::class)->isConfigured(),
                'mode' => $cfg?->string('stripe.mode', 'test'),
                'secret_key' => Crypto::mask($cfg?->string('stripe.secret_key')),
                'publishable_key' => Crypto::mask($cfg?->string('stripe.publishable_key')),
                'webhook_secret_set' => ($cfg?->string('stripe.webhook_secret') ?? '') !== '',
                'price_id' => $cfg?->string('stripe.price_id'),
                'webhook_url' => $this->app->url->to('/webhooks/stripe'),
            ],
            'cron' => [
                'last_run' => $this->app->settings()->get('last_cron_at'),
                'command' => 'php ' . $this->app->root . '/cron/run.php',
                'url' => $this->app->url->to('/cron/run', ['key' => Crypto::mask($cfg?->string('app.cron_key'))]),
            ],
        ];
    }

    /** Integrationsschlüssel nachträglich ergänzen/ändern (werden in config/config.php geschrieben, nie angezeigt). */
    public function updateIntegrations(Request $r): array
    {
        $cfg = $this->app->config?->all() ?? [];
        $json = $r->json();
        $changed = [];
        $map = [
            'spotify_client_id' => ['spotify', 'client_id', '/^[a-f0-9]{32}$/i'],
            'spotify_client_secret' => ['spotify', 'client_secret', null],
            'openai_api_key' => ['images', 'openai_api_key', null],
            'stripe_secret_key' => ['stripe', 'secret_key', null],
            'stripe_publishable_key' => ['stripe', 'publishable_key', null],
            'stripe_webhook_secret' => ['stripe', 'webhook_secret', null],
            'stripe_price_id' => ['stripe', 'price_id', null],
            'stripe_mode' => ['stripe', 'mode', '/^(test|live)$/'],
        ];
        foreach ($map as $field => [$section, $key, $pattern]) {
            if (!array_key_exists($field, $json)) {
                continue;
            }
            $val = trim((string) $json[$field]);
            if ($val !== '' && $pattern && !preg_match($pattern, $val)) {
                throw HttpException::validation([$field => 'validation.invalid']);
            }
            $cfg[$section][$key] = $val;
            $changed[] = $field;
        }
        if ($changed) {
            Config::write($this->app->root, $cfg);
            $this->app->config = new Config($cfg);
            $this->log($r, 'integrations.update', null, null, ['fields' => $changed]);
        }
        return ['updated' => $changed];
    }

    public function settings(Request $r): array
    {
        $s = $this->app->settings();
        return [
            'operation_mode' => $s->str('operation_mode'),
            'registration_open' => $s->bool('registration_open'),
            'playback_mode' => $s->str('playback_mode'),
            'premium_price_chf' => $s->str('premium_price_chf'),
            'premium_cover_quota' => $s->int('premium_cover_quota'),
            'cover_model' => $s->str('cover_model'),
            'cover_quality' => $s->str('cover_quality'),
            'cover_price_table' => $s->get('cover_price_table'),
            'max_parallel_cover_jobs' => $s->int('max_parallel_cover_jobs'),
            'spotify_cache_ttl_minutes' => $s->int('spotify_cache_ttl_minutes'),
            'spotify_items_cache_ttl_minutes' => $s->int('spotify_items_cache_ttl_minutes'),
            'legal_operator_name' => $s->str('legal_operator_name'),
            'legal_operator_address' => $s->str('legal_operator_address'),
            'legal_operator_email' => $s->str('legal_operator_email'),
            'legal_operator_country' => $s->str('legal_operator_country'),
        ];
    }

    public function updateSettings(Request $r): array
    {
        $s = $this->app->settings();
        $json = $r->json();
        $changed = [];
        if (isset($json['operation_mode']) && in_array($json['operation_mode'], ['closed_beta', 'public'], true) && $s->bool('owner_registered')) {
            $s->set('operation_mode', $json['operation_mode']);
            if ($json['operation_mode'] === 'public') {
                $s->set('registration_open', true);
            }
            $changed[] = 'operation_mode';
        }
        if (array_key_exists('registration_open', $json) && $s->bool('owner_registered')) {
            $s->set('registration_open', (bool) $json['registration_open']);
            $changed[] = 'registration_open';
        }
        if (isset($json['playback_mode']) && in_array($json['playback_mode'], ['off', 'connect'], true)) {
            $s->set('playback_mode', $json['playback_mode']);
            $changed[] = 'playback_mode';
        }
        if (isset($json['cover_model']) && in_array($json['cover_model'], OpenAiImageProvider::MODELS, true)) {
            $s->set('cover_model', $json['cover_model']);
            $changed[] = 'cover_model';
        }
        if (isset($json['cover_quality']) && in_array($json['cover_quality'], OpenAiImageProvider::QUALITIES, true)) {
            $s->set('cover_quality', $json['cover_quality']);
            $changed[] = 'cover_quality';
        }
        if (isset($json['cover_price_table']) && is_array($json['cover_price_table'])) {
            $table = [];
            foreach ($json['cover_price_table'] as $model => $q) {
                if (!is_array($q)) {
                    continue;
                }
                foreach ($q as $quality => $price) {
                    if (is_numeric($price)) {
                        $table[(string) $model][(string) $quality] = round((float) $price, 4);
                    }
                }
            }
            $s->set('cover_price_table', $table);
            $changed[] = 'cover_price_table';
        }
        foreach (['max_parallel_cover_jobs' => [1, 10], 'spotify_cache_ttl_minutes' => [5, 1440], 'spotify_items_cache_ttl_minutes' => [1, 1440]] as $key => [$min, $max]) {
            if (isset($json[$key]) && is_numeric($json[$key])) {
                $s->set($key, max($min, min($max, (int) $json[$key])));
                $changed[] = $key;
            }
        }
        foreach (['legal_operator_name', 'legal_operator_address', 'legal_operator_email', 'legal_operator_country'] as $key) {
            if (array_key_exists($key, $json)) {
                $s->set($key, mb_substr(trim((string) $json[$key]), 0, 500));
                $changed[] = $key;
            }
        }
        // Preis und Kontingent sind bewusst nur über die Datenbank/Config änderbar (Produktentscheidung), nicht über die UI.
        if ($changed) {
            $this->log($r, 'settings.update', null, null, ['fields' => $changed]);
        }
        return $this->settings($r);
    }

    public function audit(Request $r): array
    {
        return ['entries' => $this->app->db()->all('SELECT a.*, u.username AS admin_username FROM audit_log a LEFT JOIN users u ON u.id = a.admin_id ORDER BY a.id DESC LIMIT 200')];
    }

    public function covers(Request $r): array
    {
        $db = $this->app->db();
        return [
            'jobs' => $db->all('SELECT j.job_uuid, j.user_id, u.username, j.model, j.quality, j.status, j.counts_against_quota, j.cost_estimate_usd, j.error, j.created_at FROM cover_jobs j JOIN users u ON u.id = j.user_id ORDER BY j.id DESC LIMIT 100'),
            'by_month' => $db->all('SELECT DATE_FORMAT(created_at, \'%Y-%m\') AS month, COUNT(*) AS jobs, SUM(status IN (\'done\',\'applied\')) AS done, SUM(status = \'failed\') AS failed, ROUND(SUM(CASE WHEN counts_against_quota = 1 OR status IN (\'done\',\'applied\') THEN cost_estimate_usd ELSE 0 END), 4) AS cost_usd FROM cover_jobs GROUP BY month ORDER BY month DESC LIMIT 12'),
            'top_users' => $db->all('SELECT u.username, COUNT(*) AS jobs, ROUND(SUM(j.cost_estimate_usd), 4) AS cost_usd FROM cover_jobs j JOIN users u ON u.id = j.user_id WHERE j.status IN (\'done\',\'applied\') GROUP BY u.id ORDER BY jobs DESC LIMIT 10'),
            'estimate_note' => 'Kosten sind Schätzungen aus der konfigurierten Preistabelle (USD/Bild), nicht die Abrechnung des Anbieters.',
        ];
    }

    public function runCron(Request $r): array
    {
        $this->log($r, 'cron.manual', null, null);
        return $this->app->service(CronRunner::class)->run();
    }
}
