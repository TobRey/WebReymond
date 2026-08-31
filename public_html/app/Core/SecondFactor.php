<?php

declare(strict_types=1);

namespace WebAtze\Core;

/**
 * Der zweite Faktor bei der Anmeldung.
 *
 * Hinter /create liegen alle Kundendaten, die FTP-Zugänge und der
 * Schlüssel zum Sprachmodell. Ein Passwort allein ist dafür knapp: Es
 * kann auf einem fremden Rechner mitgelesen, aus einem anderen Dienst
 * durchgesickert oder erraten worden sein. Ein Zeitcode vom Telefon
 * hilft in allen drei Fällen.
 *
 * Der Ablauf:
 *   1. Benutzername und Passwort stimmen  ->  halbe Anmeldung
 *   2. Zeitcode oder Ersatzcode stimmt    ->  volle Anmeldung
 *
 * Zwischen 1 und 2 gibt es keinen Zugang. Wer nur das Passwort hat,
 * kommt nicht weiter.
 *
 * Bekannte Geräte müssen nicht jedes Mal fragen (30 Tage). Die Kennung
 * dafür steht nur als Prüfsumme in der Datenbank – wer sie dort liest,
 * kann sie nicht benutzen.
 */
final class SecondFactor
{
    private const KEY_PENDING = '_wa_2fa_user';
    private const KEY_SINCE = '_wa_2fa_since';
    private const KEY_USED_STEP = '_wa_2fa_step';

    private const COOKIE = 'webatze_device';
    private const DEVICE_DAYS = 30;

    /** Wer nach fünf Minuten noch keinen Code eingegeben hat, fängt neu an. */
    private const PENDING_SECONDS = 300;

    /** Hat dieses Konto einen zweiten Faktor eingerichtet? */
    public static function isActive(array $user): bool
    {
        return (string) ($user['totp_secret'] ?? '') !== ''
            && ($user['totp_confirmed_at'] ?? null) !== null;
    }

    // ------------------------------------------------------------------
    // Die halbe Anmeldung
    // ------------------------------------------------------------------

    public static function beginPending(int $userId): void
    {
        Session::start();

        // Neue Sitzungskennung: Wer vorher zusehen konnte, sieht die
        // halbe Anmeldung nicht mit.
        Session::regenerate();

        $_SESSION[self::KEY_PENDING] = $userId;
        $_SESSION[self::KEY_SINCE] = time();
    }

    /** Wer wartet gerade auf seinen Code? */
    public static function pendingUser(): ?array
    {
        Session::start();

        $id = (int) ($_SESSION[self::KEY_PENDING] ?? 0);
        $since = (int) ($_SESSION[self::KEY_SINCE] ?? 0);

        if ($id <= 0 || $since < time() - self::PENDING_SECONDS) {
            self::clearPending();
            return null;
        }

        return Db::first('SELECT * FROM users WHERE id = :id', ['id' => $id]);
    }

    public static function clearPending(): void
    {
        Session::start();
        unset($_SESSION[self::KEY_PENDING], $_SESSION[self::KEY_SINCE]);
    }

    // ------------------------------------------------------------------
    // Prüfen
    // ------------------------------------------------------------------

    /**
     * Zeitcode oder Ersatzcode prüfen.
     *
     * @return array{ok:bool, error:string, recovery:bool}
     */
    public static function check(array $user, string $code): array
    {
        $code = trim($code);

        if ($code === '') {
            return ['ok' => false, 'error' => 'Bitte den Code eingeben.', 'recovery' => false];
        }

        // Ein Ersatzcode enthält einen Bindestrich, ein Zeitcode nicht.
        if (str_contains($code, '-')) {
            return self::checkRecovery($user, $code);
        }

        $step = Totp::verify(
            (string) $user['totp_secret'],
            $code,
            self::lastUsedStep((int) $user['id'])
        );

        if ($step === null) {
            return [
                'ok' => false,
                'error' => 'Der Code stimmt nicht. Er wechselt alle 30 Sekunden – '
                    . 'am besten den nächsten abwarten.',
                'recovery' => false,
            ];
        }

        // Denselben Code nicht zweimal gelten lassen.
        self::rememberStep((int) $user['id'], $step);

        return ['ok' => true, 'error' => '', 'recovery' => false];
    }

    /** @return array{ok:bool, error:string, recovery:bool} */
    private static function checkRecovery(array $user, string $code): array
    {
        $hashed = json_decode((string) ($user['recovery_codes'] ?? '[]'), true);

        if (!is_array($hashed) || $hashed === []) {
            return [
                'ok' => false,
                'error' => 'Für dieses Konto sind keine Ersatzcodes hinterlegt.',
                'recovery' => false,
            ];
        }

        $rest = Totp::useRecoveryCode($code, $hashed);

        if ($rest === null) {
            return ['ok' => false, 'error' => 'Dieser Ersatzcode stimmt nicht.', 'recovery' => false];
        }

        // Ein Ersatzcode gilt genau einmal.
        Db::update('users', ['recovery_codes' => $rest], 'id = :id', ['id' => (int) $user['id']]);

        return ['ok' => true, 'error' => '', 'recovery' => true];
    }

    // ------------------------------------------------------------------
    // Bekannte Geräte
    // ------------------------------------------------------------------

    /** Darf dieses Gerät ohne Code hinein? */
    public static function deviceIsTrusted(int $userId, Request $request): bool
    {
        $raw = $request->cookie(self::COOKIE);

        if ($raw === '' || strlen($raw) < 32) {
            return false;
        }

        $row = Db::first(
            'SELECT * FROM trusted_devices WHERE id = :id AND user_id = :u',
            ['id' => hash('sha256', $raw), 'u' => $userId]
        );

        if ($row === null) {
            return false;
        }

        if (strtotime((string) $row['expires_at']) < time()) {
            Db::delete('trusted_devices', 'id = :id', ['id' => (string) $row['id']]);
            return false;
        }

        return true;
    }

    /** Dieses Gerät für 30 Tage merken. */
    public static function trustDevice(int $userId, Request $request): void
    {
        $raw = bin2hex(random_bytes(32));
        $expires = time() + self::DEVICE_DAYS * 86400;

        Db::insert('trusted_devices', [
            // Nur die Prüfsumme. Wer die Datenbank liest, kann sich damit
            // nicht als bekanntes Gerät ausgeben.
            'id' => hash('sha256', $raw),
            'user_id' => $userId,
            'label' => mb_substr($request->userAgent(), 0, 120),
            'ip' => $request->ip(),
            'expires_at' => date('Y-m-d H:i:s', $expires),
            'created_at' => Db::now(),
        ]);

        setcookie(self::COOKIE, $raw, [
            'expires' => $expires,
            'path' => '/',
            'secure' => Session::cookiesShouldBeSecure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /** Alle bekannten Geräte vergessen – etwa nach einem Passwortwechsel. */
    public static function forgetDevices(int $userId): int
    {
        return Db::delete('trusted_devices', 'user_id = :u', ['u' => $userId]);
    }

    public static function devices(int $userId): array
    {
        return Db::all(
            'SELECT * FROM trusted_devices WHERE user_id = :u ORDER BY created_at DESC',
            ['u' => $userId]
        );
    }

    /** Abgelaufene Geräte wegräumen (läuft im Worker). */
    public static function purgeExpired(): int
    {
        return Db::delete('trusted_devices', 'expires_at < :now', ['now' => Db::now()]);
    }

    // ------------------------------------------------------------------
    // Einrichten
    // ------------------------------------------------------------------

    /**
     * Einen Schlüssel vorbereiten – noch nicht scharf schalten.
     *
     * Bestätigt wird er erst, wenn ein Code daraus stimmt. Sonst könnte
     * sich jemand aussperren, dessen App den Schlüssel gar nicht
     * übernommen hat.
     */
    public static function prepare(int $userId): string
    {
        $secret = Totp::generateSecret();

        Db::update('users', [
            'totp_secret' => $secret,
            'totp_confirmed_at' => null,
        ], 'id = :id', ['id' => $userId]);

        return $secret;
    }

    /**
     * Scharf schalten, wenn der erste Code stimmt.
     *
     * @return string[]|null Die Ersatzcodes, oder null bei falschem Code
     */
    public static function confirm(int $userId, string $code): ?array
    {
        $user = Db::first('SELECT * FROM users WHERE id = :id', ['id' => $userId]);

        if ($user === null || (string) $user['totp_secret'] === '') {
            return null;
        }

        if (Totp::verify((string) $user['totp_secret'], $code) === null) {
            return null;
        }

        $codes = Totp::recoveryCodes();

        Db::update('users', [
            'totp_confirmed_at' => Db::now(),
            'recovery_codes' => $codes['hashed'],
        ], 'id = :id', ['id' => $userId]);

        return $codes['plain'];
    }

    /** Abschalten – nur mit gültigem Code, sonst genügte ein offener Bildschirm. */
    public static function disable(int $userId, string $code): bool
    {
        $user = Db::first('SELECT * FROM users WHERE id = :id', ['id' => $userId]);

        if ($user === null || !self::isActive($user)) {
            return false;
        }

        if (self::check($user, $code)['ok'] !== true) {
            return false;
        }

        Db::update('users', [
            'totp_secret' => '',
            'totp_confirmed_at' => null,
            'recovery_codes' => null,
        ], 'id = :id', ['id' => $userId]);

        self::forgetDevices($userId);

        return true;
    }

    /** Wie viele Ersatzcodes sind noch übrig? */
    public static function recoveryCodesLeft(array $user): int
    {
        $codes = json_decode((string) ($user['recovery_codes'] ?? '[]'), true);
        return is_array($codes) ? count($codes) : 0;
    }

    // ------------------------------------------------------------------

    private static function lastUsedStep(int $userId): ?int
    {
        Session::start();
        $steps = $_SESSION[self::KEY_USED_STEP] ?? [];
        return isset($steps[$userId]) ? (int) $steps[$userId] : null;
    }

    private static function rememberStep(int $userId, int $step): void
    {
        Session::start();
        $_SESSION[self::KEY_USED_STEP][$userId] = $step;
    }
}
