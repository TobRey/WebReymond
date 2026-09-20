<?php

declare(strict_types=1);

namespace SkyKingdoms\Core;

/** Sicherheitskopfzeilen und kleine Schutzhelfer. */
final class Security
{
    private static ?string $nonce = null;

    /** Einmaliger Wert, mit dem das Inline-Startskript erlaubt wird. */
    public static function nonce(): string
    {
        if (self::$nonce === null) {
            self::$nonce = base64_encode(random_bytes(16));
        }

        return self::$nonce;
    }

    /** Schutzkopfzeilen setzen. $csp=false für Seiten ohne eigenes Skript. */
    public static function headers(bool $csp = true): void
    {
        if (headers_sent()) {
            return;
        }

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: same-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');
        header('Cross-Origin-Opener-Policy: same-origin');

        if (Session::isSecure()) {
            header('Strict-Transport-Security: max-age=15552000');
        }

        if ($csp) {
            $nonce = self::nonce();
            header(
                "Content-Security-Policy: default-src 'self'; "
                . "script-src 'self' 'nonce-{$nonce}'; "
                . "style-src 'self' 'unsafe-inline'; "
                . "img-src 'self' data: blob:; "
                . "font-src 'self'; "
                . "connect-src 'self'; "
                . "media-src 'self' data:; "
                . "object-src 'none'; "
                . "base-uri 'self'; "
                . "form-action 'self'; "
                . "frame-ancestors 'self'"
            );
        }
    }

    /** Prüft, ob die Anfrage per POST kam. */
    public static function isPost(): bool
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
    }

    /** Nur POST zulassen. */
    public static function requirePost(): void
    {
        if (self::isPost()) {
            return;
        }
        if (App::mode() === App::MODE_JSON) {
            Response::fail('Diese Aktion benötigt POST.', 405);
        }
        Response::redirect();
    }

    /**
     * Zeichenkette auf sichere Länge und Zeichen bringen. Steuerzeichen und
     * unsichtbare Sonderzeichen werden entfernt (Schutz vor gefälschten Namen).
     */
    public static function clean(mixed $value, int $maxLength = 255): string
    {
        $value = is_scalar($value) ? (string) $value : '';
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{200B}-\x{200F}\x{2028}\x{2029}\x{FEFF}]/u', '', $value) ?? '';
        $value = trim($value);

        return mb_substr($value, 0, $maxLength);
    }
}
