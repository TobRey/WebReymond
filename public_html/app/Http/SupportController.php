<?php

declare(strict_types=1);

namespace WebAtze\Http;

use WebAtze\Core\{Audit, Db, Logger, Mailer, RateLimit, Request, Response, Security, Settings};
use WebAtze\Domain\Websites;

/**
 * Das Relais für den Support-Bereich der Kunden.
 *
 * Auf der Website des Kunden liegt unter /support ein einfaches
 * Formular. Was er dort schreibt, landet hier – nicht in einer E-Mail,
 * die untergeht, sondern als Gesprächsfaden im Adminbereich.
 *
 * Bewusst kein Sofort-Chat: Niemand muss sofort antworten, und niemand
 * sitzt vor einem Fenster und wartet. Der Kunde sieht, dass seine Frage
 * angekommen ist; die Antwort holt er sich, wenn sie da ist. Damit ist
 * die Erwartung von Anfang an richtig gesetzt.
 *
 * Ausgewiesen wird die Website über dasselbe Kennwort wie beim
 * Assistenten – es gilt nur für sie und lässt sich zurückziehen.
 */
final class SupportController
{
    private const MAX_PER_HOUR = 20;

    /** Eine neue Frage oder eine Antwort des Kunden. */
    public function post(Request $request): Response
    {
        $project = $this->authenticate($request);

        if ($project === null) {
            return $this->denied();
        }

        $projectId = (int) $project['id'];

        if (!RateLimit::hit('support:' . $projectId, self::MAX_PER_HOUR, 3600)) {
            return Response::json([
                'ok' => false,
                'error' => 'Es sind viele Nachrichten in kurzer Zeit eingegangen. '
                    . 'Bitte später weitermachen.',
            ], 429)->noCache();
        }

        $body = $request->jsonBody();

        $text = trim((string) ($body['message'] ?? ''));
        $subject = mb_substr(trim((string) ($body['subject'] ?? '')), 0, 190);
        $name = mb_substr(trim((string) ($body['name'] ?? '')), 0, 120);
        $email = mb_substr(trim((string) ($body['email'] ?? '')), 0, 190);
        $threadId = (int) ($body['thread'] ?? 0);

        if ($text === '') {
            return Response::json(['ok' => false, 'error' => 'Die Nachricht ist leer.'], 422)->noCache();
        }

        $text = mb_substr(str_replace("\0", '', $text), 0, 5000);

        // Antwort auf einen bestehenden Faden?
        if ($threadId > 0) {
            $thread = Db::first(
                'SELECT * FROM support_threads WHERE id = :id AND project_id = :p',
                ['id' => $threadId, 'p' => $projectId]
            );

            if ($thread === null) {
                return Response::json([
                    'ok' => false,
                    'error' => 'Diesen Gesprächsfaden gibt es nicht.',
                ], 404)->noCache();
            }
        } else {
            $threadId = Db::insert('support_threads', [
                'project_id' => $projectId,
                // Damit die Frage auch auf der Seite ihres Kunden
                // steht. Die Liste unter Uebersicht zeigt sie ohnehin -
                // aber wer beim Kunden nachsieht, soll sie dort finden.
                'customer_id' => Websites::kundeVon($projectId) ?: null,
                'subject' => $subject !== '' ? $subject : mb_substr($text, 0, 80),
                'status' => 'open',
                'asker_name' => $name,
                'asker_email' => $email,
                'unread_for_owner' => 1,
                'unread_for_customer' => 0,
                'last_message_at' => Db::now(),
                'created_at' => Db::now(),
            ]);
        }

        Db::insert('support_messages', [
            'thread_id' => $threadId,
            'author' => 'customer',
            'body' => $text,
            'created_at' => Db::now(),
        ]);

        Db::update('support_threads', [
            'status' => 'open',
            'unread_for_owner' => 1,
            'last_message_at' => Db::now(),
        ], 'id = :id', ['id' => $threadId]);

        Audit::log('support.received', (string) $project['name'], ['faden' => $threadId]);

        // Eine kurze Nachricht an dich – sonst müsstest du ins Backend
        // schauen, um überhaupt zu merken, dass jemand gefragt hat.
        $this->notifyOwner($project, $subject !== '' ? $subject : mb_substr($text, 0, 60), $text);

        return Response::json([
            'ok' => true,
            'thread' => $threadId,
            'message' => 'Die Frage ist angekommen. Die Antwort erscheint hier, sobald sie da ist.',
        ])->noCache()->noIndex();
    }

    /** Den Verlauf abrufen – so sieht der Kunde die Antwort. */
    public function thread(Request $request): Response
    {
        $project = $this->authenticate($request);

        if ($project === null) {
            return $this->denied();
        }

        $body = $request->jsonBody();
        $threadId = (int) ($body['thread'] ?? 0);

        $thread = Db::first(
            'SELECT * FROM support_threads WHERE id = :id AND project_id = :p',
            ['id' => $threadId, 'p' => (int) $project['id']]
        );

        if ($thread === null) {
            return Response::json(['ok' => false, 'error' => 'Nicht gefunden.'], 404)->noCache();
        }

        $messages = Db::all(
            'SELECT author, body, created_at FROM support_messages
             WHERE thread_id = :t ORDER BY id ASC',
            ['t' => $threadId]
        );

        // Der Kunde hat den Faden angesehen.
        Db::update('support_threads', ['unread_for_customer' => 0], 'id = :id', ['id' => $threadId]);

        $out = [];
        foreach ($messages as $message) {
            $out[] = [
                // Nach aussen heisst es nicht "owner", sondern der Name
                // der Firma – der Kunde schreibt mit WebAtze, nicht mit
                // einem Datenbankfeld.
                'from' => (string) $message['author'] === 'owner'
                    ? (string) Settings::get('company_name', 'WebAtze')
                    : 'Du',
                'own' => (string) $message['author'] === 'customer',
                'body' => (string) $message['body'],
                'at' => (string) $message['created_at'],
            ];
        }

        return Response::json([
            'ok' => true,
            'thread' => $threadId,
            'subject' => (string) $thread['subject'],
            'status' => (string) $thread['status'],
            'messages' => $out,
        ])->noCache()->noIndex();
    }

    // ------------------------------------------------------------------

    private function notifyOwner(array $project, string $subject, string $text): void
    {
        $to = (string) Settings::get('email', '');

        if ($to === '' || !Mailer::isValidAddress($to)) {
            return;
        }

        try {
            Mailer::send(
                $to,
                'Support-Frage: ' . (string) $project['name'],
                sprintf(
                    "Von der Website %s ist eine Frage eingegangen.\n\n"
                    . "Betreff: %s\n\n%s\n\n"
                    . "Antworten im Adminbereich unter Support.\n",
                    (string) $project['name'],
                    $subject,
                    $text
                )
            );
        } catch (\Throwable $e) {
            // Der Faden steht in der Datenbank. Eine Mail, die nicht
            // hinausgeht, darf die Frage nicht verschlucken.
            Logger::warning('Support-Meldung konnte nicht gesendet werden: ' . $e->getMessage());
        }
    }

    /**
     * Welche Website meldet sich hier?
     *
     * Zwei Schlüssel gelten. Der support_token ist der richtige: Er kann
     * genau das hier - eine Nachricht senden, den eigenen Faden lesen -
     * und steht deshalb gefahrlos im Auftragstext, damit auf der
     * Kundenwebsite nichts von Hand eingerichtet werden muss.
     *
     * Der assistant_token wird weiterhin angenommen, sonst verstummten
     * alle Websites, die vor dieser Änderung ausgeliefert wurden.
     *
     * Verglichen wird zeitunabhängig und über alle Zeilen hinweg: Ein
     * frühes Aussteigen würde verraten, wie weit ein geratener Schlüssel
     * gestimmt hat.
     */
    private function authenticate(Request $request): ?array
    {
        $token = trim($request->header('X-WebAtze-Token'));

        if ($token === '') {
            $token = trim((string) ($request->jsonBody()['token'] ?? ''));
        }

        if (strlen($token) < 20 || strlen($token) > 100) {
            return null;
        }

        $treffer = null;

        foreach (Db::all(
            "SELECT * FROM projects WHERE support_token <> :leer1 OR assistant_token <> :leer2",
            ['leer1' => '', 'leer2' => '']
        ) as $row) {
            $eigener = (string) $row['support_token'];
            $alter = (string) $row['assistant_token'];

            if (($eigener !== '' && Security::equals($eigener, $token))
                || ($alter !== '' && Security::equals($alter, $token))) {
                $treffer = $row;
            }
        }

        return $treffer;
    }

    private function denied(): Response
    {
        return Response::json(['ok' => false, 'error' => 'Zugang verweigert.'], 403)
            ->noCache()->noIndex();
    }
}
