<?php
declare(strict_types=1);

namespace SwipeGroove\Http;

use SwipeGroove\Core\Router;
use SwipeGroove\Http\Controllers as C;

/**
 * Routentabelle. Optionen: auth = none|user|admin|premium, csrf = bool.
 */
final class Routes
{
    public static function register(Router $r): void
    {
        $pub = ['auth' => 'none'];
        $pubNoCsrf = ['auth' => 'none', 'csrf' => false];
        $admin = ['auth' => 'admin'];

        // --- Öffentlich / Auth
        $r->get('/install', [C\SystemController::class, 'installedNotice'], $pub);
        $r->get('/auth/spotify/start', [C\AuthController::class, 'start'], $pub);
        $r->get('/auth/spotify/callback', [C\AuthController::class, 'callback'], $pub);
        $r->post('/api/auth/setup-start', [C\AuthController::class, 'setupStart'], $pub);
        $r->post('/api/auth/logout', [C\AuthController::class, 'logout']);
        $r->post('/api/auth/logout-all', [C\AuthController::class, 'logoutAll']);
        $r->get('/api/i18n/{lang}', [C\I18nController::class, 'bundle'], $pub);
        $r->get('/api/platform', [C\SystemController::class, 'platform'], $pub);
        $r->post('/api/lang', [C\MeController::class, 'setLangGuest'], $pub);
        $r->post('/webhooks/stripe', [C\BillingController::class, 'webhook'], $pubNoCsrf);
        $r->get('/cron/run', [C\SystemController::class, 'cron'], $pubNoCsrf);
        $r->get('/media/cover/{id}', [C\CoverController::class, 'image']);
        $r->get('/api/health', [C\SystemController::class, 'health'], $pub);

        // --- Eigenes Konto
        $r->get('/api/me', [C\MeController::class, 'me']);
        $r->patch('/api/me', [C\MeController::class, 'update']);
        $r->post('/api/me/onboarding', [C\MeController::class, 'onboarding']);
        $r->post('/api/me/image/{kind}', [C\MeController::class, 'uploadImage']);
        $r->delete('/api/me/image/{kind}', [C\MeController::class, 'deleteImage']);
        $r->get('/api/me/export', [C\MeController::class, 'export']);
        $r->delete('/api/me', [C\MeController::class, 'deleteAccount']);
        $r->post('/api/me/spotify/disconnect', [C\MeController::class, 'disconnectSpotify']);
        $r->post('/api/me/swipes/reset', [C\SwipeController::class, 'resetHidden']);
        $r->get('/api/me/sessions', [C\MeController::class, 'sessions']);

        // --- Playlists (Spotify)
        $r->get('/api/playlists', [C\PlaylistController::class, 'index']);
        $r->post('/api/playlists/sync', [C\PlaylistController::class, 'sync']);
        $r->post('/api/playlists', [C\PlaylistController::class, 'create']);
        $r->get('/api/playlists/empty', [C\PlaylistController::class, 'emptyCandidates']);
        $r->post('/api/playlists/empty/remove', [C\PlaylistController::class, 'removeEmpty']);
        $r->post('/api/playlists/merge', [C\PlaylistController::class, 'merge']);
        $r->post('/api/playlists/move', [C\PlaylistController::class, 'move']);
        $r->post('/api/playlists/copy', [C\PlaylistController::class, 'copy']);
        $r->get('/api/playlists/operations/{id}', [C\PlaylistController::class, 'operation']);
        $r->post('/api/playlists/operations/{id}/resume', [C\PlaylistController::class, 'resumeOperation']);
        $r->get('/api/playlists/{id}', [C\PlaylistController::class, 'show']);
        $r->patch('/api/playlists/{id}', [C\PlaylistController::class, 'update']);
        $r->post('/api/playlists/{id}/cover', [C\PlaylistController::class, 'uploadCover']);
        $r->post('/api/playlists/{id}/items', [C\PlaylistController::class, 'addItems']);
        $r->post('/api/playlists/{id}/items/remove', [C\PlaylistController::class, 'removeItems']);
        $r->post('/api/playlists/{id}/items/reorder', [C\PlaylistController::class, 'reorder']);
        $r->get('/api/playlists/{id}/duplicates', [C\PlaylistController::class, 'duplicates']);
        $r->post('/api/playlists/{id}/duplicates/remove', [C\PlaylistController::class, 'removeDuplicates']);
        $r->post('/api/playlists/{id}/duplicate', [C\PlaylistController::class, 'duplicatePlaylist']);
        $r->post('/api/playlists/{id}/publish', [C\PlaylistController::class, 'publish']);
        $r->post('/api/playlists/{id}/unfollow', [C\PlaylistController::class, 'unfollow']);
        $r->get('/api/spotify/search', [C\PlaylistController::class, 'search']);
        $r->get('/api/spotify/status', [C\PlaylistController::class, 'spotifyStatus']);

        // --- Swipe-Feed
        $r->get('/api/swipe/targets', [C\SwipeController::class, 'targets']);
        $r->get('/api/swipe/feed', [C\SwipeController::class, 'feed']);
        $r->post('/api/swipe/action', [C\SwipeController::class, 'action']);
        $r->post('/api/swipe/undo', [C\SwipeController::class, 'undo']);
        $r->get('/api/swipe/artists', [C\SwipeController::class, 'searchArtists']);
        $r->get('/api/playback/devices', [C\SwipeController::class, 'devices']);
        $r->post('/api/playback/play', [C\SwipeController::class, 'play']);
        $r->post('/api/playback/pause', [C\SwipeController::class, 'pause']);
        $r->get('/api/playback/token', [C\SwipeController::class, 'playerToken']);
        $r->post('/api/playback/seek', [C\SwipeController::class, 'seek']);
        $r->get('/api/playback/state', [C\SwipeController::class, 'state']);

        // --- Community (Lesen auch ohne Konto; Privatsphäre-/Blockregeln greifen im Service)
        $r->get('/api/community/search', [C\CommunityController::class, 'search'], $pub);
        $r->get('/api/community/feed', [C\CommunityController::class, 'feed'], $pub);
        $r->get('/api/community/playlists/{id}', [C\CommunityController::class, 'playlist'], $pub);
        $r->post('/api/community/playlists/{id}/like', [C\CommunityController::class, 'like']);
        $r->delete('/api/community/playlists/{id}/like', [C\CommunityController::class, 'unlike']);
        $r->get('/api/community/playlists/{id}/likes', [C\CommunityController::class, 'likes'], $pub);
        $r->get('/api/community/playlists/{id}/comments', [C\CommunityController::class, 'comments'], $pub);
        $r->post('/api/community/playlists/{id}/comments', [C\CommunityController::class, 'addComment']);
        $r->patch('/api/community/comments/{id}', [C\CommunityController::class, 'editComment']);
        $r->delete('/api/community/comments/{id}', [C\CommunityController::class, 'deleteComment']);
        $r->get('/api/users/search', [C\CommunityController::class, 'searchUsers'], $pub);
        $r->get('/api/users/{username}', [C\CommunityController::class, 'profile'], $pub);
        $r->get('/api/users/{username}/playlists', [C\CommunityController::class, 'userPlaylists'], $pub);
        $r->get('/api/users/{username}/followers', [C\CommunityController::class, 'followers'], $pub);
        $r->get('/api/users/{username}/following', [C\CommunityController::class, 'following'], $pub);
        $r->get('/api/users/{username}/friends', [C\CommunityController::class, 'friends'], $pub);
        $r->post('/api/users/{username}/follow', [C\CommunityController::class, 'follow']);
        $r->delete('/api/users/{username}/follow', [C\CommunityController::class, 'unfollow']);
        $r->post('/api/users/{username}/friend-request', [C\CommunityController::class, 'friendRequest']);
        $r->post('/api/users/{username}/friend-accept', [C\CommunityController::class, 'friendAccept']);
        $r->post('/api/users/{username}/friend-decline', [C\CommunityController::class, 'friendDecline']);
        $r->delete('/api/users/{username}/friend', [C\CommunityController::class, 'friendRemove']);
        $r->post('/api/users/{username}/block', [C\CommunityController::class, 'block']);
        $r->delete('/api/users/{username}/block', [C\CommunityController::class, 'unblock']);
        $r->get('/api/friends/requests', [C\CommunityController::class, 'friendRequests']);
        $r->get('/api/blocks', [C\CommunityController::class, 'blocks']);
        $r->post('/api/reports', [C\CommunityController::class, 'report']);

        // --- Nachrichten & Benachrichtigungen
        $r->get('/api/conversations', [C\MessageController::class, 'conversations']);
        $r->post('/api/conversations', [C\MessageController::class, 'start']);
        $r->get('/api/conversations/{id}/messages', [C\MessageController::class, 'messages']);
        $r->post('/api/conversations/{id}/messages', [C\MessageController::class, 'send']);
        $r->post('/api/conversations/{id}/read', [C\MessageController::class, 'markRead']);
        $r->post('/api/conversations/{id}/accept', [C\MessageController::class, 'accept']);
        $r->post('/api/conversations/{id}/decline', [C\MessageController::class, 'decline']);
        $r->get('/api/updates', [C\MessageController::class, 'updates']);
        $r->get('/api/notifications', [C\NotificationController::class, 'index']);
        $r->post('/api/notifications/read', [C\NotificationController::class, 'markRead']);

        // --- KI-Cover
        $r->get('/api/covers', [C\CoverController::class, 'index']);
        $r->get('/api/covers/quota', [C\CoverController::class, 'quota']);
        $r->post('/api/covers', [C\CoverController::class, 'generate']);
        $r->get('/api/covers/{id}', [C\CoverController::class, 'show']);
        $r->post('/api/covers/{id}/apply', [C\CoverController::class, 'apply']);
        $r->delete('/api/covers/{id}', [C\CoverController::class, 'delete']);

        // --- Abo
        $r->get('/api/billing', [C\BillingController::class, 'status']);
        $r->post('/api/billing/checkout', [C\BillingController::class, 'checkout']);
        $r->post('/api/billing/portal', [C\BillingController::class, 'portal']);
        $r->post('/api/billing/refresh', [C\BillingController::class, 'refresh']);

        // --- Admin
        $r->get('/api/admin/dashboard', [C\AdminController::class, 'dashboard'], $admin);
        $r->get('/api/admin/users', [C\AdminController::class, 'users'], $admin);
        $r->get('/api/admin/users/{id}', [C\AdminController::class, 'user'], $admin);
        $r->post('/api/admin/users/{id}/suspend', [C\AdminController::class, 'suspend'], $admin);
        $r->post('/api/admin/users/{id}/unsuspend', [C\AdminController::class, 'unsuspend'], $admin);
        $r->post('/api/admin/users/{id}/premium', [C\AdminController::class, 'grantPremium'], $admin);
        $r->get('/api/admin/reports', [C\AdminController::class, 'reports'], $admin);
        $r->get('/api/admin/reports/{id}', [C\AdminController::class, 'reportDetail'], $admin);
        $r->post('/api/admin/reports/{id}/resolve', [C\AdminController::class, 'resolveReport'], $admin);
        $r->post('/api/admin/moderate/comment/{id}', [C\AdminController::class, 'removeComment'], $admin);
        $r->post('/api/admin/moderate/message/{id}', [C\AdminController::class, 'removeMessage'], $admin);
        $r->post('/api/admin/moderate/playlist/{id}', [C\AdminController::class, 'unpublishPlaylist'], $admin);
        $r->get('/api/admin/subscriptions', [C\AdminController::class, 'subscriptions'], $admin);
        $r->get('/api/admin/errors', [C\AdminController::class, 'errors'], $admin);
        $r->get('/api/admin/integrations', [C\AdminController::class, 'integrations'], $admin);
        $r->get('/api/admin/covers', [C\AdminController::class, 'covers'], $admin);
        $r->get('/api/admin/settings', [C\AdminController::class, 'settings'], $admin);
        $r->patch('/api/admin/settings', [C\AdminController::class, 'updateSettings'], $admin);
        $r->patch('/api/admin/integrations', [C\AdminController::class, 'updateIntegrations'], $admin);
        $r->get('/api/admin/audit', [C\AdminController::class, 'audit'], $admin);
        $r->post('/api/admin/cron', [C\AdminController::class, 'runCron'], $admin);
    }
}
