<?php
declare(strict_types=1);

namespace SwipeGroove\Tests;

use SwipeGroove\Auth\SessionManager;
use SwipeGroove\Core\App;
use SwipeGroove\Core\Config;
use SwipeGroove\Core\Crypto;
use SwipeGroove\Core\Db;
use SwipeGroove\Core\Migrator;
use SwipeGroove\Core\Request;
use SwipeGroove\Core\Response;

/**
 * Test-Anwendung: frische Datenbank, Fake-HTTP, Basis-URL mit Unterordner (/groove).
 */
final class TestApp
{
    public App $app;
    public FakeHttp $http;
    public Db $db;
    public static string $baseUrl = 'https://example.test/groove';
    private static ?string $key = null;

    public static function create(): self
    {
        $t = new self();
        $host = getenv('SG_TEST_DB_HOST') ?: '127.0.0.1';
        $name = getenv('SG_TEST_DB_NAME') ?: 'swipegroove_test';
        $user = getenv('SG_TEST_DB_USER') ?: 'sg';
        $pass = getenv('SG_TEST_DB_PASS') ?: 'sgpass';
        $t->db = Db::connect($host, 3306, $name, $user, $pass);
        self::resetDatabase($t->db);
        (new Migrator($t->db, SG_ROOT . '/migrations'))->migrate();
        self::$key ??= Crypto::generateKey();
        $config = new Config([
            'app' => ['base_url' => self::$baseUrl, 'key' => self::$key, 'debug' => true, 'cron_key' => 'cronkey123'],
            'db' => ['host' => $host, 'port' => 3306, 'name' => $name, 'user' => $user, 'pass' => $pass],
            'spotify' => ['client_id' => str_repeat('a', 32), 'client_secret' => ''],
            'images' => ['provider' => 'openai', 'openai_api_key' => 'sk-test'],
            'stripe' => ['mode' => 'test', 'secret_key' => 'sk_test_x', 'publishable_key' => 'pk_test_x', 'webhook_secret' => 'whsec_test', 'price_id' => 'price_test'],
        ]);
        $t->http = new FakeHttp();
        $t->app = App::createForTests(SG_ROOT, $config, $t->db, $t->http);
        foreach (['storage/cache', 'storage/logs', 'storage/covers', 'uploads/avatars', 'uploads/banners', 'uploads/backgrounds'] as $d) {
            if (!is_dir(SG_ROOT . '/' . $d)) {
                mkdir(SG_ROOT . '/' . $d, 0750, true);
            }
        }
        @file_put_contents(SG_ROOT . '/storage/cache/schema.json', json_encode(['fingerprint' => 'test']));
        $_COOKIE = [];
        $_POST = [];
        $_FILES = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        return $t;
    }

    /** App-Instanz für Kindprozesse (bestehende Datenbank, eigene Verbindung). */
    public static function createForChild(): self
    {
        $t = new self();
        $host = getenv('SG_TEST_DB_HOST') ?: '127.0.0.1';
        $name = getenv('SG_TEST_DB_NAME') ?: 'swipegroove_test';
        $user = getenv('SG_TEST_DB_USER') ?: 'sg';
        $pass = getenv('SG_TEST_DB_PASS') ?: 'sgpass';
        $t->db = Db::connect($host, 3306, $name, $user, $pass);
        $config = new Config([
            'app' => ['base_url' => self::$baseUrl, 'key' => self::$key, 'debug' => true, 'cron_key' => 'cronkey123'],
            'db' => ['host' => $host, 'port' => 3306, 'name' => $name, 'user' => $user, 'pass' => $pass],
            'spotify' => ['client_id' => str_repeat('a', 32), 'client_secret' => ''],
            'images' => ['provider' => 'openai', 'openai_api_key' => 'sk-test'],
            'stripe' => ['mode' => 'test', 'secret_key' => 'sk_test_x', 'publishable_key' => 'pk_test_x', 'webhook_secret' => 'whsec_test', 'price_id' => 'price_test'],
        ]);
        $t->http = new FakeHttp();
        $t->app = App::createForTests(SG_ROOT, $config, $t->db, $t->http);
        return $t;
    }

    public static function resetDatabase(Db $db): void
    {
        $db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($db->column('SHOW TABLES') as $table) {
            $db->exec('DROP TABLE IF EXISTS `' . $table . '`');
        }
        $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** Baut ein Request-Objekt wie aus Globals. */
    public function request(string $method, string $path, array $json = [], ?string $sessionToken = null, array $query = [], array $headers = [], array $cookies = []): Response
    {
        $r = new Request();
        $r->method = strtoupper($method);
        $r->path = $path;
        $r->fullPath = '/groove' . $path;
        $r->query = $query;
        $r->rawBody = $json ? (string) json_encode($json) : '';
        $r->headers = array_change_key_case(array_merge(['content-type' => 'application/json', 'accept' => 'application/json', 'sec-fetch-site' => 'same-origin', 'user-agent' => 'TestClient'], $headers), CASE_LOWER);
        $r->ip = '127.0.0.1';
        $_COOKIE = ($sessionToken ? ['sg_session' => $sessionToken] : []) + $cookies;
        // CSRF-Token wird aus der Session gezogen und als Header gesetzt (Client-Verhalten)
        if ($sessionToken && !isset($r->headers['x-csrf-token'])) {
            $csrf = $this->db->value('SELECT csrf_token FROM sessions WHERE id = ?', [Crypto::hash($sessionToken)]);
            if ($csrf) {
                $r->headers['x-csrf-token'] = (string) $csrf;
            }
        }
        $this->app->setService(\SwipeGroove\Auth\SessionManager::class, new SessionManager($this->app));
        $resp = $this->app->handle($r);
        $_COOKIE = [];
        return $resp;
    }

    /** @return array<string,mixed> */
    public static function json(Response $r): array
    {
        return json_decode($r->body, true) ?? [];
    }

    /**
     * Simuliert den kompletten Spotify-Login (Start → Callback) und liefert das Session-Token.
     */
    public function loginAs(string $spotifyUser, ?string $setupKey = null): array
    {
        $this->http->users[$spotifyUser] ??= ['id' => $spotifyUser, 'display_name' => ucfirst($spotifyUser)];
        $auth = $this->app->service(\SwipeGroove\Spotify\SpotifyAuth::class);
        if ($setupKey !== null) {
            $resp = $this->request('POST', '/api/auth/setup-start', ['setup_key' => $setupKey, 'lang' => 'de']);
            if ($resp->status !== 200) {
                return ['status' => $resp->status, 'body' => self::json($resp), 'token' => null];
            }
            $url = self::json($resp)['url'];
        } else {
            $url = $auth->startUrl('login', null, false, 'de');
        }
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $code = $this->http->issueCode($spotifyUser);
        $nonce = (string) $auth->lastNonce;
        $resp = $this->request('GET', '/auth/spotify/callback', [], null, ['code' => $code, 'state' => $q['state']], [], ['sg_oauth' => $nonce]);
        $token = null;
        // Session-Token aus der DB (setcookie läuft in CLI nicht): neueste Session des Nutzers
        $row = $this->db->one('SELECT s.id, u.id AS uid FROM sessions s JOIN users u ON u.id = s.user_id WHERE u.spotify_user_id = ? ORDER BY s.created_at DESC, s.id DESC LIMIT 1', [$spotifyUser]);
        if ($row) {
            $token = $this->issueSessionToken((int) $row['uid']);
        }
        return ['status' => $resp->status, 'location' => $resp->headers['Location'] ?? null, 'token' => $token];
    }

    /** Erzeugt eine neue Session und gibt das Klartext-Token zurück. */
    public function issueSessionToken(int $userId): string
    {
        $token = Crypto::randomToken(32);
        $this->db->insert('sessions', [
            'id' => Crypto::hash($token), 'user_id' => $userId, 'csrf_token' => Crypto::randomToken(32), 'user_agent' => 'Test', 'ip_hash' => null,
            'created_at' => $this->db->now(), 'last_seen_at' => $this->db->now(), 'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400),
        ]);
        return $token;
    }

    public function userId(string $spotifyUser): int
    {
        return (int) $this->db->value('SELECT id FROM users WHERE spotify_user_id = ?', [$spotifyUser]);
    }

    /** Richtet Besitzer + zwei weitere Nutzer ein; Registrierung wird dafür geöffnet. */
    public function seedUsers(): array
    {
        $this->app->settings()->set('setup_key_hash', password_hash('setup-key-123456', PASSWORD_DEFAULT));
        $owner = $this->loginAs('spotify_owner', 'setup-key-123456');
        $this->app->settings()->set('registration_open', true);
        $u2 = $this->loginAs('spotify_user2');
        $u3 = $this->loginAs('spotify_user3');
        return ['owner' => $owner['token'], 'user2' => $u2['token'], 'user3' => $u3['token']];
    }
}
