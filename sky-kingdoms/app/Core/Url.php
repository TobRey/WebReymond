<?php

declare(strict_types=1);

namespace SkyKingdoms\Core;

/**
 * Zentraler URL-Helfer.
 *
 * Das Projekt darf in JEDEM Ordner liegen: direkt im Document-Root, in einem
 * Unterordner, hinter einer Domain oder Subdomain. Deshalb wird der Basispfad
 * zur Laufzeit aus SCRIPT_NAME abgeleitet und es werden ausschliesslich
 * wurzel-relative Pfade erzeugt (z. B. "/spiel/api/"). Absolute Adressen mit
 * Domain entstehen nur dort, wo sie zwingend nötig sind (E-Mail, Manifest).
 *
 * SK_ENTRY_DEPTH gibt an, wie viele Ordner der aufrufende Einstiegspunkt vom
 * Projektordner entfernt liegt: index.php = 0, api/index.php = 1.
 */
final class Url
{
    private static ?string $base = null;
    private static ?string $assetVersion = null;

    /** Basispfad ohne abschliessenden Schrägstrich. Im Document-Root: "". */
    public static function base(): string
    {
        if (self::$base !== null) {
            return self::$base;
        }

        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '/index.php');
        $script = str_replace('\\', '/', $script);
        $dir    = self::dirname($script);

        $depth = defined('SK_ENTRY_DEPTH') ? (int) SK_ENTRY_DEPTH : 0;
        for ($i = 0; $i < $depth; $i++) {
            $dir = self::dirname($dir);
        }

        self::$base = $dir === '/' ? '' : rtrim($dir, '/');

        return self::$base;
    }

    /** Für Tests und den Installer: Basispfad erzwingen bzw. zurücksetzen. */
    public static function setBase(?string $base): void
    {
        self::$base = $base === null ? null : ($base === '/' ? '' : rtrim($base, '/'));
    }

    /** Projektinterner Pfad, z. B. Url::to('api/?a=state'). */
    public static function to(string $path = ''): string
    {
        $path = ltrim($path, '/');
        $base = self::base();

        if ($path === '') {
            return $base === '' ? '/' : $base . '/';
        }

        return $base . '/' . $path;
    }

    /** Pfad in assets/ mit Cache-Kennung, damit Updates sofort ankommen. */
    public static function asset(string $path): string
    {
        $path = ltrim($path, '/');

        return self::to('assets/' . $path) . '?v=' . self::assetVersion();
    }

    /** Vollständige Adresse inklusive Schema und Domain (nur für E-Mail/Manifest). */
    public static function absolute(string $path = ''): string
    {
        $configured = (string) App::config('app_url', '');
        if ($configured !== '') {
            return rtrim($configured, '/') . '/' . ltrim(self::stripBase(self::to($path)), '/');
        }

        return self::detectOrigin() . self::to($path);
    }

    /** Erkanntes Schema und Host der laufenden Anfrage, ohne abschliessenden Schrägstrich. */
    public static function detectOrigin(): string
    {
        $https = false;
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            $https = true;
        } elseif (($_SERVER['SERVER_PORT'] ?? '') === '443') {
            $https = true;
        } elseif (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
            $https = true;
        }

        $host = (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');
        // Nur erlaubte Zeichen: verhindert Host-Header-Injection in Links und Mails.
        if (!preg_match('/^[A-Za-z0-9.\-]+(:\d{1,5})?$/', $host)) {
            $host = 'localhost';
        }

        return ($https ? 'https://' : 'http://') . $host;
    }

    /** Die erkannte Basisadresse (Schema + Host + Unterordner) für die Installation. */
    public static function detectAppUrl(): string
    {
        $base = self::base();

        return self::detectOrigin() . ($base === '' ? '' : $base);
    }

    /** Aktueller Pfad der Anfrage ohne Query-String. */
    public static function currentPath(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $pos = strpos($uri, '?');

        return $pos === false ? $uri : substr($uri, 0, $pos);
    }

    /** Kennung für Asset-Caching: Projektversion plus Installationszeitpunkt. */
    public static function assetVersion(): string
    {
        if (self::$assetVersion === null) {
            $stamp = (string) App::config('asset_version', '');
            self::$assetVersion = $stamp !== '' ? $stamp : (defined('SK_VERSION') ? SK_VERSION : '1');
        }

        return self::$assetVersion;
    }

    private static function stripBase(string $path): string
    {
        $base = self::base();
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }

        return $path;
    }

    /** dirname(), das immer "/" oder einen Pfad ohne Schrägstrich am Ende liefert. */
    private static function dirname(string $path): string
    {
        $dir = str_replace('\\', '/', dirname($path));

        return $dir === '.' || $dir === '' ? '/' : $dir;
    }
}
