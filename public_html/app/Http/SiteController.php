<?php

declare(strict_types=1);

namespace WebAtze\Http;

use WebAtze\Core\{Config, Db, I18n, Request, Response, Session, Settings, View};

/**
 * Die öffentlichen Seiten.
 */
final class SiteController
{
    /** Was in einer gespeicherten Kundenwebsite vorkommen kann. */
    private const MIME = [
        'html' => 'text/html; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'gif' => 'image/gif',
        'ico' => 'image/x-icon',
        'woff2' => 'font/woff2',
        'woff' => 'font/woff',
    ];

    /**
     * Die eigene Seite zusätzlich aus Abschnittsdaten rendern.
     *
     * Sichtbar nur für den angemeldeten Betreiber und nur mit ?stil=daten
     * in der Adresse. Damit stehen beide Fassungen gleichzeitig in zwei
     * Browserfenstern und lassen sich vergleichen - während öffentlich
     * weiterhin die alte steht.
     *
     * Das ist der Grund, warum der Umbau der eigenen Website überhaupt
     * vertretbar ist. Ohne diesen Zwischenschritt wäre jede Umstellung
     * ein Sprung: erst umschalten, dann sehen, was fehlt. Mit ihm wird
     * verglichen, nachgebessert und erst umgeschaltet, wenn kein
     * Unterschied mehr zu sehen ist.
     *
     * @return Response|null null heisst: die handgeschriebene Fassung gilt
     */
    private function ausDaten(Request $request, string $routeKey): ?Response
    {
        if ($request->query('stil') !== 'daten' || !Session::isLoggedIn()) {
            return null;
        }

        $projekt = \WebAtze\Http\StyleController::eigenes();

        if ($projekt === null) {
            return null;
        }

        $seite = Db::first(
            'SELECT * FROM project_pages WHERE project_id = :p AND route_key = :r LIMIT 1',
            ['p' => (int) $projekt['id'], 'r' => $routeKey]
        );

        if ($seite === null) {
            return null;
        }

        $seite['sections'] = \WebAtze\Domain\Sections::forPage((int) $seite['id']);

        if ($seite['sections'] === []) {
            return null;
        }

        $inhalt = '';

        $kontext = \WebAtze\Templates\Page::context(
            $this->websiteDaten($projekt),
            $seite,
            I18n::locale()
        );

        // Die Referenzen kommen aus ihrer Tabelle, nicht aus dem Inhalt
        // eines Abschnitts. Ein gebundener Abschnitt greift darauf zu -
        // welche Quellen es gibt, entscheidet der Renderer.
        $kontext['daten'] = ['showcase' => array_map(
            static fn (array $r): array => [
                'title' => (string) $r['title'],
                'text' => (string) ($r['subtitle'] ?? ''),
                'icon' => 'globe',
                'link' => [
                    'label' => __t('work.visit'),
                    'url' => '/referenzen/' . (string) $r['slug'],
                ],
            ],
            // Wie viele, entscheidet der Abschnitt ueber overrides.anzahl.
            // Auf der Startseite sind es ein paar, auf der Referenzseite
            // alle - dieselbe Quelle, zwei Ausschnitte.
            $this->showcase(60)
        )];

        foreach ($seite['sections'] as $abschnitt) {
            if (!empty($abschnitt['hidden'])) {
                continue;
            }

            $inhalt .= \WebAtze\Templates\Renderer::section($abschnitt, $kontext) . "\n";
        }

        // Derselbe Rahmen wie sonst: Kopfzeile, Fusszeile, Kulisse, die
        // Sicherheitskopfzeilen. Nur der Inhalt kommt aus Daten. Ein
        // eigener Rahmen wäre ein zweiter, der auseinanderläuft.
        return Response::html(View::partial('layouts/site', [
            'title' => (string) $seite['title'],
            'description' => (string) ($seite['meta_description'] ?? ''),
            'showHero' => $routeKey === '',
            'pageClass' => 'wa-body--daten',
            'content' => $inhalt,
            'stylesheet' => \WebAtze\Http\StyleController::url($projekt),
        ]));
    }

    /** Die Angaben zur eigenen Website, wie der Seitenbau sie erwartet. */
    private function websiteDaten(array $projekt): array
    {
        return [
            'brand' => (string) $projekt['name'],
            'locale' => (string) ($projekt['locale'] ?? 'de'),
            'locales' => array_filter(explode(',', (string) ($projekt['locales'] ?? 'de'))),
            'theme' => is_array($projekt['theme'] ?? null) ? $projekt['theme'] : [],
            'domain' => (string) ($projekt['domain'] ?? ''),
            'contact' => ['email' => '', 'phone' => '', 'address' => ''],
            'pages' => Db::all(
                'SELECT * FROM project_pages WHERE project_id = :p ORDER BY sort_order ASC',
                ['p' => (int) $projekt['id']]
            ),
        ];
    }

    public function home(Request $request): Response
    {
        $daten = $this->ausDaten($request, '');

        if ($daten !== null) {
            return $daten;
        }

        return Response::html(View::page('pages/home', [
            'title' => __t('meta.tagline'),
            'description' => __t('meta.description'),
            'showHero' => true,
            'pageClass' => 'wa-body--home',
            'showcase' => $this->showcase(9),
        ]));
    }

    public function services(Request $request): Response
    {
        $daten = $this->ausDaten($request, 'services');

        if ($daten !== null) {
            return $daten;
        }

        return Response::html(View::page('pages/services', [
            'title' => __t('services.title'),
            'description' => __t('services.lead'),
        ]));
    }

    public function process(Request $request): Response
    {
        $daten = $this->ausDaten($request, 'process');

        if ($daten !== null) {
            return $daten;
        }

        return Response::html(View::page('pages/process', [
            'title' => __t('process.title'),
            'description' => __t('process.lead'),
        ]));
    }

    public function work(Request $request): Response
    {
        $daten = $this->ausDaten($request, 'work');

        if ($daten !== null) {
            return $daten;
        }

        return Response::html(View::page('pages/work', [
            'title' => __t('work.title'),
            'description' => __t('work.lead'),
            'showcase' => $this->showcase(60),
        ]));
    }

    public function workDetail(Request $request): Response
    {
        $slug = $request->param('slug');

        $item = Db::first(
            'SELECT * FROM showcase WHERE slug = :slug AND published = 1',
            ['slug' => $slug]
        );

        if ($item === null) {
            return Response::html(
                View::page('pages/not-found', ['title' => __t('errors.not_found_title')]),
                404
            );
        }

        return Response::html(View::page('pages/work-detail', [
            'title' => (string) $item['title'],
            'description' => (string) $item['subtitle'],
            'item' => $item,
        ]));
    }

    /**
     * Die Miniatur einer Referenz.
     *
     * Ausgeliefert wird eine dauerhafte Kopie der fertigen Kundenwebsite
     * aus storage/showcase/<kennung>/. Die Vorschau unter /vorschau/ taugt
     * dafür nicht: Sie wird nach 24 Stunden aufgeräumt, und danach stünde
     * in jeder Referenzkarte nur noch ein leerer Rahmen.
     */
    public function workPreview(Request $request): Response
    {
        return $this->servePreview($request->param('slug'), 'index.html');
    }

    public function workPreviewFile(Request $request): Response
    {
        return $this->servePreview($request->param('slug'), $request->param('path'));
    }

    private function servePreview(string $slug, string $path): Response
    {
        $slug = preg_replace('/[^a-z0-9-]/', '', mb_strtolower($slug)) ?? '';

        if ($slug === '') {
            return Response::notFound();
        }

        // Nur veröffentlichte Referenzen. Eine ausgeblendete soll auch
        // nicht über den Umweg ihrer Miniatur sichtbar sein.
        $published = Db::value(
            'SELECT COUNT(*) FROM showcase WHERE slug = :slug AND published = 1',
            ['slug' => $slug],
            0
        );

        if ((int) $published === 0) {
            return Response::notFound();
        }

        $root = STORAGE_DIR . '/showcase/' . $slug;
        $file = self::insideRoot($root, $path === '' ? 'index.html' : $path);

        if ($file === null) {
            return Response::notFound();
        }

        $type = self::MIME[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? 'application/octet-stream';

        return Response::make((string) file_get_contents($file))
            ->header('Content-Type', $type)
            // Die Miniatur steckt in einem Rahmen auf der eigenen Seite –
            // fremde Seiten sollen sie nicht einbetten können.
            ->header('X-Frame-Options', 'SAMEORIGIN')
            ->header('X-Robots-Tag', 'noindex')
            ->header('Cache-Control', 'public, max-age=3600');
    }

    /**
     * Pfad auflösen und sicherstellen, dass er im Ordner bleibt.
     *
     * realpath() löst ".." und Verweise auf. Erst danach wird verglichen –
     * so führt kein Pfad aus dem Referenzordner hinaus.
     */
    private static function insideRoot(string $root, string $path): ?string
    {
        $realRoot = realpath($root);
        if ($realRoot === false) {
            return null;
        }

        $candidate = realpath($realRoot . '/' . ltrim($path, '/'));

        if ($candidate === false
            || !is_file($candidate)
            || !str_starts_with($candidate, $realRoot . DIRECTORY_SEPARATOR)
        ) {
            return null;
        }

        return $candidate;
    }

    public function contact(Request $request): Response
    {
        $daten = $this->ausDaten($request, 'contact');

        if ($daten !== null) {
            return $daten;
        }

        return Response::html(View::page('pages/contact', [
            'title' => __t('contact.title'),
            'description' => __t('contact.lead'),
        ]));
    }

    public function imprint(Request $request): Response
    {
        $daten = $this->ausDaten($request, 'imprint');

        if ($daten !== null) {
            return $daten;
        }

        return Response::html(View::page('pages/imprint', [
            'title' => __t('imprint.title'),
            'description' => __t('imprint.intro'),
        ]));
    }

    public function privacy(Request $request): Response
    {
        $daten = $this->ausDaten($request, 'privacy');

        if ($daten !== null) {
            return $daten;
        }

        return Response::html(View::page('pages/privacy', [
            'title' => __t('privacy.title'),
            'description' => __t('privacy.intro'),
        ]));
    }

    // ------------------------------------------------------------------
    // Dateien für Suchmaschinen und Browser
    // ------------------------------------------------------------------

    public function robots(Request $request): Response
    {
        // Der versteckte Bereich steht hier bewusst NICHT.
        //
        // robots.txt ist für jeden abrufbar. Wer den Pfad hier hineinschreibt,
        // verrät damit genau die Adresse, die niemand kennen soll – eine
        // Zeile, und der versteckte Bereich ist nicht mehr versteckt.
        //
        // Nötig ist sie auch nicht: Alle Antworten dort tragen
        // "X-Robots-Tag: noindex, nofollow, noarchive" und die Seiten
        // zusätzlich dasselbe als Meta-Angabe. Das ist die stärkere Zusage,
        // denn "Disallow" verhindert nur das Abrufen durch die Suchmaschine,
        // nicht die Aufnahme einer Adresse, die sie anderswo aufgelesen hat.
        //
        // Die übrigen Pfade sind fest und ohnehin bekannt; sie zu nennen
        // verrät nichts.
        $lines = [
            'User-agent: *',
            'Allow: /',
            'Disallow: /vorschau/',
            'Disallow: /api/',
            'Disallow: /assistant/',
            'Disallow: /worker/',
            '',
            'Sitemap: ' . Config::url('sitemap.xml'),
        ];

        return Response::text(implode("\n", $lines) . "\n")
            ->header('Cache-Control', 'public, max-age=3600');
    }

    public function sitemap(Request $request): Response
    {
        $keys = ['', 'services', 'process', 'work', 'contact', 'imprint', 'privacy'];
        $entries = [];

        foreach ($keys as $key) {
            foreach (I18n::LOCALES as $locale) {
                $entries[] = [
                    'loc' => Config::url(ltrim(I18n::url($key, $locale), '/')),
                    'priority' => $key === '' ? '1.0' : '0.7',
                ];
            }
        }

        foreach ($this->showcase(200) as $item) {
            foreach (I18n::LOCALES as $locale) {
                $entries[] = [
                    'loc' => Config::url(ltrim(I18n::url('work', $locale), '/') . '/' . rawurlencode((string) $item['slug'])),
                    'priority' => '0.5',
                ];
            }
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($entries as $entry) {
            $xml .= '  <url><loc>' . e($entry['loc']) . '</loc>'
                . '<priority>' . $entry['priority'] . '</priority></url>' . "\n";
        }

        $xml .= '</urlset>' . "\n";

        return Response::make($xml)
            ->header('Content-Type', 'application/xml; charset=utf-8')
            ->header('Cache-Control', 'public, max-age=3600');
    }

    public function manifest(Request $request): Response
    {
        return Response::json([
            'name' => 'WebAtze',
            'short_name' => 'WebAtze',
            'description' => __t('meta.description'),
            'start_url' => '/',
            'display' => 'standalone',
            'background_color' => '#06060f',
            'theme_color' => '#06060f',
            'icons' => [
                ['src' => '/assets/img/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => '/assets/img/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => '/assets/img/favicon.svg', 'sizes' => 'any', 'type' => 'image/svg+xml'],
            ],
        ])->header('Content-Type', 'application/manifest+json; charset=utf-8')
          ->header('Cache-Control', 'public, max-age=86400');
    }

    // ------------------------------------------------------------------

    /** Veröffentlichte Referenzen, neueste zuerst. */
    private function showcase(int $limit): array
    {
        return Db::all(
            'SELECT * FROM showcase WHERE published = 1
             ORDER BY sort_order ASC, id DESC LIMIT :lim',
            ['lim' => max(1, min(200, $limit))]
        );
    }
}
