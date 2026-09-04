<?php

declare(strict_types=1);

namespace WebAtze\Http;

use WebAtze\Build\{Contracts, DocumentBuilder};
use WebAtze\Core\{Audit, Config, Db, Mailer, Request, Response, Session, Settings, View};
use WebAtze\Domain\Websites;

/**
 * Offerten, Rechnungen und Wartungsverträge.
 *
 * Alles an einer Stelle, weil es dasselbe ist: Was kostet es, ist es
 * bezahlt, läuft es weiter.
 */
final class BillingController
{
    // ------------------------------------------------------------ Übersicht

    public function index(Request $request): Response
    {
        $kind = $request->query('art');
        $kind = isset(DocumentBuilder::KINDS[$kind]) ? $kind : '';

        // Kommt der Aufruf von einer Kundenseite, ist der Empfänger
        // schon bekannt. Ihn abzutippen wäre reine Fleissarbeit - und
        // Fleissarbeit erzeugt Tippfehler in Adressen.
        $kunde = null;
        $kundeId = $request->int('kunde');

        if ($kundeId > 0) {
            $kunde = Db::first('SELECT * FROM customers WHERE id = :id', ['id' => $kundeId]);
        }

        return Response::html(View::partial('layouts/admin', [
            'title' => 'Offerten und Rechnungen',
            'content' => View::partial('admin/billing', [
                'rows' => DocumentBuilder::listAll($kind),
                'kind' => $kind,
                'summary' => DocumentBuilder::summary(),
                'projects' => Websites::pickList(),
                'kunden' => Db::all('SELECT id, name FROM customers ORDER BY name ASC LIMIT 300'),
                'kunde' => $kunde,
                'vorgabe' => $request->query('art') === 'offer' ? 'offer' : '',
                'ibanMissing' => trim(Settings::get('iban', '')) === '',
            ]),
        ]))->noCache()->noIndex();
    }

    // -------------------------------------------------------------- Anlegen

    public function create(Request $request): Response
    {
        $items = [];

        foreach ($request->arrayOf('item_label') as $index => $label) {
            $label = trim((string) $label);

            if ($label === '') {
                continue;
            }

            $items[] = [
                'label' => $label,
                'quantity' => (float) str_replace(',', '.', (string) ($request->arrayOf('item_quantity')[$index] ?? '1')),
                'price_rappen' => self::toRappen((string) ($request->arrayOf('item_price')[$index] ?? '0')),
            ];
        }

        if ($items === []) {
            Session::flash('error', 'Ohne eine einzige Zeile gibt es nichts zu berechnen.');
            return Response::redirect(self::base() . '/rechnungen');
        }

        $id = DocumentBuilder::create([
            'kind' => $request->input('kind'),
            'project_id' => $request->int('project_id') ?: null,
            'customer_id' => $request->int('customer_id') ?: null,
            'title' => $request->input('title'),
            'recipient' => [
                'name' => $request->input('recipient_name'),
                'attn' => $request->input('recipient_attn'),
                'street' => $request->input('recipient_street'),
                'city' => $request->input('recipient_city'),
                'email' => $request->input('recipient_email'),
            ],
            'items' => $items,
            'intro' => $request->text('intro', 4000),
            'outro' => $request->text('outro', 4000),
            'vat_percent' => $request->int('vat_percent'),
            'due_days' => $request->int('due_days') ?: 30,
        ]);

        Audit::log('document.create', (string) $id, ['art' => $request->input('kind')], $request);

        Session::flash('success', 'Angelegt. Das PDF liegt bereit.');

        return Response::redirect(self::base() . '/rechnungen');
    }

    /** Das PDF herunterladen. */
    public function download(Request $request): Response
    {
        $doc = DocumentBuilder::find((int) $request->param('id'));

        if ($doc === null) {
            return Response::text('Dieses Dokument gibt es nicht.', 404)->noCache();
        }

        $path = (string) $doc['file_path'];

        // Fehlt die Datei, wird sie neu geschrieben – die Daten stehen
        // ja in der Datenbank. Ein toter Verweis wäre die schlechtere
        // Antwort.
        if ($path === '' || !is_file($path)) {
            $path = DocumentBuilder::render((int) $doc['id']);
        }

        if ($path === '' || !is_file($path)) {
            return Response::text('Das PDF liess sich nicht erzeugen.', 500)->noCache();
        }

        return Response::file($path, 'application/pdf', true, basename($path))->noCache()->noIndex();
    }

    /** Status ändern: verschickt, bezahlt, storniert. */
    public function setStatus(Request $request): Response
    {
        $id = (int) $request->param('id');
        $status = $request->input('status');

        if (!DocumentBuilder::setStatus($id, $status)) {
            Session::flash('error', 'Diesen Zustand gibt es nicht.');
            return Response::redirect(self::base() . '/rechnungen');
        }

        Audit::log('document.status', (string) $id, ['status' => $status], $request);

        // Bei "bezahlt" entsteht eine Einnahme in der Buchhaltung, beim
        // Zuruecknehmen verschwindet sie wieder. Das gehoert in die
        // Meldung: Wer eine Rechnung umstellt, soll wissen, dass sich
        // damit auch die Statistik aendert.
        $doc = DocumentBuilder::find($id);
        $rechnung = $doc !== null && (string) $doc['kind'] === 'invoice';

        $zusatz = '';

        if ($rechnung && $status === 'paid') {
            $zusatz = ' Der Betrag steht jetzt als Einnahme in der Buchhaltung.';
        } elseif ($rechnung && (string) ($doc['paid_on'] ?? '') === '') {
            $zusatz = ' Aus der Buchhaltung ist der Betrag wieder heraus.';
        }

        Session::flash('success',
            'Vermerkt: ' . (DocumentBuilder::STATUS[$status] ?? $status) . '.' . $zusatz);

        return Response::redirect(self::base() . '/rechnungen');
    }

    /**
     * Eine Offerte oder Rechnung entfernen.
     *
     * Mit ihr geht das PDF und - war sie bezahlt - die Einnahme in der
     * Buchhaltung. Eine Zahl in der Statistik ohne Beleg dahinter waere
     * schlimmer als eine geloeschte Rechnung.
     */
    public function destroy(Request $request): Response
    {
        $id = (int) $request->param('id');
        $doc = DocumentBuilder::find($id);

        if ($doc === null) {
            Session::flash('error', 'Diesen Beleg gibt es nicht mehr.');

            return Response::redirect(self::base() . '/rechnungen');
        }

        $war_bezahlt = (string) $doc['status'] === 'paid';
        $nummer = (string) $doc['number'];

        if (!DocumentBuilder::remove($id)) {
            Session::flash('error', 'Das Löschen hat nicht geklappt.');

            return Response::redirect(self::base() . '/rechnungen');
        }

        Audit::log('document.delete', $nummer, [
            'id' => $id,
            'art' => (string) $doc['kind'],
            'war_bezahlt' => $war_bezahlt,
        ], $request);

        Session::flash('success',
            (DocumentBuilder::KINDS[(string) $doc['kind']] ?? 'Beleg') . ' ' . $nummer . ' gelöscht.'
            . ($war_bezahlt ? ' Die Einnahme ist aus der Buchhaltung verschwunden.' : ''));

        return Response::redirect(self::base() . '/rechnungen');
    }

    /** Aus einer Offerte eine Rechnung machen. */
    public function convert(Request $request): Response
    {
        $new = DocumentBuilder::toInvoice((int) $request->param('id'));

        if ($new === null) {
            Session::flash('error', 'Das geht nur bei einer Offerte.');
        } else {
            Audit::log('document.convert', (string) $new, [], $request);
            Session::flash('success', 'Rechnung angelegt. Sieh sie durch, bevor du sie verschickst.');
        }

        return Response::redirect(self::base() . '/rechnungen');
    }

    /** Das PDF an den Kunden schicken – als Verweis, nicht als Anhang. */
    public function send(Request $request): Response
    {
        $doc = DocumentBuilder::find((int) $request->param('id'));

        if ($doc === null) {
            return Response::text('Dieses Dokument gibt es nicht.', 404)->noCache();
        }

        $to = trim((string) (((array) $doc['recipient'])['email'] ?? ''));

        if ($to === '') {
            Session::flash('error', 'Für diesen Empfänger ist keine E-Mail-Adresse hinterlegt.');
            return Response::redirect(self::base() . '/rechnungen');
        }

        $kind = DocumentBuilder::KINDS[(string) $doc['kind']] ?? 'Dokument';

        $ok = Mailer::send(
            $to,
            $kind . ' ' . (string) $doc['number'],
            "Guten Tag\n\n"
            . 'Anbei ' . ($doc['kind'] === 'invoice' ? 'die Rechnung' : 'die Offerte')
            . ' ' . (string) $doc['number'] . ".\n\n"
            . 'Betrag: CHF ' . DocumentBuilder::money((int) $doc['total_rappen']) . "\n"
            . ((string) $doc['due_on'] !== ''
                ? 'Zahlbar bis: ' . date('d.m.Y', strtotime((string) $doc['due_on'])) . "\n"
                : '')
            . "\nBei Fragen melden Sie sich einfach.\n\n"
            . "Freundliche Grüsse\n"
            . (string) Settings::get('owner_name', Config::get('mail.from_name', 'WebAtze')) . "\n"
        );

        if ($ok) {
            DocumentBuilder::setStatus((int) $doc['id'], 'sent');
            Session::flash(
                'success',
                'Nachricht an ' . $to . ' verschickt. Das PDF selbst hängt nicht daran – '
                . 'lade es herunter und häng es in deinem Mailprogramm an, oder schick '
                . 'den Betrag so wie er ist.'
            );
        } else {
            Session::flash('error', 'Der Versand hat nicht geklappt. Läuft mail() auf diesem Hosting?');
        }

        return Response::redirect(self::base() . '/rechnungen');
    }

    // -------------------------------------------------------------- Verträge

    public function contracts(Request $request): Response
    {
        return Response::html(View::partial('layouts/admin', [
            'title' => 'Wartungsverträge',
            'content' => View::partial('admin/contracts', [
                'rows' => Contracts::listAll(),
                // Ohne Filter auf den Zustand: Frueher stand hier
                // "status IN ('done','published')". Diese Zustaende gibt
                // es bei einer Website nicht, das Feld war deshalb immer
                // leer - auch bei von Hand eingetragenen Seiten.
                'projects' => Websites::pickList(),
            ]),
        ]))->noCache()->noIndex();
    }

    public function createContract(Request $request): Response
    {
        $projectId = $request->int('project_id');

        if ($projectId <= 0) {
            Session::flash('error', 'Ein Vertrag gehört zu einer Website.');
            return Response::redirect(self::base() . '/vertraege');
        }

        Contracts::create([
            'project_id' => $projectId,
            'plan' => $request->input('plan'),
            'price_rappen' => self::toRappen($request->input('price')),
            'interval_months' => $request->int('interval_months') ?: 12,
            'started_on' => $request->input('started_on'),
            'note' => $request->text('note', 2000),
        ]);

        Audit::log('contract.create', (string) $projectId, [], $request);

        Session::flash('success', 'Vertrag angelegt. Die erste Rechnung entsteht am Fälligkeitstag von selbst.');

        return Response::redirect(self::base() . '/vertraege');
    }

    public function cancelContract(Request $request): Response
    {
        Contracts::cancel((int) $request->param('id'));

        Audit::log('contract.cancel', (string) $request->param('id'), [], $request);

        Session::flash('info', 'Gekündigt. Es wird nichts mehr abgerechnet.');

        return Response::redirect(self::base() . '/vertraege');
    }

    /** Fällige Verträge sofort abrechnen, ohne auf die Pflege zu warten. */
    public function billContracts(Request $request): Response
    {
        $made = Contracts::billDue();

        Session::flash(
            $made > 0 ? 'success' : 'info',
            $made > 0
                ? $made . ' Rechnungsentwürfe angelegt. Sie liegen unter "Offerten und Rechnungen".'
                : 'Nichts fällig.'
        );

        return Response::redirect(self::base() . '/vertraege');
    }

    // ------------------------------------------------------------ Kleinkram

    /**
     * "1234.50" -> 123450 Rappen.
     *
     * Über Zeichenketten, nicht über Fliesskomma: (int) (12.10 * 100)
     * ergibt in PHP 1209, nicht 1210. Bei einer Rechnung ist das der
     * Unterschied zwischen richtig und peinlich.
     */
    public static function toRappen(string $value): int
    {
        $value = str_replace([' ', "'", ','], ['', '', '.'], trim($value));

        // "990.-" ist hierzulande eine ganz normale Schreibweise für
        // 990 Franken. Sie als Null zu lesen wäre ärgerlich.
        $value = preg_replace('/\.[-–]$/u', '', $value) ?? $value;

        if ($value === '' || !preg_match('/^-?\d+(\.\d{0,2})?$/', $value)) {
            return 0;
        }

        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');

        $parts = explode('.', $value, 2);
        $francs = (int) $parts[0];
        $rappen = (int) str_pad($parts[1] ?? '0', 2, '0', STR_PAD_RIGHT);

        $total = $francs * 100 + $rappen;

        return $negative ? -$total : $total;
    }

    private static function base(): string
    {
        return '/' . trim((string) Config::get('create_path', 'create'), '/');
    }
}
