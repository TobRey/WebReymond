<?php
declare(strict_types=1);

namespace SwipeGroove\Tests\Cases;

use SwipeGroove\Tests\TestApp;
use SwipeGroove\Tests\TestCase;

final class AuthTest extends TestCase
{
    private TestApp $t;

    protected function setUp(): void
    {
        $this->t = TestApp::create();
    }

    public function testOwnerRegistrationRequiresSetupKeyAndOpensAccount(): void
    {
        $this->t->app->settings()->set('setup_key_hash', password_hash('secret-setup-key', PASSWORD_DEFAULT));
        // Ohne gültigen Schlüssel: abgelehnt
        $bad = $this->t->loginAs('spotify_owner', 'wrong-key');
        self::assertSame(403, $bad['status']);
        self::assertSame(0, (int) $this->t->db->value('SELECT COUNT(*) FROM users'));
        // Mit Schlüssel: Besitzer, Admin, Premium
        $ok = $this->t->loginAs('spotify_owner', 'secret-setup-key');
        self::assertSame(302, $ok['status']);
        self::assertStringContains('/groove/welcome', (string) $ok['location']);
        $me = TestApp::json($this->t->request('GET', '/api/me', [], $ok['token']));
        self::assertTrue($me['is_owner']);
        self::assertTrue($me['is_admin']);
        self::assertTrue($me['premium']['active']);
        self::assertTrue($me['premium']['unlimited_covers']);
        // Schlüssel ungültig, Registrierung geschlossen
        self::assertNull($this->t->app->settings()->get('setup_key_hash'));
        self::assertFalse($this->t->app->settings()->bool('registration_open'));
        $closed = $this->t->loginAs('spotify_user2');
        self::assertSame(302, $closed['status']);
        self::assertStringContains('auth.registration_closed', (string) $closed['location']);
        self::assertSame(1, (int) $this->t->db->value('SELECT COUNT(*) FROM users'));
    }

    public function testOneAccountPerSpotifyIdAndRelogin(): void
    {
        $tokens = $this->t->seedUsers();
        $again = $this->t->loginAs('spotify_user2');
        self::assertSame(302, $again['status']);
        self::assertSame(3, (int) $this->t->db->value('SELECT COUNT(*) FROM users'));
        self::assertSame(1, (int) $this->t->db->value('SELECT COUNT(*) FROM users WHERE spotify_user_id = ?', ['spotify_user2']));
        // DB-Eindeutigkeit
        self::assertThrows(\PDOException::class, fn() => $this->t->db->insert('users', [
            'spotify_user_id' => 'spotify_user2', 'username' => 'dup', 'display_name' => 'x', 'created_at' => $this->t->db->now(), 'updated_at' => $this->t->db->now(),
        ]));
    }

    public function testParallelOwnerRegistrationProducesExactlyOneOwner(): void
    {
        $this->t->app->settings()->set('setup_key_hash', password_hash('k-123456789012', PASSWORD_DEFAULT));
        $db = $this->t->db;
        // Zwei Nutzer, beide mit gültigem Setup-State; zweiter Insert in platform_owner muss scheitern
        $now = $db->now();
        $u1 = $db->insert('users', ['spotify_user_id' => 'a1', 'username' => 'a1', 'display_name' => 'A1', 'role' => 'admin', 'created_at' => $now, 'updated_at' => $now]);
        $u2 = $db->insert('users', ['spotify_user_id' => 'a2', 'username' => 'a2', 'display_name' => 'A2', 'role' => 'admin', 'created_at' => $now, 'updated_at' => $now]);
        $db->run('INSERT INTO platform_owner (id, user_id, created_at) VALUES (1, ?, ?)', [$u1, $now]);
        self::assertThrows(\PDOException::class, fn() => $db->run('INSERT INTO platform_owner (id, user_id, created_at) VALUES (1, ?, ?)', [$u2, $now]));
        self::assertSame(1, (int) $db->value('SELECT COUNT(*) FROM platform_owner'));
        // Über den Service: Nutzer 2 wird nach Besitzer-Registrierung regulär behandelt (Registrierung geschlossen)
        $db->run('DELETE FROM platform_owner');
        $db->run('DELETE FROM users');
        $this->t->app->settings()->reload();
        $this->t->app->settings()->set('setup_key_hash', password_hash('k-123456789012', PASSWORD_DEFAULT));
        $auth = $this->t->app->service(\SwipeGroove\Spotify\SpotifyAuth::class);
        $urlA = $auth->startUrl('setup', null, true, 'de');
        $nonceA = (string) $auth->lastNonce;
        $urlB = $auth->startUrl('setup', null, true, 'de');
        $nonceB = (string) $auth->lastNonce;
        parse_str((string) parse_url($urlA, PHP_URL_QUERY), $qa);
        parse_str((string) parse_url($urlB, PHP_URL_QUERY), $qb);
        $ra = $this->t->request('GET', '/auth/spotify/callback', [], null, ['code' => $this->t->http->issueCode('spotify_owner'), 'state' => $qa['state']], [], ['sg_oauth' => $nonceA]);
        $rb = $this->t->request('GET', '/auth/spotify/callback', [], null, ['code' => $this->t->http->issueCode('spotify_user2'), 'state' => $qb['state']], [], ['sg_oauth' => $nonceB]);
        self::assertSame(302, $ra->status);
        self::assertStringContains('registration_closed', (string) $rb->headers['Location']);
        self::assertSame(1, (int) $db->value('SELECT COUNT(*) FROM platform_owner'));
        self::assertSame(1, (int) $db->value('SELECT COUNT(*) FROM users'));
    }

    public function testCallbackWithoutBrowserCookieIsRejected(): void
    {
        $this->t->seedUsers();
        $auth = $this->t->app->service(\SwipeGroove\Spotify\SpotifyAuth::class);
        $url = $auth->startUrl('login', null, false, 'de');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $sessionsBefore = (int) $this->t->db->value('SELECT COUNT(*) FROM sessions');
        // Angreifer schickt dem Opfer seine Callback-URL: Browser des Opfers hat die Nonce nicht
        $r = $this->t->request('GET', '/auth/spotify/callback', [], null, ['code' => $this->t->http->issueCode('spotify_user3'), 'state' => $q['state']], [], ['sg_oauth' => 'fremd']);
        self::assertSame(302, $r->status);
        self::assertStringContains('auth.state_invalid', (string) $r->headers['Location']);
        self::assertSame($sessionsBefore, (int) $this->t->db->value('SELECT COUNT(*) FROM sessions'), 'Keine Session für das Opfer erzeugt');
        // State ist verbraucht – auch mit richtiger Nonce nicht mehr nutzbar
        $r = $this->t->request('GET', '/auth/spotify/callback', [], null, ['code' => $this->t->http->issueCode('spotify_user3'), 'state' => $q['state']], [], ['sg_oauth' => (string) $auth->lastNonce]);
        self::assertStringContains('auth.state_invalid', (string) $r->headers['Location']);
    }

    public function testInvalidStateIsRejected(): void
    {
        $r = $this->t->request('GET', '/auth/spotify/callback', [], null, ['code' => 'x', 'state' => 'nope']);
        self::assertSame(302, $r->status);
        self::assertStringContains('auth.state_invalid', (string) $r->headers['Location']);
    }

    public function testLogoutAndLogoutEverywhere(): void
    {
        $tokens = $this->t->seedUsers();
        $uid = $this->t->userId('spotify_user2');
        $second = $this->t->issueSessionToken($uid);
        self::assertSame(200, $this->t->request('GET', '/api/me', [], $second)->status);
        self::assertSame(200, $this->t->request('POST', '/api/auth/logout-all', [], $tokens['user2'])->status);
        self::assertSame(401, $this->t->request('GET', '/api/me', [], $second)->status);
        self::assertSame(401, $this->t->request('GET', '/api/me', [], $tokens['user2'])->status);
    }

    public function testCsrfProtection(): void
    {
        $tokens = $this->t->seedUsers();
        $r = $this->t->request('PATCH', '/api/me', ['bio' => 'x'], $tokens['user2'], [], ['x-csrf-token' => 'wrong']);
        self::assertSame(403, $r->status);
        self::assertSame('error.csrf', TestApp::json($r)['error']);
        $r = $this->t->request('PATCH', '/api/me', ['bio' => 'x'], $tokens['user2'], [], ['sec-fetch-site' => 'cross-site']);
        self::assertSame(403, $r->status);
        $r = $this->t->request('PATCH', '/api/me', ['bio' => 'hello'], $tokens['user2']);
        self::assertSame(200, $r->status);
        self::assertSame('hello', TestApp::json($r)['bio']);
    }

    public function testExpiredAccessTokenIsRefreshedAndRevokedIsReported(): void
    {
        $tokens = $this->t->seedUsers();
        $this->t->http->addPlaylist('pl000000000000000000001', 'spotify_user2', 'Mix', [1, 2, 3]);
        $this->t->http->expireAccessTokens();
        $r = $this->t->request('GET', '/api/playlists', [], $tokens['user2']);
        self::assertSame(200, $r->status);
        self::assertCount(1, TestApp::json($r)['playlists']);
        // Widerruf: Refresh scheitert → 409 spotify.revoked, Status in DB
        $this->t->http->revokeAll();
        $r = $this->t->request('GET', '/api/playlists', [], $tokens['user2'], ['refresh' => '1']);
        self::assertSame(409, $r->status);
        self::assertSame('spotify.revoked', TestApp::json($r)['error']);
        $me = TestApp::json($this->t->request('GET', '/api/me', [], $tokens['user2']));
        self::assertSame('revoked', $me['spotify']['status']);
        // Erneutes Verbinden stellt den Zugang her
        $again = $this->t->loginAs('spotify_user2');
        self::assertSame(302, $again['status']);
        $me = TestApp::json($this->t->request('GET', '/api/me', [], $again['token']));
        self::assertSame('connected', $me['spotify']['status']);
    }

    public function testRateLimitFromSpotifyIsSurfaced(): void
    {
        $tokens = $this->t->seedUsers();
        $this->t->http->rateLimitNext = 30;
        $r = $this->t->request('GET', '/api/playlists', [], $tokens['user2'], ['refresh' => '1']);
        self::assertSame(429, $r->status);
        self::assertSame('spotify.rate_limited', TestApp::json($r)['error']);
        self::assertSame(30, TestApp::json($r)['retry_after']);
    }

    public function testOnboardingValidatesUsername(): void
    {
        $tokens = $this->t->seedUsers();
        $r = $this->t->request('POST', '/api/me/onboarding', ['username' => 'Ab', 'display_name' => 'X'], $tokens['user2']);
        self::assertSame(422, $r->status);
        $r = $this->t->request('POST', '/api/me/onboarding', ['username' => 'admin', 'display_name' => 'X'], $tokens['user2']);
        self::assertSame('validation.username_reserved', TestApp::json($r)['fields']['username']);
        $r = $this->t->request('POST', '/api/me/onboarding', ['username' => 'cool.user_1', 'display_name' => 'Cool User', 'lang' => 'en'], $tokens['user2']);
        self::assertSame(200, $r->status);
        self::assertSame('en', TestApp::json($r)['lang']);
        // Benutzername belegt
        $r = $this->t->request('POST', '/api/me/onboarding', ['username' => 'cool.user_1', 'display_name' => 'Other'], $tokens['user3']);
        self::assertSame('validation.username_taken', TestApp::json($r)['fields']['username']);
    }

    public function testSubfolderUrls(): void
    {
        $url = $this->t->app->url;
        self::assertSame('/groove', $url->basePath());
        self::assertSame('https://example.test/groove/auth/spotify/callback', $this->t->app->service(\SwipeGroove\Spotify\SpotifyAuth::class)->redirectUri());
        self::assertSame('/groove/assets/app.js', $url->path('/assets/app.js'));
        $req = new \SwipeGroove\Core\Request();
        $_SERVER['REQUEST_URI'] = '/groove/api/me?x=1';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $r = \SwipeGroove\Core\Request::fromGlobals('/groove');
        self::assertSame('/api/me', $r->path);
        $_SERVER['REQUEST_URI'] = '/groove/index.php/api/me';
        self::assertSame('/api/me', \SwipeGroove\Core\Request::fromGlobals('/groove')->path);
        $_SERVER['REQUEST_URI'] = '/groove';
        self::assertSame('/', \SwipeGroove\Core\Request::fromGlobals('/groove')->path);
    }
}
