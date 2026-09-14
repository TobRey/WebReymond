<?php
declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Wandelt PHP-Fehler in Exceptions und faengt alles zentral ab.
 * Im Produktivbetrieb werden niemals technische Details ausgegeben.
 */
final class ErrorHandler
{
    private static bool $debug = false;

    public static function register(bool $debug): void
    {
        self::$debug = $debug;
        error_reporting(E_ALL);
        ini_set('display_errors', $debug ? '1' : '0');
        ini_set('log_errors', '1');

        // Nicht kritische Meldungen werden protokolliert, aber nicht in Ausnahmen verwandelt.
        // So bringt eine Deprecation-Meldung des Hosters die Seite nicht zum Absturz.
        $soft = E_DEPRECATED | E_USER_DEPRECATED | E_NOTICE | E_USER_NOTICE | E_WARNING | E_USER_WARNING;

        set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0) use ($soft, $debug): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            if (!$debug && ($severity & $soft)) {
                Logger::warning($message, ['file' => $file, 'line' => $line, 'severity' => $severity]);
                return true;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler([self::class, 'handle']);

        register_shutdown_function(static function (): void {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                Logger::error('Fataler Fehler: ' . $error['message'], [
                    'file' => $error['file'],
                    'line' => $error['line'],
                ]);
                if (!headers_sent()) {
                    http_response_code(500);
                    header('Content-Type: text/html; charset=utf-8');
                }
                echo self::fatalPage();
            }
        });
    }

    public static function handle(Throwable $e): void
    {
        Logger::error($e->getMessage(), [
            'type'  => $e::class,
            'file'  => $e->getFile(),
            'line'  => $e->getLine(),
            'trace' => self::$debug ? $e->getTraceAsString() : null,
        ]);

        $wantsJson = str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || str_starts_with((string)($_SERVER['REQUEST_URI'] ?? ''), (defined('WIT_BASE_PATH') ? WIT_BASE_PATH : '') . '/api/');

        $status = $e instanceof HttpException ? $e->getStatusCode() : 500;
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: ' . ($wantsJson ? 'application/json' : 'text/html') . '; charset=utf-8');
        }

        $public = $e instanceof HttpException
            ? $e->getMessage()
            : (self::$debug ? $e->getMessage() : 'Unerwarteter Serverfehler.');

        if ($wantsJson) {
            echo json_encode(['ok' => false, 'error' => $public, 'status' => $status], JSON_UNESCAPED_UNICODE);
            return;
        }
        echo self::errorPage($status, $public, self::$debug ? $e->getTraceAsString() : null);
    }

    private static function fatalPage(): string
    {
        return self::errorPage(500, 'Die Ermittlungsstation ist abgestuerzt. Bitte Seite neu laden.', null);
    }

    public static function errorPage(int $status, string $message, ?string $trace): string
    {
        $safeMessage = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeTrace = $trace !== null ? '<pre>' . htmlspecialchars($trace, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>' : '';
        $base = defined('WIT_BASE_PATH') ? WIT_BASE_PATH : '';
        return <<<HTML
<!doctype html>
<html lang="de"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Fehler {$status} &middot; WHERE IS TOBY?</title>
<style>
body{margin:0;min-height:100vh;display:grid;place-items:center;background:#0b0d10;color:#d7dae0;
font-family:ui-monospace,"SFMono-Regular",Menlo,Consolas,monospace}
.box{max-width:620px;padding:2.5rem;border:1px solid #23272e;background:#101317;box-shadow:0 30px 80px rgba(0,0,0,.7)}
h1{font-size:1.1rem;letter-spacing:.32em;text-transform:uppercase;color:#8a939f;margin:0 0 1.2rem}
p{line-height:1.6}
.code{font-size:4rem;color:#7a1f24;margin:0;line-height:1}
a{color:#5b8db8}
pre{white-space:pre-wrap;font-size:.72rem;color:#6b7280;max-height:240px;overflow:auto}
</style></head>
<body><div class="box"><p class="code">{$status}</p><h1>Zugriff gestoert</h1>
<p>{$safeMessage}</p>{$safeTrace}
<p><a href="{$base}/">Zurueck zur Ermittlungsstation</a></p></div></body></html>
HTML;
    }
}
