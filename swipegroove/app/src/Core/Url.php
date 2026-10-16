<?php
declare(strict_types=1);

namespace SwipeGroove\Core;

/**
 * URL-Erzeugung relativ zur konfigurierten öffentlichen Basis-URL (inkl. Unterordner).
 */
final class Url
{
    private string $base;      // https://example.com/sub  (ohne abschliessenden Slash)
    private string $basePath;  // /sub  oder ''

    public function __construct(string $baseUrl)
    {
        $this->base = rtrim($baseUrl, '/');
        $path = (string) (parse_url($this->base, PHP_URL_PATH) ?? '');
        $this->basePath = rtrim($path, '/');
    }

    public function base(): string
    {
        return $this->base;
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    /** Absolute URL, z. B. to('/api/me') => https://example.com/sub/api/me */
    public function to(string $path, array $query = []): string
    {
        $path = '/' . ltrim($path, '/');
        $url = $this->base . $path;
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }
        return $url;
    }

    /** Pfad relativ zur Domain, z. B. path('/assets/app.js') => /sub/assets/app.js */
    public function path(string $path): string
    {
        return $this->basePath . '/' . ltrim($path, '/');
    }

    /**
     * Ermittelt die Basis-URL aus der aktuellen Anfrage (nur für den Installer, bevor eine Config existiert).
     */
    public static function detectFromRequest(string $root): string
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            || (($_SERVER['SERVER_PORT'] ?? '') === '443');
        $scheme = $https ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
        $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', $host) ?? 'localhost';

        // Unterordner aus SCRIPT_NAME ableiten (…/sub/index.php => /sub)
        $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
        $dir = str_replace('\\', '/', dirname($script));
        $dir = $dir === '/' || $dir === '.' ? '' : rtrim($dir, '/');
        return $scheme . '://' . $host . $dir;
    }
}
