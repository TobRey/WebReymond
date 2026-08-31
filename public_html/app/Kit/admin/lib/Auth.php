<?php

declare(strict_types=1);

namespace WebAtzeKit;

/**
 * Anmeldung zum Bearbeitungsbereich des Kunden.
 *
 * Ein einziges Konto, dessen Passwort als Argon2id-Hash in der
 * Konfiguration steht. Das Passwort selbst steht nirgends – auch nicht
 * bei WebAtze.
 *
 * Gegen das Durchprobieren von Passwörtern hilft weder ein langes
 * Passwort allein noch ein Bilderrätsel, sondern eine Sperre: Nach fünf
 * Fehlversuchen ist für eine Viertelstunde Schluss. Gezählt wird in einer
 * Datei, denn eine Datenbank gibt es hier nicht.
 */
final class Auth
{
    private const MAX_ATTEMPTS = 5;
    private const LOCKOUT_SECONDS = 900;
    private const IDLE_SECONDS = 7200;

    private array $config;
    private string $attemptsFile;

    public function __construct(array $config, string $dataDir)
    {
        $this->config = $config;
        $this->attemptsFile = rtrim($dataDir, '/') . '/attempts.php';
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => self::basePath(),
            'secure' => self::isSecure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name('wa_kunde');
        @session_start();
    }

    public function isLoggedIn(): bool
    {
        $this->start();

        $since = (int) ($_SESSION['seen'] ?? 0);
        if (empty($_SESSION['auth']) || $since < time() - self::IDLE_SECONDS) {
            return false;
        }

        $_SESSION['seen'] = time();
        return true;
    }

    /** @return array{ok:bool, error:string} */
    public function login(string $username, string $password): array
    {
        $this->start();

        $wait = $this->lockedFor();
        if ($wait > 0) {
            return [
                'ok' => false,
                'error' => sprintf(
                    'Zu viele Versuche. Bitte in %d Minuten erneut probieren.',
                    (int) ceil($wait / 60)
                ),
            ];
        }

        $expectedUser = (string) ($this->config['admin_username'] ?? '');
        $hash = (string) ($this->config['admin_password_hash'] ?? '');

        // Auch bei falschem Benutzernamen wird ein Hash geprüft. Sonst
        // wäre an der Antwortzeit ablesbar, ob es den Namen gibt.
        $checkHash = $hash !== ''
            ? $hash
            : '$argon2id$v=19$m=65536,t=4,p=1$' . base64_encode(str_repeat('x', 16))
              . '$' . base64_encode(str_repeat('y', 32));

        $passwordOk = password_verify($password, $checkHash);
        $userOk = $expectedUser !== '' && hash_equals($expectedUser, $username);

        if (!$passwordOk || !$userOk || $hash === '') {
            $this->noteFailure();
            return ['ok' => false, 'error' => 'Benutzername oder Passwort stimmt nicht.'];
        }

        $this->clearFailures();

        session_regenerate_id(true);
        $_SESSION['auth'] = true;
        $_SESSION['seen'] = time();
        $_SESSION['user'] = $username;

        return ['ok' => true, 'error' => ''];
    }

    public function logout(): void
    {
        $this->start();
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $p['path'],
                'secure' => $p['secure'],
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        session_destroy();
    }

    public function username(): string
    {
        $this->start();
        return (string) ($_SESSION['user'] ?? '');
    }

    // ------------------------------------------------------------------

    /** Wie lange ist noch gesperrt? 0 = gar nicht. */
    private function lockedFor(): int
    {
        $attempts = $this->recentFailures();

        if (count($attempts) < self::MAX_ATTEMPTS) {
            return 0;
        }

        $oldest = min($attempts);
        return max(0, $oldest + self::LOCKOUT_SECONDS - time());
    }

    private function recentFailures(): array
    {
        $data = Store::readGuarded($this->attemptsFile);
        $key = $this->bucket();
        $now = time();

        return array_values(array_filter(
            (array) ($data[$key] ?? []),
            static fn ($t): bool => (int) $t > $now - self::LOCKOUT_SECONDS
        ));
    }

    private function noteFailure(): void
    {
        $data = Store::readGuarded($this->attemptsFile);
        $key = $this->bucket();

        $attempts = $this->recentFailures();
        $attempts[] = time();
        $data[$key] = $attempts;

        // Abgelaufene Einträge anderer Herkünfte gleich mit wegräumen.
        $now = time();
        foreach ($data as $k => $times) {
            $times = array_values(array_filter(
                (array) $times,
                static fn ($t): bool => (int) $t > $now - self::LOCKOUT_SECONDS
            ));
            if ($times === []) {
                unset($data[$k]);
            } else {
                $data[$k] = $times;
            }
        }

        Store::writeGuarded($this->attemptsFile, $data);
    }

    private function clearFailures(): void
    {
        $data = Store::readGuarded($this->attemptsFile);
        unset($data[$this->bucket()]);
        Store::writeGuarded($this->attemptsFile, $data);
    }

    /** Gezählt wird je Herkunft – als Hash, damit keine Adressen herumliegen. */
    private function bucket(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        return hash('sha256', filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unbekannt');
    }

    public static function isSecure(): bool
    {
        return (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
            || ($_SERVER['SERVER_PORT'] ?? '') === '443'
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }

    /** Das Verzeichnis, in dem der Bearbeitungsbereich liegt. */
    public static function basePath(): string
    {
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '/admin/index.php');
        $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
        return $dir === '' ? '/' : $dir . '/';
    }
}
