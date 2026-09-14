<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Verschluesselung von Geheimnissen (z. B. API-Schluessel) mit dem App-Key.
 * Bevorzugt libsodium (XChaCha20-Poly1305), faellt auf OpenSSL (AES-256-GCM) zurueck.
 */
final class Crypto
{
    public static function generateKey(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function randomId(int $bytes = 16): string
    {
        return bin2hex(random_bytes($bytes));
    }

    public static function randomToken(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    private static function keyMaterial(): string
    {
        $key = defined('WIT_APP_KEY') ? (string)WIT_APP_KEY : '';
        if ($key === '') {
            throw new \RuntimeException('Kein App-Key konfiguriert.');
        }
        return hash('sha256', 'wit:' . $key, true);
    }

    public static function encrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        $key = self::keyMaterial();
        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = sodium_crypto_secretbox($plain, $nonce, $key);
            return 'sb1:' . base64_encode($nonce . $cipher);
        }
        if (function_exists('openssl_encrypt')) {
            $iv = random_bytes(12);
            $tag = '';
            $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            if ($cipher === false) {
                throw new \RuntimeException('Verschluesselung fehlgeschlagen.');
            }
            return 'og1:' . base64_encode($iv . $tag . $cipher);
        }
        throw new \RuntimeException('Keine Verschluesselungserweiterung verfuegbar (sodium oder openssl erforderlich).');
    }

    public static function decrypt(string $payload): string
    {
        if ($payload === '') {
            return '';
        }
        $key = self::keyMaterial();
        if (str_starts_with($payload, 'sb1:')) {
            $raw = base64_decode(substr($payload, 4), true);
            if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
                return '';
            }
            $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
            return $plain === false ? '' : $plain;
        }
        if (str_starts_with($payload, 'og1:')) {
            $raw = base64_decode(substr($payload, 4), true);
            if ($raw === false || strlen($raw) <= 28) {
                return '';
            }
            $iv = substr($raw, 0, 12);
            $tag = substr($raw, 12, 16);
            $cipher = substr($raw, 28);
            $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            return $plain === false ? '' : $plain;
        }
        return '';
    }

    /** Zeitkonstanter Vergleich. */
    public static function equals(string $a, string $b): bool
    {
        return hash_equals($a, $b);
    }
}
