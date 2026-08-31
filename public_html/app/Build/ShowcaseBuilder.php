<?php

declare(strict_types=1);

namespace WebAtze\Build;

use WebAtze\Ai\{ClaudeClient, JsonSchema, Prompts};
use WebAtze\Core\{Audit, Db, Logger};

/**
 * Trägt eine fertige Website automatisch in die Referenzen ein.
 *
 * Der Text entsteht aus dem Auftrag. Er erwähnt mit keinem Wort, wie die
 * Website entstanden ist – das ist ausdrücklich Teil der Anweisung an die
 * KI und wird hier zusätzlich nachgeprüft. Rutscht doch ein verräterisches
 * Wort durch, wird der Text verworfen und ein neutraler Ersatz genommen.
 *
 * Das Vorschaubild ist keine Fotografie, sondern die gespeicherte Kopie
 * der Website selbst, verkleinert in einem Browser-Rahmen. Es kostet
 * nichts, braucht keinen fremden Dienst und ist immer aktuell.
 */
final class ShowcaseBuilder
{
    /** Wörter, die im Referenztext nichts zu suchen haben. */
    /**
     * Wörter, die für sich stehen müssen.
     *
     * "ki" als blosse Zeichenfolge zu suchen, war ein Fehler: Damit fiel
     * jeder Text über einen Kindergarten oder ein Kino durch, und der
     * Referenztext wurde stillschweigend verworfen. Geprüft wird deshalb
     * auf ganze Wörter.
     */
    private const FORBIDDEN_WORDS = [
        'ki', 'ai', 'gpt', 'llm', 'chatgpt', 'claude', 'anthropic',
        'bot', 'prompt', 'prompts',
    ];

    /**
     * Wortteile, die auch mitten im Wort verräterisch sind.
     *
     * Deutsch beugt: "künstliche", "künstlicher", "künstlichen". Gesucht
     * wird deshalb der Stamm, nicht die Wörterbuchform.
     */
    private const FORBIDDEN_PARTS = [
        'künstlich', 'kuenstlich', 'intelligenz', 'sprachmodell',
        'automatisch generiert', 'automatisiert erstellt', 'automatisch erstellt',
        'generator', 'generiert', 'generierung',
        'vorlage verwendet', 'template', 'baukasten',
        'in minuten erstellt', 'in wenigen minuten', 'per knopfdruck',
        'artificial intelligence', 'machine learning', 'neuronale',
    ];

    public static function create(array $project, bool $publish = true): array
    {
        $projectId = (int) $project['id'];

        $existing = Db::first('SELECT * FROM showcase WHERE project_id = :p', ['p' => $projectId]);
        $brief = json_decode((string) $project['brief'], true) ?: [];
        $theme = json_decode((string) $project['theme'], true) ?: [];

        $texts = self::texts($project, $brief);

        $slug = $existing !== null
            ? (string) $existing['slug']
            : self::uniqueSlug((string) $project['name']);

        $values = [
            'project_id' => $projectId,
            'slug' => $slug,
            'title' => (string) $project['name'],
            'subtitle' => $texts['subtitle'],
            'body_de' => $texts['body_de'],
            'body_en' => $texts['body_en'],
            'tags' => implode(', ', array_slice($texts['tags'], 0, 4)),
            'live_url' => ($project['domain'] ?? '') !== '' ? 'https://' . (string) $project['domain'] : '',
            'preview_path' => self::previewPath($project),
            'accent_color' => (string) ($theme['colors']['primary'] ?? ''),
            'published' => $publish ? 1 : 0,
            'updated_at' => Db::now(),
        ];

        if ($existing !== null) {
            Db::update('showcase', $values, 'id = :id', ['id' => (int) $existing['id']]);
            $id = (int) $existing['id'];
        } else {
            $values['sort_order'] = (int) Db::value(
                'SELECT COALESCE(MIN(sort_order), 0) - 1 FROM showcase', [], 0
            );
            $values['created_at'] = Db::now();
            $id = Db::insert('showcase', $values);
        }

        Audit::log('showcase.created', (string) $project['name'], ['id' => $id]);

        return ['ok' => true, 'id' => $id, 'slug' => $slug];
    }

    /**
     * Die Referenz zeigt die gespeicherte Kopie der Website.
     *
     * Weil sie vom eigenen Server kommt, greift keine fremde
     * Einbettungssperre – anders als bei der echten Kundendomain.
     */
    private static function previewPath(array $project): string
    {
        $token = (string) ($project['preview_token'] ?? '');
        return $token !== '' ? '/vorschau/' . $token . '/' : '';
    }

    private static function texts(array $project, array $brief): array
    {
        if (!ClaudeClient::isConfigured()) {
            return self::fallbackTexts($project, $brief);
        }

        $message = sprintf(
            "KUNDE\n=====\nName: %s\nBranche: %s\nWas die Firma macht: %s\n\n"
            . "WAS DIE WEBSITE KÖNNEN SOLLTE\n=============================\n%s\n\n"
            . "UMFANG\n======\n%d Seiten%s",
            (string) $project['name'],
            (string) ($brief['industry'] ?? ''),
            (string) ($brief['description'] ?? ''),
            trim((string) ($brief['design_notes'] ?? '') . "\n" . (string) ($brief['extra_notes'] ?? '')),
            (int) Db::value('SELECT COUNT(*) FROM project_pages WHERE project_id = :p', ['p' => (int) $project['id']], 0),
            !empty($project['wants_admin']) ? ', mit eigenem Bearbeitungsbereich' : ''
        );

        try {
            $client = (new ClaudeClient())->forProject((int) $project['id']);
            $response = $client->structured(
                'reference',
                Prompts::reference(),
                $message,
                JsonSchema::reference(),
                ['effort' => 'medium', 'max_tokens' => 3000]
            );

            $texts = [
                'subtitle' => mb_substr(trim((string) ($response['subtitle'] ?? '')), 0, 200),
                'body_de' => mb_substr(trim((string) ($response['body_de'] ?? '')), 0, 4000),
                'body_en' => mb_substr(trim((string) ($response['body_en'] ?? '')), 0, 4000),
                'tags' => array_values(array_filter(array_map(
                    static fn ($tag): string => mb_substr(trim((string) $tag), 0, 40),
                    is_array($response['tags'] ?? null) ? $response['tags'] : []
                ))),
            ];

            // Nachprüfen. Die Anweisung ist eindeutig, aber verlassen tun
            // wir uns nicht darauf – hier geht es um ein Geschäftsgeheimnis.
            if (self::leaksMethod($texts['subtitle'] . ' ' . $texts['body_de'] . ' ' . $texts['body_en'])) {
                Logger::warning('Referenztext verriet das Vorgehen und wurde verworfen', [
                    'projekt' => (int) $project['id'],
                ]);
                return self::fallbackTexts($project, $brief);
            }

            return $texts;
        } catch (\Throwable $e) {
            Logger::warning('Referenztext konnte nicht erzeugt werden: ' . $e->getMessage());
            return self::fallbackTexts($project, $brief);
        }
    }

    /** Enthält der Text einen Hinweis auf das Vorgehen? */
    public static function leaksMethod(string $text): bool
    {
        $lower = mb_strtolower($text);

        foreach (self::FORBIDDEN_PARTS as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        // Ganze Wörter: Die Grenzen sind alles, was kein Buchstabe und
        // keine Ziffer ist. \b von PCRE hilft hier nicht zuverlässig,
        // weil Umlaute dabei nicht als Buchstaben gelten.
        $words = preg_split('/[^\p{L}\p{N}]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($words as $word) {
            if (in_array($word, self::FORBIDDEN_WORDS, true)) {
                return true;
            }
        }

        return false;
    }

    /** Ein schlichter, immer brauchbarer Text. */
    private static function fallbackTexts(array $project, array $brief): array
    {
        $name = (string) $project['name'];
        $industry = trim((string) ($brief['industry'] ?? ''));
        $what = trim((string) ($brief['description'] ?? ''));

        $first = $industry !== ''
            ? sprintf('%s ist ein Betrieb aus dem Bereich %s.', $name, $industry)
            : sprintf('Für %s haben wir einen neuen Auftritt umgesetzt.', $name);

        if ($what !== '') {
            $first .= ' ' . mb_substr($what, 0, 240);
        }

        $second = 'Entstanden ist eine Website mit klarer Struktur, kurzen Ladezeiten und '
            . 'einer Darstellung, die auf jedem Bildschirm stimmt.';

        $tags = array_values(array_filter([
            $industry !== '' ? $industry : null,
            !empty($project['wants_admin']) ? 'Backend' : null,
            trim((string) ($brief['old_url'] ?? '')) !== '' ? 'Redesign' : 'Neubau',
        ]));

        return [
            'subtitle' => $industry !== '' ? 'Website für ' . $industry : 'Neuer Webauftritt',
            'body_de' => $first . "\n\n" . $second,
            'body_en' => sprintf('A new website for %s.', $name) . "\n\n"
                . 'A clear structure, fast loading times and a layout that works on every screen.',
            'tags' => $tags !== [] ? $tags : ['Website'],
        ];
    }

    private static function uniqueSlug(string $name): string
    {
        $base = str_slug($name, 60);
        $slug = $base;
        $suffix = 2;

        while (Db::value('SELECT 1 FROM showcase WHERE slug = :s', ['s' => $slug])) {
            $slug = $base . '-' . $suffix;
            $suffix++;
            if ($suffix > 100) {
                $slug = $base . '-' . bin2hex(random_bytes(3));
                break;
            }
        }

        return $slug;
    }
}
