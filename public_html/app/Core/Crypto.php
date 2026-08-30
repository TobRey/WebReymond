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
        if (!str_starts_with($payload, 'v1.')) {
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
        $hex = (string) Config::get('crypto_key', '');
        if (strlen($hex) < 64) {
            throw new RuntimeException(
                'crypto_key fehlt oder ist zu kurz. In app/config.php einen Wert mit 64 Zeichen eintragen.'
            );
        }
        $key = @hex2bin(substr($hex, 0, 64));
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('crypto_key ist keine gültige Hex-Zeichenkette.');
        }
        return $key;
    }
}
