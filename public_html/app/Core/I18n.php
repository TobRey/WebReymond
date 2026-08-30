<?php

declare(strict_types=1);

namespace WebAtze\Core;

/**
 * Zweisprachigkeit: Deutsch (Standard) und Englisch.
 *
 * Adressen: '/' ist Deutsch, '/en/...' ist Englisch.
 * Kein Text steht fest im Code – alles kommt aus app/Lang/.
 */
final class I18n
{
    public const LOCALES = ['de', 'en'];

    private static string $locale = 'de';
    private static array $messages = [];
    private static array $fallback = [];

    public static function boot(Request $request): void
    {
        self::setLocale(self::detect($request));
    }

    public static function detect(Request $request): string
    {
        $path = trim($request->path(), '/');
        $first = explode('/', $path)[0] ?? '';

        if (in_array($first, self::LOCALES, true)) {
            return $first;
        }

        return (string) Config::get('default_locale', 'de');
    }

    public static function setLocale(string $locale): void
    {
        if (!in_array($locale, self::LOCALES, true)) {
            $locale = 'de';
        }
        self::$locale = $locale;
        self::$messages = self::loadFile($locale);
        self::$fallback = $locale === 'de' ? self::$messages : self::loadFile('de');
    }

    public static function locale(): string
    {
        return self::$locale;
    }

    public static function isDefault(): bool
    {
        return self::$locale === (string) Config::get('default_locale', 'de');
    }

    /**
     * Übersetzten Text holen.
     * Fehlt der Schlüssel in der gewählten Sprache, greift Deutsch;
     * fehlt er auch dort, erscheint der Schlüssel selbst – so fällt es auf.
     */
    public static function get(string $key, array $replace = []): string
    {
        $value = array_get(self::$messages, $key);
        if (!is_string($value)) {
            $value = array_get(self::$fallback, $key);
        }
        if (!is_string($value)) {
            return $key;
        }

        foreach ($replace as $search => $replacement) {
            $value = str_replace(':' . $search, (string) $replacement, $value);
        }
        return $value;
    }

    /** Liste holen, z.B. die FAQ-Einträge. */
    public static function list(string $key): array
    {
        $value = array_get(self::$messages, $key);
        if (!is_array($value)) {
            $value = array_get(self::$fallback, $key);
        }
        return is_array($value) ? $value : [];
    }

    /**
     * Adresse in der aktuellen Sprache bauen.
     * url('kontakt') -> '/kontakt' (de) bzw. '/en/contact' (en)
     */
    public static function url(string $routeKey = '', ?string $locale = null): string
    {
        $locale = $locale ?? self::$locale;
        $default = (string) Config::get('default_locale', 'de');

        $segment = $routeKey === '' ? '' : self::routeSegment($routeKey, $locale);
        $prefix = $locale === $default ? '' : '/' . $locale;

        $path = $prefix . ($segment === '' ? '' : '/' . $segment);
        return $path === '' ? '/' : $path;
    }

    /** Welche Adresse hat eine Seite in welcher Sprache? */
    public static function routeSegment(string $routeKey, string $locale): string
    {
        $map = self::routeMap();
        return $map[$routeKey][$locale] ?? $routeKey;
    }

    /** Aus einem Adressteil den zugehörigen Seitenschlüssel finden. */
    public static function routeKeyFromSegment(string $segment): ?string
    {
        foreach (self::routeMap() as $key => $variants) {
            if (in_array($segment, $variants, true)) {
                return $key;
            }
        }
        return null;
    }

    /** @return array<string,array<string,string>> */
    public static function routeMap(): array
    {
        return [
            'services' => ['de' => 'leistungen', 'en' => 'services'],
            'process' => ['de' => 'ablauf', 'en' => 'process'],
            'work' => ['de' => 'referenzen', 'en' => 'work'],
            'contact' => ['de' => 'kontakt', 'en' => 'contact'],
            'imprint' => ['de' => 'impressum', 'en' => 'imprint'],
            'privacy' => ['de' => 'datenschutz', 'en' => 'privacy'],
        ];
    }

    /** Für den Sprachumschalter: dieselbe Seite in der anderen Sprache. */
    public static function alternateUrl(string $currentPath, string $targetLocale): string
    {
        $trimmed = trim($currentPath, '/');
        $parts = $trimmed === '' ? [] : explode('/', $trimmed);

        if (isset($parts[0]) && in_array($parts[0], self::LOCALES, true)) {
            array_shift($parts);
        }

        if ($parts === []) {
            return self::url('', $targetLocale);
        }

        $key = self::routeKeyFromSegment($parts[0]);
        if ($key === null) {
            return self::url('', $targetLocale);
        }

        return self::url($key, $targetLocale);
    }

    private static function loadFile(string $locale): array
    {
        $file = APP_DIR . '/Lang/' . $locale . '.php';
        if (!is_file($file)) {
            return [];
        }
        $data = require $file;
        return is_array($data) ? $data : [];
    }
}
