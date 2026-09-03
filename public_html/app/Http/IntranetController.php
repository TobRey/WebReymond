<?php

declare(strict_types=1);

namespace WebAtze\Http;

use WebAtze\Core\{Audit, Config, Request, Response, Session, View};
use WebAtze\Domain\Notes;

/**
 * Das Intranet – meine eigenen Notizen.
 *
 * Alles hier liegt hinter der Anmeldung und verlässt diesen Server nie.
 * Deshalb dürfen hier Dinge stehen, die auf der Website nichts zu
 * suchen haben: Preise, Abläufe, was ich mir merken will.
 */
final class IntranetController
{
    public function index(Request $request): Response
    {
        // Beim ersten Aufruf stehen die beiden Grundbeitraege schon da:
        // der Ablauf und die Preisorientierung. Danach nie wieder.
        Notes::seed();

        $suche = trim((string) $request->query('suche', ''));
        $stichwort = trim((string) $request->query('stichwort', ''));

        return $this->page('Intranet', 'admin/intranet', [
            'beitraege' => Notes::all($suche, $stichwort),
            'suche' => $suche,
            'stichwort' => $stichwort,
            'stichworte' => Notes::tags(),
        ]);
    }

    /** Das leere Formular. */
    public function blank(Request $request): Response
    {
        return $this->page('Neuer Beitrag', 'admin/intranet-note', [
            'beitrag' => null,
            'vorschlaege' => Notes::TAGS,
        ]);
    }

    public function show(Request $request): Response
    {
        $beitrag = Notes::find((int) $request->param('id'));

        if ($beitrag === null) {
            return Response::text('Diesen Beitrag gibt es nicht.', 404)->noCache();
        }

        return $this->page((string) $beitrag['title'], 'admin/intranet-note', [
            'beitrag' => $beitrag,
            'vorschlaege' => Notes::TAGS,
        ]);
    }

    public function save(Request $request): Response
    {
        $id = (int) $request->input('note_id', '0');

        $ergebnis = Notes::save([
            'title' => (string) $request->input('title', ''),
            'body' => $request->text('body', 60000),
            'tag' => (string) $request->input('tag', ''),
            'pinned' => $request->bool('pinned'),
        ], $id > 0 ? $id : null);

        if (!$ergebnis['ok']) {
            Session::flash('error', $ergebnis['meldung']);

            return $this->to($id > 0 ? '/intranet/' . $id : '/intranet/neu');
        }

        Audit::log('note.save', (string) $request->input('title', ''), ['id' => $ergebnis['id']], $request);
        Session::flash('success', $ergebnis['meldung']);

        return $this->to('/intranet/' . $ergebnis['id']);
    }

    public function pin(Request $request): Response
    {
        $id = (int) $request->input('note_id', '0');

        Notes::pin($id, $request->bool('pinned'));

        return $this->to('/intranet');
    }

    public function destroy(Request $request): Response
    {
        $id = (int) $request->input('note_id', '0');

        if (Notes::remove($id)) {
            Audit::log('note.delete', (string) $id, [], $request);
            Session::flash('success', 'Gelöscht.');
        } else {
            Session::flash('error', 'Diesen Beitrag gibt es nicht.');
        }

        return $this->to('/intranet');
    }

    // ------------------------------------------------------------------

    private function page(string $titel, string $vorlage, array $daten): Response
    {
        return Response::html(View::partial('layouts/admin', [
            'title' => $titel,
            'content' => View::partial($vorlage, $daten),
        ]))->noCache()->noIndex();
    }

    private function to(string $pfad): Response
    {
        $base = '/' . trim((string) Config::get('create_path', 'create'), '/');

        return Response::redirect($base . $pfad)->noCache()->noIndex();
    }
}
