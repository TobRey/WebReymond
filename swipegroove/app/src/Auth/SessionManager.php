<?php
declare(strict_types=1);

namespace SwipeGroove\Auth;

use SwipeGroove\Core\App;
use SwipeGroove\Core\Crypto;
use SwipeGroove\Core\HttpException;
use SwipeGroove\Core\Request;

/**
 * Langlebige, datenbankgestützte Sessions mit sicherem Cookie und CSRF-Token.
 */
final class SessionManager
{
    public const COOKIE = 'sg_session';
    public const LIFETIME_DAYS = 180;

    public function __construct(private App $app)
    {
    }

    public function resolve(Request $request): void
    {
        $token = $_COOKIE[self::COOKIE] ?? null;
        if (!$token || !preg_match('/^[A-Za-z0-9\-_]{40,60}$/', $token)) {
            return;
        }
        $db = $this->app->db();
        $id = Crypto::hash($token);
        $row = $db->one(
            'SELECT s.id AS session_id, s.csrf_token, s.last_seen_at, s.expires_at, u.*
             FROM sessions s JOIN users u ON u.id = s.user_id
             WHERE s.id = ? AND s.expires_at > ? AND u.deleted_at IS NULL',
            [$id, $db->now()]
        );
        if (!$row) {
            $this->clearCookie();
            return;
        }
        $user = (object) $row;
        $request->user = $user;
        $request->sessionId = $id;
        $request->sessionToken = (string) $row['csrf_token'];
        // Gleitende Verlängerung: höchstens einmal pro Stunde schreiben
        if (strtotime((string) $row['last_seen_at']) < time() - 3600) {
            $db->run('UPDATE sessions SET last_seen_at = ?, expires_at = ? WHERE id = ?', [
                $db->now(), gmdate('Y-m-d H:i:s', time() + self::LIFETIME_DAYS * 86400), $id,
            ]);
        }
    }

    public function login(int $userId, Request $request): string
    {
        $db = $this->app->db();
        $token = Crypto::randomToken(32);
        $csrf = Crypto::randomToken(32);
        $db->insert('sessions', [
            'id' => Crypto::hash($token),
            'user_id' => $userId,
            'csrf_token' => $csrf,
            'user_agent' => mb_substr((string) ($request->header('user-agent') ?? ''), 0, 255),
            'ip_hash' => Crypto::hash($request->ip . '|' . $this->app->config?->string('app.key')),
            'created_at' => $db->now(),
            'last_seen_at' => $db->now(),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + self::LIFETIME_DAYS * 86400),
        ]);
        $db->run('UPDATE users SET last_login_at = ? WHERE id = ?', [$db->now(), $userId]);
        $this->setCookie($token);
        return $token;
    }

    public function logout(Request $request): void
    {
        if ($request->sessionId) {
            $this->app->db()->run('DELETE FROM sessions WHERE id = ?', [$request->sessionId]);
        }
        $this->clearCookie();
    }

    public function logoutEverywhere(int $userId): void
    {
        $this->app->db()->run('DELETE FROM sessions WHERE user_id = ?', [$userId]);
        $this->clearCookie();
    }

    public function verifyCsrf(Request $request): void
    {
        // Herkunftsprüfung (Browser setzen diese Header zuverlässig)
        $site = $request->header('sec-fetch-site');
        if ($site !== null && !in_array($site, ['same-origin', 'same-site', 'none'], true)) {
            throw HttpException::forbidden('error.csrf');
        }
        $origin = $request->header('origin');
        if ($origin !== null && $origin !== 'null') {
            $expected = parse_url($this->app->url->base());
            $got = parse_url($origin);
            if (($got['host'] ?? '') !== ($expected['host'] ?? '')) {
                throw HttpException::forbidden('error.csrf');
            }
        }
        if ($request->user === null) {
            return; // Für unauthentifizierte Formulare reicht die Herkunftsprüfung
        }
        $sent = $request->header('x-csrf-token') ?? ($request->input('_csrf') ?? '');
        if (!is_string($sent) || $sent === '' || !hash_equals((string) $request->sessionToken, $sent)) {
            throw HttpException::forbidden('error.csrf');
        }
    }

    private function cookieOptions(int $expires): array
    {
        $secure = str_starts_with($this->app->url->base(), 'https://');
        $path = $this->app->url->basePath() ?: '/';
        return [
            'expires' => $expires,
            'path' => $path,
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    private function setCookie(string $token): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        setcookie(self::COOKIE, $token, $this->cookieOptions(time() + self::LIFETIME_DAYS * 86400));
    }

    private function clearCookie(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        setcookie(self::COOKIE, '', $this->cookieOptions(time() - 3600));
    }

    public function setLangCookie(string $lang): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        $opts = $this->cookieOptions(time() + 365 * 86400);
        $opts['httponly'] = false;
        setcookie('sg_lang', $lang, $opts);
    }

    public function cleanup(): int
    {
        return $this->app->db()->exec('DELETE FROM sessions WHERE expires_at < ?', [$this->app->db()->now()]);
    }
}
