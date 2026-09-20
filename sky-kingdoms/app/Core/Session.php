<?php

declare(strict_types=1);

namespace SkyKingdoms\Core;

/**
 * PHP-Sitzung mit sicheren Voreinstellungen.
 *
 *  - eigener Ablageort (storage/sessions), damit auf Shared Hosting keine
 *    fremden Prozesse mitlesen können
 *  - HttpOnly, SameSite=Lax, Secure automatisch bei HTTPS
 *  - Kennung wird bei Anmeldung und Rechteänderung erneuert
 *  - Sitzung läuft nach Untätigkeit ab
 *  - leichter Fingerabdruck gegen gestohlene Sitzungs-Cookies
 */
final class Session
{
    private static bool $started = false;

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

        $dir = SK_ROOT . '/storage/sessions';
        if (is_dir($dir) && is_writable($dir)) {
            session_save_path($dir);
        }

        $base = Url::base();

        session_name('SKSESSID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => $base === '' ? '/' : $base . '/',
            'domain'   => '',
            'secure'   => self::isSecure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.gc_maxlifetime', (string) max(3600, (int) App::config('session_lifetime', 7200)));

        session_start();
        self::$started = true;

        self::enforceLifetime();
        self::enforceFingerprint();
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function userId(): ?string
    {
        $id = $_SESSION['uid'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    public static function isLoggedIn(): bool
    {
        return self::userId() !== null;
    }

    /** Anmelden: Sitzungskennung erneuern und Fingerabdruck festhalten. */
    public static function login(string $userId, array $extra = []): void
    {
        self::start();
        session_regenerate_id(true);

        $_SESSION = array_merge([
            'uid'         => $userId,
            'fingerprint' => self::fingerprint(),
            'started_at'  => time(),
            'last_seen'   => time(),
        ], $extra);
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];

        if (PHP_SAPI !== 'cli' && ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => 'Lax',
            ]);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        self::$started = false;
    }

    /** Einmalige Meldung für die nächste Seite hinterlegen. */
    public static function flash(string $type, string $message): void
    {
        $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
    }

    /** @return array<int,array{type:string,message:string}> */
    public static function takeFlash(): array
    {
        $flash = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);

        return is_array($flash) ? $flash : [];
    }

    public static function isSecure(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
            return true;
        }

        return ($_SERVER['SERVER_PORT'] ?? '') === '443';
    }

    private static function enforceLifetime(): void
    {
        $lifetime = max(600, (int) App::config('session_lifetime', 7200));
        $last     = (int) ($_SESSION['last_seen'] ?? time());

        if (isset($_SESSION['uid']) && time() - $last > $lifetime) {
            self::logout();
            self::start();
            self::flash('info', 'Du warst länger inaktiv und wurdest abgemeldet.');

            return;
        }
        $_SESSION['last_seen'] = time();
    }

    /**
     * Sehr leichter Fingerabdruck: nur der Browsertyp, nicht die IP-Adresse.
     * Mobilfunk wechselt die IP ständig – das würde Spieler grundlos abmelden.
     */
    private static function enforceFingerprint(): void
    {
        if (!isset($_SESSION['uid'])) {
            return;
        }
        $expected = $_SESSION['fingerprint'] ?? null;
        if ($expected !== null && !hash_equals((string) $expected, self::fingerprint())) {
            Logger::suspicious('Sitzung mit abweichendem Fingerabdruck beendet');
            self::logout();
            self::start();
        }
    }

    private static function fingerprint(): string
    {
        return hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|' . App::key());
    }
}
