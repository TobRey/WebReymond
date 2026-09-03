<?php

declare(strict_types=1);

namespace WebAtze\Core;

use RuntimeException;
use SensitiveParameter;

/**
 * Verschlüsselung für gespeicherte Geheimnisse – vor allem FTP-Passwörter
 * der Kunden. Benutzt libsodium (in PHP 8 fest eingebaut).
 *
 * Der Schlüssel steht in config.php unter 'crypto_key'. Wird er getauscht,
 * sind alte Werte unlesbar; das ist gewollt und im Kommentar dort vermerkt.
 */
final class Crypto
{
    /**
     * Ist verschlüsseln überhaupt möglich?
     *
     * Es gibt genau zwei Gründe, warum nicht: Die Erweiterung libsodium
     * fehlt, oder crypto_key in app/config.php taugt nichts. Beide sind
     * nichts, was der Code beheben könnte – aber beide lassen sich
     * benennen, statt in einer Fehlerseite zu enden.
     *
     * @return string leerer Text, wenn alles stimmt; sonst der Grund
     */
    public static function status(): string
    {
        if (!function_exists('sodium_crypto_secretbox')) {
            return 'Auf diesem Server fehlt die PHP-Erweiterung «sodium». '
                . 'In cPanel unter «Select PHP Version» → Extensions lässt sie sich einschalten.';
        }

        $hex = trim((string) Config::get('crypto_key', ''));

        if ($hex === '') {
            return 'In app/config.php fehlt der Eintrag «crypto_key».';
        }

        if (strlen($hex) < self::KEY_BYTES * 2) {
            return 'Der Eintrag «crypto_key» in app/config.php ist zu kurz: '
                . strlen($hex) . ' statt ' . (self::KEY_BYTES * 2) . ' Zeichen.';
        }

        if (@hex2bin(substr($hex, 0, self::KEY_BYTES * 2)) === false) {
            return 'Der Eintrag «crypto_key» in app/config.php enthält Zeichen, '
                . 'die dort nicht hingehören. Erlaubt sind nur 0-9 und a-f.';
        }

        return '';
    }

    public static function isReady(): bool
    {
        return self::status() === '';
    }

    /**
     * Die Länge eines Schlüssels in Bytes.
     *
     * Absichtlich als Zahl und nicht als SODIUM_CRYPTO_SECRETBOX_KEYBYTES:
     * Fehlt die Erweiterung, gibt es die Konstante nicht, und ein Zugriff
     * darauf ist in PHP 8 kein Hinweis, sondern das Ende der Anfrage.
     * Genau daran ist die Tresorseite einmal gestorben – auf einem Server,
     * auf dem sonst alles lief. Der Wert ist seit es libsodium gibt 32
     * und Teil des Formats; er ändert sich nicht mehr.
     */
    private const KEY_BYTES = 32;

    /** Ein frischer Schlüssel, wie ihn app/config.php erwartet. */
    public static function newKey(): string
    {
        return bin2hex(random_bytes(self::KEY_BYTES));
    }

    public static function encrypt(#[SensitiveParameter] string $plaintext): string
    {
        $key = self::key();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);
        sodium_memzero($key);

        return 'v1.' . base64_encode($nonce . $cipher);
    }

    public static function decrypt(string $payload): ?string
    {
        // Ohne brauchbare Verschlüsselung gibt es nichts zurückzuholen.
        // Das ist genau die Antwort, die die Aufrufer erwarten - eine
        // Ausnahme wäre hier eine Fehlerseite statt einer Meldung.
        if (!self::isReady() || !str_starts_with($payload, 'v1.')) {
            return null;
        }
        $raw = base64_decode(substr($payload, 3), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $key = self::key();
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
        sodium_memzero($key);

        return $plain === false ? null : $plain;
    }

    /** Kurzer, fälschungssicherer Stempel für Links (z.B. Vorschau-Token). */
    public static function sign(string $value): string
    {
        return hash_hmac('sha256', $value, (string) Config::get('app_key'));
    }

    public static function verify(string $value, string $signature): bool
    {
        return hash_equals(self::sign($value), $signature);
    }

    public static function hashPassword(#[SensitiveParameter] string $password): string
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        $hash = password_hash($password, $algo);
        if (!is_string($hash) || $hash === '') {
            throw new RuntimeException('Passwort konnte nicht verschlüsselt werden.');
        }
        return $hash;
    }

    public static function checkPassword(#[SensitiveParameter] string $password, string $hash): bool
    {
        return $hash !== '' && password_verify($password, $hash);
    }

    private static function key(): string
    {
        $grund = self::status();

        if ($grund !== '') {
            throw new RuntimeException($grund);
        }

        $key = @hex2bin(substr(trim((string) Config::get('crypto_key', '')), 0, self::KEY_BYTES * 2));

        if ($key === false || strlen($key) !== self::KEY_BYTES) {
            throw new RuntimeException('crypto_key ist keine gültige Hex-Zeichenkette.');
        }

        return $key;
    }
}
