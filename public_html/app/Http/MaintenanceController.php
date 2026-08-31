<?php

declare(strict_types=1);

namespace WebAtze\Http;

use WebAtze\Build\{Backup, VisitCollector};
use WebAtze\Core\{Audit, Db, Request, Response, Session, View};

/**
 * Sicherungen und Besucherzahlen.
 *
 * Beides läuft von selbst – hier steht nur, was dabei herausgekommen
 * ist. Eine Sicherung, die man ansehen kann, ist eine Sicherung, der
 * man traut; eine, von der man nur annimmt, dass es sie gibt, ist
 * keine.
 */
final class MaintenanceController
{
    // -------------------------------------------------------- Sicherungen

    public function backups(Request $request): Response
    {
        $rows = Backup::listAll(120);

        $bytes = 0;
        foreach ($rows as $row) {
            $bytes += (int) $row['bytes'];
        }

        return Response::html(View::partial('layouts/admin', [
            'title' => 'Sicherungen',
            'content' => View::partial('admin/backups', [
                'rows' => $rows,
                'bytes' => $bytes,
                'lastSelf' => Db::first(
                    "SELECT * FROM backups WHERE scope = 'self' ORDER BY created_at DESC LIMIT 1"
                ),
            ]),
        ]))->noCache()->noIndex();
    }

    /** Eine Sicherung herunterladen. */
    public function downloadBackup(Request $request): Response
    {
        $path = Backup::pathFor((int) $request->param('id'));

        if ($path === null) {
            return Response::text('Diese Sicherung gibt es nicht mehr.', 404)->noCache();
        }

        Audit::log('backup.download', basename($path), [], $request);

        return Response::file($path, 'application/zip', true, basename($path))->noCache()->noIndex();
    }

    /** Jetzt sichern, ohne auf die Pflege zu warten. */
    public function runBackup(Request $request): Response
    {
        $self = Backup::daily();
        $project = Backup::nextProject();

        Audit::log('backup.run', $project !== '' ? $project : 'nur eigene', [], $request);

        $parts = [];
        if ($self) {
            $parts[] = 'die eigene Datenbank';
        }
        if ($project !== '') {
            $parts[] = 'die Website ' . $project;
        }

        Session::flash(
            $parts === [] ? 'info' : 'success',
            $parts === []
                ? 'Alles ist schon gesichert – heute gibt es nichts Neues zu tun.'
                : 'Gesichert: ' . implode(' und ', $parts) . '.'
        );

        return Response::redirect(self::base() . '/sicherungen');
    }

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
