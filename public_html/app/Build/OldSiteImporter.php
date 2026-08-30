<?php

declare(strict_types=1);

namespace WebAtze\Build;

use DOMDocument;
use DOMElement;
use DOMXPath;
use WebAtze\Core\{Db, Http, Logger};

/**
 * Liest eine bestehende Website aus.
 *
 * Zweck: Wenn ein Kunde schon eine Website hat, soll er seine Texte und
 * Bilder nicht neu heraussuchen müssen. Der Aufbau der alten Seite wird
 * dabei ausdrücklich NICHT übernommen – die neue Website entsteht von
 * Grund auf neu, nur mit dem alten Material.
 *
 * Sicherheit: Jede Adresse läuft über Http::get(), das vorher prüft, ob
 * sie wirklich ins offene Netz zeigt. Damit lässt sich das Feld "alte
 * Website" nicht missbrauchen, um interne Adressen des Servers abzurufen.
 */
final class OldSiteImporter
{
    private const MAX_PAGES = 12;
    private const MAX_IMAGES = 40;
    private const MAX_IMAGE_BYTES = 6_000_000;

    private string $startUrl;
    private string $host;
    private array $visited = [];
    private array $queue = [];

    public function __construct(string $startUrl)
    {
        $this->startUrl = $startUrl;
        $this->host = (string) (parse_url($startUrl, PHP_URL_HOST) ?: '');
        $this->queue[] = $startUrl;
    }

    /**
     * Die alte Website durchgehen.
     *
     * @param float $budgetSeconds wie lange höchstens gearbeitet werden darf
     * @return array{pages:array, images:array, colors:array, errors:array, done:bool}
     */
    public function crawl(float $budgetSeconds = 40.0): array
    {
        $started = microtime(true);

        $pages = [];
        $images = [];
        $errors = [];
        $colors = [];

        $robots = $this->robotsRules();

        while ($this->queue !== [] && count($pages) < self::MAX_PAGES) {
            if (microtime(true) - $started > $budgetSeconds) {
                break;
            }

            $url = array_shift($this->queue);
            $key = self::normalise($url);

            if (isset($this->visited[$key])) {
                continue;
            }
            $this->visited[$key] = true;

            if (self::isDisallowed($url, $robots)) {
                continue;
            }

            $response = Http::get($url, 12);

            if (!$response['ok']) {
                $errors[] = sprintf('%s: %s', $url, $response['error'] ?: 'HTTP ' . $response['status']);
                continue;
            }

            $type = $response['headers']['content-type'] ?? '';
            if ($type !== '' && !str_contains($type, 'html')) {
                continue;
            }

            $page = $this->parse($response['body'], $response['url']);
            if ($page === null) {
                continue;
            }

            $pages[] = $page;
            $images = array_merge($images, $page['images']);
            $colors = array_merge($colors, $page['colors']);

            foreach ($page['links'] as $link) {
                if (count($this->queue) + count($pages) < self::MAX_PAGES * 2) {
                    $this->queue[] = $link;
                }
            }
        }

        return [
            'pages' => $pages,
            'images' => array_slice(self::uniqueImages($images), 0, self::MAX_IMAGES),
            'colors' => self::rankColors($colors),
            'errors' => $errors,
            'done' => $this->queue === [] || count($pages) >= self::MAX_PAGES,
        ];
    }

    /** Eine Seite auseinandernehmen. */
    private function parse(string $html, string $url): ?array
    {
        if (trim($html) === '') {
            return null;
        }

        $document = new DOMDocument();

        // Fremdes HTML ist selten fehlerfrei. Die Warnungen interessieren
        // nicht – der Parser kommt auch mit kaputtem Markup zurecht.
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return null;
        }

        $xpath = new DOMXPath($document);

        // Was nie Inhalt ist, fliegt zuerst raus.
        foreach ($xpath->query('//script | //style | //noscript | //svg | //iframe') ?: [] as $node) {
            $node->parentNode?->removeChild($node);
        }

        $title = self::firstText($xpath, '//title');
        $description = self::attribute($xpath, '//meta[@name="description"]', 'content');

        $headings = [];
        foreach (['h1', 'h2', 'h3'] as $tag) {
            foreach ($xpath->query('//' . $tag) ?: [] as $node) {
                $text = self::clean($node->textContent);
                if ($text !== '' && mb_strlen($text) < 200) {
                    $headings[] = ['level' => $tag, 'text' => $text];
                }
            }
        }

        $paragraphs = [];
        foreach ($xpath->query('//p | //li') ?: [] as $node) {
            $text = self::clean($node->textContent);
            // Zu kurze Schnipsel sind meist Navigation oder Beiwerk.
            if (mb_strlen($text) >= 40 && mb_strlen($text) <= 1200) {
                $paragraphs[] = $text;
            }
        }

        $images = [];
        foreach ($xpath->query('//img') ?: [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $src = trim($node->getAttribute('src'));
            if ($src === '' || str_starts_with($src, 'data:')) {
                continue;
            }
            $absolute = Http::resolveUrl($url, $src);

            // Kleine Grafiken sind fast immer Sinnbilder oder Zählpixel.
            $width = (int) $node->getAttribute('width');
            if ($width > 0 && $width < 120) {
                continue;
            }

            $images[] = [
                'url' => $absolute,
                'alt' => self::clean($node->getAttribute('alt')),
                'from' => $url,
            ];
        }

        $links = [];
        foreach ($xpath->query('//a[@href]') ?: [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $href = trim($node->getAttribute('href'));
            if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'mailto:')
                || str_starts_with($href, 'tel:') || str_starts_with($href, 'javascript:')) {
                continue;
            }

            $absolute = Http::resolveUrl($url, $href);

            // Nur innerhalb derselben Domain weitergehen.
            if (parse_url($absolute, PHP_URL_HOST) !== $this->host) {
                continue;
            }
            // Dateien überspringen
            if (preg_match('/\.(pdf|zip|docx?|xlsx?|jpe?g|png|gif|webp|svg|mp4|mp3)($|\?)/i', $absolute)) {
                continue;
            }

            $links[] = $absolute;
        }

        return [
            'url' => $url,
            'title' => $title,
            'description' => $description,
            'headings' => array_slice($headings, 0, 25),
            'paragraphs' => array_slice($paragraphs, 0, 40),
            'images' => $images,
            'links' => array_values(array_unique($links)),
            'colors' => self::extractColors($html),
        ];
    }

    /**
     * Bilder herunterladen und im Projektordner ablegen.
     *
     * Jedes Bild wird neu kodiert. Das entfernt eingebettete Zusatzdaten
     * und stellt sicher, dass wirklich ein Bild vorliegt – eine als .jpg
     * getarnte Datei überlebt das nicht.
     */
    public static function fetchImages(array $images, int $projectId, string $slug, int $limit = 24): int
    {
        $dir = ensure_dir(STORAGE_DIR . '/projects/' . $slug . '/assets');
        $saved = 0;

        foreach (array_slice($images, 0, $limit) as $image) {
            $url = (string) ($image['url'] ?? '');
            if ($url === '') {
                continue;
            }

            $response = Http::get($url, 15);
            if (!$response['ok'] || $response['body'] === '') {
                continue;
            }
            if (strlen($response['body']) > self::MAX_IMAGE_BYTES) {
                continue;
            }

            $result = ImageStore::storeBinary($response['body'], $dir, (string) ($image['alt'] ?? ''));
            if ($result === null) {
                continue;
            }

            Db::insert('project_assets', [
                'project_id' => $projectId,
                'kind' => 'image',
                'path' => $result['name'],
                'original_name' => basename(parse_url($url, PHP_URL_PATH) ?: 'bild'),
                'alt' => (string) ($image['alt'] ?? ''),
                'width' => $result['width'],
                'height' => $result['height'],
                'bytes' => $result['bytes'],
                'source_url' => mb_substr($url, 0, 500),
                'created_at' => Db::now(),
            ]);

            $saved++;
        }

        return $saved;
    }

    /** Das Wichtigste in Textform, für die Anweisung an die KI. */
    public static function summarise(array $crawl, int $maxChars = 14000): string
    {
        $parts = [];

        foreach ($crawl['pages'] ?? [] as $page) {
            $block = ['--- ' . $page['url']];

            if ($page['title'] !== '') {
                $block[] = 'Titel: ' . $page['title'];
            }
            if ($page['description'] !== '') {
                $block[] = 'Beschreibung: ' . $page['description'];
            }
            foreach (array_slice($page['headings'], 0, 12) as $heading) {
                $block[] = strtoupper($heading['level']) . ': ' . $heading['text'];
            }
            foreach (array_slice($page['paragraphs'], 0, 12) as $paragraph) {
                $block[] = $paragraph;
            }

            $parts[] = implode("\n", $block);
        }

        $text = implode("\n\n", $parts);

        return mb_strlen($text) > $maxChars
            ? mb_substr($text, 0, $maxChars) . "\n\n[gekürzt]"
            : $text;
    }

    // ------------------------------------------------------------------

    /** Farben aus dem Quelltext einsammeln. */
    private static function extractColors(string $html): array
    {
        $found = [];

        if (preg_match_all('/#([0-9a-fA-F]{6})\b/', $html, $matches)) {
            foreach ($matches[1] as $hex) {
                $found[] = '#' . strtolower($hex);
            }
        }
        if (preg_match_all('/rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $found[] = Theme::hex((int) $m[1], (int) $m[2], (int) $m[3]);
            }
        }

        return $found;
    }

    /**
     * Die häufigsten kräftigen Farben ermitteln.
     *
     * Grau, Schwarz und Weiss fallen weg – sie sagen nichts über die
     * Marke aus und kommen auf jeder Website vor.
     */
    private static function rankColors(array $colors): array
    {
        $counts = array_count_values($colors);
        arsort($counts);

        $result = [];

        foreach ($counts as $hex => $count) {
            [$r, $g, $b] = Theme::rgb($hex);

            $max = max($r, $g, $b);
            $min = min($r, $g, $b);
            $saturation = $max === 0 ? 0 : ($max - $min) / $max;

            // Zu blass, zu hell oder zu dunkel: keine Markenfarbe.
            if ($saturation < 0.25 || $max < 40 || $min > 225) {
                continue;
            }

            $result[] = ['hex' => $hex, 'count' => $count];

            if (count($result) >= 6) {
                break;
            }
        }

        return $result;
    }

    /** robots.txt lesen; im Zweifel erlauben. */
    private function robotsRules(): array
    {
        $scheme = parse_url($this->startUrl, PHP_URL_SCHEME) ?: 'https';
        $response = Http::get($scheme . '://' . $this->host . '/robots.txt', 6);

        if (!$response['ok']) {
            return [];
        }

        $rules = [];
        $applies = false;

        foreach (preg_split('/\R/', $response['body']) ?: [] as $line) {
            $line = trim($line);

            if (stripos($line, 'user-agent:') === 0) {
                $agent = trim(substr($line, 11));
                $applies = $agent === '*';
                continue;
            }
            if ($applies && stripos($line, 'disallow:') === 0) {
                $path = trim(substr($line, 9));
                if ($path !== '') {
                    $rules[] = $path;
                }
            }
        }

        return $rules;
    }

    private static function isDisallowed(string $url, array $rules): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '/';

        foreach ($rules as $rule) {
            if ($rule === '/' || str_starts_with($path, $rule)) {
                return true;
            }
        }
        return false;
    }

    private static function normalise(string $url): string
    {
        $url = strtok($url, '#') ?: $url;
        return rtrim($url, '/');
    }

    private static function uniqueImages(array $images): array
    {
        $seen = [];
        $out = [];

        foreach ($images as $image) {
            $url = (string) ($image['url'] ?? '');
            if ($url === '' || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $out[] = $image;
        }

        return $out;
    }

    private static function firstText(DOMXPath $xpath, string $query): string
    {
        $nodes = $xpath->query($query);
        return $nodes !== false && $nodes->length > 0 ? self::clean($nodes->item(0)->textContent) : '';
    }

    private static function attribute(DOMXPath $xpath, string $query, string $name): string
    {
        $nodes = $xpath->query($query);
        if ($nodes === false || $nodes->length === 0) {
            return '';
        }
        $node = $nodes->item(0);
        return $node instanceof DOMElement ? self::clean($node->getAttribute($name)) : '';
    }

    private static function clean(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }
}
