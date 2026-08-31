<?php

declare(strict_types=1);

namespace WebAtze\Http;

use WebAtze\Build\ShowcaseBuilder;
use WebAtze\Core\{Audit, Config, Db, Request, Response, Session, View};

/**
 * Die Referenzen auf der eigenen Website.
 *
 * Sie entstehen automatisch, sobald eine Kundenwebsite fertig ist –
 * lassen sich hier aber nachbearbeiten oder ausblenden.
 */
final class ShowcaseController
{
    public function index(Request $request): Response
    {
        $entries = Db::all(
            'SELECT s.*, p.slug AS project_slug, p.status AS project_status
             FROM showcase s
             LEFT JOIN projects p ON p.id = s.project_id
             ORDER BY s.sort_order ASC, s.id DESC'
        );

        // Fertige Projekte, die noch keine Referenz haben
        $candidates = Db::all(
            "SELECT p.* FROM projects p
             LEFT JOIN showcase s ON s.project_id = p.id
             WHERE s.id IS NULL AND p.status IN ('ready', 'live')
             ORDER BY p.updated_at DESC LIMIT 20"
        );

        return Response::html(View::partial('layouts/admin', [
            'title' => 'Referenzen',
            'content' => View::partial('admin/showcase', [
                'entries' => $entries,
                'candidates' => $candidates,
            ]),
        ]))->noCache()->noIndex();
    }

    public function update(Request $request): Response
    {
        $id = $request->paramInt('id');
        $action = $request->input('action');

        // Eine neue Referenz aus einem Projekt erzeugen
        if ($action === 'create') {
            $project = ProjectController::find($id);
            if ($project === null) {
                return Response::notFound();
            }

            try {
                ShowcaseBuilder::create($project, true);
                Session::flash('success', 'Referenz angelegt. Der Text lässt sich hier anpassen.');
            } catch (\Throwable $e) {
                Session::flash('error', 'Die Referenz konnte nicht angelegt werden: ' . $e->getMessage());
            }

            return $this->back();
        }

        $entry = Db::first('SELECT * FROM showcase WHERE id = :id', ['id' => $id]);
        if ($entry === null) {
            return Response::notFound();
        }

        if ($action === 'delete') {
            Db::delete('showcase', 'id = :id', ['id' => $id]);

            // Die gespeicherte Kopie geht mit. Sonst läge sie für immer
            // herum und wäre über ihre Adresse weiter erreichbar.
            $slug = preg_replace('/[^a-z0-9-]/', '', mb_strtolower((string) $entry['slug'])) ?? '';
            if ($slug !== '') {
                delete_tree(STORAGE_DIR . '/showcase/' . $slug);
            }

            Audit::log('showcase.deleted', (string) $entry['title'], [], $request);
            Session::flash('success', 'Referenz entfernt.');
            return $this->back();
        }

        if ($action === 'toggle') {
            $published = !((bool) $entry['published']);
            Db::update('showcase', ['published' => $published ? 1 : 0, 'updated_at' => Db::now()],
                'id = :id', ['id' => $id]);
            Session::flash('success', $published ? 'Referenz ist sichtbar.' : 'Referenz ist ausgeblendet.');
            return $this->back();
        }

        // Text bearbeiten
        Db::update('showcase', [
            'title' => mb_substr($request->input('title'), 0, 190),
            'subtitle' => mb_substr($request->input('subtitle'), 0, 250),
            'body_de' => $request->text('body_de', 4000),
            'body_en' => $request->text('body_en', 4000),
            'tags' => mb_substr($request->input('tags'), 0, 250),
            'live_url' => mb_substr($request->input('live_url'), 0, 250),
            'sort_order' => $request->int('sort_order'),
            'updated_at' => Db::now(),
        ], 'id = :id', ['id' => $id]);

        Audit::log('showcase.updated', (string) $entry['title'], [], $request);
        Session::flash('success', 'Referenz gespeichert.');

        return $this->back();
    }

    private function back(): Response
    {
        return Response::redirect(
            '/' . trim((string) Config::get('create_path', 'create'), '/') . '/referenzen'
        )->noCache()->noIndex();
    }
}
