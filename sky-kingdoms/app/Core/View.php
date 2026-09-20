<?php

declare(strict_types=1);

namespace SkyKingdoms\Core;

/**
 * Sehr schlanke Vorlagen-Engine: reine PHP-Dateien unter app/Views.
 * Ausgabe von Benutzerdaten immer über e().
 *
 * Die internen Variablen heissen absichtlich $skTemplate/$skData. extract()
 * überspringt mit EXTR_SKIP bereits vorhandene Namen – hiesse der Parameter
 * schlicht $data, käme eine Ansicht mit einer Variablen namens „data" nie an
 * ihre Werte.
 */
final class View
{
    /** Vorlage rendern und zurückgeben. */
    public static function render(string $skTemplate, array $skData = []): string
    {
        $skFile = SK_ROOT . '/app/Views/' . str_replace(['..', '\\'], '', $skTemplate) . '.php';
        if (!is_file($skFile)) {
            throw new \RuntimeException('Vorlage nicht gefunden: ' . $skTemplate);
        }

        extract($skData, EXTR_SKIP);
        unset($skData, $skTemplate);

        ob_start();
        require $skFile;

        return (string) ob_get_clean();
    }

    /** Vorlage innerhalb eines Layouts rendern und ausgeben. */
    public static function page(string $skTemplate, array $skData = [], string $skLayout = 'partials/layout'): never
    {
        $skData['content'] = self::render($skTemplate, $skData);

        echo self::render($skLayout, $skData);
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
