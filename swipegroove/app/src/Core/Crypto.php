<?php
declare(strict_types=1);

namespace SwipeGroove\Core;

/**
 * Symmetrische Verschlüsselung sensibler Werte (Spotify-Tokens) mit libsodium.
 */
final class Crypto
{
    private string $key;

    public function __construct(string $base64Key)
    {
        $key = base64_decode($base64Key, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('Ungültiger Anwendungsschlüssel (app.key).');
        }
        $this->key = $key;
    }

    public static function generateKey(): string
    {
        return base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plain, $nonce, $this->key);
        return 'v1:' . base64_encode($nonce . $cipher);
    }

    public function decrypt(string $payload): ?string
    {
        if (!str_starts_with($payload, 'v1:')) {
            return null;
        }
        $raw = base64_decode(substr($payload, 3), true);
        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + 1) {
            return null;
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);
        return $plain === false ? null : $plain;
    }

    public static function randomToken(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    public static function hash(string $value): string
    {
        return hash('sha256', $value);
    }

    /** Maskiert ein Geheimnis für Anzeige im Adminbereich (nie vollständig). */
    public static function mask(?string $secret): string
    {
        if ($secret === null || $secret === '') {
            return '';
        }
        $len = strlen($secret);
        if ($len <= 8) {
            return str_repeat('•', $len);
        }
        return substr($secret, 0, 4) . str_repeat('•', min(12, $len - 8)) . substr($secret, -4);
    }
}
