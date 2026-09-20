<?php
/**
 * Kurze globale Hilfsfunktionen. Bewusst wenige – alles andere lebt in Klassen.
 */

declare(strict_types=1);

use SkyKingdoms\Core\App;
use SkyKingdoms\Core\Num;
use SkyKingdoms\Core\Url;

if (!function_exists('e')) {
    /** HTML-sicher ausgeben. Jede Ausgabe von Benutzerdaten läuft hierüber. */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('url')) {
    /** Pfad innerhalb des Projekts, unabhängig vom Installationsordner. */
    function url(string $path = ''): string
    {
        return Url::to($path);
    }
}

if (!function_exists('asset')) {
    /** Pfad zu einer Datei in assets/ inklusive Cache-Kennung. */
    function asset(string $path): string
    {
        return Url::asset($path);
    }
}

if (!function_exists('cfg')) {
    /** Konfigurationswert per Punktschreibweise, z. B. cfg('mail.transport'). */
    function cfg(string $key, mixed $default = null): mixed
    {
        return App::config($key, $default);
    }
}

if (!function_exists('balance')) {
    /** Balancewert per Punktschreibweise, z. B. balance('resources.wood.name'). */
    function balance(string $key = '', mixed $default = null): mixed
    {
        return App::balance($key, $default);
    }
}

if (!function_exists('nf')) {
    /** Zahl kompakt und deutsch formatieren: 12,4K – 3,2M – 1,1B. */
    function nf(float|int $value, int $decimals = 1): string
    {
        return Num::compact($value, $decimals);
    }
}
