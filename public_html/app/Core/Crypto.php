<?php

declare(strict_types=1);

namespace WebAtze\Core;

use RuntimeException;
use SensitiveParameter;

/**
 * Verschlüsselung für gespeicherte Geheimnisse – die Passwörter im Tresor
 * und die FTP-Zugänge der Kunden.
 *
 * Zwei Verfahren, und das aus einem konkreten Grund: Auf geteiltem
 * Hosting ist libsodium nicht selbstverständlich. Es kam vor, dass die
 * Erweiterung in cPanel als eingeschaltet angezeigt wurde und PHP sie
 * trotzdem nicht kannte – dann stand der Tresor still, obwohl auf dem
 * Server alles Nötige vorhanden war.
 *
 *   v1  libsodium, XSalsa20-Poly1305 (sodium_crypto_secretbox)
 *   v2  OpenSSL, AES-256-GCM
 *
 * Beides ist Verschlüsselung mit Echtheitsnachweis auf demselben
 * Sicherheitsniveau; AES-256-GCM ist der Standard, auf dem auch HTTPS
 * ruht. Geschrieben wird mit dem, was da ist – libsodium zuerst, weil
 * bestehende Daten in v1 liegen. Gelesen wird beides, an der Vorsilbe
 * erkennbar. Ein Wechsel des Servers macht also nichts unlesbar,
 * solange eines der beiden vorhanden ist.
 *
 * Der Schlüssel steht in config.php unter 'crypto_key' und gilt für
 * beide Verfahren – 32 Bytes, als 64 Hex-Zeichen notiert. Wird er
 * getauscht, sind alte Werte unlesbar; das ist gewollt.
 */
final class Crypto
{
    /**
     * Die Länge des Schlüssels in Bytes.
     *
     * Absichtlich als Zahl und nicht als SODIUM_CRYPTO_SECRETBOX_KEYBYTES:
     * Fehlt die Erweiterung, gibt es die Konstante nicht, und ein Zugriff
     * darauf ist in PHP 8 kein Hinweis, sondern das Ende der Anfrage.
     * Genau daran ist die Tresorseite einmal gestorben. Beide Verfahren
     * brauchen 32 Bytes; der Wert ist Teil des Formats und ändert sich
     * nicht mehr.
     */
    private const KEY_BYTES = 32;

    /** Länge des Einmalwerts bei libsodium (v1) und bei AES-GCM (v2). */
    private const NONCE_V1 = 24;
    private const NONCE_V2 = 12;

    /** Länge des Echtheitsnachweises bei AES-GCM. */
    private const TAG_V2 = 16;

    // ------------------------------------------------------------------
    // Was der Server kann
    // ------------------------------------------------------------------

    public static function hasSodium(): bool
    {
        return function_exists('sodium_crypto_secretbox')
            && function_exists('sodium_crypto_secretbox_open');
    }

    public static function hasOpenssl(): bool
    {
        return function_exists('openssl_encrypt')
            && function_exists('openssl_decrypt')
            && function_exists('openssl_get_cipher_methods')
            && in_array('aes-256-gcm', openssl_get_cipher_methods(), true);
    }

    /** Womit neu verschlüsselt wird: 'libsodium', 'OpenSSL' oder ''. */
    public static function method(): string
    {
        if (self::hasSodium()) {
            return 'libsodium';
        }

        return self::hasOpenssl() ? 'OpenSSL (AES-256-GCM)' : '';
    }

    /**
     * Ist verschlüsseln überhaupt möglich?
     *
     * @return string leerer Text, wenn alles stimmt; sonst der Grund
     */
    public static function status(): string
    {
        if (!self::hasSodium() && !self::hasOpenssl()) {
            return 'Auf diesem Server steht weder «sodium» noch «openssl» mit AES-256-GCM '
                . 'zur Verfügung. Eines von beiden genügt; in cPanel unter '
                . '«Select PHP Version» → Extensions lassen sie sich einschalten.';
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
     * Was der Server tatsächlich mitbringt – für die Fehlersuche.
     *
     * Steht im Adminbereich, wenn etwas nicht stimmt. Eine Zeile, die
     * sich vorlesen lässt, erspart das Raten aus der Ferne.
     *
     * @return array<string, string>
     */
    public static function diagnosis(): array
    {
        $abgeschaltet = trim((string) @ini_get('disable_functions'));

        return [
            'PHP' => PHP_VERSION . ' (' . PHP_SAPI . ')',
            'sodium' => (extension_loaded('sodium') ? 'geladen' : 'nicht geladen')
                . ', sodium_crypto_secretbox() '
                . (function_exists('sodium_crypto_secretbox') ? 'vorhanden' : 'FEHLT'),
            'openssl' => (extension_loaded('openssl') ? 'geladen' : 'nicht geladen')
                . ', AES-256-GCM '
                . (self::hasOpenssl() ? 'vorhanden' : 'FEHLT'),
            'Verfahren' => self::method() !== '' ? self::method() : 'keines verfügbar',
            'disable_functions' => $abgeschaltet === '' ? '(leer)' : $abgeschaltet,
            'php.ini' => (string) (php_ini_loaded_file() ?: 'unbekannt'),
        ];
    }

    /** Ein frischer Schlüssel, wie ihn app/config.php erwartet. */
    public static function newKey(): string
    {
        return bin2hex(random_bytes(self::KEY_BYTES));
    }

    // ------------------------------------------------------------------
    // Verschlüsseln und zurückholen
    // ------------------------------------------------------------------

    public static function encrypt(#[SensitiveParameter] string $plaintext): string
    {
        $key = self::key();

        if (self::hasSodium()) {
            $nonce = random_bytes(self::NONCE_V1);
            $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);
            self::wipe($key);

            return 'v1.' . base64_encode($nonce . $cipher);
        }

        $iv = random_bytes(self::NONCE_V2);
        $tag = '';
        $cipher = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_V2
        );
        self::wipe($key);

        if ($cipher === false) {
            throw new RuntimeException('Das Verschlüsseln ist fehlgeschlagen.');
        }

        return 'v2.' . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $payload): ?string
    {
        // Ohne brauchbaren Schlüssel gibt es nichts zurückzuholen. Das ist
        // genau die Antwort, die die Aufrufer erwarten – eine Ausnahme
        // wäre hier eine Fehlerseite statt einer Meldung.
        if (!self::isReady()) {
            return null;
        }

        if (str_starts_with($payload, 'v1.')) {
            return self::openV1(substr($payload, 3));
        }

        if (str_starts_with($payload, 'v2.')) {
            return self::openV2(substr($payload, 3));
        }

        return null;
    }

    /** Liegt dieser Wert in einem Verfahren, das der Server nicht kann? */
    public static function needsMissingMethod(string $payload): bool
    {
        return (str_starts_with($payload, 'v1.') && !self::hasSodium())
            || (str_starts_with($payload, 'v2.') && !self::hasOpenssl());
    }

    private static function openV1(string $kodiert): ?string
    {
        if (!self::hasSodium()) {
            return null;
        }

        $raw = base64_decode($kodiert, true);

        if ($raw === false || strlen($raw) <= self::NONCE_V1) {
            return null;
        }

        $key = self::key();
        $plain = sodium_crypto_secretbox_open(
            substr($raw, self::NONCE_V1),
            substr($raw, 0, self::NONCE_V1),
            $key
        );
        self::wipe($key);

        return $plain === false ? null : $plain;
    }

    private static function openV2(string $kodiert): ?string
    {
        if (!self::hasOpenssl()) {
            return null;
        }

        $raw = base64_decode($kodiert, true);

        if ($raw === false || strlen($raw) <= self::NONCE_V2 + self::TAG_V2) {
            return null;
        }

        $key = self::key();
        $plain = openssl_decrypt(
            substr($raw, self::NONCE_V2 + self::TAG_V2),
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            substr($raw, 0, self::NONCE_V2),
            substr($raw, self::NONCE_V2, self::TAG_V2)
        );
        self::wipe($key);

        return $plain === false ? null : $plain;
    }

    /**
     * Ein Geheimnis aus dem Speicher nehmen.
     *
     * sodium_memzero() tut das gründlich, gibt es aber nicht überall.
     * Ohne die Erweiterung wird überschrieben – nicht ganz so
     * verlässlich, aber deutlich besser als die Zeichenkette einfach
     * liegen zu lassen.
     *
     * Die Übergabe ist absichtlich ohne Typangabe: sodium_memzero()
     * setzt die Variable auf null, nicht auf einen leeren Text. Ein
     * «string &$x» wäre damit ein Versprechen, das die Erweiterung
     * gleich wieder bricht. Nach dem Aufruf steht in der Variablen in
     * jedem Fall nichts Brauchbares mehr.
     */
    public static function wipe(#[SensitiveParameter] mixed &$geheimnis): void
    {
        if (!is_string($geheimnis)) {
            $geheimnis = null;

            return;
        }

        if (function_exists('sodium_memzero')) {
            sodium_memzero($geheimnis);

            return;
        }

        $geheimnis = str_repeat("\0", strlen($geheimnis));
        $geheimnis = null;
    }

    // ------------------------------------------------------------------
    // Unterschriften und Passwörter
    // ------------------------------------------------------------------

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
