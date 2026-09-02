<?php

declare(strict_types=1);

namespace WebAtze\Http;

use WebAtze\Core\{Audit, Config, Db, Request, Response, Session, View};
use WebAtze\Domain\Billing;

/**
 * Die Kundenverwaltung.
 *
 * Bis hierher kannte WebAtze nur Projekte: eine Website, gebaut, fertig.
 * Ein Kunde ist aber mehr – er hat wiederkehrende Kosten, offene
 * Aufgaben, Termine, und irgendwann zahlt er oder eben nicht. Das stand
 * bisher auf Zetteln.
 */
final class CustomerController
{
    public function index(Request $request): Response
    {
        $suche = trim((string) $request->query('suche', ''));
        $zeigen = (string) $request->query('status', 'aktiv');

        $wo = [];
        $werte = [];

        if (in_array($zeigen, ['aktiv', 'ruhend', 'beendet'], true)) {
            $wo[] = 'status = :st';
            $werte['st'] = $zeigen;
        }

        if ($suche !== '') {
            $wo[] = '(name LIKE :q OR email LIKE :q OR contact_name LIKE :q)';
            $werte['q'] = '%' . $suche . '%';
        }

        $sql = 'SELECT * FROM customers'
            . ($wo !== [] ? ' WHERE ' . implode(' AND ', $wo) : '')
            . ' ORDER BY name ASC LIMIT 400';

        $kunden = Db::all($sql, $werte);

        // Je Kunde der Zahlungsstand – das ist die Zahl, die zählt.
        foreach ($kunden as $nummer => $kunde) {
            $stand = Billing::statusOf((int) $kunde['id']);

            $kunden[$nummer]['offen_rappen'] = $stand['offen_rappen'];
            $kunden[$nummer]['monatlich_rappen'] = $stand['monatlich_rappen'];
            $kunden[$nummer]['posten'] = count($stand['posten']);
            $kunden[$nummer]['offene_todos'] = (int) Db::value(
                'SELECT COUNT(*) FROM todos WHERE customer_id = :c AND done_at IS NULL',
                ['c' => (int) $kunde['id']],
                0
            );
        }

        return Response::html(View::partial('layouts/admin', [
            'title' => 'Kunden',
            'content' => View::partial('admin/customers', [
                'kunden' => $kunden,
                'suche' => $suche,
                'zeigen' => $zeigen,
                'zahlen' => self::zahlen(),
            ]),
        ]))->noCache()->noIndex();
    }

    /** Das leere Formular fuer einen neuen Kunden. */
    public function blank(Request $request): Response
    {
        return Response::html(View::partial('layouts/admin', [
            'title' => 'Neuer Kunde',
            'content' => View::partial('admin/customer', [
                'kunde' => [],
                'stand' => ['posten' => [], 'offen_rappen' => 0, 'bezahlt_rappen' => 0,
                            'monatlich_rappen' => 0, 'jaehrlich_rappen' => 0],
                'historie' => [],
                'todos' => [],
                'termine' => [],
                'mitarbeitende' => Db::all('SELECT * FROM employees WHERE active = 1 ORDER BY name'),
                'projekte' => Db::all('SELECT id, name FROM projects ORDER BY name'),
                'rechnungen' => [],
            ]),
        ]))->noCache()->noIndex();
    }

    public function show(Request $request): Response
    {
        $kunde = self::find($request->paramInt('id'));

        if ($kunde === null) {
            return Response::notFound();
        }

        $id = (int) $kunde['id'];

        return Response::html(View::partial('layouts/admin', [
            'title' => (string) $kunde['name'],
            'content' => View::partial('admin/customer', [
                'kunde' => $kunde,
                'stand' => Billing::statusOf($id),
                'historie' => Db::all(
                    'SELECT * FROM payments WHERE customer_id = :c ORDER BY paid_on DESC, id DESC LIMIT 60',
                    ['c' => $id]
                ),
                'todos' => Db::all(
                    'SELECT * FROM todos WHERE customer_id = :c
                     ORDER BY done_at IS NULL DESC, due_on ASC, id DESC LIMIT 100',
                    ['c' => $id]
                ),
                'termine' => Db::all(
                    'SELECT * FROM appointments WHERE customer_id = :c ORDER BY starts_at DESC LIMIT 40',
                    ['c' => $id]
                ),
                'mitarbeitende' => Db::all('SELECT * FROM employees WHERE active = 1 ORDER BY name'),
                'projekte' => Db::all('SELECT id, name FROM projects ORDER BY name'),
                'rechnungen' => Db::all(
                    'SELECT * FROM documents WHERE customer_id = :c ORDER BY id DESC LIMIT 30',
                    ['c' => $id]
                ),
            ]),
        ]))->noCache()->noIndex();
    }

    // ------------------------------------------------------------------

    public function save(Request $request): Response
    {
        $id = $request->paramInt('id');
        $kunde = $id > 0 ? self::find($id) : null;

        if ($id > 0 && $kunde === null) {
            return Response::notFound();
        }

        $name = trim((string) $request->input('name', ''));

        if ($name === '') {
            Session::flash('error', 'Ohne Namen geht es nicht.');
            return self::back($id);
        }

        $werte = [
            'name' => mb_substr($name, 0, 191),
            'contact_name' => mb_substr(trim((string) $request->input('contact_name', '')), 0, 191),
            'email' => mb_substr(trim((string) $request->input('email', '')), 0, 191),
            'phone' => mb_substr(trim((string) $request->input('phone', '')), 0, 60),
            'address' => $request->text('address', 500),
            'website' => mb_substr(trim((string) $request->input('website', '')), 0, 191),
            'project_id' => (int) $request->input('project_id', '0') ?: null,
            'employee_id' => (int) $request->input('employee_id', '0') ?: null,
            'status' => in_array($request->input('status'), ['aktiv', 'ruhend', 'beendet'], true)
                ? (string) $request->input('status') : 'aktiv',
            'notes' => $request->text('notes', 4000),
            'updated_at' => Db::now(),
        ];

        if ($kunde === null) {
            $id = (int) Db::insert('customers', $werte + ['created_at' => Db::now()]);
            Audit::log('customer.created', $name, ['id' => $id], $request);
            Session::flash('success', 'Kunde angelegt. Jetzt die Kosten eintragen.');
        } else {
            Db::update('customers', $werte, 'id = :id', ['id' => $id]);
            Audit::log('customer.updated', $name, ['id' => $id], $request);
            Session::flash('success', 'Gespeichert.');
        }

        return Response::redirect(self::base() . '/kunden/' . $id)->noCache()->noIndex();
    }

    public function destroy(Request $request): Response
    {
        $kunde = self::find($request->paramInt('id'));

        if ($kunde === null) {
            return Response::notFound();
        }

        $id = (int) $kunde['id'];

        // Die Zahlungen bleiben stehen. Sie gehören in die Buchhaltung,
        // nicht zum Kunden – wer einen Kunden löscht, soll damit nicht
        // die Einnahmen des letzten Jahres verlieren.
        Db::delete('charges', 'customer_id = :c', ['c' => $id]);
        Db::delete('todos', 'customer_id = :c', ['c' => $id]);
        Db::delete('appointments', 'customer_id = :c', ['c' => $id]);
        Db::delete('customers', 'id = :id', ['id' => $id]);

        Audit::log('customer.deleted', (string) $kunde['name'], ['id' => $id], $request);
        Session::flash('success', 'Der Kunde ist weg. Die Zahlungen bleiben in der Buchhaltung.');

        return Response::redirect(self::base() . '/kunden')->noCache()->noIndex();
    }

    // ------------------------------------------------------- Kostenposten

    public function saveCharge(Request $request): Response
    {
        $kunde = self::find($request->paramInt('id'));

        if ($kunde === null) {
            return Response::notFound();
        }

        $chargeId = (int) $request->input('charge_id', '0');
        $label = trim((string) $request->input('label', ''));

        if ($label === '') {
            Session::flash('error', 'Der Posten braucht eine Bezeichnung.');
            return self::back((int) $kunde['id']);
        }

        $werte = [
            'customer_id' => (int) $kunde['id'],
            'label' => mb_substr($label, 0, 191),
            'kind' => array_key_exists((string) $request->input('kind'), Billing::KINDS)
                ? (string) $request->input('kind') : 'weiteres',
            'amount_rappen' => Billing::toRappen((string) $request->input('amount', '0')),
            // Das Formularfeld heisst weiter 'interval'; nur die Spalte
            // musste weichen (INTERVAL ist in MySQL reserviert).
            'billing_interval' => array_key_exists((string) $request->input('interval'), Billing::INTERVALS)
                ? (string) $request->input('interval') : 'einmalig',
            'starts_on' => self::datum((string) $request->input('starts_on', '')),
            'ends_on' => self::datum((string) $request->input('ends_on', '')),
            'active' => $request->input('active') ? 1 : 0,
            'note' => mb_substr(trim((string) $request->input('note', '')), 0, 255),
            'updated_at' => Db::now(),
        ];

        if ($chargeId > 0) {
            Db::update('charges', $werte, 'id = :id AND customer_id = :c',
                ['id' => $chargeId, 'c' => (int) $kunde['id']]);
        } else {
            Db::insert('charges', $werte + ['created_at' => Db::now()]);
        }

        Session::flash('success', 'Der Posten steht.');

        return self::back((int) $kunde['id']);
    }

    public function deleteCharge(Request $request): Response
    {
        $kunde = self::find($request->paramInt('id'));

        if ($kunde === null) {
            return Response::notFound();
        }

        Db::delete('charges', 'id = :id AND customer_id = :c', [
            'id' => (int) $request->input('charge_id', '0'),
            'c' => (int) $kunde['id'],
        ]);

        Session::flash('success', 'Der Posten ist weg. Bereits erfasste Zahlungen bleiben.');

        return self::back((int) $kunde['id']);
    }

    /** Das Häkchen: bezahlt oder eben doch nicht. */
    public function togglePaid(Request $request): Response
    {
        $kunde = self::find($request->paramInt('id'));

        if ($kunde === null) {
            return Response::notFound();
        }

        $chargeId = (int) $request->input('charge_id', '0');
        $charge = Db::first('SELECT * FROM charges WHERE id = :id AND customer_id = :c',
            ['id' => $chargeId, 'c' => (int) $kunde['id']]);

        if ($charge === null) {
            return Response::notFound();
        }

        if (Billing::isPaid($chargeId, (string) $charge['billing_interval'])) {
            Billing::markUnpaid($chargeId);
            Session::flash('success', '"' . $charge['label'] . '" steht wieder als offen.');
        } else {
            Billing::markPaid($chargeId, null, (string) $request->input('method', ''));
            Session::flash('success', '"' . $charge['label'] . '" ist abgehakt.');
        }

        return self::back((int) $kunde['id']);
    }

    /**
     * Eine Rechnung aus dem, was gerade offen ist.
     *
     * Das ist der Weg, den man taeglich geht: Der Kunde schuldet drei
     * Posten, daraus wird eine Rechnung. Von Hand abzutippen, was zwei
     * Zeilen weiter oben schon steht, waere Arbeit ohne Ertrag - und
     * eine Fehlerquelle.
     */
    public function invoice(Request $request): Response
    {
        $kunde = self::find($request->paramInt('id'));

        if ($kunde === null) {
            return Response::notFound();
        }

        $id = (int) $kunde['id'];
        $stand = Billing::statusOf($id);

        $zeilen = [];

        foreach ($stand['posten'] as $posten) {
            if (!$posten['faellig'] || $posten['bezahlt']) {
                continue;
            }

            $bezeichnung = (string) $posten['label'];

            // Bei wiederkehrenden Posten gehoert die Periode dazu - sonst
            // weiss in einem halben Jahr niemand mehr, welcher Monat
            // gemeint war.
            if ((string) $posten['periode'] !== '') {
                $bezeichnung .= ' (' . $posten['periode'] . ')';
            }

            $zeilen[] = [
                'label' => $bezeichnung,
                'quantity' => 1.0,
                'price_rappen' => (int) $posten['amount_rappen'],
            ];
        }

        if ($zeilen === []) {
            Session::flash('warning', 'Bei diesem Kunden ist gerade nichts offen.');
            return self::back($id);
        }

        $adresse = preg_split('/\r?\n/', (string) ($kunde['address'] ?? '')) ?: [];

        $docId = \WebAtze\Build\DocumentBuilder::create([
            'kind' => 'invoice',
            'customer_id' => $id,
            'project_id' => (int) ($kunde['project_id'] ?? 0) ?: null,
            'title' => 'Rechnung ' . (string) $kunde['name'],
            'recipient' => [
                'name' => (string) $kunde['name'],
                'attn' => (string) $kunde['contact_name'],
                'street' => trim((string) ($adresse[0] ?? '')),
                'city' => trim((string) ($adresse[1] ?? '')),
                'email' => (string) $kunde['email'],
            ],
            'items' => $zeilen,
            'intro' => 'Besten Dank für Ihr Vertrauen. Wir erlauben uns, folgende '
                . 'Leistungen in Rechnung zu stellen.',
            'outro' => '',
            'vat_percent' => (int) \WebAtze\Core\Settings::get('vat_percent', '0'),
            'due_days' => 30,
        ]);

        Audit::log('customer.invoiced', (string) $kunde['name'],
            ['kunde' => $id, 'rechnung' => $docId, 'zeilen' => count($zeilen)], $request);

        Session::flash('success', 'Rechnung angelegt – das PDF liegt bereit.');

        return Response::redirect(self::base() . '/rechnungen')->noCache()->noIndex();
    }

    // ------------------------------------------------------------------

    public static function find(int $id): ?array
    {
        return $id > 0 ? Db::first('SELECT * FROM customers WHERE id = :id', ['id' => $id]) : null;
    }

    /** Ein paar Zahlen für den Kopf der Liste. */
    private static function zahlen(): array
    {
        $offen = 0;

        foreach (Billing::openItems() as $posten) {
            $offen += (int) $posten['amount_rappen'];
        }

        $monat = date('Y-m');

        return [
            'kunden' => (int) Db::value("SELECT COUNT(*) FROM customers WHERE status = 'aktiv'", [], 0),
            'offen_rappen' => $offen,
            'monat_rappen' => (int) (Db::value(
                'SELECT COALESCE(SUM(amount_rappen), 0) FROM payments WHERE paid_on LIKE :m',
                ['m' => $monat . '%']
            ) ?? 0),
        ];
    }

    private static function datum(string $wert): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($wert)) === 1 ? trim($wert) : '';
    }

    private static function base(): string
    {
        return '/' . trim((string) Config::get('create_path', 'create'), '/');
    }

    private static function back(int $id): Response
    {
        $ziel = $id > 0 ? self::base() . '/kunden/' . $id : self::base() . '/kunden';

        return Response::redirect($ziel)->noCache()->noIndex();
    }
}
