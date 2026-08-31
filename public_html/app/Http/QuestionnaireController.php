<?php

declare(strict_types=1);

namespace WebAtze\Http;

use WebAtze\Core\{Audit, Config, Csrf, Db, Mailer, RateLimit, Request, Response, Session, View};
use WebAtze\Domain\Questionnaire;

/**
 * Der Fragebogen – die öffentliche Seite und die Verwaltung dazu.
 *
 * Die öffentliche Seite ist bewusst schlicht und trägt kein Wort über
 * WebAtze hinaus, das nicht dort hingehört: Der Kunde soll ausfüllen,
 * nicht lesen.
 */
final class QuestionnaireController
{
    // ------------------------------------------------------- Für den Kunden

    public function show(Request $request): Response
    {
        $row = Questionnaire::byToken((string) $request->param('token'));

        if ($row === null) {
            return $this->gone();
        }

        // Damit du im Adminbereich siehst, dass er ihn geöffnet hat.
        if ($row['opened_at'] === null) {
            Db::update('questionnaires', ['opened_at' => Db::now()], 'id = :id', [
                'id' => (int) $row['id'],
            ]);
        }

        return Response::html(View::partial('pages/fragebogen', [
            'row' => $row,
            'fields' => Questionnaire::fields(),
            'values' => (array) $row['answers'],
            'errors' => [],
            'done' => (string) $row['status'] === 'submitted',
        ]))->noCache()->noIndex();
    }

    public function submit(Request $request): Response
    {
        $row = Questionnaire::byToken((string) $request->param('token'));

        if ($row === null) {
            return $this->gone();
        }

        // Ein Formular ohne Anmeldung braucht eine Bremse.
        if (!RateLimit::hit('fragebogen:' . $request->ip(), 20, 3600)) {
            return Response::text('Zu viele Versuche. Bitte in einer Stunde noch einmal.', 429)
                ->noCache();
        }

        $input = $request->all();

        // Die Falle für Maschinen: ein Feld, das ein Mensch nie sieht.
        if (trim((string) ($input['website'] ?? '')) !== '') {
            return Response::redirect('/fragebogen/' . (string) $row['token'] . '?danke=1');
        }

        $result = Questionnaire::store($row, $input);

        if (!$result['ok']) {
            return Response::html(View::partial('pages/fragebogen', [
                'row' => $row,
                'fields' => Questionnaire::fields(),
                'values' => $input,
                'errors' => $result['errors'],
                'done' => false,
            ]), 422)->noCache()->noIndex();
        }

        $this->notifyOwner($row, $input);

        return Response::redirect('/fragebogen/' . (string) $row['token'] . '?danke=1');
    }

    private function gone(): Response
    {
        return Response::html(View::partial('pages/fragebogen-weg', []), 410)
            ->noCache()
            ->noIndex();
    }

    /** Kurze Nachricht an dich – ohne die Antworten selbst. */
    private function notifyOwner(array $row, array $input): void
    {
        $to = (string) Config::get('mail.to', '');

        if ($to === '') {
            return;
        }

        $base = rtrim((string) Config::get('app_url', ''), '/')
            . '/' . trim((string) Config::get('create_path', 'create'), '/');

        Mailer::send(
            $to,
            'Fragebogen ausgefüllt: ' . mb_substr((string) ($input['company_name'] ?? $row['company']), 0, 80),
            "Der Fragebogen ist ausgefüllt.\n\n"
            . "Firma: " . mb_substr((string) ($input['company_name'] ?? ''), 0, 120) . "\n\n"
            . "Die Antworten stehen unter:\n" . $base . "/fragebogen\n\n"
            . "Von dort füllst du mit einem Klick das Formular für eine neue\n"
            . "Website damit vor.\n"
        );
    }

    // ---------------------------------------------------------- Für dich

    public function index(Request $request): Response
    {
        $rows = Db::all('SELECT * FROM questionnaires ORDER BY id DESC LIMIT 200');

        foreach ($rows as $index => $row) {
            $rows[$index]['answers'] = json_decode((string) $row['answers'], true) ?: [];
        }

        return Response::html(View::partial('layouts/admin', [
            'title' => 'Fragebögen',
            'content' => View::partial('admin/questionnaires', [
                'rows' => $rows,
                'fields' => Questionnaire::fields(),
                'publicBase' => rtrim((string) Config::get('app_url', ''), '/') . '/fragebogen/',
            ]),
        ]))->noCache()->noIndex();
    }

    /** Einen neuen Verweis erzeugen. */
    public function create(Request $request): Response
    {
        $created = Questionnaire::create(
            (string) $request->input('company'),
            (string) $request->input('email'),
            (string) $request->input('note')
        );

        Audit::log('questionnaire.create', (string) $request->input('company'), [], $request);

        $url = rtrim((string) Config::get('app_url', ''), '/') . '/fragebogen/' . $created['token'];

        $email = trim((string) $request->input('email'));

        if ($email !== '' && $request->input('send') !== '') {
            Mailer::send(
                $email,
                'Ein paar Fragen zu Ihrer neuen Website',
                "Guten Tag\n\n"
                . "Damit Ihre Website zu Ihnen passt, brauche ich ein paar Angaben.\n"
                . "Unter diesem Verweis können Sie sie in Ruhe eintragen:\n\n"
                . $url . "\n\n"
                . "Es dauert etwa zehn Minuten. Sie können zwischendurch aufhören\n"
                . "und später weitermachen – der Verweis gilt 30 Tage.\n\n"
                . "Freundliche Grüsse\n"
                . (string) Config::get('mail.from_name', 'WebAtze') . "\n"
            );

            Session::flash('success', 'Verweis erzeugt und an ' . $email . ' geschickt.');
        } else {
            Session::flash('success', 'Verweis erzeugt: ' . $url);
        }

        return Response::redirect(self::base() . '/fragebogen');
    }

    /** Die Antworten ins Formular für eine neue Website übernehmen. */
    public function adopt(Request $request): Response
    {
        $row = Db::first('SELECT * FROM questionnaires WHERE id = :id', [
            'id' => (int) $request->param('id'),
        ]);

        if ($row === null) {
            return Response::text('Diesen Fragebogen gibt es nicht.', 404)->noCache();
        }

        $answers = json_decode((string) $row['answers'], true) ?: [];

        Session::put('create_prefill', Questionnaire::toBrief($answers));

        Db::update('questionnaires', ['status' => 'used'], 'id = :id', ['id' => (int) $row['id']]);

        Session::flash(
            'info',
            'Die Antworten stehen im Formular. Ändere, was nicht passt – der Kunde '
            . 'hat seine Firma beschrieben, nicht seine Website.'
        );

        return Response::redirect(self::base() . '/neu');
    }

    public function destroy(Request $request): Response
    {
        Db::delete('questionnaires', 'id = :id', ['id' => (int) $request->param('id')]);

        Session::flash('info', 'Fragebogen gelöscht. Der Verweis führt jetzt ins Leere.');

        return Response::redirect(self::base() . '/fragebogen');
    }

    private static function base(): string
    {
        return '/' . trim((string) Config::get('create_path', 'create'), '/');
    }
}
