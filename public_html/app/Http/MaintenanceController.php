<?php

declare(strict_types=1);

namespace WebAtze\Http;

use WebAtze\Build\VisitCollector;
use WebAtze\Core\{Db, Request, Response, Session, View};
use WebAtze\Domain\Visits;

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

    /**
     * Die eigene Website und jede Kundenwebsite.
     *
     * Frueher stand hier nur, was einen stats_token hatte - also
     * ausschliesslich selbst gebaute Seiten mit angehakter Zaehlung.
     * Alles andere fehlte, die eigene Website eingeschlossen. Jetzt
     * steht hier jede Website, die eingetragen ist; wo noch nichts
     * ankommt, steht der Einzeiler zum Einbauen daneben.
     */
    public function visits(Request $request): Response
    {
        $tage = max(7, min(90, (int) $request->query('tage', '7')));
        $gewaehlt = (int) $request->query('website', '-1');

        $zeilen = Visits::overview($tage);

        $detail = null;

        if ($gewaehlt >= 0) {
            foreach ($zeilen as $zeile) {
                if ((int) $zeile['id'] === $gewaehlt) {
                    $detail = $zeile + Visits::detail($gewaehlt, max(30, $tage));
                    break;
                }
            }
        }

        return Response::html(View::partial('layouts/admin', [
            'title' => 'Besucher',
            'content' => View::partial('admin/visits', [
                'zeilen' => $zeilen,
                'tage' => $tage,
                'detail' => $detail,
                'einbau' => Visits::snippet(0) === '' && trim((string) \WebAtze\Core\Config::get('app_url', '')) === '',
            ]),
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
