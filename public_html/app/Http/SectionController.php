<?php

declare(strict_types=1);

namespace WebAtze\Http;

use WebAtze\Ai\{ClaudeClient, SectionEditor};
use WebAtze\Build\{ImagePlacer, ImageStore, Pipeline, SiteBuilder};
use WebAtze\Core\{Audit, Db, Jobs, Logger, RateLimit, Request, Response, Session};
use WebAtze\Templates\{Catalog, Renderer, Schema};

/**
 * Schnittstelle für den Bearbeitungsbalken in der Vorschau.
 *
 * Jede Aktion betrifft genau einen Abschnitt. Nach einer Änderung wird
 * die Vorschau neu gebaut, damit sofort sichtbar ist, was passiert ist.
 */
final class SectionController
{
    /** Höchstens so viele KI-Änderungen pro Stunde. */
    private const AI_LIMIT_PER_HOUR = 60;

    public function show(Request $request): Response
    {
        $section = $this->find($request->paramInt('id'));
        if ($section === null) {
            return $this->missing();
        }

        $type = (string) $section['type'];
        $definition = Schema::forType($type);

        $changes = Db::all(
            'SELECT id, source, actor, instruction, summary, created_at
             FROM section_changes WHERE section_id = :s ORDER BY id DESC LIMIT 12',
            ['s' => (int) $section['id']]
        );

        foreach ($changes as $key => $change) {
            $changes[$key]['label'] = SectionEditor::actorLabel(
                (string) $change['source'],
                (string) $change['actor']
            );
        }

        return Response::json([
            'ok' => true,
            'section' => [
                'id' => (int) $section['id'],
                'type' => $type,
                'type_label' => $definition['label'] ?? $type,
                'template' => (string) $section['template_key'],
                'template_label' => Catalog::label($type, (string) $section['template_key']),
                'hidden' => (bool) $section['hidden'],
                'content' => json_decode((string) $section['content'], true) ?: [],
                'overrides' => json_decode((string) $section['overrides'], true) ?: [],
            ],
            'changes' => $changes,
            'ai_available' => ClaudeClient::isConfigured(),
        ])->noCache();
    }

    /** Alle Vorlagen dieses Typs, für die Auswahl im Editor. */
    public function templates(Request $request): Response
    {
        $section = $this->find($request->paramInt('id'));
        if ($section === null) {
            return $this->missing();
        }

        $type = (string) $section['type'];
        $current = (string) $section['template_key'];

        $variants = [];
        foreach (Catalog::forType($type) as $key => $variant) {
            $variants[] = [
                'key' => $key,
                'label' => $variant['label'],
                'current' => $key === $current,
            ];
        }

        return Response::json([
            'ok' => true,
            'type' => $type,
            'variants' => $variants,
            'ai_available' => ClaudeClient::isConfigured(),
        ])->noCache();
    }

    /**
     * Der Textbefehl: "mach den Knopf grösser", "kürze den Text".
     */
    public function instruct(Request $request): Response
    {
        $section = $this->find($request->paramInt('id'));
        if ($section === null) {
            return $this->missing();
        }

        $user = Session::user();
        $actor = (string) ($user['username'] ?? 'unbekannt');

        if (!RateLimit::hit('ai-edit:' . $actor, self::AI_LIMIT_PER_HOUR, 3600)) {
            return Response::json([
                'ok' => false,
                'error' => 'Das waren viele Änderungen in kurzer Zeit. Bitte einen Moment warten.',
            ], 429)->noCache();
        }

        $instruction = $request->text('instruction', 2000);

        $section['content'] = json_decode((string) $section['content'], true) ?: [];
        $section['overrides'] = json_decode((string) $section['overrides'], true) ?: [];

        $editor = new SectionEditor((int) $section['project_id']);

        try {
            $result = $editor->apply($section, $instruction, $actor, 'ai');
        } catch (\Throwable $e) {
            $id = Logger::exception($e);
            return Response::json([
                'ok' => false,
                'error' => $e->getMessage() . ' (Kennnummer ' . $id . ')',
            ], 500)->noCache();
        }

        if (!$result['ok']) {
            return Response::json(['ok' => false, 'error' => $result['error']], 422)->noCache();
        }

        Audit::log('section.edited', 'Abschnitt #' . $section['id'], [
            'projekt' => (int) $section['project_id'],
            'anweisung' => mb_substr($instruction, 0, 200),
        ], $request);

        $this->refresh((int) $section['project_id']);

        return Response::json([
            'ok' => true,
            'summary' => $result['summary'],
            'label' => SectionEditor::actorLabel('ai', $actor),
            'changed' => $result['changed'],
            'template' => $result['template'],
        ])->noCache();
    }

    /** Vorlage wechseln – ohne KI, ohne Kosten, sofort. */
    public function switchTemplate(Request $request): Response
    {
        $section = $this->find($request->paramInt('id'));
        if ($section === null) {
            return $this->missing();
        }

        $type = (string) $section['type'];
        $key = $request->input('template');

        if (!Catalog::exists($type, $key)) {
            return Response::json(['ok' => false, 'error' => 'Diese Vorlage gibt es nicht.'], 422)->noCache();
        }

        $before = (string) $section['template_key'];

        Db::update('project_sections', [
            'template_key' => $key,
            'updated_at' => Db::now(),
        ], 'id = :id', ['id' => (int) $section['id']]);

        SectionEditor::log(
            (int) $section['project_id'],
            (int) $section['id'],
            'manual',
            (string) (Session::user()['username'] ?? ''),
            '',
            sprintf('Vorlage gewechselt: %s statt %s.', Catalog::label($type, $key), Catalog::label($type, $before)),
            ['template_key' => $key],
            ['template_key' => $before]
        );

        $this->refresh((int) $section['project_id']);

        return Response::json([
            'ok' => true,
            'template' => $key,
            'label' => Catalog::label($type, $key),
        ])->noCache();
    }

    /**
     * Eine neue Vorlage von der KI erzeugen lassen.
     *
     * Passt keine der 20 Varianten, beschreibt man die gewünschte
     * Anordnung – die KI wählt daraufhin die passendste Kombination der
     * vorhandenen Merkmale. Es entsteht kein neuer Code, sondern eine
     * neue Zusammenstellung; deshalb kann dabei nichts kaputtgehen.
     */
    public function generateTemplate(Request $request): Response
    {
        $section = $this->find($request->paramInt('id'));
        if ($section === null) {
            return $this->missing();
        }

        $wish = $request->text('wish', 600);
        if ($wish === '') {
            return Response::json(['ok' => false, 'error' => 'Bitte beschreiben, wie es aussehen soll.'], 422)->noCache();
        }

        $section['content'] = json_decode((string) $section['content'], true) ?: [];
        $section['overrides'] = json_decode((string) $section['overrides'], true) ?: [];

        $editor = new SectionEditor((int) $section['project_id']);
        $actor = (string) (Session::user()['username'] ?? '');

        try {
            $result = $editor->apply(
                $section,
                'Ändere nur die Anordnung, nicht die Texte. Gewünscht: ' . $wish,
                $actor,
                'ai'
            );
        } catch (\Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500)->noCache();
        }

        if (!$result['ok']) {
            return Response::json(['ok' => false, 'error' => $result['error']], 422)->noCache();
        }

        $this->refresh((int) $section['project_id']);

        return Response::json([
            'ok' => true,
            'summary' => $result['summary'],
            'template' => $result['template'],
        ])->noCache();
    }

    /** Abschnitt aus- oder wieder einblenden. */
    public function toggle(Request $request): Response
    {
        $section = $this->find($request->paramInt('id'));
        if ($section === null) {
            return $this->missing();
        }

        $hidden = !((bool) $section['hidden']);

        Db::update('project_sections', [
            'hidden' => $hidden ? 1 : 0,
            'updated_at' => Db::now(),
        ], 'id = :id', ['id' => (int) $section['id']]);

        $this->refresh((int) $section['project_id']);

        return Response::json(['ok' => true, 'hidden' => $hidden])->noCache();
    }

    /** Abschnitt nach oben oder unten schieben. */
    public function reorder(Request $request): Response
    {
        $section = $this->find($request->paramInt('id'));
        if ($section === null) {
            return $this->missing();
        }

        $direction = $request->input('direction') === 'up' ? -1 : 1;

        $siblings = Db::all(
            'SELECT id, sort_order FROM project_sections WHERE page_id = :p ORDER BY sort_order ASC, id ASC',
            ['p' => (int) $section['page_id']]
        );

        $index = null;
        foreach ($siblings as $key => $sibling) {
            if ((int) $sibling['id'] === (int) $section['id']) {
                $index = $key;
                break;
            }
        }

        $target = $index === null ? null : $index + $direction;

        // Kopf und Fuss bleiben, wo sie sind.
        if ($target === null || $target < 1 || $target >= count($siblings) - 1 || $index < 1 || $index > count($siblings) - 2) {
            return Response::json(['ok' => false, 'error' => 'Der Abschnitt lässt sich nicht weiter verschieben.'], 422)->noCache();
        }

        Db::transaction(static function () use ($siblings, $index, $target): void {
            Db::update('project_sections', ['sort_order' => $target],
                'id = :id', ['id' => (int) $siblings[$index]['id']]);
            Db::update('project_sections', ['sort_order' => $index],
                'id = :id', ['id' => (int) $siblings[$target]['id']]);
        });

        $this->refresh((int) $section['project_id']);

        return Response::json(['ok' => true])->noCache();
    }

    /** Ein Bild im Abschnitt austauschen. */
    public function replaceImage(Request $request): Response
    {
        $section = $this->find($request->paramInt('id'));
        if ($section === null) {
            return $this->missing();
        }

        $file = $request->file('image');
        if ($file === null) {
            return Response::json(['ok' => false, 'error' => 'Es wurde keine Datei gewählt.'], 422)->noCache();
        }
        if ((int) ($file['size'] ?? 0) > 8_000_000) {
            return Response::json(['ok' => false, 'error' => 'Das Bild ist grösser als 8 MB.'], 422)->noCache();
        }

        $project = Db::first('SELECT * FROM projects WHERE id = :id', ['id' => (int) $section['project_id']]);
        if ($project === null) {
            return $this->missing();
        }

        $dir = ensure_dir(STORAGE_DIR . '/projects/' . (string) $project['slug'] . '/assets');
        $stored = ImageStore::storeUpload($file, $dir);

        if ($stored === null) {
            return Response::json([
                'ok' => false,
                'error' => 'Die Datei liess sich nicht als Bild lesen. Bitte JPG, PNG, WebP oder SVG verwenden.',
            ], 422)->noCache();
        }

        Db::insert('project_assets', [
            'project_id' => (int) $project['id'],
            'kind' => 'image',
            'path' => $stored['name'],
            'original_name' => mb_substr((string) ($file['name'] ?? ''), 0, 200),
            'alt' => $request->input('alt'),
            'width' => $stored['width'],
            'height' => $stored['height'],
            'bytes' => $stored['bytes'],
            'created_at' => Db::now(),
        ]);

        // Das Bild an die angegebene Stelle im Inhalt setzen
        $content = json_decode((string) $section['content'], true) ?: [];
        $path = $request->input('field', 'image');

        $image = [
            'src' => 'assets/img/' . $stored['name'],
            'alt' => $request->input('alt'),
            'width' => $stored['width'],
            'height' => $stored['height'],
        ];

        // "items.2.image" -> im dritten Eintrag
        if (preg_match('/^items\.(\d+)\.image$/', $path, $m)) {
            $index = (int) $m[1];
            if (isset($content['items'][$index]) && is_array($content['items'][$index])) {
                $image['alt'] = $image['alt'] !== ''
                    ? $image['alt']
                    : (string) ($content['items'][$index]['image']['alt'] ?? '');
                $content['items'][$index]['image'] = $image;
            }
        } elseif ($path === 'image') {
            $image['alt'] = $image['alt'] !== '' ? $image['alt'] : (string) ($content['image']['alt'] ?? '');
            $content['image'] = $image;
        } else {
            return Response::json(['ok' => false, 'error' => 'Unbekannte Stelle für das Bild.'], 422)->noCache();
        }

        Db::update('project_sections', [
            'content' => $content,
            'updated_at' => Db::now(),
        ], 'id = :id', ['id' => (int) $section['id']]);

        SectionEditor::log(
            (int) $project['id'],
            (int) $section['id'],
            'manual',
            (string) (Session::user()['username'] ?? ''),
            '',
            'Bild ausgetauscht.',
            ['image' => $image],
            []
        );

        $this->refresh((int) $project['id']);

        return Response::json(['ok' => true, 'src' => $image['src']])->noCache();
    }

    /** Eine Änderung zurücknehmen. */
    public function revert(Request $request): Response
    {
        $section = $this->find($request->paramInt('id'));
        if ($section === null) {
            return $this->missing();
        }

        $changeId = $request->int('change');
        $result = SectionEditor::revert(
            (int) $section['project_id'],
            $changeId,
            (string) (Session::user()['username'] ?? '')
        );

        if (!$result['ok']) {
            return Response::json(['ok' => false, 'error' => $result['error']], 422)->noCache();
        }

        $this->refresh((int) $section['project_id']);

        return Response::json(['ok' => true, 'summary' => $result['summary']])->noCache();
    }

    // ------------------------------------------------------------------

    private function find(int $id): ?array
    {
        return $id > 0
            ? Db::first('SELECT * FROM project_sections WHERE id = :id', ['id' => $id])
            : null;
    }

    /**
     * Website und Vorschau neu bauen.
     *
     * Läuft sofort, nicht über die Warteschlange: eine einzelne Änderung
     * dauert Millisekunden, und der Bearbeitende soll das Ergebnis
     * unmittelbar sehen.
     */
    private function refresh(int $projectId): void
    {
        $project = Db::first('SELECT * FROM projects WHERE id = :id', ['id' => $projectId]);
        if ($project === null) {
            return;
        }

        try {
            $dist = STORAGE_DIR . '/projects/' . (string) $project['slug'] . '/dist';
            (new SiteBuilder($project, $dist))->build();

            $token = (string) $project['preview_token'];
            if ($token !== '') {
                $preview = STORAGE_DIR . '/previews/' . preg_replace('/[^A-Za-z0-9_-]/', '', $token);
                delete_tree($preview);
                Pipeline::copyTree($dist, $preview);
            }

            Db::update('projects', ['updated_at' => Db::now()], 'id = :id', ['id' => $projectId]);
        } catch (\Throwable $e) {
            Logger::exception($e);
        }
    }

    private function missing(): Response
    {
        return Response::json(['ok' => false, 'error' => 'Dieser Abschnitt existiert nicht.'], 404)->noCache();
    }
}
