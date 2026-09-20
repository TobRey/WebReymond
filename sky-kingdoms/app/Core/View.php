<?php

declare(strict_types=1);

namespace SkyKingdoms\Core;

/**
 * Sehr schlanke Vorlagen-Engine: reine PHP-Dateien unter app/Views.
 * Ausgabe von Benutzerdaten immer über e().
 */
final class View
{
    /** Vorlage rendern und zurückgeben. */
    public static function render(string $template, array $data = []): string
    {
        $file = SK_ROOT . '/app/Views/' . str_replace(['..', '\\'], '', $template) . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException('Vorlage nicht gefunden: ' . $template);
        }

        extract($data, EXTR_SKIP);
        ob_start();
        require $file;

        return (string) ob_get_clean();
    }

    /** Vorlage innerhalb eines Layouts rendern und ausgeben. */
    public static function page(string $template, array $data = [], string $layout = 'partials/layout'): never
    {
        $content = self::render($template, $data);
        $data['content'] = $content;

        echo self::render($layout, $data);
        exit;
    }

    /** Minimalseite ohne Layout (Fehlerseiten, Wartung). */
    public static function renderSimple(string $title, string $message, string $linkText = '', string $linkUrl = ''): string
    {
        $link = $linkText !== ''
            ? '<p><a href="' . e($linkUrl) . '">' . e($linkText) . '</a></p>'
            : '';

        return '<!doctype html><html lang="de"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
            . '<title>' . e($title) . ' – ' . e(App::config('name', 'Sky Kingdoms')) . '</title>'
            . '<link rel="stylesheet" href="' . e(Url::asset('css/app.css')) . '">'
            . '</head><body class="sk-simple"><main class="sk-simple__card">'
            . '<h1>' . e($title) . '</h1><p>' . e($message) . '</p>' . $link
            . '</main></body></html>';
    }
}
