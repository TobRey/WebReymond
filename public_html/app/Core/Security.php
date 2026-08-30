<?php

declare(strict_types=1);

namespace WebAtze\Core;

/**
 * Sicherheitsheader und die Inhaltsrichtlinie (CSP).
 *
 * Die CSP ist die wirksamste Bremse gegen eingeschleustes JavaScript:
 * Skripte laufen nur, wenn sie den Einmalwert dieser Anfrage tragen.
 */
final class Security
{
    private static ?string $nonce = null;

    /** Einmalwert dieser Anfrage – gehört an jedes <script>. */
    public static function nonce(): string
    {
        return self::$nonce ??= rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }

    /** @return array<string,string> */
    public static function baseHeaders(): array
    {
        return [
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Permissions-Policy' => 'geolocation=(), microphone=(), camera=(), payment=(), usb=(), browsing-topics=()',
            'Content-Security-Policy' => self::contentSecurityPolicy(),
        ];
    }

    /**
     * Was die Seite laden darf.
     *
     * 'unsafe-inline' bei style-src ist nötig, weil Farben und Layoutwerte
     * als style-Attribut gesetzt werden (Design-Tokens je Kunde). Bei
     * script-src wird bewusst darauf verzichtet – dort gilt der Einmalwert.
     */
    public static function contentSecurityPolicy(): string
    {
        $nonce = self::nonce();

        $directives = [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'self'",
            "form-action 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "media-src 'self'",
            "worker-src 'self' blob:",
            "frame-src 'self'",
            "manifest-src 'self'",
        ];

        return implode('; ', $directives);
    }

    /**
     * Zwei Zeichenketten zeitunabhängig vergleichen.
     * Verhindert, dass sich ein Geheimnis über die Antwortzeit erraten lässt.
     */
    public static function equals(string $known, string $given): bool
    {
        return hash_equals($known, $given);
    }
}
