<?php

declare(strict_types=1);

namespace WebAtze\Http;

use WebAtze\Core\{Audit, Config, Db, Request, Response, Session, View};

/**
 * Die Anfragen über das Kontaktformular der eigenen Website.
 */
final class LeadController
{
    private const STATUSES = ['new', 'open', 'done', 'spam'];

    public function index(Request $request): Response
    {
        $filter = $request->query('status');
        if (!in_array($filter, self::STATUSES, true)) {
            $filter = '';
        }

        $leads = $filter !== ''
            ? Db::all('SELECT * FROM leads WHERE status = :s ORDER BY id DESC LIMIT 200', ['s' => $filter])
            : Db::all('SELECT * FROM leads ORDER BY id DESC LIMIT 200');

        $counts = [];
        foreach (self::STATUSES as $status) {
            $counts[$status] = (int) Db::value(
                'SELECT COUNT(*) FROM leads WHERE status = :s',
                ['s' => $status],
                0
            );
        }

        return Response::html(View::partial('layouts/admin', [
            'title' => 'Anfragen',
            'content' => View::partial('admin/leads', [
                'leads' => $leads,
                'counts' => $counts,
                'filter' => $filter,
            ]),
        ]))->noCache()->noIndex();
    }

    public function setStatus(Request $request): Response
    {
        $id = $request->paramInt('id');
        $status = $request->input('status');

        if (!in_array($status, self::STATUSES, true)) {
            Session::flash('error', 'Unbekannter Status.');
            return $this->back();
        }

        $lead = Db::first('SELECT * FROM leads WHERE id = :id', ['id' => $id]);
        if ($lead === null) {
            return Response::notFound();
        }

        Db::update('leads', ['status' => $status], 'id = :id', ['id' => $id]);
        Audit::log('lead.status', 'Anfrage #' . $id, ['status' => $status], $request);

        return $this->back();
    }

    /**
     * Eine Anfrage entfernen.
     *
     * Als Spam zu markieren reicht nicht: Was Werbung war, bleibt sonst
     * fuer immer in der Liste stehen und macht die Zahl im Menue
     * nutzlos.
     */
    public function destroy(Request $request): Response
    {
        $id = $request->paramInt('id');

        $lead = Db::first('SELECT * FROM leads WHERE id = :id', ['id' => $id]);

        if ($lead === null) {
            Session::flash('error', 'Diese Anfrage gibt es nicht mehr.');

            return $this->back();
        }

        Db::delete('leads', 'id = :id', ['id' => $id]);

        Audit::log('lead.delete', 'Anfrage #' . $id, [
            'von' => (string) $lead['name'],
        ], $request);

        Session::flash('success', 'Anfrage gelöscht.');

        return $this->back();
    }

    private function back(): Response
    {
        return Response::redirect(
            '/' . trim((string) Config::get('create_path', 'create'), '/') . '/anfragen'
        )->noCache()->noIndex();
    }
}
