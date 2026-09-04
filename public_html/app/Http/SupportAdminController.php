<?php

declare(strict_types=1);

namespace WebAtze\Http;

use WebAtze\Core\{Audit, Config, Db, Logger, Mailer, Request, Response, Session, Settings, View};

/**
 * Die Support-Fragen der Kunden – dein Teil davon.
 *
 * Ein Gesprächsfaden je Frage, nach Datum sortiert, offene zuerst.
 * Antworten geht direkt hier; der Kunde sieht sie beim nächsten Aufruf
 * von /support auf seiner Website.
 *
 * Es gibt keinen Zwang zur sofortigen Antwort – genau das ist der
 * Unterschied zu einem Chat. Deshalb auch keine Anwesenheitsanzeige und
 * kein "schreibt gerade": Was man nicht verspricht, muss man nicht
 * halten.
 */
final class SupportAdminController
{
    public function index(Request $request): Response
    {
        $filter = $request->query('status');
        $filter = in_array($filter, ['open', 'done'], true) ? $filter : '';

        $threads = Db::all(
            'SELECT t.*, p.name AS project_name, p.slug AS project_slug
             FROM support_threads t
             LEFT JOIN projects p ON p.id = t.project_id'
            . ($filter !== '' ? ' WHERE t.status = :s' : '')
            . ' ORDER BY t.unread_for_owner DESC, t.last_message_at DESC
             LIMIT 200',
            $filter !== '' ? ['s' => $filter] : []
        );

        foreach ($threads as $index => $thread) {
            $threads[$index]['messages'] = Db::all(
                'SELECT * FROM support_messages WHERE thread_id = :t ORDER BY id ASC',
                ['t' => (int) $thread['id']]
            );
        }

        return Response::html(View::partial('layouts/admin', [
            'title' => 'Support',
            'content' => View::partial('admin/support', [
                'threads' => $threads,
                'filter' => $filter,
                'counts' => [
                    'open' => (int) Db::value("SELECT COUNT(*) FROM support_threads WHERE status = 'open'", [], 0),
                    'done' => (int) Db::value("SELECT COUNT(*) FROM support_threads WHERE status = 'done'", [], 0),
                    'unread' => (int) Db::value('SELECT COUNT(*) FROM support_threads WHERE unread_for_owner = 1', [], 0),
                ],
            ]),
        ]))->noCache()->noIndex();
    }

    /** Antworten. */
    public function reply(Request $request): Response
    {
        $id = $request->paramInt('id');

        $thread = Db::first('SELECT * FROM support_threads WHERE id = :id', ['id' => $id]);

        if ($thread === null) {
            return Response::notFound();
        }

        $text = trim($request->text('message', 5000));

        if ($text === '') {
            Session::flash('error', 'Die Antwort ist leer.');
            return $this->back();
        }

        Db::insert('support_messages', [
            'thread_id' => $id,
            'author' => 'owner',
            'body' => $text,
            'created_at' => Db::now(),
        ]);

        Db::update('support_threads', [
            // Beantwortet heisst gelesen – aber noch nicht erledigt.
            // Erledigt entscheidest du, nicht das System.
            'unread_for_owner' => 0,
            'unread_for_customer' => 1,
            'last_message_at' => Db::now(),
        ], 'id = :id', ['id' => $id]);

        Audit::log('support.replied', (string) $thread['subject'], ['faden' => $id], $request);

        $this->notifyCustomer($thread, $text);

        Session::flash('success', 'Antwort abgeschickt. Der Kunde sieht sie unter /support.');

        return $this->back();
    }

    /** Erledigt oder wieder offen. */
    public function setStatus(Request $request): Response
    {
        $id = $request->paramInt('id');
        $status = $request->input('status') === 'done' ? 'done' : 'open';

        $thread = Db::first('SELECT * FROM support_threads WHERE id = :id', ['id' => $id]);

        if ($thread === null) {
            return Response::notFound();
        }

        Db::update('support_threads', [
            'status' => $status,
            'unread_for_owner' => 0,
        ], 'id = :id', ['id' => $id]);

        Audit::log('support.status', (string) $thread['subject'], ['status' => $status], $request);

        return $this->back();
    }

    /**
     * Einen Gespraechsfaden entfernen - samt Nachrichten.
     *
     * Als erledigt zu markieren reicht nicht immer: Ein Faden, der aus
     * einem Versehen entstanden ist oder von einem Werbeprogramm kam,
     * soll weg und nicht bloss zugeklappt sein.
     */
    public function destroy(Request $request): Response
    {
        $id = $request->paramInt('id');

        $thread = Db::first('SELECT * FROM support_threads WHERE id = :id', ['id' => $id]);

        if ($thread === null) {
            Session::flash('error', 'Diesen Gesprächsfaden gibt es nicht mehr.');

            return $this->back();
        }

        // Erst die Nachrichten, dann der Faden. Andersherum blieben die
        // Nachrichten als Waisen liegen.
        Db::delete('support_messages', 'thread_id = :t', ['t' => $id]);
        Db::delete('support_threads', 'id = :id', ['id' => $id]);

        Audit::log('support.delete', (string) $thread['subject'], ['id' => $id], $request);

        Session::flash('success', 'Gesprächsfaden gelöscht.');

        return $this->back();
    }

    // ------------------------------------------------------------------

    /**
     * Den Kunden benachrichtigen, falls er eine Adresse hinterlassen hat.
     *
     * Ohne das müsste er raten, wann eine Antwort da ist – und /support
     * täglich aufrufen. Die Antwort selbst steht nicht in der Mail: Sie
     * gehört auf die Seite, nicht in ein Postfach, das jemand mitliest.
     */
    private function notifyCustomer(array $thread, string $text): void
    {
        $to = (string) $thread['asker_email'];

        if ($to === '' || !Mailer::isValidAddress($to)) {
            return;
        }

        $project = Db::first('SELECT * FROM projects WHERE id = :p', ['p' => (int) $thread['project_id']]);
        $domain = (string) ($project['domain'] ?? '');
        $link = $domain !== '' ? 'https://' . $domain . '/support/' : 'die Support-Seite';

        try {
            Mailer::send(
                $to,
                'Antwort auf deine Frage',
                sprintf(
                    "Guten Tag%s\n\n"
                    . "Auf deine Frage \"%s\" gibt es eine Antwort.\n\n"
                    . "Sie steht unter: %s\n\n"
                    . "Freundliche Grüsse\n%s\n",
                    (string) $thread['asker_name'] !== '' ? ' ' . (string) $thread['asker_name'] : '',
                    (string) $thread['subject'],
                    $link,
                    (string) Settings::get('company_name', 'WebAtze')
                )
            );
        } catch (\Throwable $e) {
            Logger::warning('Support-Antwort konnte nicht gemeldet werden: ' . $e->getMessage());
        }
    }

    private function back(): Response
    {
        return Response::redirect(
            '/' . trim((string) Config::get('create_path', 'create'), '/') . '/support'
        )->noCache()->noIndex();
    }
}
