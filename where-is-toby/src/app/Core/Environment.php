<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Erkennung der Laufzeitumgebung: Basispfad, HTTPS, PHP-Faehigkeiten.
 */
final class Environment
{
    /** Ermittelt den Unterordner, in dem die Anwendung liegt (z. B. "" oder "/spiel"). */
    public static function detectBasePath(): string
    {
        $script = (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php');
        $dir = str_replace('\\', '/', dirname($script));
        $dir = $dir === '/' || $dir === '.' ? '' : rtrim($dir, '/');
        return $dir;
    }

    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (($_SERVER['SERVER_PORT'] ?? '') === '443') {
            return true;
        }
        $proto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        return $proto === 'https';
    }

    public static function isCli(): bool
    {
        return PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
    }

    /** @return array<string,bool> */
    public static function extensions(): array
    {
        return [
            'json'     => extension_loaded('json'),
            'mbstring' => extension_loaded('mbstring'),
            'openssl'  => extension_loaded('openssl'),
            'sodium'   => extension_loaded('sodium'),
            'curl'     => extension_loaded('curl'),
            'gd'       => extension_loaded('gd'),
            'imagick'  => extension_loaded('imagick'),
            'fileinfo' => extension_loaded('fileinfo'),
            'zip'      => extension_loaded('zip'),
            'exif'     => extension_loaded('exif'),
            'session'  => extension_loaded('session'),
        ];
    }

    public static function clientIp(): string
    {
        $candidates = [];
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $candidates[] = (string)$_SERVER['HTTP_CF_CONNECTING_IP'];
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            foreach (explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']) as $part) {
                $candidates[] = trim($part);
            }
        }
        $candidates[] = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        foreach ($candidates as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
        return '0.0.0.0';
    }
}
