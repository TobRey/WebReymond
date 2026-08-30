<?php

declare(strict_types=1);

namespace WebAtze\Ai;

use WebAtze\Core\Logger;
use WebAtze\Domain\Brief;
use WebAtze\Templates\{Catalog, Schema};

/**
 * Entscheidet, aus welchen Seiten und Abschnitten die Website besteht.
 *
 * Das Ergebnis wird streng nachgeprüft: Nur bekannte Abschnittstypen und
 * vorhandene Vorlagen kommen durch, jede Seite bekommt Kopf und Fuss,
 * Impressum und Datenschutz werden notfalls ergänzt. So kann ein
 * ungewöhnlicher Vorschlag der KI keine kaputte Website erzeugen.
 */
final class SitePlanner
{
    private ClaudeClient $claude;

    public function __construct(private int $projectId, private int $jobId)
    {
        $this->claude = (new ClaudeClient())->forProject($projectId, $jobId);
    }

    public function plan(array $brief, string $oldSiteSummary = ''): array
    {
        $plan = ClaudeClient::isConfigured()
            ? $this->ask($brief, $oldSiteSummary)
            : self::fallbackPlan($brief);

        return self::sanitise($plan, $brief);
    }

    private function ask(array $brief, string $oldSiteSummary): array
    {
        $message = "AUFTRAG\n=======\n" . Brief::toPromptText($brief);

        if ($oldSiteSummary !== '') {
            $message .= "\n\nINHALTE DER BESTEHENDEN WEBSITE\n"
                . "===============================\n"
                . "Diese Texte dürfen als Grundlage dienen. Der Aufbau der alten\n"
                . "Seite ist ausdrücklich NICHT zu übernehmen.\n\n"
                . $oldSiteSummary;
        }

        $message .= "\n\nVERFÜGBARE VORLAGEN JE TYP\n==========================\n";
        foreach (Schema::types() as $type) {
            $message .= $type . ': ' . Prompts::templateHints($type) . "\n";
        }

        return $this->claude->structured(
            'plan',
            Prompts::planner(),
            $message,
            JsonSchema::sitePlan(),
            [
                'model' => (string) \WebAtze\Core\Config::get('anthropic.model_plan', 'claude-opus-5'),
                'effort' => 'high',
                'max_tokens' => 8000,
            ]
        );
    }

    /**
     * Den Vorschlag in eine sichere Form bringen.
     *
     * Alles, was hier nicht durchkommt, würde später beim Rendern Fehler
     * verursachen. Lieber jetzt geradebiegen als später eine halbe Seite.
     */
    public static function sanitise(array $plan, array $brief): array
    {
        $pages = [];
        $seenPaths = [];

        foreach ($plan['pages'] ?? [] as $page) {
            if (!is_array($page)) {
                continue;
            }

            $path = self::cleanPath((string) ($page['path'] ?? ''));
            if (isset($seenPaths[$path])) {
                continue;
            }
            $seenPaths[$path] = true;

            $sections = self::cleanSections($page['sections'] ?? [], $path);
            if ($sections === []) {
                continue;
            }

            $pages[] = [
                'path' => $path,
                'title' => mb_substr(trim((string) ($page['title'] ?? 'Seite')), 0, 60) ?: 'Seite',
                'meta_description' => mb_substr(trim((string) ($page['meta_description'] ?? '')), 0, 200),
                'in_navigation' => !in_array($path, ['/impressum', '/datenschutz', '/agb'], true)
                    && ($page['in_navigation'] ?? true) !== false,
                'sections' => $sections,
            ];
        }

        // Ohne Startseite geht nichts.
        if (!isset($seenPaths['/'])) {
            array_unshift($pages, self::defaultHome($brief));
        }

        // Rechtliches ist Pflicht – notfalls ergänzen.
        foreach (['/impressum' => 'Impressum', '/datenschutz' => 'Datenschutz'] as $path => $title) {
            if (!isset($seenPaths[$path])) {
                $pages[] = [
                    'path' => $path,
                    'title' => $title,
                    'meta_description' => $title,
                    'in_navigation' => false,
                    'sections' => [
                        ['type' => 'header', 'template' => 'links-rechts', 'purpose' => 'Navigation'],
                        ['type' => 'text', 'template' => 'schmal', 'purpose' => $title],
                        ['type' => 'footer', 'template' => 'vier-spalten', 'purpose' => 'Abschluss'],
                    ],
                ];
            }
        }

        return [
            'site_title' => mb_substr(trim((string) ($plan['site_title'] ?? ($brief['company_name'] ?? ''))), 0, 120),
            'meta_description' => mb_substr(trim((string) ($plan['meta_description'] ?? '')), 0, 200),
            'tone_note' => mb_substr(trim((string) ($plan['tone_note'] ?? '')), 0, 300),
            'pages' => array_slice($pages, 0, 14),
        ];
    }

    /** @param mixed $sections */
    private static function cleanSections(mixed $sections, string $path): array
    {
        if (!is_array($sections)) {
            return [];
        }

        $clean = [];
        $previousType = '';

        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }

            $type = (string) ($section['type'] ?? '');
            if (!Schema::exists($type)) {
                continue;
            }
            // Kopf und Fuss kommen unten dazu – hier nicht doppelt.
            if ($type === 'header' || $type === 'footer') {
                continue;
            }
            // Denselben Typ nicht zweimal hintereinander.
            if ($type === $previousType) {
                continue;
            }

            $template = (string) ($section['template'] ?? '');
            if (!Catalog::exists($type, $template)) {
                $template = Catalog::defaultKey($type);
            }

            $clean[] = [
                'type' => $type,
                'template' => $template,
                'purpose' => mb_substr(trim((string) ($section['purpose'] ?? '')), 0, 250),
            ];

            $previousType = $type;
        }

        if ($clean === []) {
            return [];
        }

        // Jede Seite bekommt Kopf und Fuss – ohne wäre sie unbedienbar.
        array_unshift($clean, [
            'type' => 'header',
            'template' => Catalog::defaultKey('header'),
            'purpose' => 'Navigation und Aufruf zum Handeln',
        ]);
        $clean[] = [
            'type' => 'footer',
            'template' => Catalog::defaultKey('footer'),
            'purpose' => 'Abschluss mit Verweisen und Kontaktangaben',
        ];

        return array_slice($clean, 0, 14);
    }

    private static function cleanPath(string $path): string
    {
        $path = '/' . trim($path, '/');
        if ($path === '/') {
            return '/';
        }
        return '/' . str_slug($path, 60);
    }

    private static function defaultHome(array $brief): array
    {
        return [
            'path' => '/',
            'title' => 'Start',
            'meta_description' => mb_substr((string) ($brief['description'] ?? ''), 0, 160),
            'in_navigation' => true,
            'sections' => [
                ['type' => 'header', 'template' => 'links-rechts', 'purpose' => 'Navigation'],
                ['type' => 'hero', 'template' => 'bild-rechts', 'purpose' => 'Erster Eindruck'],
                ['type' => 'services', 'template' => 'raster-3', 'purpose' => 'Was angeboten wird'],
                ['type' => 'about', 'template' => 'bild-links', 'purpose' => 'Wer dahintersteht'],
                ['type' => 'cta', 'template' => 'mittig', 'purpose' => 'Kontakt anstossen'],
                ['type' => 'footer', 'template' => 'vier-spalten', 'purpose' => 'Abschluss'],
            ],
        ];
    }

    /**
     * Plan ohne KI.
     *
     * Greift, wenn kein Schlüssel hinterlegt ist. Damit lässt sich der
     * ganze Ablauf ausprobieren, ohne einen Rappen auszugeben – und die
     * automatischen Tests kommen ohne Netz aus.
     */
    public static function fallbackPlan(array $brief): array
    {
        $scope = (string) ($brief['scope'] ?? 'small');
        $name = (string) ($brief['company_name'] ?? 'Website');

        $home = [
            'path' => '/',
            'title' => 'Start',
            'meta_description' => mb_substr((string) ($brief['description'] ?? ''), 0, 160),
            'in_navigation' => true,
            'sections' => [
                ['type' => 'hero', 'template' => 'bild-rechts', 'purpose' => 'Erster Eindruck'],
                ['type' => 'features', 'template' => 'raster-3', 'purpose' => 'Vorteile'],
                ['type' => 'services', 'template' => 'raster-3', 'purpose' => 'Leistungen'],
                ['type' => 'about', 'template' => 'bild-links', 'purpose' => 'Über die Firma'],
                ['type' => 'process', 'template' => 'schritte-4', 'purpose' => 'Ablauf'],
                ['type' => 'faq', 'template' => 'aufklappen-erste', 'purpose' => 'Fragen'],
                ['type' => 'cta', 'template' => 'karte-verlauf', 'purpose' => 'Kontakt anstossen'],
            ],
        ];

        $pages = [$home];

        if ($scope !== 'onepager') {
            $pages[] = [
                'path' => '/leistungen', 'title' => 'Leistungen',
                'meta_description' => 'Leistungen von ' . $name,
                'in_navigation' => true,
                'sections' => [
                    ['type' => 'services', 'template' => 'abwechselnd', 'purpose' => 'Leistungen im Detail'],
                    ['type' => 'cta', 'template' => 'mittig', 'purpose' => 'Kontakt'],
                ],
            ];
            $pages[] = [
                'path' => '/ueber-uns', 'title' => 'Über uns',
                'meta_description' => 'Über ' . $name,
                'in_navigation' => true,
                'sections' => [
                    ['type' => 'about', 'template' => 'bild-rechts', 'purpose' => 'Die Geschichte'],
                    ['type' => 'stats', 'template' => 'reihe-3', 'purpose' => 'Zahlen'],
                ],
            ];
            $pages[] = [
                'path' => '/kontakt', 'title' => 'Kontakt',
                'meta_description' => 'Kontakt zu ' . $name,
                'in_navigation' => true,
                'sections' => [
                    ['type' => 'contact', 'template' => 'formular-rechts', 'purpose' => 'Kontaktaufnahme'],
                ],
            ];
        }

        return [
            'site_title' => $name,
            'meta_description' => mb_substr((string) ($brief['description'] ?? ''), 0, 160),
            'tone_note' => 'Übungsmodus ohne KI – Struktur nach Standardmuster.',
            'pages' => $pages,
        ];
    }
}
