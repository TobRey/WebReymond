<?php
declare(strict_types=1);

/**
 * Admin-Authentifizierung. Der Code wird serverseitig geprueft und
 * niemals an den Client ausgeliefert. Quelle in dieser Reihenfolge:
 *   1. Umgebungsvariable ADMIN_CODE
 *   2. data/admin.json (Hash, beim ersten Start aus 1. oder Standard erzeugt)
 */
require_once __DIR__ . '/Store.php';

final class Auth
{
    private const DEFAULT_CODE = '6713';
    /** Konto, das ohne Code in den Adminbereich darf. */
    private const DEFAULT_ADMIN_USER = 'spielatze';
    private const MAX_TRIES = 6;
    private const LOCK_SECONDS = 300;

    /**
     * Eine einzige Sitzung fuer Spieler und Admin.
     *
     * Frueher lief der Admin unter 'arena_admin' und das Spielerkonto unter
     * 'arena_player'. PHP kann pro Anfrage aber nur eine Sitzung oeffnen:
     * Wer sich zuerst meldete, gewann - und jedes session_regenerate_id des
     * einen warf den anderen hinaus. Wer im Spiel angemeldet war, kam
     * deshalb nicht mehr in den Adminbereich. Beide Rollen teilen sich jetzt
     * eine Sitzung und liegen darin unter eigenen Schluesseln.
     */
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_set_cookie_params([
            'lifetime' => 60 * 60 * 24 * 30,
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off'),
        ]);
        session_name('arena');
        session_start();
    }

    /**
     * Name des Kontos, das ohne Code hineindarf.
     * Ueber die Umgebung einstellbar: ADMIN_USER=spielatze
     */
    public static function adminUser(): string
    {
        $name = getenv('ADMIN_USER');
        if (!is_string($name) || trim($name) === '') {
            $name = self::DEFAULT_ADMIN_USER;
        }
        return strtolower(trim($name));
    }

    /**
     * Ist die aktuelle Sitzung Admin?
     *
     * Zwei Wege fuehren hinein: der Code - oder ein angemeldetes Spielerkonto
     * mit dem im Server hinterlegten Adminnamen. Der Name wird dabei gegen
     * die Sitzung geprueft, nicht gegen die Anfrage; wer sich als dieses
     * Konto ausgeben will, muss dessen Passwort kennen.
     */
    public static function isAdmin(): bool
    {
        self::start();
        if (!empty($_SESSION['arena_admin'])) {
            return true;
        }
        $spieler = $_SESSION['arena_player'] ?? null;
        return is_string($spieler) && strtolower($spieler) === self::adminUser();
    }

    /** True, wenn der Zugang ueber das Spielerkonto kommt (kein Code noetig). */
    public static function isAdminByAccount(): bool
    {
        self::start();
        $spieler = $_SESSION['arena_player'] ?? null;
        return is_string($spieler) && strtolower($spieler) === self::adminUser();
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

    /**
     * Abmelden im Adminbereich.
     *
     * Kam der Zugang ueber das Adminkonto, wird auch dessen Anmeldung
     * geloest - sonst waere man mit einem Klick auf "Abmelden" sofort
     * wieder drin und der Knopf haette keine Wirkung.
     */
    public static function logout(): void
    {
        self::start();
        if (self::isAdminByAccount()) {
            unset($_SESSION['arena_player']);
        }
        unset($_SESSION['arena_admin'], $_SESSION['arena_admin_since']);
        session_regenerate_id(true);
    }

    /** Aendert den Admin-Code (nur für angemeldete Admins sinnvoll). */
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
        $data = is_file($file) ? json_decode(Store::strip(@file_get_contents($file)), true) : null;
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
        return @file_put_contents($file, Store::GUARD . json_encode($state), LOCK_EX) !== false;
    }

    private static function file(): string
    {
        // .php statt .json: schützt sich selbst, auch wenn .htaccess fehlt.
        return dirname(__DIR__) . '/data/admin.php';
    }
}
