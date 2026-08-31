<?php

declare(strict_types=1);

namespace WebAtzeKit;

use WebAtze\Templates\Page;

/**
 * Baut die Website neu, nachdem der Kunde etwas geändert hat.
 *
 * Die Website besteht aus fertigen HTML-Dateien – deshalb ist sie
 * schnell, deshalb läuft sie überall, und deshalb muss sie nach jeder
 * Änderung neu geschrieben werden.
 *
 * Gebaut wird mit demselben Rahmen wie bei WebAtze (Templates\Page).
 * Gäbe es hier eine zweite Fassung davon, sähe die Seite nach dem ersten
 * Kundenklick anders aus als vorher.
 */
final class Publisher
{
    private Store $store;
    private string $siteDir;

    public function __construct(Store $store, string $siteDir)
    {
        $this->store = $store;
        $this->siteDir = rtrim($siteDir, '/');
    }

    /**
     * Alle Seiten neu schreiben.
     *
     * @return array{ok:bool, pages:int, error:string}
     */
    public function publish(): array
    {
        $site = $this->store->all();
        $pages = $site['pages'] ?? [];

        if ($pages === []) {
            return ['ok' => false, 'pages' => 0, 'error' => 'Es sind keine Seiten hinterlegt.'];
        }

        $critical = (string) ($site['critical_css'] ?? '');
        $written = 0;

        // Erst alles bauen, dann alles schreiben. Bricht das Bauen einer
        // Seite ab, bleibt die Website unangetastet statt halb erneuert.
        $rendered = [];

        foreach ($pages as $page) {
            try {
                $html = Page::render($site, $page, $critical);
            } catch (\Throwable $e) {
                return [
                    'ok' => false,
                    'pages' => 0,
                    'error' => 'Die Seite "' . (string) ($page['title'] ?? '?')
                        . '" liess sich nicht bauen: ' . $e->getMessage(),
                ];
            }

            $rendered[Page::fileNameFor((string) ($page['path'] ?? '/'))] = $html;
        }

        foreach ($rendered as $name => $html) {
            if (Store::writeAtomic($this->siteDir . '/' . $name, $html)) {
                $written++;
            }
        }

        $this->writeSitemap($pages, (string) ($site['domain'] ?? ''));

        return ['ok' => $written > 0, 'pages' => $written, 'error' => ''];
    }

    /** Nach einer Änderung an den Seiten gehört auch die Sitemap erneuert. */
    private function writeSitemap(array $pages, string $domain): void
    {
        $base = $domain !== '' ? 'https://' . $domain : '';

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($pages as $page) {
            $loc = $base . '/' . Page::fileNameFor((string) ($page['path'] ?? '/'));
            $xml .= '  <url><loc>'
                . htmlspecialchars($loc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</loc></url>' . "\n";
        }

        $xml .= '</urlset>' . "\n";

        Store::writeAtomic($this->siteDir . '/sitemap.xml', $xml);
    }
}
