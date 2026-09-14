<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Einfaches, rotierendes Datei-Logging (JSON-Lines).
 */
final class Logger
{
    private static string $dir = '';
    private const MAX_BYTES = 1048576; // 1 MB pro Datei

    public static function configure(string $dir): void
    {
        self::$dir = rtrim($dir, '/');
    }

    public static function debug(string $message, array $context = []): void
    {
        if (defined('WIT_DEBUG') && WIT_DEBUG) {
            self::write('debug', $message, $context);
        }
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    /** Sicherheitsrelevante Ereignisse landen zusaetzlich in security.log */
    public static function security(string $message, array $context = []): void
    {
        self::write('security', $message, $context, 'security.log');
    }

    private static function write(string $level, string $message, array $context, string $file = 'app.log'): void
    {
        if (self::$dir === '') {
            return;
        }
        if (!is_dir(self::$dir)) {
            @mkdir(self::$dir, 0750, true);
        }
        $path = self::$dir . '/' . $file;
        self::rotate($path);

        $entry = [
            'ts'      => gmdate('c'),
            'level'   => $level,
            'msg'     => mb_substr($message, 0, 800),
            'ip'      => Environment::isCli() ? 'cli' : Environment::clientIp(),
            'uid'     => $_SESSION['user_id'] ?? null,
            'context' => self::scrub($context),
        ];
        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($line === false) {
            return;
        }
        @file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /** Entfernt Geheimnisse aus Log-Kontexten. */
    private static function scrub(array $context): array
    {
        $out = [];
        foreach ($context as $key => $value) {
            $k = strtolower((string)$key);
            if (str_contains($k, 'key') || str_contains($k, 'secret') || str_contains($k, 'password') || str_contains($k, 'token')) {
                $out[$key] = '***';
                continue;
            }
            if (is_array($value)) {
                $out[$key] = self::scrub($value);
            } elseif (is_scalar($value) || $value === null) {
                $out[$key] = is_string($value) ? mb_substr($value, 0, 500) : $value;
            } else {
                $out[$key] = '[object]';
            }
        }
        return $out;
    }

    private static function rotate(string $path): void
    {
        if (is_file($path) && filesize($path) > self::MAX_BYTES) {
            @rename($path, $path . '.' . gmdate('Ymd_His') . '.old');
            // alte Rotationen aufraeumen (max. 5)
            $old = glob($path . '.*.old') ?: [];
            if (count($old) > 5) {
                sort($old);
                foreach (array_slice($old, 0, count($old) - 5) as $file) {
                    @unlink($file);
                }
            }
        }
    }

    /** @return array<int,array<string,mixed>> Letzte N Eintraege (neueste zuerst) */
    public static function tail(string $file = 'app.log', int $limit = 200): array
    {
        $path = self::$dir . '/' . basename($file);
        if (!is_file($path)) {
            return [];
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $lines = array_slice($lines, -$limit);
        $out = [];
        foreach (array_reverse($lines) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }
        return $out;
    }

    /** @return string[] */
    public static function files(): array
    {
        $files = glob(self::$dir . '/*.log') ?: [];
        return array_map('basename', $files);
    }

    public static function clear(string $file): bool
    {
        $path = self::$dir . '/' . basename($file);
        return is_file($path) ? @unlink($path) : false;
    }
}
