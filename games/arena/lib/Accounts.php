<?php
declare(strict_types=1);

/**
 * Spielerkonten ohne Datenbank.
 *
 * Ein Konto = eine JSON-Datei unter data/accounts/. Der Dateiname ist der
 * Hash des kleingeschriebenen Namens, dadurch bleibt die Suche ohne Index
 * schnell und Groß-/Kleinschreibung spielt keine Rolle. Die Bestenliste
 * liegt zusätzlich als kompakte Datei bereit, damit die Startseite nicht
 * alle Konten lesen muss.
 */
require_once __DIR__ . '/Store.php';

final class Accounts
{
    private const MAX_TRIES = 8;
    private const LOCK_SECONDS = 300;
    private const LEADERBOARD_SIZE = 50;

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
        session_name('arena_player');
        session_start();
    }

    /* ------------------------------------------------------------- Anlegen */

    /** @return array{ok: bool, error?: string, account?: array<string, mixed>} */
    public static function register(string $name, string $password): array
    {
        $name = self::cleanName($name);
        if (mb_strlen($name) < 3) {
            return ['ok' => false, 'error' => 'Der Name braucht mindestens 3 Zeichen.'];
        }
        if (mb_strlen($name) > 16) {
            return ['ok' => false, 'error' => 'Der Name darf höchstens 16 Zeichen haben.'];
        }
        if (!preg_match('/^[\p{L}\p{N} _.\-]+$/u', $name)) {
            return ['ok' => false, 'error' => 'Erlaubt sind Buchstaben, Zahlen, Leerzeichen, Punkt, Strich und Unterstrich.'];
        }
        if (strlen($password) < 6) {
            return ['ok' => false, 'error' => 'Das Passwort braucht mindestens 6 Zeichen.'];
        }
        if (self::load($name) !== null) {
            return ['ok' => false, 'error' => 'Diesen Namen gibt es schon.'];
        }

        $account = [
            'name' => $name,
            'hash' => password_hash($password, PASSWORD_DEFAULT),
            'createdAt' => time(),
            'lastSeen' => time(),
            'xp' => 0,
            'xpSpent' => 0,
            'runs' => 0,
            'bestScore' => 0,
            'bestCycle' => 0,
            'bestWave' => 0,
            'bestTime' => 0,
            'totalKills' => 0,
            'unlocked' => [],
            'tries' => 0,
            'lockedUntil' => 0,
        ];
        if (!self::save($account)) {
            return ['ok' => false, 'error' => 'Konto konnte nicht gespeichert werden.'];
        }
        self::startSession($account);
        return ['ok' => true, 'account' => self::publicView($account)];
    }

    /** @return array{ok: bool, error?: string, account?: array<string, mixed>} */
    public static function login(string $name, string $password): array
    {
        $account = self::load($name);
        if ($account === null) {
            // Gleiche Antwortzeit wie bei falschem Passwort.
            password_verify($password, '$2y$12$usesomesillystringfoursaltingusesomesillystringfor');
            return ['ok' => false, 'error' => 'Name oder Passwort stimmt nicht.'];
        }
        if (($account['lockedUntil'] ?? 0) > time()) {
            $wait = (int) $account['lockedUntil'] - time();
            return ['ok' => false, 'error' => 'Zu viele Versuche. Bitte ' . $wait . ' Sekunden warten.'];
        }
        if (!password_verify($password, (string) $account['hash'])) {
            $account['tries'] = (int) ($account['tries'] ?? 0) + 1;
            if ($account['tries'] >= self::MAX_TRIES) {
                $account['tries'] = 0;
                $account['lockedUntil'] = time() + self::LOCK_SECONDS;
            }
            self::save($account);
            return ['ok' => false, 'error' => 'Name oder Passwort stimmt nicht.'];
        }

        $account['tries'] = 0;
        $account['lockedUntil'] = 0;
        $account['lastSeen'] = time();
        self::save($account);
        self::startSession($account);
        return ['ok' => true, 'account' => self::publicView($account)];
    }

    public static function logout(): void
    {
        self::start();
        unset($_SESSION['arena_player']);
        session_regenerate_id(true);
    }

    /** @return array<string, mixed>|null */
    public static function current(): ?array
    {
        self::start();
        $name = $_SESSION['arena_player'] ?? null;
        if (!is_string($name)) {
            return null;
        }
        return self::load($name);
    }

    /* ------------------------------------------------------- Fortschritt */

    /**
     * Schreibt das Ergebnis eines Runs gut: Erfahrung, Bestwerte, Statistik.
     * Je abgeschlossener Welle gibt es genau einen Erfahrungspunkt.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>|null Das aktualisierte Konto.
     */
    public static function submitRun(array $result): ?array
    {
        $account = self::current();
        if ($account === null) {
            return null;
        }

        // Die Werte kommen aus dem Browser - deshalb hart begrenzen.
        $waves = max(0, min(500, (int) ($result['waves'] ?? 0)));
        $kills = max(0, min(100000, (int) ($result['kills'] ?? 0)));
        $cycle = max(1, min(200, (int) ($result['cycle'] ?? 1)));
        $money = max(0, min(1000000, (int) ($result['money'] ?? 0)));
        $time = max(0, min(60 * 60 * 6, (int) ($result['time'] ?? 0)));
        $score = $waves * 100 + $kills * 5 + $money;

        $account['xp'] = (int) $account['xp'] + $waves;
        $account['runs'] = (int) $account['runs'] + 1;
        $account['totalKills'] = (int) $account['totalKills'] + $kills;
        $account['lastSeen'] = time();
        if ($score > (int) $account['bestScore']) {
            $account['bestScore'] = $score;
            $account['bestCycle'] = $cycle;
            $account['bestWave'] = $waves;
            $account['bestTime'] = $time;
        }
        self::save($account);
        self::updateLeaderboard($account);
        return self::publicView($account);
    }

    /**
     * Schaltet einen Charakter gegen Erfahrungspunkte frei.
     *
     * @return array{ok: bool, error?: string, account?: array<string, mixed>}
     */
    public static function unlock(string $characterId, int $cost): array
    {
        $account = self::current();
        if ($account === null) {
            return ['ok' => false, 'error' => 'Bitte zuerst anmelden.'];
        }
        $characterId = preg_replace('/[^a-z0-9_-]/', '', strtolower($characterId)) ?? '';
        if ($characterId === '') {
            return ['ok' => false, 'error' => 'Unbekannter Charakter.'];
        }
        if (in_array($characterId, $account['unlocked'], true)) {
            return ['ok' => true, 'account' => self::publicView($account)];
        }
        $available = (int) $account['xp'] - (int) $account['xpSpent'];
        if ($available < $cost) {
            return ['ok' => false, 'error' => 'Dafür fehlen dir noch ' . ($cost - $available) . ' Punkte.'];
        }
        $account['xpSpent'] = (int) $account['xpSpent'] + $cost;
        $account['unlocked'][] = $characterId;
        self::save($account);
        return ['ok' => true, 'account' => self::publicView($account)];
    }

    /* ------------------------------------------------------- Bestenliste */

    /** @return list<array<string, mixed>> */
    public static function leaderboard(int $limit = 25): array
    {
        $file = self::dir() . '/leaderboard.php';
        $data = is_file($file) ? json_decode(Store::strip(@file_get_contents($file)), true) : null;
        if (!is_array($data)) {
            return [];
        }
        return array_slice($data, 0, max(1, min(self::LEADERBOARD_SIZE, $limit)));
    }

    /** @param array<string, mixed> $account */
    private static function updateLeaderboard(array $account): void
    {
        $file = self::dir() . '/leaderboard.php';
        $handle = fopen($file, 'c+');
        if ($handle === false) {
            return;
        }
        try {
            flock($handle, LOCK_EX);
            $list = json_decode(Store::strip(stream_get_contents($handle)), true);
            if (!is_array($list)) {
                $list = [];
            }
            $entry = [
                'name' => $account['name'],
                'score' => (int) $account['bestScore'],
                'cycle' => (int) $account['bestCycle'],
                'waves' => (int) $account['bestWave'],
                'kills' => (int) $account['totalKills'],
                'xp' => (int) $account['xp'],
                'at' => time(),
            ];
            $list = array_values(array_filter(
                $list,
                static fn(array $row): bool => ($row['name'] ?? '') !== $account['name']
            ));
            $list[] = $entry;
            usort($list, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
            $list = array_slice($list, 0, self::LEADERBOARD_SIZE);

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, Store::GUARD . json_encode($list, JSON_UNESCAPED_UNICODE));
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /* ----------------------------------------------------------- Interna */

    /** @param array<string, mixed> $account @return array<string, mixed> */
    public static function publicView(array $account): array
    {
        return [
            'name' => $account['name'],
            'xp' => (int) $account['xp'],
            'xpAvailable' => (int) $account['xp'] - (int) $account['xpSpent'],
            'runs' => (int) $account['runs'],
            'bestScore' => (int) $account['bestScore'],
            'bestCycle' => (int) $account['bestCycle'],
            'bestWave' => (int) $account['bestWave'],
            'bestTime' => (int) $account['bestTime'],
            'totalKills' => (int) $account['totalKills'],
            'unlocked' => array_values($account['unlocked'] ?? []),
        ];
    }

    private static function cleanName(string $name): string
    {
        $name = preg_replace('/\s+/u', ' ', strip_tags($name)) ?? '';
        return trim($name);
    }

    /** @param array<string, mixed> $account */
    private static function startSession(array $account): void
    {
        self::start();
        session_regenerate_id(true);
        $_SESSION['arena_player'] = $account['name'];
    }

    /** @return array<string, mixed>|null */
    private static function load(string $name): ?array
    {
        $file = self::path($name);
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode(Store::strip(@file_get_contents($file)), true);
        if (!is_array($data)) {
            return null;
        }
        $data['unlocked'] = is_array($data['unlocked'] ?? null) ? $data['unlocked'] : [];
        return $data;
    }

    /** @param array<string, mixed> $account */
    private static function save(array $account): bool
    {
        $file = self::path((string) $account['name']);
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $tmp = $file . '.' . bin2hex(random_bytes(3)) . '.tmp.php';
        if (@file_put_contents($tmp, Store::GUARD . json_encode($account, JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
            return false;
        }
        return @rename($tmp, $file);
    }

    private static function path(string $name): string
    {
        // .php statt .json: die Datei schützt sich selbst, auch ohne .htaccess.
        return self::dir() . '/' . sha1(mb_strtolower(self::cleanName($name))) . '.php';
    }

    private static function dir(): string
    {
        $dir = dirname(__DIR__) . '/data/accounts';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }
}
