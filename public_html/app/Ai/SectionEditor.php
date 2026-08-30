<?php

declare(strict_types=1);

namespace WebAtze\Ai;

use RuntimeException;
use WebAtze\Core\{Config, Db, Logger, Session};
use WebAtze\Templates\{Catalog, Renderer, Schema};

/**
 * Ändert einen einzelnen Abschnitt nach einer Anweisung in normaler Sprache.
 *
 * Warum das den Rest der Website garantiert nicht berührt:
 * Die KI bekommt nur diesen einen Abschnitt zu sehen und liefert nur
 * Felder zurück, die im Schema dieses Typs stehen. Gespeichert wird
 * ausschliesslich in der einen Zeile der Datenbank. Es gibt keinen Weg,
 * über den eine Anweisung an einer anderen Stelle wirken könnte – das
 * ist keine Frage guter Formulierung, sondern des Aufbaus.
 *
 * Jede Änderung wird protokolliert und lässt sich zurücknehmen.
 */
final class SectionEditor
{
    private ClaudeClient $claude;

    public function __construct(private int $projectId, private ?int $jobId = null)
    {
        $this->claude = (new ClaudeClient())->forProject($projectId, $jobId);
    }

    /**
     * @param string $actor  wer die Änderung ausgelöst hat
     * @param string $source 'ai' (bei uns) oder 'assistant' (beim Kunden)
     * @return array{ok:bool, summary:string, changed:array, template:string, error:string}
     */
    public function apply(array $section, string $instruction, string $actor, string $source = 'ai'): array
    {
        $type = (string) $section['type'];
        $definition = Schema::forType($type);

        if ($definition === null) {
            return self::fail('Dieser Abschnittstyp ist unbekannt.');
        }

        $instruction = trim($instruction);
        if ($instruction === '') {
            return self::fail('Es wurde nicht gesagt, was geändert werden soll.');
        }
        if (mb_strlen($instruction) > 2000) {
            $instruction = mb_substr($instruction, 0, 2000);
        }

        if (!ClaudeClient::isConfigured()) {
            return self::fail(
                'Für Änderungen per Textbefehl wird ein Anthropic-Schlüssel gebraucht. '
                . 'Er gehört in app/config.php.'
            );
        }

        $before = is_array($section['content'] ?? null) ? $section['content'] : [];
        $beforeOverrides = is_array($section['overrides'] ?? null) ? $section['overrides'] : [];

        $response = $this->ask($type, (string) $section['template_key'], $before, $beforeOverrides, $instruction);

        $content = ContentWriter::validate($type, $response['content'] ?? []);
        if ($content === []) {
            return self::fail('Die Antwort war leer. Bitte die Anweisung anders formulieren.');
        }

        $overrides = self::validateOverrides($response['overrides'] ?? [], $beforeOverrides);
        $summary = mb_substr(trim((string) ($response['summary'] ?? '')), 0, 400);

        // Vorschlag für eine andere Vorlage – nur wenn es sie gibt.
        $template = (string) $section['template_key'];
        $suggested = trim((string) ($response['suggest_template'] ?? ''));
        if ($suggested !== '' && Catalog::exists($type, $suggested)) {
            $template = $suggested;
            $summary .= ' Vorlage gewechselt zu "' . Catalog::label($type, $suggested) . '".';
        }

        // Speichern – ausschliesslich diese eine Zeile.
        Db::update('project_sections', [
            'content' => $content,
            'overrides' => $overrides,
            'template_key' => $template,
            'updated_at' => Db::now(),
        ], 'id = :id AND project_id = :p', [
            'id' => (int) $section['id'],
            'p' => $this->projectId,
        ]);

        self::log(
            $this->projectId,
            (int) $section['id'],
            $source,
            $actor,
            $instruction,
            $summary !== '' ? $summary : 'Abschnitt angepasst.',
            ['content' => $content, 'overrides' => $overrides],
            ['content' => $before, 'overrides' => $beforeOverrides]
        );

        return [
            'ok' => true,
            'summary' => $summary !== '' ? $summary : 'Abschnitt angepasst.',
            'changed' => self::diffFields($before, $content),
            'template' => $template,
            'error' => '',
        ];
    }

    private function ask(string $type, string $template, array $content, array $overrides, string $instruction): array
    {
        $definition = Schema::forType($type);

        $message = sprintf(
            "ABSCHNITT\n=========\nTyp: %s (%s)\nVorlage: %s\n\n"
            . "AKTUELLER INHALT\n================\n%s\n\n"
            . "AKTUELLE DARSTELLUNGSWERTE\n==========================\n%s\n\n"
            . "ANWEISUNG\n=========\n%s\n\n"
            . "VERFÜGBARE VORLAGEN FÜR DIESEN TYP\n==================================\n%s",
            $type,
            $definition['label'],
            Catalog::label($type, $template),
            json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($overrides ?: new \stdClass(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            $instruction,
            Prompts::templateHints($type)
        );

        return $this->claude->structured(
            'section-edit',
            Prompts::editor(),
            $message,
            JsonSchema::sectionContent($type),
            [
                'model' => (string) Config::get('anthropic.model_content', 'claude-sonnet-5'),
                'effort' => 'medium',
                'max_tokens' => 8000,
            ]
        );
    }

    /**
     * Darstellungswerte prüfen.
     *
     * Hier landen Angaben wie "titleSize: 3rem". Sie wandern später
     * unverändert ins style-Attribut – deshalb kommt nur durch, was
     * Renderer::cssValue() als unbedenklich einstuft.
     */
    public static function validateOverrides(mixed $raw, array $previous = []): array
    {
        if (!is_array($raw)) {
            return $previous;
        }

        $clean = [];

        $spacing = (string) ($raw['spacing'] ?? '');
        if (in_array($spacing, ['tight', 'normal', 'loose'], true)) {
            $clean['spacing'] = $spacing;
        }

        $allowed = ['align', 'columns', 'gap', 'padding', 'titleSize', 'ctaScale', 'maxWidth', 'radius', 'imageRatio'];

        foreach (['desktop', 'tablet', 'mobile'] as $breakpoint) {
            $values = $raw[$breakpoint] ?? [];
            if (!is_array($values)) {
                continue;
            }

            $bucket = [];
            foreach ($allowed as $name) {
                $value = $values[$name] ?? '';
                if (!is_scalar($value)) {
                    continue;
                }
                $safe = Renderer::cssValue((string) $value);
                if ($safe !== '') {
                    $bucket[$name] = $safe;
                }
            }

            if ($bucket !== []) {
                $clean[$breakpoint] = $bucket;
            }
        }

        return $clean;
    }

    /** Welche Felder haben sich geändert? Für die Rückmeldung im Editor. */
    private static function diffFields(array $before, array $after): array
    {
        $changed = [];

        foreach ($after as $key => $value) {
            if (!array_key_exists($key, $before) || $before[$key] !== $value) {
                $changed[] = $key;
            }
        }
        foreach ($before as $key => $value) {
            if (!array_key_exists($key, $after)) {
                $changed[] = $key;
            }
        }

        return array_values(array_unique($changed));
    }

    /**
     * Änderung protokollieren.
     *
     * Der vorherige Stand wird mitgespeichert – nur so lässt sich eine
     * Änderung mit einem Klick zurücknehmen.
     */
    public static function log(
        int $projectId,
        int $sectionId,
        string $source,
        string $actor,
        string $instruction,
        string $summary,
        array $patch,
        array $before
    ): int {
        return Db::insert('section_changes', [
            'project_id' => $projectId,
            'section_id' => $sectionId,
            'source' => mb_substr($source, 0, 24),
            'actor' => mb_substr($actor, 0, 64),
            'instruction' => mb_substr($instruction, 0, 2000),
            'summary' => mb_substr($summary, 0, 500),
            'patch' => $patch,
            'before_state' => $before,
            'created_at' => Db::now(),
        ]);
    }

    /**
     * Wie die Änderung im Protokoll erscheint.
     *
     * Bei uns steht ausdrücklich, dass Claude sie gemacht hat. Beim
     * Kunden erscheint stattdessen der neutrale Name des Assistenten –
     * so wie es abgesprochen ist.
     */
    public static function actorLabel(string $source, string $actor): string
    {
        return match ($source) {
            'ai' => 'Von Claude angepasst',
            'assistant' => 'Angepasst durch den WebAtze-Assistent',
            'manual' => 'Von ' . ($actor !== '' ? $actor : 'Hand') . ' geändert',
            default => 'Angepasst',
        };
    }

    /** Eine Änderung zurücknehmen. */
    public static function revert(int $projectId, int $changeId, string $actor): array
    {
        $change = Db::first(
            'SELECT * FROM section_changes WHERE id = :id AND project_id = :p',
            ['id' => $changeId, 'p' => $projectId]
        );

        if ($change === null) {
            return self::fail('Diese Änderung gibt es nicht.');
        }

        $before = json_decode((string) $change['before_state'], true);
        if (!is_array($before) || !isset($before['content'])) {
            return self::fail('Von dieser Änderung gibt es keinen vorherigen Stand.');
        }

        $section = Db::first(
            'SELECT * FROM project_sections WHERE id = :id AND project_id = :p',
            ['id' => (int) $change['section_id'], 'p' => $projectId]
        );

        if ($section === null) {
            return self::fail('Der Abschnitt gibt es nicht mehr.');
        }

        $current = [
            'content' => json_decode((string) $section['content'], true) ?: [],
            'overrides' => json_decode((string) $section['overrides'], true) ?: [],
        ];

        Db::update('project_sections', [
            'content' => $before['content'],
            'overrides' => $before['overrides'] ?? [],
            'updated_at' => Db::now(),
        ], 'id = :id AND project_id = :p', [
            'id' => (int) $section['id'],
            'p' => $projectId,
        ]);

        self::log(
            $projectId,
            (int) $section['id'],
            'manual',
            $actor,
            '',
            'Änderung zurückgenommen.',
            $before,
            $current
        );

        return ['ok' => true, 'summary' => 'Änderung zurückgenommen.', 'changed' => [], 'template' => '', 'error' => ''];
    }

    private static function fail(string $message): array
    {
        return ['ok' => false, 'summary' => '', 'changed' => [], 'template' => '', 'error' => $message];
    }
}
