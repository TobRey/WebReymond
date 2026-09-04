<?php

declare(strict_types=1);

namespace WebAtze\Http;

use WebAtze\Core\{Audit, Config, Request, Response, Session, View};
use WebAtze\Domain\FrontendFix;

/**
 * Die eigene Website per Textbefehl anpassen.
 *
 * Ein Feld, ein Satz, und die Änderung ist auf der Seite. Was dahinter
 * steckt, steht in Domain\FrontendFix – hier geht es nur um die
 * Oberfläche und um das Ausliefern der erzeugten Datei.
 */
final class FrontendFixController
{
    public function index(Request $request): Response
    {
        return Response::html(View::partial('layouts/admin', [
            'title' => 'Frontend-Fix',
            'content' => View::partial('admin/frontendfix', [
                'anpassungen' => FrontendFix::all(),
                'aktiv' => FrontendFix::activeCount(),
                'bereit' => \WebAtze\Ai\ClaudeClient::isConfigured(),
                'letzter' => (string) (Session::get('fix_letzter') ?? ''),
            ]),
        ]))->noCache()->noIndex();
    }

    /** Einen Befehl ausführen. */
    public function apply(Request $request): Response
    {
        $befehl = $request->text('befehl', 2000);

        // Der Aufruf an die Schnittstelle dauert. Auf einem gemieteten
        // Hosting bricht PHP sonst mitten darin ab.
        @set_time_limit(180);

        $ergebnis = FrontendFix::apply($befehl);

        if (!$ergebnis['ok']) {
            // Den Befehl zurückgeben, damit niemand ihn neu tippen muss.
            Session::flash('error', $ergebnis['meldung']);
            Session::put('fix_letzter', $befehl);

            return $this->zurueck();
        }

        Session::forget('fix_letzter');

        Audit::log('frontend.fix', mb_substr($befehl, 0, 80), ['id' => $ergebnis['id']], $request);
        Session::flash('success', $ergebnis['meldung']);

        return $this->zurueck();
    }

    public function toggle(Request $request): Response
    {
        $id = (int) $request->input('fix_id', '0');
        $an = $request->bool('aktiv');

        if (!FrontendFix::toggle($id, $an)) {
            Session::flash('error', 'Diese Anpassung gibt es nicht.');

            return $this->zurueck();
        }

        Audit::log('frontend.fix.toggle', (string) $id, ['aktiv' => $an], $request);
        Session::flash('success', $an ? 'Wieder eingeschaltet.' : 'Abgeschaltet.');

        return $this->zurueck();
    }

    public function destroy(Request $request): Response
    {
        $id = (int) $request->input('fix_id', '0');

        if (!FrontendFix::remove($id)) {
            Session::flash('error', 'Diese Anpassung gibt es nicht.');

            return $this->zurueck();
        }

        Audit::log('frontend.fix.delete', (string) $id, [], $request);
        Session::flash('success', 'Gelöscht.');

        return $this->zurueck();
    }

    /** Alles zurück auf den ausgelieferten Stand. */
    public function clear(Request $request): Response
    {
        $weg = FrontendFix::clear();

        Audit::log('frontend.fix.clear', (string) $weg, [], $request);
        Session::flash('success', $weg . ' Anpassungen entfernt. Die Website sieht wieder aus wie gebaut.');

        return $this->zurueck();
    }

    /**
     * Das Stylesheet für die öffentliche Website.
     *
     * Es liegt unter storage/ und wird hier ausgeliefert, nicht direkt
     * aus dem Web-Ordner. So braucht der Server nirgends Schreibrechte,
     * wo er sonst keine hat.
     */
    public function stylesheet(Request $request): Response
    {
        $css = FrontendFix::gespeichert();

        return Response::make($css)
            ->header('Content-Type', 'text/css; charset=utf-8')
            // Die Kennung in der Adresse ändert sich bei jeder
            // Anpassung. Deshalb darf der Browser die Datei lange
            // behalten: Eine neue Fassung hat ohnehin eine neue Adresse.
            ->header('Cache-Control', 'public, max-age=604800');
    }

    // ------------------------------------------------------------------

    private function zurueck(): Response
    {
        return Response::redirect(
            '/' . trim((string) Config::get('create_path', 'create'), '/') . '/frontend-fix'
        )->noCache()->noIndex();
    }
}
