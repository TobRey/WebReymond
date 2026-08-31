<?php

declare(strict_types=1);

namespace WebAtze\Core;

/**
 * Zweiter Faktor nach dem Zeitcode-Verfahren (TOTP, RFC 6238).
 *
 * Warum selbst gebaut: Das Verfahren ist zwanzig Zeilen Rechnung über
 * HMAC-SHA1. Eine Bibliothek dafür einzubinden hiesse, für eine
 * Handvoll Zeilen ein fremdes Paket auf dem Server zu haben – und
 * Composer läuft auf diesem Hosting ohnehin nicht.
 *
 * Es passt zu jeder üblichen App (Google Authenticator, Aegis, 1Password,
 * Bitwarden). Der Schlüssel wird einmal als QR-Code gezeigt und danach
 * nie wieder.
 *
 * Gegen die zwei bekannten Schwächen ist vorgesorgt:
 *   - Ein abgefangener Code lässt sich nicht ein zweites Mal einlösen
 *     (jeder benutzte Zeitschritt wird vermerkt).
 *   - Codes lassen sich nicht durchprobieren (Sperre nach Fehlversuchen,
 *     dieselbe wie beim Passwort).
 */
final class Totp
{
    private const PERIOD = 30;
    private const DIGITS = 6;

    /** Ein Zeitschritt Toleranz nach vorn und hinten – Uhren gehen nach. */
    private const WINDOW = 1;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Einen neuen Schlüssel erzeugen (160 Bit, wie empfohlen). */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /**
     * Die Adresse für den QR-Code.
     *
     * Der Schlüssel steckt darin – die Adresse gehört deshalb nie in ein
     * Protokoll und nie in eine E-Mail.
     */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            rawurlencode($issuer),
            rawurlencode($account),
            $secret,
            rawurlencode($issuer),
            self::DIGITS,
            self::PERIOD
        );
    }

    /**
     * Stimmt der Code?
     *
     * @param int|null $usedStep Der zuletzt eingelöste Zeitschritt. Ein
     *                           Code, der schon einmal galt, gilt nicht
     *                           noch einmal.
     * @return int|null Der eingelöste Zeitschritt, oder null bei falsch
     */
    public static function verify(string $secret, string $code, ?int $usedStep = null): ?int
    {
        $code = preg_replace('/\D/', '', $code) ?? '';

        if (strlen($code) !== self::DIGITS || $secret === '') {
            return null;
        }

        $now = (int) floor(time() / self::PERIOD);

        for ($offset = -self::WINDOW; $offset <= self::WINDOW; $offset++) {
            $step = $now + $offset;

            if ($usedStep !== null && $step <= $usedStep) {
                continue;
            }

            if (Security::equals(self::codeFor($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    /** Der Code für einen bestimmten Zeitschritt. */
    public static function codeFor(string $secret, int $step): string
    {
        $key = self::base32Decode($secret);

        if ($key === '') {
            return '';
        }

        $hash = hash_hmac('sha1', pack('N*', 0, $step), $key, true);
        $offset = ord($hash[19]) & 0x0f;

        $value = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);

        return str_pad(
            (string) ($value % (10 ** self::DIGITS)),
            self::DIGITS,
            '0',
            STR_PAD_LEFT
        );
    }

    /**
     * Ersatzcodes für den Fall, dass das Telefon weg ist.
     *
     * Ohne sie wäre ein verlorenes Telefon gleichbedeutend mit einem
     * verlorenen Zugang. Gespeichert werden nur die Prüfsummen.
     *
     * @return array{plain: string[], hashed: string[]}
     */
    public static function recoveryCodes(int $count = 8): array
    {
        $plain = [];
        $hashed = [];

        for ($i = 0; $i < $count; $i++) {
            // Gruppen zu vier Zeichen – so lässt es sich abschreiben.
            $code = strtoupper(bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(2)));
            $plain[] = $code;
            $hashed[] = hash('sha256', $code);
        }

        return ['plain' => $plain, 'hashed' => $hashed];
    }

    /**
     * Einen Ersatzcode einlösen.
     *
     * @param string[] $hashed
     * @return string[]|null Die verbleibenden Codes, oder null bei falsch
     */
    public static function useRecoveryCode(string $code, array $hashed): ?array
    {
        $needle = hash('sha256', strtoupper(trim($code)));
        $rest = [];
        $found = false;

        foreach ($hashed as $known) {
            // Jeder wird geprüft, auch nach einem Treffer: Sonst liesse
            // sich an der Dauer ablesen, an welcher Stelle er stand.
            if (!$found && Security::equals((string) $known, $needle)) {
                $found = true;
                continue;
            }
            $rest[] = $known;
        }

        return $found ? $rest : null;
    }

    // ------------------------------------------------------------------
    // Base32 – das Format, das die Apps erwarten
    // ------------------------------------------------------------------

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $out;
    }

    public static function base32Decode(string $value): string
    {
        $value = strtoupper(rtrim(trim($value), '='));
        $bits = '';

        foreach (str_split($value) as $char) {
            $index = strpos(self::ALPHABET, $char);
            if ($index === false) {
                return '';
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $out .= chr(bindec($chunk));
            }
        }

        return $out;
    }
}
