<?php

/**
 * Grundgerüst des Bearbeitungsbereichs.
 *
 * Lädt die Klassen, stellt die wenigen Hilfen bereit, die die Vorlagen
 * brauchen, und setzt die Sicherheitskopfzeilen.
 *
 * Kein Composer, kein Rahmenwerk: Diese Dateien werden auf das Hosting
 * des Kunden kopiert und sollen dort ohne Kommandozeile laufen.
 */

declare(strict_types=1);

// Dieselbe Marke wie im Bausatz von WebAtze. Die mitgelieferten
// Vorlagen prüfen sie und brechen ab, wenn jemand sie direkt aufruft.
define('WEBATZE', true);
define('KIT_DIR', __DIR__);
define('ADMIN_DIR', dirname(__DIR__));
define('SITE_DIR', dirname(ADMIN_DIR));
define('DATA_DIR', SITE_DIR . '/data');

// --------------------------------------------------------------- Laden

spl_autoload_register(static function (string $class): void {
    // Die Vorlagen kommen unverändert von WebAtze und behalten deshalb
    // ihren Namensraum.
    $map = [
        'WebAtzeKit\\' => KIT_DIR . '/',
        'WebAtze\\Templates\\' => ADMIN_DIR . '/engine/Templates/',
    ];

    foreach ($map as $prefix => $dir) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
            return;
        }
    }
});

// ------------------------------------------------------------- Hilfen

if (!function_exists('e')) {
    /** Ausgabe absichern. Wird von den Vorlagen bei jedem Wert benutzt. */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('json_out')) {
    function json_out(mixed $data): string
    {
        return (string) json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP
        );
    }
}

if (!function_exists('str_slug')) {
    /** Aus einem Titel wird ein Dateiname: "Über uns" → "ueber-uns". */
    function str_slug(string $value, int $max = 80): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');

        $value = strtr($value, [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ]);

        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');

        return mb_substr($value === '' ? 'seite' : $value, 0, $max);
    }
}

if (!function_exists('ensure_dir')) {
    function ensure_dir(string $path): bool
    {
        return is_dir($path) || @mkdir($path, 0755, true) || is_dir($path);
    }
}

// ------------------------------------------------------- Kopfzeilen

/**
 * Dieselben Regeln wie auf der WebAtze-Seite.
 *
 * "same-origin" beim Verweisenden und nicht "no-referrer": Sonst schickt
 * der Browser bei jedem Formular "Origin: null", die Herkunftsprüfung
 * schlägt an, und nichts funktioniert mehr.
 */
function kit_headers(string $nonce): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header('Cache-Control: no-store, private');
    header(
        "Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'none'; "
        . "script-src 'self' 'nonce-" . $nonce . "'; style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data:; form-action 'self'; frame-ancestors 'self'"
    );
}
