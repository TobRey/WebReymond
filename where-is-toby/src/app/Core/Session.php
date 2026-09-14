<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Sichere PHP-Sessions mit eigenem Speicherpfad, Fixierungsschutz und Idle-Timeout.
 */
final class Session
{
    private static bool $started = false;
    private const IDLE_TIMEOUT = 7200;      // 2 Stunden
    private const REGENERATE_AFTER = 900;   // 15 Minuten

    public static function start(): void
    {
        if (self::$started || Environment::isCli()) {
            self::$started = true;
            if (!isset($_SESSION)) {
                $_SESSION = [];
            }
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            return;
        }

        $path = WIT_STORAGE . '/sessions';
        if (!is_dir($path)) {
            @mkdir($path, 0750, true);
        }
        if (is_dir($path) && is_writable($path)) {
            session_save_path($path);
            ini_set('session.gc_probability', '1');
            ini_set('session.gc_divisor', '50');
            ini_set('session.gc_maxlifetime', (string)self::IDLE_TIMEOUT);
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        if (PHP_VERSION_ID < 80400) {
            // Ab PHP 8.4 sind diese Einstellungen veraltet und ohne Wirkung
            @ini_set('session.sid_length', '48');
            @ini_set('session.sid_bits_per_character', '5');
        }

        session_name('WIT_SESSION');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => (WIT_BASE_PATH === '' ? '/' : WIT_BASE_PATH . '/'),
            'domain'   => '',
            'secure'   => Environment::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        self::$started = true;

        $now = time();
        if (isset($_SESSION['_last_activity']) && ($now - (int)$_SESSION['_last_activity']) > self::IDLE_TIMEOUT) {
            self::destroy();
            session_start();
        }
        $_SESSION['_last_activity'] = $now;

        if (!isset($_SESSION['_created'])) {
            $_SESSION['_created'] = $now;
            $_SESSION['_fingerprint'] = self::fingerprint();
        } elseif (($now - (int)$_SESSION['_created']) > self::REGENERATE_AFTER) {
            session_regenerate_id(true);
            $_SESSION['_created'] = $now;
        }

        // Session-Hijacking erschweren (grober Fingerabdruck, ohne IP wegen Mobilfunk)
        if (isset($_SESSION['_fingerprint']) && !hash_equals((string)$_SESSION['_fingerprint'], self::fingerprint())) {
            Logger::security('Session-Fingerabdruck stimmt nicht - Session verworfen.');
            self::destroy();
            session_start();
            $_SESSION['_created'] = $now;
            $_SESSION['_fingerprint'] = self::fingerprint();
        }
    }

    private static function fingerprint(): string
    {
        return hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|' . ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
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

    public static function flash(string $key, ?string $value = null): ?string
    {
        if ($value !== null) {
            $_SESSION['_flash'][$key] = $value;
            return null;
        }
        $message = $_SESSION['_flash'][$key] ?? null;
        unset($_SESSION['_flash'][$key]);
        return is_string($message) ? $message : null;
    }

    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            $_SESSION['_created'] = time();
        }
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (ini_get('session.use_cookies')) {
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
            session_destroy();
        }
    }
}
