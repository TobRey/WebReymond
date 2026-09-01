<?php

declare(strict_types=1);

namespace WebAtze\Ai;

use WebAtze\Ai\JsonSchema;
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

    /**
     * Der ganze Plan in einem Rutsch.
     *
     * Bleibt fuer den Uebungsmodus und die Tests. Im Betrieb geht die
     * Pipeline den Weg darunter - Seite fuer Seite, damit kein einzelner
     * Aufruf zu lange dauert.
     */
    public function plan(array $brief, string $oldSiteSummary = ''): array
    {
        if (!ClaudeClient::isConfigured()) {
            return self::sanitise(self::fallbackPlan($brief), $brief);
        }

        $plan = $this->planPages($brief, $oldSiteSummary);

        foreach ($plan['pages'] as $nummer => $seite) {
            $plan['pages'][$nummer]['sections'] = $this->planSections($brief, $seite);
        }

        return self::sanitise($plan, $brief);
    }

    /**
     * Der ganze Aufbau in einer einzigen Anfrage.
     *
     * Der Weg darunter - erst die Seitenliste, dann je Seite die
     * Abschnitte - ist verlaesslich, aber teuer: Bei elf Seiten sind das
     * zwoelf Anfragen fuer etwas, das eine leisten kann. Und jede
     * Anfrage ist eine Stelle, an der etwas schiefgehen kann.
     *
     * Das Schema ist dabei klein, denn hier stehen nur Typ, Vorlage und
     * Zweck je Abschnitt - nicht die Texte. Die Grenze, an der die
     * Grammatik zu gross wird, ist weit weg.
     *
     * Scheitert diese eine Anfrage, faellt der Bau auf den Weg Seite fuer
     * Seite zurueck. Der ist langsamer, aber er kommt an.
     *
     * @return array<string, mixed>|null null, wenn es nicht geklappt hat
     */
    public function planAllAtOnce(array $brief, string $oldSiteSummary = ''): ?array
    {
        if (!ClaudeClient::isConfigured()) {
            return null;
        }

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

        $message .= "\nHoechstens fuenf Abschnitte je Seite, Kopf und Fuss nicht "
            . "mitgezaehlt - lieber eine Seite mehr als eine ueberladene.";

        try {
            $antwort = $this->claude->structured(
                'plan',
                Prompts::planner(),
                $message,
                JsonSchema::sitePlan(),
                [
                    'model' => (string) \WebAtze\Core\Config::get('anthropic.model_plan', 'claude-opus-5'),
                    'effort' => 'low',
                    'max_tokens' => 8000,
                ]
            );
        } catch (\WebAtze\Core\ConfigurationError $e) {
            // Fehlendes Guthaben, falscher Schluessel: Warten hilft nicht,
            // und der Weg Seite fuer Seite scheitert genauso.
            throw $e;
        } catch (\Throwable $e) {
            Logger::warning('Aufbau in einem Zug fehlgeschlagen, es geht Seite fuer Seite weiter.', [
                'grund' => $e->getMessage(),
            ]);

            return null;
        }

        $seiten = array_values(array_filter(
            (array) ($antwort['pages'] ?? []),
            static fn (mixed $p): bool => is_array($p) && ($p['sections'] ?? []) !== []
        ));

        if ($seiten === []) {
            return null;
        }

        $antwort['pages'] = $seiten;

        return $antwort;
    }

    /**
     * Nur die Seitenliste - ohne Abschnitte.
     *
     * Frueher entstand der ganze Plan in einem Aufruf: zwoelf Seiten samt
     * allen Abschnitten, mit hohem Aufwand gerechnet. Bei einer
     * groesseren Website dauerte das fast eine Minute. Auf geteiltem
     * Hosting bricht der Server den Vorgang vorher ab - und ein Aufruf,
     * der nicht in das Zeitfenster passt, kommt nie zustande, so oft man
     * es auch versucht. Genau daran blieb der Bau haengen.
     */
    public function planPages(array $brief, string $oldSiteSummary = ''): array
    {
        if (!ClaudeClient::isConfigured()) {
            return self::fallbackPlan($brief);
        }

        $message = "AUFTRAG\n=======\n" . Brief::toPromptText($brief);

        if ($oldSiteSummary !== '') {
            $message .= "\n\nINHALTE DER BESTEHENDEN WEBSITE\n"
                . "===============================\n"
                . "Diese Texte dürfen als Grundlage dienen. Der Aufbau der alten\n"
                . "Seite ist ausdrücklich NICHT zu übernehmen.\n\n"
                . $oldSiteSummary;
        }

        $message .= "\n\nJETZT NUR DIE SEITEN\n====================\n"
            . "Welche Seiten braucht diese Website? Nur die Liste - welche\n"
            . "Abschnitte auf welche Seite gehoeren, wird danach einzeln gefragt.";

        $antwort = $this->claude->structured(
            'plan',
            Prompts::planner(),
            $message,
            JsonSchema::sitePages(),
            [
                'model' => (string) \WebAtze\Core\Config::get('anthropic.model_plan', 'claude-opus-5'),
                'effort' => 'medium',
                'max_tokens' => 3000,
            ]
        );

        $antwort['pages'] = array_values(array_filter(
            (array) ($antwort['pages'] ?? []),
            static fn (mixed $p): bool => is_array($p)
        ));

        return $antwort;
    }

    /**
     * Die Abschnitte einer einzelnen Seite.
     *
     * Ein kleiner, schneller Aufruf - und weil er je Seite einzeln
     * laeuft, kostet ein Abbruch hoechstens eine Seite statt des ganzen
     * Plans.
     *
     * @return array<int, array<string, mixed>>
     */
    public function planSections(array $brief, array $seite): array
    {
        // Ohne Schluessel den Standardaufbau - nicht eine leere Liste.
        // Leer hiesse: eine Seite ohne einen einzigen Abschnitt, und die
        // wird beim Bauen zu gar keiner Seite.
        if (!ClaudeClient::isConfigured()) {
            return self::fallbackSections($seite);
        }

        $message = "AUFTRAG\n=======\n" . Brief::toPromptText($brief)
            . "\n\nDIESE SEITE\n===========\n"
            . 'Adresse: ' . (string) ($seite['path'] ?? '/') . "\n"
            . 'Titel: ' . (string) ($seite['title'] ?? '') . "\n"
            . 'Zweck: ' . (string) ($seite['purpose'] ?? '') . "\n\n"
            . "VERFÜGBARE VORLAGEN JE TYP\n==========================\n";

        foreach (Schema::types() as $type) {
            $message .= $type . ': ' . Prompts::templateHints($type) . "\n";
        }

        $message .= "\nWelche Abschnitte gehoeren auf genau diese Seite, in welcher\n"
            . "Reihenfolge, mit welcher Vorlage?";

        $antwort = $this->claude->structured(
            'plan',
            Prompts::planner(),
            $message,
            JsonSchema::pageSections(),
            [
                'model' => (string) \WebAtze\Core\Config::get('anthropic.model_plan', 'claude-opus-5'),
                'effort' => 'low',
                'max_tokens' => 2500,
            ]
        );

        return array_values(array_filter(
            (array) ($antwort['sections'] ?? []),
            static fn (mixed $a): bool => is_array($a)
        ));
    }

    /**
     * Abschnitte fuer eine Seite, ohne die KI zu fragen.
     *
     * Der Rueckfall, wenn ein Aufruf nicht durchkommt. Er ist bewusst
     * unspektakulaer, aber vollstaendig: Kopf, ein Aufmacher, Inhalt,
     * ein Aufruf zum Handeln, Fuss. Eine Seite, die so entsteht, ist
     * unendlich viel besser als eine, die nie entsteht.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function fallbackSections(array $seite): array
    {
        $pfad = trim((string) ($seite['path'] ?? '/'), '/');

        // Rechtliches braucht keinen Aufmacher, sondern Text.
        if (in_array($pfad, ['impressum', 'datenschutz', 'agb'], true)) {
            return [
                ['type' => 'header', 'template' => 'links-rechts', 'purpose' => 'Navigation'],
                ['type' => 'text', 'template' => 'einspaltig', 'purpose' => 'Rechtliche Angaben'],
                ['type' => 'footer', 'template' => 'drei-spalten', 'purpose' => 'Abschluss'],
            ];
        }

        if ($pfad === 'kontakt') {
            return [
                ['type' => 'header', 'template' => 'links-rechts', 'purpose' => 'Navigation'],
                ['type' => 'contact', 'template' => 'formular-links', 'purpose' => 'Kontakt aufnehmen'],
                ['type' => 'footer', 'template' => 'drei-spalten', 'purpose' => 'Abschluss'],
            ];
        }

        if ($pfad === '') {
            return self::defaultHome([])['sections'];
        }

        return [
            ['type' => 'header', 'template' => 'links-rechts', 'purpose' => 'Navigation'],
            ['type' => 'hero', 'template' => 'bild-rechts', 'purpose' => 'Einstieg in das Thema'],
            ['type' => 'text', 'template' => 'einspaltig', 'purpose' => 'Der Inhalt dieser Seite'],
            ['type' => 'features', 'template' => 'raster-3', 'purpose' => 'Das Wichtigste in Kuerze'],
            ['type' => 'cta', 'template' => 'mittig', 'purpose' => 'Kontakt anstossen'],
            ['type' => 'footer', 'template' => 'drei-spalten', 'purpose' => 'Abschluss'],
        ];
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

        $pages = array_slice($pages, 0, 14);

        // Hier, an der einen Stelle, durch die jede Seite muss.
        //
        // Vorher stand die Kuerzung in cleanSections - und der Weg fuer
        // die Startseite ging daran vorbei. Genau so entstehen die
        // Faelle, die man beim Lesen nicht sieht: Eine Seite mit sechs
        // Abschnitten braucht zwei Anfragen fuers Schreiben und zwei je
        // Sprache fuers Uebersetzen. Bei drei Sprachen sind das sechs
        // Aufrufe statt drei - fuer eine einzige Seite.
        foreach ($pages as $nummer => $seite) {
            $pages[$nummer]['sections'] = self::passtInEineAnfrage(
                (array) ($seite['sections'] ?? [])
            );
        }

        return [
            'site_title' => mb_substr(trim((string) ($plan['site_title'] ?? ($brief['company_name'] ?? ''))), 0, 120),
            'meta_description' => mb_substr(trim((string) ($plan['meta_description'] ?? '')), 0, 200),
            'tone_note' => mb_substr(trim((string) ($plan['tone_note'] ?? '')), 0, 300),
            'pages' => $pages,
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

        // Auf so viele Abschnitte kuerzen, wie in EINE Anfrage passen.
        //
        // Das ist der Hebel gegen die Bauzeit. Eine Seite mit vierzehn
        // Abschnitten sprengt das Schema und wurde deshalb in zwei bis
        // drei Anfragen geschrieben - und beim Uebersetzen noch einmal je
        // Sprache. Aus einer Seite wurden so schnell zehn Aufrufe.
        //
        // Passt eine Seite in eine Anfrage, ist es einer je Seite und
        // einer je Sprache. Bei elf Seiten und drei Sprachen sind das
        // dreiunddreissig statt achtzig.
        //
        // Gemessen wird, nicht gezaehlt: Ein Kontaktabschnitt mit zehn
        // Feldern wiegt schwerer als eine Zahlenreihe mit zweien. Kopf
        // und Fuss bleiben immer stehen - ohne sie waere es keine Seite.
        return array_slice($clean, 0, 14);
    }

    /**
     * Die Liste so weit kuerzen, dass sie in eine einzige Anfrage passt.
     *
     * @param array<int, array<string, mixed>> $abschnitte
     * @return array<int, array<string, mixed>>
     */
    private static function passtInEineAnfrage(array $abschnitte): array
    {
        $abschnitte = array_slice($abschnitte, 0, 14);

        // Kopf und Fuss sind gesetzt; gekuerzt wird in der Mitte.
        while (count($abschnitte) > 3 && count(JsonSchema::chunkSections($abschnitte)) > 1) {
            // Den vorletzten entfernen - der Fuss soll bleiben.
            array_splice($abschnitte, count($abschnitte) - 2, 1);
        }

        return $abschnitte;
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
