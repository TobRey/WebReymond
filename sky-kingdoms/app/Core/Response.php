<?php

declare(strict_types=1);

namespace SkyKingdoms\Core;

/** Antworten: JSON, Weiterleitung, Fehlerseite. */
final class Response
{
    /** JSON ausgeben und beenden. Antworten bleiben bewusst klein. */
    public static function json(array $payload, int $status = 200): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
        }

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function ok(array $payload = []): never
    {
        self::json(['ok' => true] + $payload);
    }

    public static function fail(string $message, int $status = 400, array $extra = []): never
    {
        self::json(['ok' => false, 'error' => $message] + $extra, $status);
    }

    /** Weiterleitung innerhalb des Projekts (immer über den URL-Helfer). */
    public static function redirect(string $path = '', int $status = 302): never
    {
        $target = str_starts_with($path, 'http') ? Url::to('') : Url::to($path);
        if (!headers_sent()) {
            header('Location: ' . $target, true, $status);
        }
        exit;
    }

    public static function notFound(string $message = 'Seite nicht gefunden.'): never
    {
        http_response_code(404);
        if (App::mode() === App::MODE_JSON) {
            self::json(['ok' => false, 'error' => $message], 404);
        }
        echo View::renderSimple('Nicht gefunden', $message);
        exit;
    }

    public static function forbidden(string $message = 'Kein Zugriff.'): never
    {
        http_response_code(403);
        if (App::mode() === App::MODE_JSON) {
            self::json(['ok' => false, 'error' => $message], 403);
        }
        echo View::renderSimple('Kein Zugriff', $message);
        exit;
    }
}
