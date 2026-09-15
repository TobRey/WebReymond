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
        /* Zuverlaessigster Weg: Wo liegt der Programmordner relativ zum Dokumentenstamm?
           Das ist unabhaengig davon, ueber welchen Weg PHP aufgerufen wurde. */
        $root = defined('WIT_ROOT') ? WIT_ROOT : dirname(__DIR__, 2);
        $documentRoot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
        if ($documentRoot !== '') {
            $documentRoot = self::normalizePath(realpath($documentRoot) ?: $documentRoot);
            $application = self::normalizePath(realpath($root) ?: $root);
            if ($application === $documentRoot) {
                return '';
            }
            if ($documentRoot !== '' && str_starts_with($application . '/', $documentRoot . '/')) {
                return rtrim(substr($application, strlen($documentRoot)), '/');
            }
        }

        /* Rueckfall ueber SCRIPT_NAME - aber nur, wenn dort wirklich der Front-Controller
           steht. Manche Hoster tragen dort einen CGI-Wrapper ein (z. B. /cgi-sys/php.cgi);
           dessen Ordner waere als Basispfad falsch und wuerde jede Adresse ins Leere fuehren. */
        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        if (in_array(basename($script), ['index.php', 'install.php'], true)) {
            $dir = rtrim(dirname($script), '/');
            return $dir === '' || $dir === '.' || $dir === '/' ? '' : $dir;
        }

        return '';
    }

    /**
     * Liefert den Basispfad fuer die aktuelle Anfrage.
     *
     * Der gespeicherte Wert aus der Konfiguration hat Vorrang - aber nur, wenn die
     * aufgerufene Adresse tatsaechlich darunter liegt. Passt er nicht (falsch erkannt,
     * Umzug in einen anderen Ordner), wird die Erkennung verwendet. So repariert sich
     * eine falsche Angabe beim naechsten Aufruf selbst, statt ueberall 404 zu liefern.
     */
    public static function resolveBasePath(mixed $stored): string
    {
        $path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');

        foreach ([is_string($stored) ? $stored : '', self::detectBasePath(), self::basePathFromUri()] as $candidate) {
            $candidate = rtrim(str_replace('\\', '/', $candidate), '/');
            if ($candidate === '') {
                continue;
            }
            if ($path === $candidate || str_starts_with($path, $candidate . '/')) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * Ermittelt den Basispfad allein aus der aufgerufenen Adresse und dem eigenen
     * Ordnernamen - ohne DOCUMENT_ROOT und ohne SCRIPT_NAME.
     *
     * Liegt die Anwendung z. B. in ".../htdocs/spiel" und wird "/spiel/faelle"
     * aufgerufen, ist "/spiel" der gesuchte Pfad: die ersten Adressteile entsprechen
     * den letzten Ordnernamen. Gebraucht wird das bei Hostern, deren DOCUMENT_ROOT
     * nicht auf den tatsaechlich ausgelieferten Ordner zeigt.
     */
    private static function basePathFromUri(): string
    {
        $root = defined('WIT_ROOT') ? WIT_ROOT : dirname(__DIR__, 2);
        $uri = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');

        $fromUri = self::segments(rawurldecode($uri));
        $fromDirectory = self::segments(str_replace('\\', '/', $root));
        if ($fromUri === [] || $fromDirectory === []) {
            return '';
        }

        /* Laengste Uebereinstimmung gewinnt: so wird auch ".../apps/spiel" erkannt. */
        for ($length = min(count($fromUri), count($fromDirectory)); $length >= 1; $length--) {
            if (array_slice($fromUri, 0, $length) === array_slice($fromDirectory, -$length)) {
                return '/' . implode('/', array_slice($fromUri, 0, $length));
            }
        }

        return '';
    }

    /** @return string[] */
    private static function segments(string $path): array
    {
        return array_values(array_filter(explode('/', trim($path, '/')), static fn (string $s): bool => $s !== ''));
    }

    private static function normalizePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
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
