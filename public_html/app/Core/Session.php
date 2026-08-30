<?php

declare(strict_types=1);

namespace WebAtze\Core;

/**
 * Sitzungsverwaltung.
 *
 * Die PHP-Sitzung hält nur eine Kennung; die eigentliche Anmeldung steht in
 * der Tabelle auth_sessions. Dadurch lässt sich eine Anmeldung serverseitig
 * beenden – auch wenn der Browser das Cookie noch hat.
 */
final class Session
{
    private const KEY_SESSION_ID = '_wa_sid';
    private const KEY_FLASH = '_wa_flash';
    private const KEY_CSRF = '_wa_csrf';
    private const IDLE_SECONDS = 7200;      // 2 Stunden ohne Aktivität
    private const ABSOLUTE_SECONDS = 43200; // 12 Stunden insgesamt

    private static bool $started = false;
    private static ?array $user = null;

    public static function start(): void
    {
        if (self::$started || PHP_SAPI === 'cli') {
            self::$started = true;
            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            return;
        }

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => self::cookiesShouldBeSecure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.gc_maxlifetime', (string) self::ABSOLUTE_SECONDS);
        session_name('webatze_session');

        @session_start();
        self::$started = true;
    }

    public static function cookiesShouldBeSecure(): bool
    {
        if (PHP_SAPI === 'cli') {
            return false;
        }
        return str_starts_with((string) Config::get('app_url', ''), 'https://')
            || (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }

    // ------------------------------------------------------------------
    // Anmeldung
    // ------------------------------------------------------------------

    public static function login(array $user, Request $request): void
    {
        self::start();
        self::regenerate();

        $sessionId = bin2hex(random_bytes(24));
        $now = Db::now();

        Db::insert('auth_sessions', [
            'id' => $sessionId,
            'user_id' => (int) $user['id'],
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => $now,
            'last_seen_at' => $now,
            'expires_at' => date('Y-m-d H:i:s', time() + self::ABSOLUTE_SECONDS),
        ]);

        $_SESSION[self::KEY_SESSION_ID] = $sessionId;
        self::$user = $user;

        Db::update('users', ['last_login_at' => $now], 'id = :id', ['id' => (int) $user['id']]);
    }

    public static function logout(): void
    {
        self::start();

        $sessionId = $_SESSION[self::KEY_SESSION_ID] ?? null;
        if (is_string($sessionId) && $sessionId !== '') {
            Db::delete('auth_sessions', 'id = :id', ['id' => $sessionId]);
        }

        self::$user = null;
        $_SESSION = [];

        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name() ?: 'webatze_session', '', [
                    'expires' => time() - 42000,
                    'path' => $params['path'],
                    'domain' => $params['domain'],
                    'secure' => $params['secure'],
                    'httponly' => $params['httponly'],
                    'samesite' => 'Lax',
                ]);
            }
            session_destroy();
        }
    }

    /** Angemeldeter Benutzer oder null. Prüft dabei Ablauf und Gültigkeit. */
    public static function user(): ?array
    {
        if (self::$user !== null) {
            return self::$user;
        }
        self::start();

        $sessionId = $_SESSION[self::KEY_SESSION_ID] ?? null;
        if (!is_string($sessionId) || $sessionId === '') {
            return null;
        }

        $row = Db::first(
            'SELECT s.id, s.user_id, s.last_seen_at, s.expires_at,
                    u.id AS uid, u.username, u.display_name, u.role
             FROM auth_sessions s
             JOIN users u ON u.id = s.user_id
             WHERE s.id = :id',
            ['id' => $sessionId]
        );

        if ($row === null) {
            return null;
        }

        $now = time();
        $expired = strtotime((string) $row['expires_at']) < $now
            || strtotime((string) $row['last_seen_at']) < $now - self::IDLE_SECONDS;

        if ($expired) {
            Db::delete('auth_sessions', 'id = :id', ['id' => $sessionId]);
            unset($_SESSION[self::KEY_SESSION_ID]);
            return null;
        }

        // Aktivität nur einmal pro Minute schreiben – spart Schreibzugriffe.
        if (strtotime((string) $row['last_seen_at']) < $now - 60) {
            Db::update('auth_sessions', ['last_seen_at' => Db::now()], 'id = :id', ['id' => $sessionId]);
        }

        return self::$user = [
            'id' => (int) $row['uid'],
            'username' => (string) $row['username'],
            'display_name' => (string) $row['display_name'],
            'role' => (string) $row['role'],
        ];
    }

    public static function isLoggedIn(): bool
    {
        return self::user() !== null;
    }

    public static function regenerate(): void
    {
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /** Abgelaufene Sitzungen entfernen (läuft im Worker). */
    public static function purgeExpired(): int
    {
        return Db::delete('auth_sessions', 'expires_at < :now', ['now' => Db::now()]);
    }

    // ------------------------------------------------------------------
    // Kurzlebige Meldungen und CSRF-Speicher
    // ------------------------------------------------------------------

    public static function flash(string $type, string $message): void
    {
        self::start();
        $_SESSION[self::KEY_FLASH][] = ['type' => $type, 'message' => $message];
    }

    /** Meldungen holen und dabei leeren. */
    public static function takeFlash(): array
    {
        self::start();
        $messages = $_SESSION[self::KEY_FLASH] ?? [];
        unset($_SESSION[self::KEY_FLASH]);
        return is_array($messages) ? $messages : [];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::start();
        return $_SESSION[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        self::start();
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        self::start();
        unset($_SESSION[$key]);
    }

    public static function csrfToken(): string
    {
        self::start();
        $token = $_SESSION[self::KEY_CSRF] ?? null;
        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            $_SESSION[self::KEY_CSRF] = $token;
        }
        return $token;
    }
}
