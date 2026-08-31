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
    /**
     * Eine vollständige Seite bauen.
     *
     * @param array $site  brand, locale, theme, pages, logo, contact
     * @param array $page  title, path, meta_description, sections
     */
    public static function render(array $site, array $page, string $criticalCss = ''): string
    {
        $context = self::context($site, $page);

        $body = '';
        foreach ($page['sections'] ?? [] as $section) {
            if (!empty($section['hidden'])) {
                continue;
            }
            $body .= Renderer::section($section, $context) . "\n";
        }

        return self::wrap($site, $page, $body, $criticalCss);
    }

    /**
     * Angaben, die jeder Abschnitt braucht: Navigation, Kontakt, Logo.
     */
    public static function context(array $site, array $currentPage): array
    {
        $contact = $site['contact'] ?? [];
        $currentPath = (string) ($currentPage['path'] ?? '/');

        $nav = [];
        $legal = [];

        foreach ($site['pages'] ?? [] as $page) {
            $entry = [
                'label' => (string) ($page['title'] ?? ''),
                'url' => self::fileNameFor((string) ($page['path'] ?? '/')),
                'current' => (string) ($page['path'] ?? '') === $currentPath,
            ];

            if (in_array((string) ($page['path'] ?? ''), ['/impressum', '/datenschutz', '/agb'], true)) {
                $legal[] = $entry;
            } elseif (!empty($page['in_navigation'])) {
                $nav[] = $entry;
            }
        }

        return [
            'brand' => (string) ($site['brand'] ?? ''),
            'home' => 'index.html',
            'nav' => $nav,
            'legal' => $legal,
            'logo' => $site['logo'] ?? null,
            'email' => (string) ($contact['email'] ?? ''),
            'phone' => (string) ($contact['phone'] ?? ''),
            'address' => (string) ($contact['address'] ?? ''),
            'locale' => (string) ($site['locale'] ?? 'de'),
        ];
    }

    /** Aus einem Pfad wird ein Dateiname: "/leistungen" → "leistungen.html". */
    public static function fileNameFor(string $path): string
    {
        $path = trim($path, '/');
        return $path === '' ? 'index.html' : str_slug($path, 60) . '.html';
    }

    // ------------------------------------------------------------------

    /** Kopf des Dokuments samt Metaangaben. */
    private static function wrap(array $site, array $page, string $body, string $criticalCss): string
    {
        $contact = $site['contact'] ?? [];

        $locale = (string) ($site['locale'] ?? 'de');
        $title = (string) ($page['title'] ?? '');
        $brand = (string) ($site['brand'] ?? '');
        $description = (string) ($page['meta_description'] ?? '');
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
        <link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">

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
        <link rel="stylesheet" href="assets/css/site.css">

        <script type="application/ld+json">{$schema}</script>
        </head>
        <body>
        <a class="s-skip" href="#inhalt">Direkt zum Inhalt</a>
        {$body}
        <script src="assets/js/site.js" defer></script>
        </body>
        </html>
        HTML;
    }
}
