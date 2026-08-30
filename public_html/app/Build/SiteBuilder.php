<?php

declare(strict_types=1);

namespace WebAtze\Build;

use WebAtze\Core\{Db, Logger};
use WebAtze\Templates\Renderer;

/**
 * Baut aus den gespeicherten Seiten und Abschnitten die fertige Website.
 *
 * Das Ergebnis ist eine gewöhnliche Website aus HTML, CSS und ein wenig
 * JavaScript – keine Datenbank, kein Rahmenwerk, nichts, was installiert
 * werden müsste. Sie läuft auf jedem Hosting und lässt sich in zehn
 * Jahren noch öffnen.
 *
 * Eine Datenbank entsteht nur, wenn ausdrücklich etwas gewünscht wurde,
 * das etwas speichern muss (Buchungen, Bestellungen). Dann kommt ein
 * eigenes Modul dazu.
 */
final class SiteBuilder
{
    private array $project;
    private array $theme;
    private array $pages;
    private string $outputDir;

    public function __construct(array $project, string $outputDir)
    {
        $this->project = $project;
        $this->outputDir = rtrim($outputDir, '/');

        $theme = json_decode((string) ($project['theme'] ?? '{}'), true);
        $this->theme = is_array($theme) && $theme !== [] ? $theme : Theme::build([]);

        $this->pages = self::loadPages((int) $project['id']);
    }

    /**
     * Alles bauen.
     *
     * @return array{files:int, bytes:int, pages:int}
     */
    public function build(): array
    {
        ensure_dir($this->outputDir);
        ensure_dir($this->outputDir . '/assets/css');
        ensure_dir($this->outputDir . '/assets/js');
        ensure_dir($this->outputDir . '/assets/img');

        $files = 0;

        // --- Stylesheets ------------------------------------------------
        $files += $this->writeStyles();

        // --- Skript -----------------------------------------------------
        $kitJs = APP_DIR . '/Kit/site/js/site.js';
        if (is_file($kitJs)) {
            write_file_atomic($this->outputDir . '/assets/js/site.js', (string) file_get_contents($kitJs));
            $files++;
        }

        // --- Bilder -----------------------------------------------------
        $files += $this->copyAssets();

        // --- Seiten -----------------------------------------------------
        foreach ($this->pages as $page) {
            $html = $this->renderPage($page);
            $target = $this->outputDir . '/' . self::fileNameFor((string) $page['path']);

            ensure_dir(dirname($target));
            write_file_atomic($target, $html);
            $files++;
        }

        // --- Beiwerk ----------------------------------------------------
        $files += $this->writeExtras();

        return [
            'files' => $files,
            'bytes' => dir_size($this->outputDir),
            'pages' => count($this->pages),
        ];
    }

    /** Eine einzelne Seite als HTML. */
    public function renderPage(array $page): string
    {
        $sections = $page['sections'] ?? [];
        $context = $this->context($page);

        $body = '';
        foreach ($sections as $section) {
            if (!empty($section['hidden'])) {
                continue;
            }
            $body .= Renderer::section($section, $context) . "\n";
        }

        return $this->wrap($page, $body, $context);
    }

    /** Der Rahmen um die Abschnitte: Kopf des Dokuments, Metaangaben. */
    private function wrap(array $page, string $body, array $context): string
    {
        $brief = json_decode((string) ($this->project['brief'] ?? '{}'), true) ?: [];

        $locale = (string) ($this->project['locale'] ?? 'de');
        $title = (string) ($page['title'] ?? '');
        $brand = (string) ($this->project['name'] ?? '');
        $description = (string) ($page['meta_description'] ?? '');
        $isHome = ($page['path'] ?? '/') === '/';

        $pageTitle = $isHome && $title !== ''
            ? $title
            : trim($title . ' · ' . $brand, ' ·');

        $schema = json_out(array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'LocalBusiness',
            'name' => $brand,
            'description' => $description,
            'email' => $brief['contact_email'] ?? null,
            'telephone' => $brief['contact_phone'] ?? null,
            'address' => trim((string) ($brief['contact_address'] ?? '')) !== '' ? [
                '@type' => 'PostalAddress',
                'streetAddress' => (string) $brief['contact_address'],
            ] : null,
        ]));

        $critical = self::criticalCss();

        return <<<HTML
        <!doctype html>
        <html lang="{$locale}">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{$this->esc($pageTitle)}</title>
        <meta name="description" content="{$this->esc($description)}">
        <meta name="theme-color" content="{$this->esc($this->theme['colors']['primary'] ?? '#000000')}">
        <link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">

        <meta property="og:type" content="website">
        <meta property="og:title" content="{$this->esc($pageTitle)}">
        <meta property="og:description" content="{$this->esc($description)}">
        <meta property="og:site_name" content="{$this->esc($brand)}">

        <!-- Das Nötigste steht direkt hier: die Seite erscheint sofort
             fertig gestaltet, ohne auf eine zweite Datei zu warten. -->
        <style>{$critical}</style>
        <link rel="stylesheet" href="assets/css/site.css" media="print" onload="this.media='all'">
        <noscript><link rel="stylesheet" href="assets/css/site.css"></noscript>

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

    /** Angaben, die jeder Abschnitt braucht: Navigation, Kontakt, Logo. */
    private function context(array $currentPage): array
    {
        $brief = json_decode((string) ($this->project['brief'] ?? '{}'), true) ?: [];

        $nav = [];
        $legal = [];

        foreach ($this->pages as $page) {
            $entry = [
                'label' => (string) $page['title'],
                'url' => self::linkFor((string) $page['path']),
                'current' => $page['path'] === $currentPage['path'],
            ];

            if (in_array($page['path'], ['/impressum', '/datenschutz', '/agb'], true)) {
                $legal[] = $entry;
            } elseif (!empty($page['in_navigation'])) {
                $nav[] = $entry;
            }
        }

        $logo = null;
        $logoAsset = Db::first(
            "SELECT * FROM project_assets WHERE project_id = :p AND kind = 'logo' ORDER BY id DESC LIMIT 1",
            ['p' => (int) $this->project['id']]
        );
        if ($logoAsset !== null) {
            $logo = [
                'src' => 'assets/img/' . basename((string) $logoAsset['path']),
                'alt' => (string) $this->project['name'],
                'width' => (int) $logoAsset['width'],
                'height' => (int) $logoAsset['height'],
            ];
        }

        return [
            'brand' => (string) $this->project['name'],
            'home' => 'index.html',
            'nav' => $nav,
            'legal' => $legal,
            'logo' => $logo,
            'email' => (string) ($brief['contact_email'] ?? ''),
            'phone' => (string) ($brief['contact_phone'] ?? ''),
            'address' => (string) ($brief['contact_address'] ?? ''),
            'locale' => (string) ($this->project['locale'] ?? 'de'),
        ];
    }

    /** Alle Stylesheets zu einer Datei zusammenfügen. */
    private function writeStyles(): int
    {
        $kit = APP_DIR . '/Kit/site/css';
        $parts = [Theme::toCss($this->theme)];

        foreach (['tokens', 'base', 'layout', 'sections', 'motion'] as $name) {
            $file = $kit . '/' . $name . '.css';
            if (!is_file($file)) {
                continue;
            }
            $css = (string) file_get_contents($file);

            // tokens.css enthält die Voreinstellungen. Die echten Farben
            // stehen davor – deshalb hier nur den Teil ohne Farben nehmen.
            if ($name === 'tokens') {
                $css = self::stripDefaultColours($css);
            }

            $parts[] = "\n/* ---- " . $name . " ---- */\n" . $css;
        }

        $css = implode("\n", $parts);
        write_file_atomic($this->outputDir . '/assets/css/site.css', self::minifyCss($css));

        return 1;
    }

    /**
     * Aus tokens.css die Farbwerte entfernen.
     *
     * Sie stehen bereits im erzeugten Farbblock davor; doppelte Werte
     * würden die Kundenfarben wieder überschreiben.
     */
    private static function stripDefaultColours(string $css): string
    {
        // Nur genau diese Namen dürfen weg. Eine Suche nach "beginnt mit
        // --c-text" würde auch --c-text-hero und die ganze Schriftskala
        // treffen – dann hätte die Website keine Grössen mehr.
        $tokens = [
            'primary', 'primary-dark', 'primary-light',
            'secondary', 'secondary-dark', 'ink',
            'on-primary', 'on-secondary',
            'bg', 'bg-soft', 'surface', 'surface-2',
            'border', 'border-strong',
            'text', 'text-muted', 'text-faint',
            'dark-bg', 'dark-surface', 'dark-text', 'dark-muted', 'dark-border',
            'success', 'danger',
            'font-display', 'font-body',
        ];

        $pattern = '/^[ \t]*--c-(?:' . implode('|', array_map(
            static fn (string $t): string => preg_quote($t, '/'),
            $tokens
        )) . ')\s*:[^;]*;[ \t]*\r?\n?/mi';

        return preg_replace($pattern, '', $css) ?? $css;
    }

    /** Bilder aus der Projektablage in den Ausgabeordner kopieren. */
    private function copyAssets(): int
    {
        $assets = Db::all(
            'SELECT * FROM project_assets WHERE project_id = :p',
            ['p' => (int) $this->project['id']]
        );

        $count = 0;
        $source = STORAGE_DIR . '/projects/' . (string) $this->project['slug'] . '/assets';

        foreach ($assets as $asset) {
            $name = basename((string) $asset['path']);
            $from = $source . '/' . $name;

            if (!is_file($from)) {
                continue;
            }
            if (@copy($from, $this->outputDir . '/assets/img/' . $name)) {
                $count++;
            }
        }

        // Ein schlichtes Zeichen für den Browser-Tab, falls kein Logo da ist.
        write_file_atomic(
            $this->outputDir . '/assets/img/favicon.svg',
            $this->faviconSvg()
        );

        return $count + 1;
    }

    /** robots.txt, sitemap.xml und die Serverkonfiguration. */
    private function writeExtras(): int
    {
        $domain = trim((string) ($this->project['domain'] ?? ''));
        $base = $domain !== '' ? 'https://' . $domain : '';

        // robots.txt
        $robots = "User-agent: *\nAllow: /\n";
        if ($base !== '') {
            $robots .= "\nSitemap: {$base}/sitemap.xml\n";
        }
        write_file_atomic($this->outputDir . '/robots.txt', $robots);

        // sitemap.xml
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($this->pages as $page) {
            $loc = $base . '/' . ltrim(self::linkFor((string) $page['path']), '/');
            $xml .= '  <url><loc>' . $this->esc($loc) . '</loc></url>' . "\n";
        }
        $xml .= '</urlset>' . "\n";
        write_file_atomic($this->outputDir . '/sitemap.xml', $xml);

        // .htaccess: Sicherheitsheader, Kompression, Zwischenspeicherung
        write_file_atomic($this->outputDir . '/.htaccess', self::htaccess());

        // 404-Seite
        $notFound = $this->renderPage([
            'path' => '/404',
            'title' => 'Seite nicht gefunden',
            'meta_description' => '',
            'in_navigation' => 0,
            'sections' => [[
                'id' => 0,
                'type' => 'text',
                'template_key' => 'mittig',
                'content' => [
                    'title' => 'Diese Seite gibt es nicht',
                    'body' => "Vielleicht hat sich ein Tippfehler eingeschlichen.\n\n"
                        . '<a href="index.html">Zurück zur Startseite</a>',
                ],
                'overrides' => [],
            ]],
        ]);
        write_file_atomic($this->outputDir . '/404.html', $notFound);

        return 4;
    }

    private static function htaccess(): string
    {
        return <<<'CONF'
        # Diese Website – erzeugt, aber ganz normale Dateien.
        # Nichts hier muss installiert werden.

        <IfModule mod_headers.c>
            Header always set X-Content-Type-Options "nosniff"
            Header always set X-Frame-Options "SAMEORIGIN"
            Header always set Referrer-Policy "strict-origin-when-cross-origin"
            Header always set Permissions-Policy "geolocation=(), microphone=(), camera=()"

            <FilesMatch "\.(css|js|woff2|png|jpg|jpeg|webp|avif|svg|ico)$">
                Header set Cache-Control "public, max-age=2592000"
            </FilesMatch>
        </IfModule>

        <IfModule mod_deflate.c>
            AddOutputFilterByType DEFLATE text/html text/css text/plain text/xml \
                application/javascript application/json image/svg+xml
        </IfModule>

        <IfModule mod_rewrite.c>
            RewriteEngine On
            # Adressen ohne .html zulassen: /kontakt findet kontakt.html
            RewriteCond %{REQUEST_FILENAME} !-f
            RewriteCond %{REQUEST_FILENAME} !-d
            RewriteCond %{REQUEST_FILENAME}.html -f
            RewriteRule ^(.*)$ $1.html [L]
        </IfModule>

        ErrorDocument 404 /404.html
        Options -Indexes
        AddDefaultCharset UTF-8
        CONF;
    }

    private function faviconSvg(): string
    {
        $primary = (string) ($this->theme['colors']['primary'] ?? '#333333');
        $onPrimary = (string) ($this->theme['colors']['on_primary'] ?? '#ffffff');
        $initial = mb_strtoupper(mb_substr((string) $this->project['name'], 0, 1));

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
            . '<rect width="64" height="64" rx="14" fill="' . $this->esc($primary) . '"/>'
            . '<text x="32" y="43" text-anchor="middle" font-family="system-ui,sans-serif"'
            . ' font-size="34" font-weight="700" fill="' . $this->esc($onPrimary) . '">'
            . $this->esc($initial) . '</text></svg>';
    }

    /**
     * Das Nötigste für den ersten Bildaufbau.
     *
     * Ohne das würde die Seite kurz unformatiert erscheinen, bevor das
     * Stylesheet geladen ist – ein sichtbares Zucken, das teurer wirkt
     * als es ist.
     */
    private static function criticalCss(): string
    {
        return self::minifyCss(
            '*,*::before,*::after{box-sizing:border-box}'
            . 'body{margin:0;background:var(--c-bg,#fff);color:var(--c-text,#16172a);'
            . 'font-family:var(--c-font-body,system-ui,sans-serif);line-height:1.65;'
            . '-webkit-font-smoothing:antialiased}'
            . 'img,svg{display:block;max-width:100%;height:auto}'
            . '.s-shell{width:100%;max-width:74rem;margin-inline:auto;padding-inline:clamp(1.15rem,4vw,2.75rem)}'
            . '[data-section]{position:relative;padding-block:clamp(3.5rem,8vw,7rem)}'
            . '[data-section="header"]{padding-block:0}'
            . '.s-header__inner{display:flex;align-items:center;justify-content:space-between;'
            . 'gap:1.5rem;min-height:4.25rem}'
            . '.s-hero__title{font-size:clamp(2.4rem,4.6vw + 1rem,4.5rem);line-height:1.12;'
            . 'letter-spacing:-0.025em;margin:0}'
            . '.s-skip{position:absolute;inset-inline-start:1rem;inset-block-start:-5rem;z-index:200}'
        );
    }

    /** Einfache, verlustfreie Verkleinerung des CSS. */
    public static function minifyCss(string $css): string
    {
        // Kommentare entfernen, aber den Kopf der Datei behalten
        $css = preg_replace('#/\*(?!!).*?\*/#s', '', $css) ?? $css;
        $css = preg_replace('/\s+/', ' ', $css) ?? $css;
        $css = str_replace([' {', '{ ', ' }', '} ', ': ', '; ', ' ,', ', ', ';}'],
                           ['{', '{', '}', '}', ':', ';', ',', ',', '}'], $css);

        return trim($css);
    }

    // ------------------------------------------------------------------
    // Seiten laden
    // ------------------------------------------------------------------

    /** Alle Seiten eines Projekts samt ihrer Abschnitte. */
    public static function loadPages(int $projectId): array
    {
        $pages = Db::all(
            'SELECT * FROM project_pages WHERE project_id = :p ORDER BY sort_order ASC, id ASC',
            ['p' => $projectId]
        );

        foreach ($pages as $index => $page) {
            $sections = Db::all(
                'SELECT * FROM project_sections WHERE page_id = :page ORDER BY sort_order ASC, id ASC',
                ['page' => (int) $page['id']]
            );

            foreach ($sections as $key => $section) {
                $sections[$key]['content'] = json_decode((string) ($section['content'] ?? '{}'), true) ?: [];
                $sections[$key]['overrides'] = json_decode((string) ($section['overrides'] ?? '{}'), true) ?: [];
                $sections[$key]['id'] = (int) $section['id'];
            }

            $pages[$index]['sections'] = $sections;
        }

        return $pages;
    }

    /** '/kontakt' -> 'kontakt.html', '/' -> 'index.html' */
    public static function fileNameFor(string $path): string
    {
        $path = trim($path, '/');
        return $path === '' ? 'index.html' : str_slug($path, 60) . '.html';
    }

    public static function linkFor(string $path): string
    {
        return self::fileNameFor($path);
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
