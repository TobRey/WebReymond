<?php

declare(strict_types=1);

namespace WebAtze\Templates;

/**
 * Der Rahmen um eine erzeugte Seite.
 *
 * Bewusst ohne jede Abhängigkeit: keine Datenbank, kein Protokoll, keine
 * Konfiguration – nur einfache Felder hinein, fertiges HTML heraus.
 *
 * Der Grund ist der Bearbeitungsbereich beim Kunden. Ändert dort jemand
 * einen Text, muss die Seite auf dem Server des Kunden neu entstehen –
 * und zwar Zeichen für Zeichen so, wie sie hier entstanden ist. Gäbe es
 * zwei Fassungen dieses Rahmens, liefen sie mit der Zeit auseinander:
 * Eine Verbesserung hier, ein vergessener Nachzug dort, und plötzlich
 * sieht die Seite nach dem ersten Kundenklick anders aus als zuvor.
 * Deshalb steht er genau einmal, hier.
 */
final class Page
{
    /** Wie die Sprachen im Umschalter heissen. */
    public const LANGUAGE_NAMES = [
        'de' => 'Deutsch',
        'en' => 'English',
        'fr' => 'Français',
        'it' => 'Italiano',
    ];

    /** Ein Bildpfad aus einem Sprachordner heraus zeigt eine Ebene höher. */
    private static function liftAssets(mixed $image): mixed
    {
        if (!is_array($image) || !isset($image['src'])) {
            return $image;
        }

        $src = (string) $image['src'];

        if (!preg_match('#^(https?:|/|\.\./)#i', $src)) {
            $image['src'] = '../' . $src;
        }

        return $image;
    }

    /**
     * Eine vollständige Seite bauen.
     *
     * @param array $site  brand, locale, theme, pages, logo, contact
     * @param array $page  title, path, meta_description, sections
     */
    public static function render(
        array $site,
        array $page,
        string $criticalCss = '',
        string $locale = ''
    ): string {
        $locales = $site['locales'] ?? [];
        $primary = (string) ($locales[0] ?? ($site['locale'] ?? 'de'));
        $locale = $locale !== '' ? $locale : $primary;

        $context = self::context($site, $page, $locale);

        $body = '';
        foreach ($page['sections'] ?? [] as $section) {
            if (!empty($section['hidden'])) {
                continue;
            }

            // In einer weiteren Sprache tritt die Übersetzung an die
            // Stelle des Originals. Fehlt sie, steht der Originaltext
            // da – besser als eine Lücke.
            if ($locale !== $primary) {
                $translated = $section['translations'][$locale] ?? null;
                if (is_array($translated) && $translated !== []) {
                    $section['content'] = $translated;
                }
            }

            $body .= Renderer::section($section, $context) . "\n";
        }

        return self::wrap($site, $page, $body, $criticalCss, $locale);
    }

    /**
     * Angaben, die jeder Abschnitt braucht: Navigation, Kontakt, Logo.
     */
    public static function context(array $site, array $currentPage, string $locale = ''): array
    {
        $contact = $site['contact'] ?? [];
        $currentPath = (string) ($currentPage['path'] ?? '/');

        $locales = $site['locales'] ?? [];
        $primary = (string) ($locales[0] ?? ($site['locale'] ?? 'de'));
        $locale = $locale !== '' ? $locale : $primary;

        $nav = [];
        $legal = [];

        foreach ($site['pages'] ?? [] as $page) {
            // Innerhalb einer Sprache bleibt man in ihrem Ordner.
            $file = self::fileNameFor((string) ($page['path'] ?? '/'), $locale, $primary);
            $file = $locale === $primary ? $file : basename($file);

            $title = (string) ($page['title'] ?? '');
            if ($locale !== $primary) {
                $title = (string) ($page['translations'][$locale]['title'] ?? $title);
            }

            $entry = [
                'label' => $title,
                'url' => $file,
                'current' => (string) ($page['path'] ?? '') === $currentPath,
            ];

            if (in_array((string) ($page['path'] ?? ''), ['/impressum', '/datenschutz', '/agb'], true)) {
                $legal[] = $entry;
            } elseif (!empty($page['in_navigation'])) {
                $nav[] = $entry;
            }
        }

        // Der Sprachumschalter: dieselbe Seite in den anderen Sprachen.
        $languages = [];
        foreach ($locales as $code) {
            $target = self::fileNameFor($currentPath, (string) $code, $primary);
            $languages[] = [
                'code' => (string) $code,
                'label' => self::LANGUAGE_NAMES[(string) $code] ?? strtoupper((string) $code),
                'url' => self::relativeLink($target, $locale, $primary),
                'current' => (string) $code === $locale,
            ];
        }

        return [
            'brand' => (string) ($site['brand'] ?? ''),
            'home' => $locale === $primary ? 'index.html' : 'index.html',
            'nav' => $nav,
            'legal' => $legal,
            'logo' => $locale === $primary
                ? ($site['logo'] ?? null)
                : self::liftAssets($site['logo'] ?? null),
            'email' => (string) ($contact['email'] ?? ''),
            'phone' => (string) ($contact['phone'] ?? ''),
            'address' => (string) ($contact['address'] ?? ''),
            'locale' => $locale,
            'languages' => count($languages) > 1 ? $languages : [],
            'asset_prefix' => $locale === $primary ? '' : '../',
        ];
    }

    /**
     * Aus einem Pfad wird ein Dateiname: "/leistungen" → "leistungen.html".
     *
     * Weitere Sprachen liegen in einem Unterordner: "fr/leistungen.html".
     * Die Hauptsprache bleibt in der Wurzel – sie ist die Adresse, die
     * überall verlinkt und in Suchmaschinen steht.
     */
    public static function fileNameFor(string $path, string $locale = '', string $primary = ''): string
    {
        $path = trim($path, '/');
        $file = $path === '' ? 'index.html' : str_slug($path, 60) . '.html';

        if ($locale === '' || $locale === $primary) {
            return $file;
        }

        return $locale . '/' . $file;
    }

    /**
     * Der Weg von einer Seite zu einer anderen.
     *
     * Aus einem Unterordner heraus muss ein Verweis eine Ebene hinauf,
     * sonst zeigt er ins Leere. Absolute Verweise wären einfacher,
     * brächen aber in der Vorschau und in jedem Unterordner.
     */
    public static function relativeLink(string $target, string $fromLocale, string $primary): string
    {
        $prefix = ($fromLocale !== '' && $fromLocale !== $primary) ? '../' : '';
        return $prefix . $target;
    }

    // ------------------------------------------------------------------

    /** Kopf des Dokuments samt Metaangaben. */
    private static function wrap(
        array $site,
        array $page,
        string $body,
        string $criticalCss,
        string $locale = ''
    ): string {
        $contact = $site['contact'] ?? [];

        $locales = $site['locales'] ?? [];
        $primary = (string) ($locales[0] ?? ($site['locale'] ?? 'de'));
        $locale = $locale !== '' ? $locale : $primary;

        // Aus einem Sprachordner heraus liegen Stylesheet, Skript und
        // Bilder eine Ebene höher.
        $up = $locale === $primary ? '' : '../';

        $title = (string) ($page['title'] ?? '');

        // Titel und Kurzbeschreibung gibt es je Sprache.
        if ($locale !== $primary) {
            $title = (string) ($page['translations'][$locale]['title'] ?? $title);
        }
        $brand = (string) ($site['brand'] ?? '');
        $description = (string) ($page['meta_description'] ?? '');

        if ($locale !== $primary) {
            $description = (string) (
                $page['translations'][$locale]['meta_description'] ?? $description
            );
        }
        $isHome = (string) ($page['path'] ?? '/') === '/';

        $pageTitle = $isHome && $title !== ''
            ? $title
            : trim($title . ' · ' . $brand, ' ·');

        $schema = json_out(array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'LocalBusiness',
            'name' => $brand,
            'description' => $description,
            'email' => $contact['email'] ?? null,
            'telephone' => $contact['phone'] ?? null,
            'address' => trim((string) ($contact['address'] ?? '')) !== '' ? [
                '@type' => 'PostalAddress',
                'streetAddress' => (string) $contact['address'],
            ] : null,
        ]));

        $themeColor = (string) ($site['theme']['colors']['primary'] ?? '#000000');

        // hreflang sagt Suchmaschinen, dass es dieselbe Seite in mehreren
        // Sprachen gibt. Ohne das gelten sie als doppelter Inhalt.
        $alternates = '';
        if (count($locales) > 1) {
            $currentPath = (string) ($page['path'] ?? '/');
            $lines = [];

            foreach ($locales as $code) {
                $target = self::fileNameFor($currentPath, (string) $code, $primary);
                $lines[] = sprintf(
                    '<link rel="alternate" hreflang="%s" href="%s">',
                    htmlspecialchars((string) $code, ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars(self::relativeLink($target, $locale, $primary), ENT_QUOTES, 'UTF-8')
                );
            }

            $lines[] = sprintf(
                '<link rel="alternate" hreflang="x-default" href="%s">',
                htmlspecialchars(
                    self::relativeLink(self::fileNameFor($currentPath, $primary, $primary), $locale, $primary),
                    ENT_QUOTES,
                    'UTF-8'
                )
            );

            $alternates = implode("\n        ", $lines);
        }

        $esc = static fn (string $v): string
            => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $escTitle = $esc($pageTitle);
        $escDescription = $esc($description);

        return <<<HTML
        <!doctype html>
        <html lang="{$esc($locale)}">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{$escTitle}</title>
        <meta name="description" content="{$escDescription}">
        <meta name="theme-color" content="{$esc($themeColor)}">
        <link rel="icon" href="{$up}assets/img/favicon.svg" type="image/svg+xml">
        {$alternates}

        <meta property="og:type" content="website">
        <meta property="og:title" content="{$escTitle}">
        <meta property="og:description" content="{$escDescription}">
        <meta property="og:site_name" content="{$esc($brand)}">

        <!-- Das Nötigste steht direkt hier: die Seite erscheint sofort
             fertig gestaltet, ohne auf eine zweite Datei zu warten.
             Das vollständige Stylesheet folgt als gewöhnlicher Verweis.
             Der bekannte Trick mit media="print" und onload wäre ein
             Zeilenskript – jede strenge Content-Security-Policy blockt
             es, und dann bliebe die Seite für immer unformatiert. -->
        <style>{$criticalCss}</style>
        <link rel="stylesheet" href="{$up}assets/css/site.css">

        <script type="application/ld+json">{$schema}</script>
        </head>
        <body>
        <a class="s-skip" href="#inhalt">Direkt zum Inhalt</a>
        {$body}
        <script src="{$up}assets/js/site.js" defer></script>
        </body>
        </html>
        HTML;
    }
}
