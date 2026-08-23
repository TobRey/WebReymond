<?php
declare(strict_types=1);

/**
 * Admin-Authentifizierung. Der Code wird serverseitig geprueft und
 * niemals an den Client ausgeliefert. Quelle in dieser Reihenfolge:
 *   1. Umgebungsvariable ADMIN_CODE
 *   2. data/admin.json (Hash, beim ersten Start aus 1. oder Standard erzeugt)
 */
final class Auth
{
    private const DEFAULT_CODE = '6713';
    private const MAX_TRIES = 6;
    private const LOCK_SECONDS = 300;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off'),
        ]);
        session_name('arena_admin');
        session_start();
    }

    public static function isAdmin(): bool
    {
        self::start();
        return !empty($_SESSION['arena_admin']);
    }

    /** @return array{ok: bool, error?: string} */
    public static function login(string $code): array
    {
        self::start();
        $state = self::state();

        if ($state['lockedUntil'] > time()) {
            $wait = $state['lockedUntil'] - time();
            return ['ok' => false, 'error' => 'Zu viele Versuche. Bitte ' . $wait . ' Sekunden warten.'];
        }

        if (!password_verify($code, $state['hash'])) {
            $state['tries']++;
            if ($state['tries'] >= self::MAX_TRIES) {
                $state['tries'] = 0;
                $state['lockedUntil'] = time() + self::LOCK_SECONDS;
            }
            self::saveState($state);
            return ['ok' => false, 'error' => 'Falscher Code.'];
        }

        $state['tries'] = 0;
        $state['lockedUntil'] = 0;
        self::saveState($state);
        session_regenerate_id(true);
        $_SESSION['arena_admin'] = true;
        $_SESSION['arena_admin_since'] = time();
        return ['ok' => true];
    }

    public static function logout(): void
    {
        self::start();
        unset($_SESSION['arena_admin'], $_SESSION['arena_admin_since']);
        session_regenerate_id(true);
    }

    /** Aendert den Admin-Code (nur fuer angemeldete Admins sinnvoll). */
    public static function changeCode(string $new): bool
    {
        $new = trim($new);
        if (strlen($new) < 4) {
            return false;
        }
        $state = self::state();
        $state['hash'] = password_hash($new, PASSWORD_DEFAULT);
        return self::saveState($state);
    }

    /** @return array{hash: string, tries: int, lockedUntil: int} */
    private static function state(): array
    {
        $file = self::file();
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($data) || empty($data['hash'])) {
            $code = getenv('ADMIN_CODE');
            if (!is_string($code) || $code === '') {
                $code = self::DEFAULT_CODE;
            }
            $data = ['hash' => password_hash($code, PASSWORD_DEFAULT), 'tries' => 0, 'lockedUntil' => 0];
            self::saveState($data);
        }
        return [
            'hash' => (string) $data['hash'],
            'tries' => (int) ($data['tries'] ?? 0),
            'lockedUntil' => (int) ($data['lockedUntil'] ?? 0),
        ];
    }

    /** @param array<string, mixed> $state */
    private static function saveState(array $state): bool
    {
        $file = self::file();
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0775, true);
        }
        return @file_put_contents($file, json_encode($state), LOCK_EX) !== false;
    }

    private static function file(): string
    {
        return dirname(__DIR__) . '/data/admin.json';
    }
}
