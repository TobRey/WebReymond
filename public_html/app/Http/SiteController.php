<?php

declare(strict_types=1);

namespace WebAtze\Http;

use WebAtze\Core\{Config, Db, I18n, Request, Response, Settings, View};

/**
 * Die öffentlichen Seiten.
 */
final class SiteController
{
    public function home(Request $request): Response
    {
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
        return Response::html(View::page('pages/services', [
            'title' => __t('services.title'),
            'description' => __t('services.lead'),
        ]));
    }

    public function process(Request $request): Response
    {
        return Response::html(View::page('pages/process', [
            'title' => __t('process.title'),
            'description' => __t('process.lead'),
        ]));
    }

    public function work(Request $request): Response
    {
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

    public function contact(Request $request): Response
    {
        return Response::html(View::page('pages/contact', [
            'title' => __t('contact.title'),
            'description' => __t('contact.lead'),
        ]));
    }

    public function imprint(Request $request): Response
    {
        return Response::html(View::page('pages/imprint', [
            'title' => __t('imprint.title'),
            'description' => __t('imprint.intro'),
        ]));
    }

    public function privacy(Request $request): Response
    {
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
