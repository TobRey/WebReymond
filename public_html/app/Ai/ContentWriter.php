<?php

declare(strict_types=1);

namespace WebAtze\Ai;

use WebAtze\Core\{Config, Db, Logger};
use WebAtze\Domain\Brief;
use WebAtze\Templates\{Catalog, Icons, Schema};

/**
 * Schreibt die Texte einer Seite und legt Seite und Abschnitte an.
 *
 * Alles, was zurückkommt, wird gegen das Inhaltsschema geprüft. Ein
 * unbekanntes Feld fällt weg, ein zu langer Text wird gekürzt, ein
 * erfundenes Sinnbild wird verworfen. Erst danach geht es in die
 * Datenbank.
 */
final class ContentWriter
{
    private ClaudeClient $claude;

    public function __construct(private int $projectId, private int $jobId)
    {
        $this->claude = (new ClaudeClient())->forProject($projectId, $jobId);
    }

    /** Eine geplante Seite mit Inhalt füllen und speichern. */
    public function writePage(int $projectId, array $page, array $brief, string $oldSiteSummary = ''): int
    {
        $sections = $page['sections'] ?? [];

        $contents = ClaudeClient::isConfigured()
            ? $this->ask($page, $brief, $oldSiteSummary)
            : self::fallbackContent($page, $brief);

        $pageId = Db::insert('project_pages', [
            'project_id' => $projectId,
            'path' => (string) $page['path'],
            'title' => (string) $page['title'],
            'meta_description' => (string) ($page['meta_description'] ?? ''),
            'sort_order' => self::orderFor((string) $page['path']),
            'in_navigation' => !empty($page['in_navigation']) ? 1 : 0,
            'created_at' => Db::now(),
            'updated_at' => Db::now(),
        ]);

        foreach ($sections as $index => $section) {
            $type = (string) $section['type'];
            $raw = $contents[$index] ?? [];

            Db::insert('project_sections', [
                'project_id' => $projectId,
                'page_id' => $pageId,
                'type' => $type,
                'template_key' => (string) $section['template'],
                'content' => self::validate($type, $raw),
                'overrides' => [],
                'hidden' => 0,
                'sort_order' => $index,
                'created_at' => Db::now(),
                'updated_at' => Db::now(),
            ]);
        }

        return $pageId;
    }

    /** @return array<int,array> Inhalt je Abschnittsposition */
    private function ask(array $page, array $brief, string $oldSiteSummary): array
    {
        $sections = $page['sections'] ?? [];

        $list = [];
        foreach ($sections as $index => $section) {
            $definition = Schema::forType((string) $section['type']);
            // Der Feldname der Antwort steht dabei, damit nichts
            // verwechselt werden kann.
            $list[] = sprintf(
                "abschnitt_%d: %s (%s) – Vorlage: %s. Zweck: %s",
                $index,
                (string) $section['type'],
                $definition['label'] ?? '',
                Catalog::label((string) $section['type'], (string) $section['template']),
                (string) ($section['purpose'] ?? '')
            );
        }

        $message = "AUFTRAG\n=======\n" . Brief::toPromptText($brief)
            . "\n\nDIESE SEITE\n===========\n"
            . 'Titel: ' . (string) $page['title'] . "\n"
            . 'Adresse: ' . (string) $page['path'] . "\n\n"
            . "Abschnitte in dieser Reihenfolge:\n" . implode("\n", $list);

        if ($oldSiteSummary !== '') {
            $message .= "\n\nTEXTE DER BESTEHENDEN WEBSITE\n"
                . "=============================\n"
                . "Übernimm daraus, was passt, und formuliere es besser.\n\n"
                . $oldSiteSummary;
        }

        // Nicht die ganze Seite auf einmal.
        //
        // Am echten Dienst gemessen: Ab sechs Abschnitten wird die
        // Anfrage abgewiesen – "the compiled grammar is too large". Das
        // Schema wächst mit jedem Abschnitt, und irgendwann ist Schluss.
        //
        // Also in Gruppen. Der Systemteil ist bei jeder Gruppe derselbe
        // und wird von Anthropic zwischengespeichert; die zweite Anfrage
        // kostet deshalb nur einen Bruchteil der ersten.
        $gruppen = JsonSchema::chunkSections($sections);
        $byIndex = [];

        foreach ($gruppen as $nummer => $gruppe) {
            $teil = $message;

            if (count($gruppen) > 1) {
                $teil .= "\n\nJETZT SCHREIBEN\n===============\n"
                    . 'Nur diese Abschnitte, die übrigen kommen getrennt: '
                    . implode(', ', array_map(
                        static fn (int $i): string => 'abschnitt_' . $i,
                        array_keys($gruppe)
                    ));
            }

            $response = $this->claude->structured(
                'content',
                Prompts::writer(),
                $teil,
                JsonSchema::pageContent($gruppe),
                [
                    'model' => (string) Config::get('anthropic.model_content', 'claude-sonnet-5'),
                    'effort' => 'medium',
                    'max_tokens' => 16000,
                ]
            );

            // Die Antwort trägt ein Feld je Abschnitt: abschnitt_0,
            // abschnitt_1 … Die Nummer steht im Namen, deshalb braucht es
            // kein Zuordnen über ein mitgeliefertes "index", das auch
            // falsch sein konnte.
            foreach ((array) ($response['sections'] ?? []) as $key => $content) {
                if (!is_array($content) || !preg_match('/^abschnitt_(\d+)$/', (string) $key, $m)) {
                    continue;
                }

                $byIndex[(int) $m[1]] = $content;
            }
        }

        ksort($byIndex);

        return $byIndex;
    }

    // ------------------------------------------------------------------
    // Prüfung
    // ------------------------------------------------------------------

    /**
     * Inhalt gegen das Schema des Typs prüfen.
     *
     * Das ist die Stelle, an der die Website vor unbrauchbaren Antworten
     * geschützt wird – und zugleich die Stelle, an der eine Änderung
     * durch die KI später auf ihren Abschnitt beschränkt bleibt.
     */
    public static function validate(string $type, mixed $raw): array
    {
        $definition = Schema::forType($type);
        if ($definition === null || !is_array($raw)) {
            return [];
        }

        return self::validateFields($definition['fields'], $raw);
    }

    private static function validateFields(array $fields, array $raw): array
    {
        $clean = [];

        foreach ($fields as $name => $field) {
            if (!array_key_exists($name, $raw)) {
                continue;
            }

            $value = self::validateField($field, $raw[$name]);
            if ($value !== null) {
                $clean[$name] = $value;
            }
        }

        return $clean;
    }

    private static function validateField(array $field, mixed $value): mixed
    {
        $max = (int) ($field['max'] ?? 0);

        return match ($field['type']) {
            'text' => self::text($value, $max ?: 300),

            'textarea' => self::text($value, $max ?: 1200, true),

            'richtext' => is_string($value) ? mb_substr(trim($value), 0, $max ?: 12000) : null,

            'icon' => is_string($value) && Icons::exists($value) ? $value : '',

            'flag' => is_bool($value) ? $value : (bool) ($field['default'] ?? false),

            'number' => is_numeric($value)
                ? max((int) ($field['min'] ?? 0), min((int) ($field['max'] ?? 100), (int) $value))
                : null,

            'image' => is_array($value) ? [
                // src kommt niemals von der KI – Bilder setzt der Server ein.
                'src' => '',
                'alt' => self::text($value['alt'] ?? '', 200) ?? '',
            ] : null,

            'link' => self::link($value),

            'list' => self::list($field, $value),

            default => null,
        };
    }

    private static function text(mixed $value, int $max, bool $keepBreaks = false): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $text = (string) $value;
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Steuerzeichen raus; Zeilenumbrüche nur wo erlaubt
        $pattern = $keepBreaks ? '/[^\P{C}\n]/u' : '/[^\P{C}]/u';
        $text = preg_replace($pattern, $keepBreaks ? '' : ' ', $text) ?? $text;

        if (!$keepBreaks) {
            $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        }

        return mb_substr(trim($text), 0, $max);
    }

    private static function link(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $label = self::text($value['label'] ?? '', 60) ?? '';
        if ($label === '') {
            return ['label' => '', 'url' => ''];
        }

        $url = trim((string) ($value['url'] ?? ''));

        // Nur seiteninterne Ziele und die üblichen Protokolle.
        if ($url !== '' && !preg_match('#^(https?://|mailto:|tel:|/|\#)#i', $url)) {
            $url = preg_replace('/[^a-zA-Z0-9._\/#-]/', '', $url) ?? '';
        }

        return ['label' => $label, 'url' => mb_substr($url, 0, 300)];
    }

    private static function list(array $field, mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $itemFields = $field['item'] ?? [];
        $max = (int) ($field['max'] ?? 12);

        $items = [];
        foreach ($value as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $item = self::validateFields($itemFields, $entry);

            // Ein Eintrag ganz ohne Inhalt hilft niemandem.
            $hasContent = false;
            foreach ($item as $fieldValue) {
                if (is_string($fieldValue) && trim($fieldValue) !== '') {
                    $hasContent = true;
                    break;
                }
                if (is_array($fieldValue) && $fieldValue !== []) {
                    $hasContent = true;
                    break;
                }
            }

            if ($hasContent) {
                $items[] = $item;
            }
            if (count($items) >= $max) {
                break;
            }
        }

        return $items;
    }

    private static function orderFor(string $path): int
    {
        return match ($path) {
            '/' => 0,
            '/impressum' => 90,
            '/datenschutz' => 91,
            '/agb' => 92,
            default => 10,
        };
    }

    // ------------------------------------------------------------------
    // Übungsmodus
    // ------------------------------------------------------------------

    /**
     * Beispielinhalte ohne KI.
     *
     * Damit lässt sich der ganze Ablauf prüfen, ohne einen Schlüssel zu
     * hinterlegen. Die Texte sind erkennbar Platzhalter – niemand soll
     * sie versehentlich für fertig halten.
     */
    public static function fallbackContent(array $page, array $brief): array
    {
        $name = (string) ($brief['company_name'] ?? 'Ihre Firma');
        $slogan = (string) ($brief['slogan'] ?? '');
        $industry = (string) ($brief['industry'] ?? '');
        $description = (string) ($brief['description'] ?? '');

        $out = [];

        foreach ($page['sections'] ?? [] as $index => $section) {
            $type = (string) $section['type'];

            $out[$index] = match ($type) {
                'header' => [
                    'brand' => $name,
                    'cta' => ['label' => 'Kontakt', 'url' => 'kontakt.html'],
                ],
                'hero' => [
                    'eyebrow' => $industry,
                    'title' => $slogan !== '' ? $slogan : $name,
                    'lead' => mb_substr($description, 0, 220),
                    'cta' => ['label' => 'Kontakt aufnehmen', 'url' => 'kontakt.html'],
                    'cta_secondary' => ['label' => 'Mehr erfahren', 'url' => 'leistungen.html'],
                    'image' => ['src' => '', 'alt' => 'Aufmacherbild'],
                    'badges' => [['text' => 'Platzhalter'], ['text' => 'Übungsmodus']],
                ],
                'features', 'services' => [
                    'eyebrow' => 'Übungsmodus',
                    'title' => 'Hier stehen später die Leistungen',
                    // Bewusst ohne den Namen des Dienstes: Dieser Text
                    // steht in einer Datei, die auf einer Kundenwebsite
                    // landen könnte. Was dort steht, verrät nicht, wie
                    // sie entsteht – auch nicht im Übungsmodus.
                    'lead' => 'Diese Texte werden noch geschrieben.',
                    'items' => array_map(
                        static fn (int $i): array => [
                            'icon' => ['check', 'star', 'shield', 'bolt', 'heart', 'tools'][$i] ?? 'check',
                            'title' => 'Beispielleistung ' . ($i + 1),
                            'text' => 'Platzhaltertext. Hier beschreibt die fertige Website, was angeboten wird.',
                        ],
                        range(0, 2)
                    ),
                ],
                'about' => [
                    'eyebrow' => 'Über uns',
                    'title' => 'Hier steht später die Geschichte',
                    'body' => $description !== '' ? $description : 'Platzhaltertext im Übungsmodus.',
                    'image' => ['src' => '', 'alt' => 'Bild der Firma'],
                ],
                'process' => [
                    'title' => 'So läuft es ab',
                    'items' => array_map(
                        static fn (int $i): array => [
                            'title' => 'Schritt ' . ($i + 1),
                            'text' => 'Platzhaltertext für diesen Schritt.',
                        ],
                        range(0, 3)
                    ),
                ],
                'faq' => [
                    'title' => 'Häufige Fragen',
                    'items' => array_map(
                        static fn (int $i): array => [
                            'question' => 'Beispielfrage ' . ($i + 1) . '?',
                            'answer' => 'Platzhalterantwort im Übungsmodus.',
                        ],
                        range(0, 2)
                    ),
                ],
                'stats' => [
                    'items' => [
                        ['value' => '—', 'label' => 'Platzhalter'],
                        ['value' => '—', 'label' => 'Platzhalter'],
                        ['value' => '—', 'label' => 'Platzhalter'],
                    ],
                ],
                'cta' => [
                    'title' => 'Jetzt Kontakt aufnehmen',
                    'lead' => 'Platzhaltertext im Übungsmodus.',
                    'cta' => ['label' => 'Kontakt', 'url' => 'kontakt.html'],
                ],
                'contact' => [
                    'title' => 'Kontakt',
                    'lead' => 'So erreichen Sie uns.',
                    'email' => (string) ($brief['contact_email'] ?? ''),
                    'phone' => (string) ($brief['contact_phone'] ?? ''),
                    'address' => (string) ($brief['contact_address'] ?? ''),
                    'show_form' => true,
                ],
                'text' => [
                    'title' => (string) $page['title'],
                    'body' => "Dieser Text wird noch geschrieben.\n\nIm Übungsmodus stehen hier nur Platzhalter.",
                ],
                'footer' => [
                    'about' => mb_substr($description, 0, 160),
                    'email' => (string) ($brief['contact_email'] ?? ''),
                    'phone' => (string) ($brief['contact_phone'] ?? ''),
                    'address' => (string) ($brief['contact_address'] ?? ''),
                ],
                default => [
                    'title' => 'Platzhalter',
                    'items' => [['title' => 'Platzhalter', 'text' => 'Übungsmodus.']],
                ],
            };
        }

        return $out;
    }
}
