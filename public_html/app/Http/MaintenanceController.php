<?php

declare(strict_types=1);

namespace WebAtze\Http;

use WebAtze\Build\VisitCollector;
use WebAtze\Core\{Db, Request, Response, Session, View};

/**
 * Besucherzahlen der Kundenwebsites.
 *
 * Sie kommen von selbst herein – hier steht nur, was dabei herausgekommen
 * ist. Die Sicherungen standen früher ebenfalls hier; sie sind ins
 * Wartungscenter gewandert, weil «läuft die Seite» und «ist sie
 * gesichert» dieselbe Frage in zwei Zeiten sind.
 */
final class MaintenanceController
{
    // ------------------------------------------------------ Besucherzahlen

    public function visits(Request $request): Response
    {
        $projects = Db::all(
            "SELECT * FROM projects WHERE stats_token <> :empty ORDER BY name ASC",
            ['empty' => '']
        );

        $rows = [];

        foreach ($projects as $project) {
            $rows[] = [
                'project' => $project,
                'week' => VisitCollector::week((int) $project['id']),
            ];
        }

        return Response::html(View::partial('layouts/admin', [
            'title' => 'Besucherzahlen',
            'content' => View::partial('admin/visits', ['rows' => $rows]),
        ]))->noCache()->noIndex();
    }

    /** Jetzt abholen, ohne auf die Pflege zu warten. */
    public function fetchVisits(Request $request): Response
    {
        $project = Db::first('SELECT * FROM projects WHERE id = :id', [
            'id' => (int) $request->param('id'),
        ]);

        if ($project === null) {
            return Response::text('Unbekanntes Projekt.', 404)->noCache();
        }

        $count = VisitCollector::collect($project);

        Session::flash(
            $count > 0 ? 'success' : 'info',
            $count > 0
                ? $count . ' Zeilen übernommen.'
                : 'Von dieser Website kam nichts zurück. Ist sie schon hochgeladen '
                  . 'und läuft dort zahlen.php?'
        );

        return Response::redirect(self::base() . '/zahlen');
    }

    private static function base(): string
    {
        return '/' . trim((string) \WebAtze\Core\Config::get('create_path', 'create'), '/');
    }
}
