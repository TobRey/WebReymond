<?php

declare(strict_types=1);

namespace SkyKingdoms\Core;

/** Passwörter hashen, prüfen und auf Mindestqualität kontrollieren. */
final class Password
{
    /** Häufigste Passwörter, die trotz Länge nicht erlaubt sind. */
    private const FORBIDDEN = [
        'passwort', 'password', 'passwort1', '1234567890', '12345678', 'qwertzuiop', 'qwertyuiop',
        'administrator', 'willkommen', 'sonnenschein', 'fussball', 'schatzi', 'geheim123',
        'iloveyou', 'letmein', 'monkey123', 'dragon123', 'skykingdoms', 'sky-kingdoms',
    ];

    public static function hash(string $plain): string
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;

        $hash = @password_hash($plain, $algo);
        if (!is_string($hash) || $hash === '') {
            $hash = password_hash($plain, PASSWORD_BCRYPT);
        }

        return (string) $hash;
    }

    public static function verify(string $plain, string $hash): bool
    {
        return $hash !== '' && password_verify($plain, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;

        return password_needs_rehash($hash, $algo);
    }

    /**
     * Qualitätsprüfung. Gibt eine Liste verständlicher Fehler zurück
     * (leer = Passwort in Ordnung).
     *
     * @return string[]
     */
    public static function problems(string $plain, string $username = '', string $email = ''): array
    {
        $min      = max(8, (int) App::config('password_min_length', 10));
        $problems = [];
        $length   = mb_strlen($plain);

        if ($length < $min) {
            $problems[] = 'Das Passwort muss mindestens ' . $min . ' Zeichen lang sein.';
        }
        if ($length > 200) {
            $problems[] = 'Das Passwort darf höchstens 200 Zeichen lang sein.';
        }

        $classes = 0;
        $classes += preg_match('/[a-zäöüß]/u', $plain) ? 1 : 0;
        $classes += preg_match('/[A-ZÄÖÜ]/u', $plain) ? 1 : 0;
        $classes += preg_match('/\d/', $plain) ? 1 : 0;
        $classes += preg_match('/[^\p{L}\p{N}]/u', $plain) ? 1 : 0;

        if ($classes < 3) {
            $problems[] = 'Bitte mische Gross- und Kleinbuchstaben, Ziffern oder Sonderzeichen (mindestens drei Arten).';
        }

        $lower = mb_strtolower($plain);
        foreach (self::FORBIDDEN as $bad) {
            if ($lower === $bad || str_contains($lower, $bad)) {
                $problems[] = 'Dieses Passwort ist zu bekannt. Bitte wähle ein anderes.';
                break;
            }
        }

        if ($username !== '' && mb_stripos($plain, $username) !== false) {
            $problems[] = 'Das Passwort darf den Benutzernamen nicht enthalten.';
        }
        if ($email !== '') {
            $local = explode('@', $email)[0];
            if (mb_strlen($local) >= 3 && mb_stripos($plain, $local) !== false) {
                $problems[] = 'Das Passwort darf die E-Mail-Adresse nicht enthalten.';
            }
        }
        if (preg_match('/^(.)\1+$/u', $plain)) {
            $problems[] = 'Ein Passwort aus nur einem Zeichen ist nicht erlaubt.';
        }

        return array_values(array_unique($problems));
    }

    /** Grobe Einschätzung 0–4 für die Anzeige im Formular. */
    public static function strength(string $plain): int
    {
        $score  = 0;
        $length = mb_strlen($plain);
        $score += $length >= 10 ? 1 : 0;
        $score += $length >= 14 ? 1 : 0;
        $score += preg_match('/\d/', $plain) && preg_match('/[a-zäöü]/u', $plain) && preg_match('/[A-ZÄÖÜ]/u', $plain) ? 1 : 0;
        $score += preg_match('/[^\p{L}\p{N}]/u', $plain) ? 1 : 0;

        return min(4, $score);
    }
}
