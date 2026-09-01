<?php

declare(strict_types=1);

namespace WebAtze\Http;

use WebAtze\Core\{Audit, Config, Db, Request, Response, Session, View};
use WebAtze\Domain\{Billing, Calendar, IcsFeed};

/**
 * Mitarbeitende, Aufgaben, Termine und die Buchhaltung.
 *
 * Alles, was zum Betrieb gehört und nicht zu einem einzelnen Kunden –
 * beziehungsweise, was quer über alle Kunden geht.
 */
final class CompanyController
{
    // --------------------------------------------------- Mitarbeitende

    public function employees(Request $request): Response
    {
        $leute = Db::all('SELECT * FROM employees ORDER BY active DESC, name ASC');

        // Wer betreut wie viele Kunden, und was ist dort offen?
        foreach ($leute as $nummer => $person) {
            $leute[$nummer]['kunden'] = (int) Db::value(
                'SELECT COUNT(*) FROM customers WHERE employee_id = :e',
                ['e' => (int) $person['id']],
                0
            );
            $leute[$nummer]['offene_todos'] = (int) Db::value(
                'SELECT COUNT(*) FROM todos WHERE employee_id = :e AND done_at IS NULL',
                ['e' => (int) $person['id']],
                0
            );
        }

        return Response::html(View::partial('layouts/admin', [
            'title' => 'Mitarbeitende',
            'content' => View::partial('admin/employees', ['leute' => $leute]),
        ]))->noCache()->noIndex();
    }

    public function saveEmployee(Request $request): Response
    {
        $id = (int) $request->input('employee_id', '0');
        $name = trim((string) $request->input('name', ''));

        if ($name === '') {
            Session::flash('error', 'Ohne Namen geht es nicht.');
            return self::to('/mitarbeitende');
        }

        $werte = [
            'name' => mb_substr($name, 0, 191),
            'email' => mb_substr(trim((string) $request->input('email', '')), 0, 191),
            'phone' => mb_substr(trim((string) $request->input('phone', '')), 0, 60),
            'role' => mb_substr(trim((string) $request->input('role', '')), 0, 120),
            'hourly_rappen' => Billing::toRappen((string) $request->input('hourly', '0')),
            'active' => $request->input('active') ? 1 : 0,
            'notes' => $request->text('notes', 2000),
            'updated_at' => Db::now(),
        ];

        if ($id > 0) {
            Db::update('employees', $werte, 'id = :id', ['id' => $id]);
        } else {
            $id = (int) Db::insert('employees', $werte + ['created_at' => Db::now()]);
        }

        Audit::log('employee.saved', $name, ['id' => $id], $request);
        Session::flash('success', 'Gespeichert.');

        return self::to('/mitarbeitende');
    }

    public function deleteEmployee(Request $request): Response
    {
        $id = (int) $request->input('employee_id', '0');
        $person = Db::first('SELECT * FROM employees WHERE id = :id', ['id' => $id]);

        if ($person === null) {
            return Response::notFound();
        }

        // Zuweisungen lösen, nicht mitlöschen: Der Kunde bleibt, er ist
        // nur wieder ohne Betreuung.
        Db::update('customers', ['employee_id' => null], 'employee_id = :e', ['e' => $id]);
        Db::update('todos', ['employee_id' => null], 'employee_id = :e', ['e' => $id]);
        Db::update('appointments', ['employee_id' => null], 'employee_id = :e', ['e' => $id]);
        Db::delete('employees', 'id = :id', ['id' => $id]);

        Audit::log('employee.deleted', (string) $person['name'], ['id' => $id], $request);
        Session::flash('success', 'Entfernt. Die Kunden sind jetzt ohne Betreuung.');

        return self::to('/mitarbeitende');
    }

    // ---------------------------------------------------------- Aufgaben

    public function saveTodo(Request $request): Response
    {
        $titel = trim((string) $request->input('title', ''));
        $kunde = (int) $request->input('customer_id', '0') ?: null;

        if ($titel === '') {
            Session::flash('error', 'Die Aufgabe braucht einen Titel.');
            return self::backTo($kunde);
        }

        $id = (int) $request->input('todo_id', '0');

        $werte = [
            'customer_id' => $kunde,
            'employee_id' => (int) $request->input('employee_id', '0') ?: null,
            'title' => mb_substr($titel, 0, 255),
            'note' => $request->text('note', 2000),
            'due_on' => self::datum((string) $request->input('due_on', '')),
            'priority' => in_array($request->input('priority'), ['hoch', 'normal', 'tief'], true)
                ? (string) $request->input('priority') : 'normal',
            'updated_at' => Db::now(),
        ];

        if ($id > 0) {
            Db::update('todos', $werte, 'id = :id', ['id' => $id]);
        } else {
            Db::insert('todos', $werte + ['created_at' => Db::now()]);
        }

        Session::flash('success', 'Notiert.');

        return self::backTo($kunde);
    }

    public function toggleTodo(Request $request): Response
    {
        $id = (int) $request->input('todo_id', '0');
        $todo = Db::first('SELECT * FROM todos WHERE id = :id', ['id' => $id]);

        if ($todo === null) {
            return Response::notFound();
        }

        Db::update('todos', [
            'done_at' => ($todo['done_at'] ?? null) === null ? Db::now() : null,
            'updated_at' => Db::now(),
        ], 'id = :id', ['id' => $id]);

        return self::backTo($todo['customer_id'] !== null ? (int) $todo['customer_id'] : null, $request);
    }

    public function deleteTodo(Request $request): Response
    {
        $id = (int) $request->input('todo_id', '0');
        $todo = Db::first('SELECT customer_id FROM todos WHERE id = :id', ['id' => $id]);

        Db::delete('todos', 'id = :id', ['id' => $id]);
        Session::flash('success', 'Weg.');

        return self::backTo($todo !== null && $todo['customer_id'] !== null
            ? (int) $todo['customer_id'] : null);
    }

    // ----------------------------------------------------------- Termine

    public function calendar(Request $request): Response
    {
        $monat = (string) $request->query('monat', date('Y-m'));

        if (preg_match('/^\d{4}-\d{2}$/', $monat) !== 1) {
            $monat = date('Y-m');
        }

        return Response::html(View::partial('layouts/admin', [
            'title' => 'Kalender',
            'content' => View::partial('admin/calendar', [
                'monat' => $monat,
                'wochen' => Calendar::month($monat),
                'kunden' => Db::all('SELECT id, name FROM customers ORDER BY name'),
                'mitarbeitende' => Db::all('SELECT id, name FROM employees WHERE active = 1 ORDER BY name'),
                'naechste' => Calendar::upcoming(20),
                'abo' => IcsFeed::url($request->baseUrl()),
                'aboWebcal' => IcsFeed::webcalUrl($request->baseUrl()),
            ]),
        ]))->noCache()->noIndex();
    }

    // ------------------------------------------------- Kalender aufs Telefon

    /**
     * Der abonnierbare Kalender.
     *
     * Diese Adresse liegt bewusst ausserhalb der Anmeldung: Das iPhone
     * holt die Datei selbst, und dabei kann es sich nirgends anmelden.
     * Der Schutz steckt im Zufallswort in der Adresse. Wer es hat, sieht
     * die Termine – deshalb steht neben dem Verweis im Kalender ein
     * Knopf, der ein neues erzeugt.
     */
    public function feed(Request $request): Response
    {
        if (!IcsFeed::tokenMatches((string) $request->param('token'))) {
            // Bewusst dieselbe Antwort wie fuer eine unbekannte Seite:
            // Ein "falsches Token" waere die Bestaetigung, dass es hier
            // ueberhaupt etwas gibt.
            return Response::text('Nicht gefunden.', 404)->noCache()->noIndex();
        }

        return Response::make(IcsFeed::build())
            ->header('Content-Type', 'text/calendar; charset=utf-8')
            ->noCache()
            ->noIndex();
    }

    /** Ein neues Zufallswort – die alte Adresse gilt dann nicht mehr. */
    public function newFeedToken(Request $request): Response
    {
        IcsFeed::newToken();
        Audit::log('calendar.token.new', '', [], $request);

        Session::flash(
            'success',
            'Neue Adresse erzeugt. Das alte Abonnement auf dem Telefon zeigt nichts mehr '
            . 'und muss einmal neu eingerichtet werden.'
        );

        return self::to('/kalender');
    }

    public function saveAppointment(Request $request): Response
    {
        $titel = trim((string) $request->input('title', ''));
        $beginn = self::zeitpunkt((string) $request->input('starts_at', ''));

        if ($titel === '' || $beginn === '') {
            Session::flash('error', 'Termin braucht einen Titel und einen Zeitpunkt.');
            return self::to('/kalender');
        }

        $id = (int) $request->input('appointment_id', '0');
        $kunde = (int) $request->input('customer_id', '0') ?: null;

        $werte = [
            'customer_id' => $kunde,
            'employee_id' => (int) $request->input('employee_id', '0') ?: null,
            'title' => mb_substr($titel, 0, 255),
            'note' => $request->text('note', 2000),
            'starts_at' => $beginn,
            'ends_at' => self::zeitpunkt((string) $request->input('ends_at', '')),
            'place' => mb_substr(trim((string) $request->input('place', '')), 0, 191),
            'updated_at' => Db::now(),
        ];

        if ($id > 0) {
            Db::update('appointments', $werte, 'id = :id', ['id' => $id]);
        } else {
            Db::insert('appointments', $werte + ['created_at' => Db::now()]);
        }

        Session::flash('success', 'Der Termin steht.');

        $ziel = (string) $request->input('zurueck', '');

        return $ziel === 'kunde' && $kunde !== null
            ? self::backTo($kunde)
            : self::to('/kalender?monat=' . substr($beginn, 0, 7));
    }

    public function deleteAppointment(Request $request): Response
    {
        $id = (int) $request->input('appointment_id', '0');
        $termin = Db::first('SELECT customer_id FROM appointments WHERE id = :id', ['id' => $id]);

        Db::delete('appointments', 'id = :id', ['id' => $id]);
        Session::flash('success', 'Termin abgesagt.');

        $ziel = (string) $request->input('zurueck', '');

        return $ziel === 'kunde' && $termin !== null && $termin['customer_id'] !== null
            ? self::backTo((int) $termin['customer_id'])
            : self::to('/kalender');
    }

    // ------------------------------------------------------- Buchhaltung

    public function books(Request $request): Response
    {
        $jahr = (int) $request->query('jahr', date('Y'));

        if ($jahr < 2000 || $jahr > 2100) {
            $jahr = (int) date('Y');
        }

        $von = $jahr . '-01-01';
        $bis = $jahr . '-12-31';

        // Monatsweise, damit man den Verlauf sieht und nicht nur eine
        // Jahreszahl, mit der niemand etwas anfangen kann.
        $monate = [];

        for ($m = 1; $m <= 12; $m++) {
            $anfang = sprintf('%04d-%02d-01', $jahr, $m);
            $ende = date('Y-m-t', strtotime($anfang) ?: time());

            $monate[] = ['monat' => $anfang] + Billing::books($anfang, $ende);
        }

        return Response::html(View::partial('layouts/admin', [
            'title' => 'Buchhaltung ' . $jahr,
            'content' => View::partial('admin/books', [
                'jahr' => $jahr,
                'summe' => Billing::books($von, $bis),
                'monate' => $monate,
                'ausgaben' => Db::all(
                    'SELECT * FROM expenses WHERE spent_on >= :v AND spent_on <= :b
                     ORDER BY spent_on DESC, id DESC LIMIT 200',
                    ['v' => $von, 'b' => $bis]
                ),
                'einnahmen' => Db::all(
                    'SELECT p.*, c.name AS kunde FROM payments p
                     LEFT JOIN customers c ON c.id = p.customer_id
                     WHERE p.paid_on >= :v AND p.paid_on <= :b
                     ORDER BY p.paid_on DESC, p.id DESC LIMIT 200',
                    ['v' => $von, 'b' => $bis]
                ),
                'offen' => Billing::openItems(),
            ]),
        ]))->noCache()->noIndex();
    }

    public function saveExpense(Request $request): Response
    {
        $label = trim((string) $request->input('label', ''));

        if ($label === '') {
            Session::flash('error', 'Die Ausgabe braucht eine Bezeichnung.');
            return self::to('/buchhaltung');
        }

        $id = (int) $request->input('expense_id', '0');

        $werte = [
            'label' => mb_substr($label, 0, 191),
            'category' => mb_substr(trim((string) $request->input('category', 'sonstiges')), 0, 60),
            'amount_rappen' => Billing::toRappen((string) $request->input('amount', '0')),
            'spent_on' => self::datum((string) $request->input('spent_on', '')) ?: date('Y-m-d'),
            'recurring' => array_key_exists((string) $request->input('recurring'), Billing::INTERVALS)
                ? (string) $request->input('recurring') : 'einmalig',
            'note' => $request->text('note', 255),
            'updated_at' => Db::now(),
        ];

        if ($id > 0) {
            Db::update('expenses', $werte, 'id = :id', ['id' => $id]);
        } else {
            Db::insert('expenses', $werte + ['created_at' => Db::now()]);
        }

        Session::flash('success', 'Eingetragen.');

        return self::to('/buchhaltung?jahr=' . substr((string) $werte['spent_on'], 0, 4));
    }

    public function deleteExpense(Request $request): Response
    {
        Db::delete('expenses', 'id = :id', ['id' => (int) $request->input('expense_id', '0')]);
        Session::flash('success', 'Weg.');

        return self::to('/buchhaltung');
    }

    // ------------------------------------------------------------------

    private static function datum(string $wert): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($wert)) === 1 ? trim($wert) : '';
    }

    private static function zeitpunkt(string $wert): string
    {
        $wert = trim(str_replace('T', ' ', $wert));

        return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $wert) === 1 ? $wert : '';
    }

    private static function base(): string
    {
        return '/' . trim((string) Config::get('create_path', 'create'), '/');
    }

    private static function to(string $pfad): Response
    {
        return Response::redirect(self::base() . $pfad)->noCache()->noIndex();
    }

    /**
     * Zurück, wo man hergekommen ist.
     *
     * Ein Feld '_back' im Formular schlaegt alles andere: Wer eine
     * Aufgabe auf der Uebersicht abhakt, will dort bleiben und nicht im
     * Kalender landen. Erlaubt sind nur eigene Pfade - ein '_back' aus
     * dem Formular ist Eingabe und keine Anweisung.
     */
    private static function backTo(?int $kunde, ?Request $request = null): Response
    {
        $zurueck = $request !== null ? trim((string) $request->input('_back', '')) : '';

        if ($zurueck !== '' && preg_match('#^/[A-Za-z0-9/_-]{0,60}$#', $zurueck) === 1) {
            return self::to($zurueck);
        }

        return $kunde !== null && $kunde > 0
            ? self::to('/kunden/' . $kunde)
            : self::to('/kalender');
    }
}
