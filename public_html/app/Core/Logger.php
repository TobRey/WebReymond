<?php

declare(strict_types=1);

namespace WebAtze\Core;

use Throwable;

/**
 * Schreibt Meldungen nach storage/logs/.
 *
 * Zwei Grundsätze:
 *   1. Auf dem Server sieht der Besucher niemals eine echte Fehlermeldung,
 *      sondern nur eine Kennnummer, die er dir nennen kann.
 *   2. Passwörter und Schlüssel werden vor dem Schreiben unkenntlich gemacht.
 */
final class Logger
{
    private const REDACT_KEYS = [
        'password', 'passwort', 'pass', 'secret', 'token', 'api_key', 'apikey',
        'authorization', 'x-api-key', 'crypto_key', 'app_key', 'ftp_password',
        'admin_password', 'csrf',
    ];

    public static function register(): void
    {
        set_exception_handler(static function (Throwable $e): void {
            $id = self::exception($e);
            self::renderFatal($id);
        });

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            self::write('warning', $message, ['file' => $file, 'line' => $line]);
            return true;
        });

        register_shutdown_function(static function (): void {
            $error = error_get_last();
            if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }
            $id = self::write('critical', $error['message'], [
                'file' => $error['file'],
                'line' => $error['line'],
            ]);
            if (!headers_sent()) {
                self::renderFatal($id);
            }
        });
    }

    public static function exception(Throwable $e): string
    {
        return self::write('error', $e->getMessage(), [
            'type' => $e::class,
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 12),
        ]);
    }

    public static function info(string $message, array $context = []): string
    {
        return self::write('info', $message, $context);
    }

    public static function warning(string $message, array $context = []): string
    {
        return self::write('warning', $message, $context);
    }

    public static function error(string $message, array $context = []): string
    {
        return self::write('error', $message, $context);
    }

    private static function write(string $level, string $message, array $context = []): string
    {
        $id = bin2hex(random_bytes(4));

        $line = json_encode([
            'time' => date('c'),
            'id' => $id,
            'level' => $level,
            'message' => self::redactText($message),
            'context' => self::redact($context),
            'url' => $_SERVER['REQUEST_URI'] ?? 'cli',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $dir = STORAGE_DIR . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($dir . '/app-' . date('Y-m-d') . '.log', $line . "\n", FILE_APPEND | LOCK_EX);

        return $id;
    }

    /** Verräterische Werte durch *** ersetzen. */
    private static function redact(array $data): array
    {
        $clean = [];
        foreach ($data as $key => $value) {
            $lower = strtolower((string) $key);
            $isSecret = false;
            foreach (self::REDACT_KEYS as $needle) {
                if (str_contains($lower, $needle)) {
                    $isSecret = true;
                    break;
                }
            }
            if ($isSecret) {
                $clean[$key] = '***';
            } elseif (is_array($value)) {
                $clean[$key] = self::redact($value);
            } elseif (is_string($value)) {
                $clean[$key] = self::redactText($value);
            } else {
                $clean[$key] = $value;
            }
        }
        return $clean;
    }

    /** Schlüssel, die im Klartext in einer Meldung stehen, unkenntlich machen. */
    private static function redactText(string $text): string
    {
        return preg_replace('/\b(sk-ant-[A-Za-z0-9_\-]{8,}|[A-Fa-f0-9]{48,})\b/', '***', $text) ?? $text;
    }

    private static function renderFatal(string $id): void
    {
        if (headers_sent()) {
            return;
        }
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        $safeId = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');
        echo <<<HTML
        <!doctype html><html lang="de"><head><meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>Etwas ist schiefgelaufen</title>
        <style>
          body{margin:0;display:grid;place-items:center;min-height:100dvh;
               font:16px/1.6 system-ui,sans-serif;background:#0A0A1F;color:#E8E8F5}
          .box{max-width:34rem;padding:2rem;text-align:center}
          code{background:#1a1a35;padding:.2rem .5rem;border-radius:.35rem;color:#17C8C8}
          a{color:#8B8BFF}
        </style></head><body><div class="box">
          <h1>Etwas ist schiefgelaufen</h1>
          <p>Der Fehler wurde protokolliert. Bitte versuche es gleich noch einmal.</p>
          <p>Kennnummer: <code>{$safeId}</code></p>
          <p><a href="/">Zur Startseite</a></p>
        </div></body></html>
        HTML;
    }
}
