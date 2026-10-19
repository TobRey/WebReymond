<?php
declare(strict_types=1);

namespace SwipeGroove\Tests\Cases;

use SwipeGroove\Tests\TestApp;
use SwipeGroove\Tests\TestCase;
use SwipeGroove\Users\ImageService;

/** Gastmodus, Freunde-Feed, Suche, Player-Token, Bildrechte (Social-Redesign). */
final class SocialTest extends TestCase
{
    private TestApp $t;
    private array $tokens;

    protected function setUp(): void
    {
        $this->t = TestApp::create();
        $this->tokens = $this->t->seedUsers();
        foreach (['spotify_owner' => 'owner', 'spotify_user2' => 'alice', 'spotify_user3' => 'bob'] as $sp => $name) {
            $this->t->db->run('UPDATE users SET username = ?, display_name = ?, onboarding_done = 1 WHERE spotify_user_id = ?', [$name, ucfirst($name), $sp]);
        }
        $this->t->http->addPlaylist('plalice0000000000000001', 'spotify_user2', 'Sunset Groove', [1, 2, 3]);
        $this->t->request('POST', '/api/playlists/plalice0000000000000001/publish', ['published' => true], $this->tokens['user2']);
    }

    private function publishedId(): int
    {
        return (int) $this->t->db->value('SELECT id FROM published_playlists WHERE spotify_playlist_id = ?', ['plalice0000000000000001']);
    }

    public function testGuestCanBrowseButNotInteract(): void
    {
        $pid = $this->publishedId();
        $feed = TestApp::json($this->t->request('GET', '/api/community/feed'));
        self::assertCount(1, $feed['items']);
        self::assertFalse($feed['items'][0]['playlist']['liked']);
        $pl = $this->t->request('GET', '/api/community/playlists/' . $pid);
        self::assertSame(200, $pl->status);
        self::assertCount(3, TestApp::json($pl)['tracks']);
        self::assertSame(200, $this->t->request('GET', '/api/community/playlists/' . $pid . '/comments')->status);
        self::assertSame('Alice', TestApp::json($this->t->request('GET', '/api/users/alice'))['display_name']);
        self::assertNull(TestApp::json($this->t->request('GET', '/api/users/alice'))['relationship']);
        self::assertCount(1, TestApp::json($this->t->request('GET', '/api/users/alice/playlists'))['playlists']);
        $s = TestApp::json($this->t->request('GET', '/api/community/search', [], null, ['q' => 'sunset']));
        self::assertCount(1, $s['playlists']);
        $s = TestApp::json($this->t->request('GET', '/api/community/search', [], null, ['q' => 'ali']));
        self::assertSame('alice', $s['users'][0]['username']);
        // Interaktionen verlangen ein Konto
        self::assertSame(401, $this->t->request('POST', '/api/community/playlists/' . $pid . '/like')->status);
        self::assertSame(401, $this->t->request('POST', '/api/community/playlists/' . $pid . '/comments', ['body' => 'hi'])->status);
        self::assertSame(401, $this->t->request('POST', '/api/users/alice/follow')->status);
        self::assertSame(401, $this->t->request('GET', '/api/playlists')->status);
        self::assertSame(401, $this->t->request('GET', '/api/conversations')->status);
        // Privates Profil: für Gäste unsichtbar
        $this->t->request('PATCH', '/api/me', ['is_private' => true], $this->tokens['user2']);
        self::assertSame(404, $this->t->request('GET', '/api/community/playlists/' . $pid)->status);
        self::assertSame([], TestApp::json($this->t->request('GET', '/api/community/feed'))['items']);
        self::assertSame([], TestApp::json($this->t->request('GET', '/api/community/search', [], null, ['q' => 'sunset']))['playlists']);
        self::assertFalse(TestApp::json($this->t->request('GET', '/api/users/alice'))['content_visible']);
        // Eigene Inhalte sieht man im eigenen Feed trotzdem
        self::assertCount(1, TestApp::json($this->t->request('GET', '/api/community/feed', [], $this->tokens['user2'], ['scope' => 'following']))['items']);
    }

    public function testFollowingFeedOnlyShowsPeopleYouFollow(): void
    {
        $bob = $this->tokens['user3'];
        self::assertCount(0, TestApp::json($this->t->request('GET', '/api/community/feed', [], $bob, ['scope' => 'following']))['items']);
        self::assertCount(1, TestApp::json($this->t->request('GET', '/api/community/feed', [], $bob))['items']);
        $this->t->request('POST', '/api/users/alice/follow', [], $bob);
        self::assertCount(1, TestApp::json($this->t->request('GET', '/api/community/feed', [], $bob, ['scope' => 'following']))['items']);
        // Blockierte verschwinden auch aus dem Freunde-Feed
        $this->t->request('POST', '/api/users/bob/block', [], $this->tokens['user2']);
        self::assertCount(0, TestApp::json($this->t->request('GET', '/api/community/feed', [], $bob, ['scope' => 'following']))['items']);
    }

    public function testPlayerTokenRequiresPlaybackAndStreamingScope(): void
    {
        $alice = $this->tokens['user2'];
        self::assertSame(403, $this->t->request('GET', '/api/playback/token', [], $alice)->status);
        self::assertSame(401, $this->t->request('GET', '/api/playback/token')->status);
        $this->t->app->settings()->set('playback_mode', 'connect');
        $this->t->request('PATCH', '/api/me', ['playback_enabled' => true], $alice);
        $r = $this->t->request('GET', '/api/playback/token', [], $alice);
        self::assertSame(403, $r->status);
        self::assertSame('playback.scope_missing', TestApp::json($r)['error']);
        $uid = $this->t->userId('spotify_user2');
        $this->t->db->run('UPDATE spotify_accounts SET scopes = ? WHERE user_id = ?', ['playlist-read-private user-modify-playback-state streaming user-read-email user-read-private', $uid]);
        $r = $this->t->request('GET', '/api/playback/token', [], $alice);
        self::assertSame(200, $r->status);
        $j = TestApp::json($r);
        self::assertTrue(strlen((string) $j['access_token']) > 10);
        self::assertTrue($j['expires_in'] >= 60);
        self::assertStringContains('no-store', (string) $r->headers['Cache-Control']);
        self::assertFalse(isset($j['refresh_token']));
        // Shell erlaubt das SDK nur bei aktivierter Wiedergabe
        $html = $this->t->request('GET', '/', [], $alice, [], ['accept' => 'text/html']);
        self::assertStringContains('https://sdk.scdn.co', (string) $html->headers['Content-Security-Policy']);
        self::assertFalse(str_contains($html->body, 'boot-splash'));
        $this->t->app->settings()->set('playback_mode', 'off');
        $html = $this->t->request('GET', '/', [], $alice, [], ['accept' => 'text/html']);
        self::assertFalse(str_contains((string) $html->headers['Content-Security-Policy'], 'sdk.scdn.co'));
    }

    public function testQueuePlaybackValidatesTrackIds(): void
    {
        $alice = $this->tokens['user2'];
        $this->t->app->settings()->set('playback_mode', 'connect');
        $this->t->request('PATCH', '/api/me', ['playback_enabled' => true], $alice);
        $this->t->db->run('UPDATE spotify_accounts SET scopes = ? WHERE user_id = ?', ['user-modify-playback-state streaming', $this->t->userId('spotify_user2')]);
        $r = $this->t->request('POST', '/api/playback/play', ['track_ids' => ['abc', 'def']], $alice);
        self::assertSame(400, $r->status);
        $a = str_repeat('a', 22);
        $b = str_repeat('b', 22);
        $r = $this->t->request('POST', '/api/playback/play', ['track_ids' => [$a, $b], 'offset' => 1, 'device_id' => 'webplayer0123456789'], $alice);
        self::assertSame(200, $r->status);
        $last = end($this->t->http->plays);
        self::assertSame(['spotify:track:' . $a, 'spotify:track:' . $b], $last['uris']);
        self::assertSame(['position' => 1], $last['offset']);
        self::assertSame('webplayer0123456789', $last['device']);
        self::assertSame(200, $this->t->request('POST', '/api/playback/play', ['resume' => true], $alice)->status);
        // Ganze Playlist als Kontext (z. B. sehr lange Playlists), Start an Position + Sekunde
        $r = $this->t->request('POST', '/api/playback/play', ['playlist_id' => str_repeat('c', 22), 'offset' => 3, 'position_ms' => 30000], $alice);
        self::assertSame(200, $r->status);
        $last = end($this->t->http->plays);
        self::assertSame('spotify:playlist:' . str_repeat('c', 22), $last['context_uri']);
        self::assertSame(['position' => 3], $last['offset']);
        self::assertSame(30000, $last['position_ms']);
        self::assertSame(400, $this->t->request('POST', '/api/playback/play', ['playlist_id' => '../x'], $alice)->status);
        // Spulen und Wiedergabestand
        self::assertSame(200, $this->t->request('POST', '/api/playback/seek', ['position_ms' => 90000], $alice)->status);
        self::assertSame(90000, end($this->t->http->plays)['seek']);
        $st = TestApp::json($this->t->request('GET', '/api/playback/state', [], $alice));
        self::assertTrue($st['active']);
        self::assertSame(200000, $st['duration_ms']);
        // Ohne Premium: klare Meldung
        $this->t->http->premiumUsers = false;
        $r = $this->t->request('POST', '/api/playback/play', ['track_id' => $a], $alice);
        self::assertSame('playback.premium_required', TestApp::json($r)['error']);
    }

    public function testEmptyPublishedSongListHealsWhenOwnerLoadsPlaylist(): void
    {
        $pid = $this->publishedId();
        $this->t->db->run('DELETE FROM published_playlist_tracks WHERE published_playlist_id = ?', [$pid]);
        self::assertSame([], TestApp::json($this->t->request('GET', '/api/community/playlists/' . $pid))['tracks']);
        $this->t->request('GET', '/api/playlists/plalice0000000000000001', [], $this->tokens['user2']);
        self::assertCount(3, TestApp::json($this->t->request('GET', '/api/community/playlists/' . $pid))['tracks']);
    }

    public function testRepairPermissionsMakesUploadsReadable(): void
    {
        $dir = sys_get_temp_dir() . '/sg-perm-' . bin2hex(random_bytes(4));
        mkdir($dir . '/avatars', 0750, true);
        chmod($dir . '/avatars', 0750);
        file_put_contents($dir . '/avatars/1-a.webp', 'x');
        chmod($dir . '/avatars/1-a.webp', 0640);
        $fixed = ImageService::repairPermissions($dir);
        clearstatcache();
        self::assertSame(2, $fixed);
        self::assertSame(0755, fileperms($dir . '/avatars') & 0777);
        self::assertSame(0644, fileperms($dir . '/avatars/1-a.webp') & 0777);
        unlink($dir . '/avatars/1-a.webp');
        rmdir($dir . '/avatars');
        rmdir($dir);
    }

    public function testFreshOwnerOpensRegistration(): void
    {
        self::assertTrue($this->t->app->settings()->bool('registration_open'));
        $r = $this->t->loginAs('spotify_newbie');
        self::assertSame(302, $r['status']);
        self::assertSame(1, (int) $this->t->db->value('SELECT COUNT(*) FROM users WHERE spotify_user_id = ?', ['spotify_newbie']));
    }
}
