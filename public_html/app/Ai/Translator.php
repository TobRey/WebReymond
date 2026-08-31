<?php

declare(strict_types=1);

namespace WebAtze\Ai;

use WebAtze\Core\{Config, Db, Logger};
use WebAtze\Templates\Schema;

/**
 * Übersetzt eine fertige Seite in die weiteren Sprachen.
 *
 * In der Schweiz ist Mehrsprachigkeit oft Bedingung, nicht Kür. Das
 * Formular fragt sie ab – gebaut wurde bisher trotzdem nur eine Sprache.
 *
 * Übersetzt wird der geprüfte Inhalt, nicht das fertige HTML. Damit
 * gilt für die Übersetzung dieselbe Zusage wie fürs Original: Sie kann
 * nur die Felder füllen, die der Abschnittstyp kennt. Ein Modell, das
 * sich verrennt, kann die Seite nicht kaputtmachen – die Prüfung wirft
 * weg, was nicht ins Schema passt.
 *
 * Adressen, Telefonnummern und Eigennamen bleiben stehen. Eine
 * übersetzte Strasse wäre falsch, kein Dienst am Kunden.
 */
final class Translator
{
    /** Wie die Sprachen heissen – für die Anweisung und den Umschalter. */
    public const NAMES = [
        'de' => 'Deutsch',
        'en' => 'English',
        'fr' => 'Français',
        'it' => 'Italiano',
    ];

    private ClaudeClient $claude;
    private int $projectId;

    public function __construct(int $projectId, ?int $jobId = null)
    {
        $this->projectId = $projectId;
        $this->claude = (new ClaudeClient())->forProject($projectId, $jobId);
    }

    /**
     * Eine Seite in eine Sprache übersetzen.
     *
     * @return int Anzahl übersetzter Abschnitte
     */
    public function translatePage(int $pageId, string $target): int
    {
        $sections = Db::all(
            'SELECT * FROM project_sections WHERE page_id = :p ORDER BY sort_order ASC, id ASC',
            ['p' => $pageId]
        );

        if ($sections === [] || !isset(self::NAMES[$target])) {
            return 0;
        }

        $page = Db::first('SELECT * FROM project_pages WHERE id = :id', ['id' => $pageId]);

        if ($page === null) {
            return 0;
        }

        // Ohne Schlüssel wird nicht übersetzt, sondern der Originaltext
        // übernommen. Eine Seite mit fehlenden Texten wäre schlechter als
        // eine einsprachige.
        if (!ClaudeClient::isConfigured()) {
            return $this->copyOriginal($sections, $page, $target);
        }

        try {
            $translated = $this->ask($sections, $page, $target);
        } catch (\Throwable $e) {
            Logger::warning('Übersetzung fehlgeschlagen: ' . $e->getMessage(), [
                'projekt' => $this->projectId,
                'seite' => $pageId,
                'sprache' => $target,
            ]);
            return $this->copyOriginal($sections, $page, $target);
        }

        $count = 0;

        foreach ($sections as $index => $section) {
            // Ein Feld je Abschnitt, benannt nach seiner Nummer.
            $raw = $translated['sections']['abschnitt_' . (int) $index] ?? null;

            if (!is_array($raw)) {
                continue;
            }

            // Dieselbe Prüfung wie beim Original: Was der Typ nicht
            // kennt, kommt nicht hinein.
            $clean = ContentWriter::validate((string) $section['type'], $raw);

            self::store((int) $section['id'], $target, $clean);
            $count++;
        }

        // Titel und Kurzbeschreibung der Seite gehören ebenfalls übersetzt.
        self::storePage($pageId, $target, [
            'title' => mb_substr(trim((string) ($translated['page']['title'] ?? $page['title'])), 0, 120),
            'meta_description' => mb_substr(
                trim((string) ($translated['page']['meta_description'] ?? $page['meta_description'])),
                0,
                300
            ),
        ]);

        return $count;
    }

    // ------------------------------------------------------------------

    private function ask(array $sections, array $page, string $target): array
    {
        // Wie beim Schreiben: nicht die ganze Seite auf einmal. Ab etwa
        // sechs Abschnitten weist der Dienst die Anfrage ab, weil das
        // Schema zu gross wird. Siehe JsonSchema::chunkSections().
        $gruppen = JsonSchema::chunkSections($sections);

        $ergebnis = ['page' => [], 'sections' => []];

        foreach ($gruppen as $gruppe) {
            // Dieselben Feldnamen wie in der erwarteten Antwort. So muss
            // das Modell nichts zuordnen – es füllt aus, was es sieht.
            $payload = [];

            foreach ($gruppe as $index => $section) {
                $payload['abschnitt_' . (int) $index] = [
                    'typ' => (string) $section['type'],
                    'inhalt' => json_decode((string) $section['content'], true) ?: [],
                ];
            }

            $message = sprintf(
                "ZIELSPRACHE\n===========\n%s\n\n"
                . "SEITE\n=====\nTitel: %s\nKurzbeschreibung: %s\n\n"
                . "INHALTE\n=======\n%s",
                self::NAMES[$target],
                (string) $page['title'],
                (string) ($page['meta_description'] ?? ''),
                json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );

            $teil = $this->claude->structured(
                'translate',
                Prompts::translator(self::NAMES[$target]),
                $message,
                JsonSchema::translation($gruppe),
                [
                    'model' => (string) Config::get('anthropic.model_content', 'claude-sonnet-5'),
                    'effort' => 'low',
                    'max_tokens' => 16000,
                ]
            );

            // Titel und Kurzbeschreibung nur aus der ersten Gruppe – sie
            // gehören zur Seite, nicht zum Abschnitt.
            if ($ergebnis['page'] === []) {
                $ergebnis['page'] = (array) ($teil['page'] ?? []);
            }

            foreach ((array) ($teil['sections'] ?? []) as $schlüssel => $inhalt) {
                if (is_array($inhalt)) {
                    $ergebnis['sections'][$schlüssel] = $inhalt;
                }
            }
        }

        return $ergebnis;
    }

    /**
     * Ohne Übersetzung: den Originaltext übernehmen.
     *
     * Damit steht die Seite in jeder Sprache vollständig da. Dass sie
     * nicht übersetzt ist, sieht man sofort – besser als eine Seite mit
     * Lücken, bei der man rätselt, ob etwas kaputt ist.
     */
    private function copyOriginal(array $sections, array $page, string $target): int
    {
        foreach ($sections as $section) {
            self::store(
                (int) $section['id'],
                $target,
                json_decode((string) $section['content'], true) ?: []
            );
        }

        self::storePage((int) $page['id'], $target, [
            'title' => (string) $page['title'],
            'meta_description' => (string) ($page['meta_description'] ?? ''),
        ]);

        return count($sections);
    }

    /** Eine Übersetzung ablegen, ohne die anderen anzurühren. */
    public static function store(int $sectionId, string $locale, array $content): void
    {
        $row = Db::first('SELECT translations FROM project_sections WHERE id = :id', ['id' => $sectionId]);

        $all = json_decode((string) ($row['translations'] ?? '{}'), true);
        $all = is_array($all) ? $all : [];
        $all[$locale] = $content;

        Db::update('project_sections', [
            'translations' => $all,
            'updated_at' => Db::now(),
        ], 'id = :id', ['id' => $sectionId]);
    }

    public static function storePage(int $pageId, string $locale, array $values): void
    {
        $row = Db::first('SELECT translations FROM project_pages WHERE id = :id', ['id' => $pageId]);

        $all = json_decode((string) ($row['translations'] ?? '{}'), true);
        $all = is_array($all) ? $all : [];
        $all[$locale] = $values;

        Db::update('project_pages', [
            'translations' => $all,
            'updated_at' => Db::now(),
        ], 'id = :id', ['id' => $pageId]);
    }

    /**
     * Die Sprachen eines Projekts, Hauptsprache zuerst.
     *
     * @return string[]
     */
    public static function localesOf(array $project): array
    {
        $raw = (string) ($project['locales'] ?? $project['locale'] ?? 'de');

        $out = [];
        foreach (explode(',', $raw) as $code) {
            $code = trim($code);
            if ($code !== '' && isset(self::NAMES[$code]) && !in_array($code, $out, true)) {
                $out[] = $code;
            }
        }

        return $out === [] ? ['de'] : $out;
    }

    /** Welche Sprachen müssen übersetzt werden? (alle ausser der ersten) */
    public static function extraLocales(array $project): array
    {
        return array_slice(self::localesOf($project), 1);
    }
}
