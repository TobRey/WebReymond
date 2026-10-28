<?php
declare(strict_types=1);

if (!defined('CASH_ENTRY')) {
    http_response_code(404);
    exit;
}

/**
 * PIN-Sperre vor der gesamten Anwendung (Seiten und API).
 *
 * - Die PIN wird nie im Code gespeichert: Einrichtung beim ersten Aufruf (bei bestehender
 *   Installation nur mit Benutzername + Passwort des Hauptaccounts).
 * - Gespeichert wird nur password_hash(HMAC-SHA256(PIN, Geheimschlüssel)); der Schlüssel
 *   liegt in einer anderen Datei als der Hash.
 * - Rate-Limiting: pro IP 5 Fehlversuche → Sperre 15 min, jede weitere Sperre doppelt so lang
 *   (max. 24 h). Zusätzlich global: 10 Fehlversuche in 24 h von unbekannten Geräten →
 *   24 h Sperre für alle unbekannten Geräte. Geräte, die schon einmal korrekt entsperrt
 *   wurden (Geräte-Cookie), sind von der globalen Sperre ausgenommen, damit ein Angreifer
 *   den Besitzer nicht aussperren kann.
 */
final class Pin
{
    public const IDLE = 3600;               // 1 h Inaktivität → PIN erneut
    public const MAX = 43200;               // spätestens nach 12 h erneut
    public const KEY_FAILS = 5;
    public const KEY_WINDOW = 900;
    public const BASE_LOCK = 900;
    public const MAX_LOCK = 86400;
    public const GLOBAL_FAILS = 10;
    public const GLOBAL_WINDOW = 86400;
    public const GLOBAL_LOCK = 86400;
    public const COOKIE_DEV = 'CASHDEV';
    public const DEVICE_DAYS = 180;

    private static function file(): string { return cash_path('pin.json.php'); }
    private static function limitFile(): string { return cash_path('pinlimit.json.php'); }

    public static function data(): array
    {
        return Store::read(self::file(), []);
    }

    public static function isConfigured(): bool
    {
        $d = self::data();
        return is_string($d['hash'] ?? null) && $d['hash'] !== '';
    }

    /** Geheimschlüssel (Pepper) – liegt in config.json.php, nicht neben dem Hash. */
    private static function pepper(): string
    {
        return Store::mutate(cash_path('config.json.php'), function (array &$c) {
            if (empty($c['pin_pepper']) || !is_string($c['pin_pepper'])) {
                $c['pin_pepper'] = bin2hex(random_bytes(32));
            }
            return $c['pin_pepper'];
        });
    }

    private static function prehash(string $pin): string
    {
        return hash_hmac('sha256', $pin, self::pepper());
    }

    public static function validFormat(mixed $pin): bool
    {
        return is_string($pin) && preg_match('/^\d{4,8}$/', $pin) === 1;
    }

    // ------------------------------------------------------------ Session-Status

    public static function verified(): bool
    {
        $ok = (int)($_SESSION['pin_ok'] ?? 0);
        if ($ok === 0) {
            return false;
        }
        $now = time();
        $d = self::data();
        if (!isset($d['hash']) || (int)($_SESSION['pin_epoch'] ?? -1) !== (int)($d['epoch'] ?? 0)
            || $now - (int)($_SESSION['pin_last'] ?? 0) > self::IDLE || $now - $ok > self::MAX) {
            unset($_SESSION['pin_ok'], $_SESSION['pin_last'], $_SESSION['pin_epoch']);
            return false;
        }
        $_SESSION['pin_last'] = $now;
        return true;
    }

    private static function markVerified(int $epoch): void
    {
        session_regenerate_id(true);
        $_SESSION['pin_ok'] = time();
        $_SESSION['pin_last'] = time();
        $_SESSION['pin_epoch'] = $epoch;
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    public static function lock(): void
    {
        unset($_SESSION['pin_ok'], $_SESSION['pin_last'], $_SESSION['pin_epoch']);
    }

    // ------------------------------------------------------------ Geräte-Cookie

    private static function deviceHash(): ?string
    {
        $c = $_COOKIE[self::COOKIE_DEV] ?? '';
        return is_string($c) && preg_match('/^[a-f0-9]{64}$/', $c) ? hash('sha256', $c) : null;
    }

    private static function deviceTrusted(array $d): bool
    {
        $h = self::deviceHash();
        if ($h === null) {
            return false;
        }
        foreach ($d['devices'] ?? [] as $dev) {
            if (hash_equals($dev['hash'], $h) && $dev['expires'] > time()) {
                return true;
            }
        }
        return false;
    }

    private static function trustDevice(array &$d): void
    {
        $now = time();
        $h = self::deviceHash();
        $known = false;
        foreach ($d['devices'] ?? [] as &$dev) {
            if ($h !== null && hash_equals($dev['hash'], $h)) {
                $dev['lastUsed'] = $now;
                $dev['expires'] = $now + self::DEVICE_DAYS * 86400;
                $known = true;
            }
        }
        unset($dev);
        $value = $_COOKIE[self::COOKIE_DEV] ?? '';
        if (!$known) {
            $value = bin2hex(random_bytes(32));
            $d['devices'][] = ['hash' => hash('sha256', $value), 'created' => $now, 'lastUsed' => $now, 'expires' => $now + self::DEVICE_DAYS * 86400];
        }
        $d['devices'] = array_slice(array_values(array_filter($d['devices'], fn($x) => $x['expires'] > $now)), -20);
        setcookie(self::COOKIE_DEV, (string)$value, [
            'expires' => $now + self::DEVICE_DAYS * 86400,
            'path' => Http::basePath(),
            'secure' => Http::isHttps(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    // ------------------------------------------------------------ Rate-Limiting

    /** Liefert eine Sperrmeldung oder null. Muss unter der Sperre der Limit-Datei laufen. */
    private static function lockMessage(array $l, string $key, bool $trusted, int $now): ?string
    {
        $until = max((int)($l['keys'][$key]['lockedUntil'] ?? 0), $trusted ? 0 : (int)($l['global']['lockedUntil'] ?? 0));
        if ($until > $now) {
            $min = (int)ceil(($until - $now) / 60);
            return $min >= 120
                ? 'Zu viele Fehlversuche. Gesperrt für ' . (int)ceil($min / 60) . ' Stunden.'
                : 'Zu viele Fehlversuche. Bitte in ' . $min . ' Minute' . ($min === 1 ? '' : 'n') . ' erneut versuchen.';
        }
        return null;
    }

    private static function recordFailure(array &$l, string $key, bool $trusted, int $now): void
    {
        $k = $l['keys'][$key] ?? ['fails' => [], 'lockedUntil' => 0, 'locks' => 0];
        $k['fails'] = array_values(array_filter($k['fails'], fn($t) => $t > $now - self::KEY_WINDOW));
        $k['fails'][] = $now;
        if (count($k['fails']) >= self::KEY_FAILS) {
            $k['locks'] = (int)$k['locks'] + 1;
            $k['lockedUntil'] = $now + min(self::BASE_LOCK * (2 ** ($k['locks'] - 1)), self::MAX_LOCK);
            $k['fails'] = [];
        }
        $k['last'] = $now;
        $l['keys'][$key] = $k;
        if (!$trusted) {
            $g = $l['global'] ?? ['fails' => [], 'lockedUntil' => 0];
            $g['fails'] = array_values(array_filter($g['fails'], fn($t) => $t > $now - self::GLOBAL_WINDOW));
            $g['fails'][] = $now;
            if (count($g['fails']) >= self::GLOBAL_FAILS) {
                $g['lockedUntil'] = $now + self::GLOBAL_LOCK;
                $g['fails'] = [];
            }
            $l['global'] = $g;
        }
        // Alte Einträge aufräumen (Sperrzähler bleibt 7 Tage für die Eskalation).
        foreach ($l['keys'] as $kk => $v) {
            if (($v['lockedUntil'] ?? 0) < $now && ($v['last'] ?? 0) < $now - 7 * 86400) {
                unset($l['keys'][$kk]);
            }
        }
    }

    private static function key(): string
    {
        return 'ip:' . hash('sha256', Http::clientIp());   // keine Klartext-IP gespeichert
    }

    /** Aktuelle Sperrmeldung für dieses Gerät (für die Anzeige). */
    public static function currentLock(): ?string
    {
        $l = Store::read(self::limitFile(), ['keys' => [], 'global' => []]);
        return self::lockMessage($l, self::key(), self::deviceTrusted(self::data()), time());
    }

    /**
     * Prüft eine PIN mit Rate-Limiting. Versuche werden unter exklusiver Dateisperre
     * serialisiert, damit parallele Anfragen das Limit nicht umgehen können.
     * @return string|null Fehlermeldung oder null bei Erfolg
     */
    public static function attempt(mixed $pin): ?string
    {
        $d = self::data();
        if (!isset($d['hash'])) {
            return 'Es ist noch keine PIN eingerichtet.';
        }
        $trusted = self::deviceTrusted($d);
        $key = self::key();
        $result = Store::mutate(self::limitFile(), function (array &$l) use ($pin, $d, $trusted, $key) {
            $now = time();
            $l['keys'] ??= [];
            $l['global'] ??= ['fails' => [], 'lockedUntil' => 0];
            if ($msg = self::lockMessage($l, $key, $trusted, $now)) {
                return $msg;
            }
            $ok = self::validFormat($pin) && password_verify(self::prehash((string)$pin), $d['hash']);
            if (!$ok) {
                self::recordFailure($l, $key, $trusted, $now);
                return self::lockMessage($l, $key, $trusted, $now) ?? 'Falsche PIN.';
            }
            unset($l['keys'][$key]);
            return null;
        }, ['keys' => [], 'global' => ['fails' => [], 'lockedUntil' => 0]]);

        if ($result !== null) {
            usleep(random_int(250000, 600000));   // bremst automatisierte Versuche zusätzlich
            return $result;
        }
        $epoch = Store::mutate(self::file(), function (array &$p) use ($pin) {
            if (password_needs_rehash($p['hash'], PASSWORD_DEFAULT)) {
                $p['hash'] = password_hash(self::prehash((string)$pin), PASSWORD_DEFAULT);
            }
            $p['devices'] ??= [];
            self::trustDevice($p);
            return (int)($p['epoch'] ?? 0);
        });
        self::markVerified($epoch);
        return null;
    }

    // ------------------------------------------------------------ Einrichten / Ändern

    /**
     * Ersteinrichtung. Gibt es schon Benutzer, sind Benutzername + Passwort des
     * Hauptaccounts nötig (rate-limitiert wie das Login).
     */
    public static function setup(array $in): ?string
    {
        if (self::isConfigured()) {
            return 'Die PIN ist bereits eingerichtet.';
        }
        $pin = $in['pin'] ?? '';
        if (!self::validFormat($pin)) {
            return 'Die PIN muss aus 4 bis 8 Ziffern bestehen.';
        }
        if (($in['pin2'] ?? '') !== $pin) {
            return 'Die beiden PINs stimmen nicht überein.';
        }
        if (Auth::hasUsers()) {
            try {
                $user = Auth::checkCredentials((string)($in['username'] ?? ''), (string)($in['password'] ?? ''));
            } catch (ApiError $e) {
                return $e->getMessage();
            }
            if ($user['role'] !== 'owner') {
                return 'Nur der Hauptaccount kann die PIN einrichten.';
            }
        }
        $hash = password_hash(self::prehash($pin), PASSWORD_DEFAULT);
        $done = Store::mutate(self::file(), function (array &$p) use ($hash) {
            if (!empty($p['hash'])) {
                return false;   // parallele Einrichtung: nur die erste zählt
            }
            $p['hash'] = $hash;
            $p['epoch'] = 1;
            $p['created'] = date('c');
            $p['devices'] = [];
            self::trustDevice($p);
            return true;
        });
        if (!$done) {
            return 'Die PIN ist bereits eingerichtet.';
        }
        self::markVerified(1);
        return null;
    }

    /** PIN ändern (angemeldeter Hauptaccount, aktuelle PIN nötig). Meldet alle anderen PIN-Sessions ab. */
    public static function change(mixed $current, mixed $new, mixed $new2): void
    {
        if (!self::validFormat($new)) {
            throw new ApiError('Die neue PIN muss aus 4 bis 8 Ziffern bestehen.');
        }
        if ($new !== $new2) {
            throw new ApiError('Die neuen PINs stimmen nicht überein.');
        }
        if ($msg = self::attempt($current)) {
            throw new ApiError($msg === 'Falsche PIN.' ? 'Die aktuelle PIN ist falsch.' : $msg, 403);
        }
        $hash = password_hash(self::prehash((string)$new), PASSWORD_DEFAULT);
        $epoch = Store::mutate(self::file(), function (array &$p) use ($hash) {
            $p['hash'] = $hash;
            $p['epoch'] = (int)($p['epoch'] ?? 0) + 1;
            $p['changed'] = date('c');
            return $p['epoch'];
        });
        $_SESSION['pin_epoch'] = $epoch;
    }

    /** Alle Geräte vergessen (sie unterliegen danach wieder der globalen Sperre). */
    public static function forgetDevices(): void
    {
        Store::mutate(self::file(), function (array &$p) {
            $p['devices'] = [];
            self::trustDevice($p);   // aktuelles Gerät bleibt bekannt
        });
    }

    public static function deviceCount(): int
    {
        return count(array_filter(self::data()['devices'] ?? [], fn($x) => $x['expires'] > time()));
    }
}
