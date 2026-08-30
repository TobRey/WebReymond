<?php

declare(strict_types=1);

namespace WebAtze\Core;

use RuntimeException;
use Throwable;

/**
 * Rendert die Vorlagen aus app/Views.
 *
 * Vorlagen sind schlichte PHP-Dateien. Innerhalb einer Vorlage stehen alle
 * übergebenen Werte als Variablen bereit, dazu die Helfer e() und __t().
 *
 * Regel: In einer Vorlage wird Text nur über e() ausgegeben. Alles andere
 * gilt als Fehler.
 */
final class View
{
    private static array $shared = [];

    /** Werte, die jede Vorlage bekommt (Sprache, angemeldeter Benutzer, ...). */
    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    public static function shared(): array
    {
        return self::$shared;
    }

    /** Vorlage mit Rahmen (Kopf, Fuss) rendern. */
    public static function page(string $template, array $data = [], string $layout = 'layouts/site'): string
    {
        $content = self::partial($template, $data);
        return self::partial($layout, array_merge($data, ['content' => $content]));
    }

    /** Vorlage ohne Rahmen rendern. */
    public static function partial(string $template, array $data = []): string
    {
        $file = self::resolve($template);

        $variables = array_merge(self::$shared, $data);

        // extract() ist hier bewusst gewählt: die Schlüssel stammen
        // ausschliesslich aus dem eigenen Code, nie aus einer Eingabe.
        $render = static function (string $__file, array $__vars): string {
            extract($__vars, EXTR_SKIP);
            ob_start();
            try {
                include $__file;
            } catch (Throwable $e) {
                ob_end_clean();
                throw $e;
            }
            return (string) ob_get_clean();
        };

        return $render($file, $variables);
    }

    public static function exists(string $template): bool
    {
        return is_file(self::path($template));
    }

    private static function resolve(string $template): string
    {
        $file = self::path($template);
        if (!is_file($file)) {
            throw new RuntimeException('Vorlage nicht gefunden: ' . $template);
        }
        return $file;
    }

    private static function path(string $template): string
    {
        // Kein Ausbrechen aus dem Vorlagenordner
        $clean = preg_replace('#[^A-Za-z0-9_/\-]#', '', $template) ?? '';
        $clean = str_replace('..', '', $clean);
        return APP_DIR . '/Views/' . trim($clean, '/') . '.php';
    }
}
