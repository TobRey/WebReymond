<?php

declare(strict_types=1);

namespace WebAtze\Http;

use WebAtze\Core\{Audit, Config, Request, Response, Session, View};
use WebAtze\Domain\{Prospects, SearchOrder};

/**
 * Die Kundensuche.
 *
 * Eine Firma zur Zeit, ansehen, annehmen oder weglegen. Wer zwanzig
 * Firmen gleichzeitig sieht, entscheidet bei keiner.
 */
final class ProspectController
{
    // ----------------------------------------------------- Durchblättern

    public function index(Request $request): Response
    {
        $firma = Prospects::next();

        return $this->page('Kundensuche', 'admin/prospects', [
            'firma' => $firma,
            'wartend' => Prospects::waiting(),
            'zahlen' => Prospects::counts(),
            'google' => $firma !== null ? Prospects::googleUrl($firma) : '',
            'karte' => $firma !== null ? Prospects::mapsUrl($firma) : '',
            'auftrag' => SearchOrder::build(SearchOrder::lastUsed()),
            'filter' => SearchOrder::lastUsed(),
        ]);
    }

    /** Annehmen, ablehnen oder für immer wegräumen. */
    public function decide(Request $request): Response
    {
        $id = (int) $request->input('prospect_id', '0');
        $wahl = (string) $request->input('wahl', '');

        $status = match ($wahl) {
            'ja' => 'vorgemerkt',
            'nein' => 'abgelehnt',
            'nie' => 'nie',
            default => '',
        };

        if ($status === '' || !Prospects::decide($id, $status)) {
            Session::flash('error', 'Diese Firma gibt es nicht mehr.');

            return $this->to('/kundensuche');
        }

        Audit::log('prospect.' . $status, (string) $id, [], $request);

        Session::flash('success', match ($status) {
            'vorgemerkt' => 'Vorgemerkt – steht jetzt in der Liste.',
            'abgelehnt' => 'Weggelegt.',
            default => 'Weggelegt, und kommt nicht wieder.',
        });

        return $this->to('/kundensuche');
    }

    // ------------------------------------------------------- Die Liste

    public function shortlist(Request $request): Response
    {
        return $this->page('Potenzielle Kunden', 'admin/prospect-list', [
            'liste' => Prospects::shortlist(),
            'verworfen' => Prospects::discarded(),
            'zahlen' => Prospects::counts(),
            'status' => Prospects::STATUS,
            'zustaende' => Prospects::SITE_STATES,
        ]);
    }

    public function setStatus(Request $request): Response
    {
        $id = (int) $request->input('prospect_id', '0');
        $status = (string) $request->input('status', '');

        if (!Prospects::decide($id, $status)) {
            Session::flash('error', 'Das ging nicht.');
        }

        return $this->to('/potenzielle-kunden');
    }

    public function note(Request $request): Response
    {
        $id = (int) $request->input('prospect_id', '0');
        Prospects::note($id, $request->text('note', 4000));
        Session::flash('success', 'Notiz gespeichert.');

        return $this->to('/potenzielle-kunden');
    }

    public function destroy(Request $request): Response
    {
        $id = (int) $request->input('prospect_id', '0');

        if (Prospects::remove($id)) {
            Audit::log('prospect.delete', (string) $id, [], $request);
            Session::flash('success', 'Gelöscht.');
        }

        return $this->to('/potenzielle-kunden');
    }

    /** Aus einer vorgemerkten Firma einen Kunden machen. */
    public function convert(Request $request): Response
    {
        $id = (int) $request->input('prospect_id', '0');
        $kunde = Prospects::toCustomer($id);

        if ($kunde === null) {
            Session::flash('error', 'Diese Firma gibt es nicht mehr.');

            return $this->to('/potenzielle-kunden');
        }

        Audit::log('prospect.convert', (string) $id, ['customer' => $kunde], $request);
        Session::flash('success', 'Als Kunde angelegt. Alles Recherchierte steht in den Notizen.');

        return $this->to('/kunden/' . $kunde);
    }

    // ------------------------------------------------- Nachschub holen

    /** Den Suchauftrag mit neuen Angaben erzeugen. */
    public function order(Request $request): Response
    {
        $filter = [
            'region' => (string) $request->input('region', ''),
            'branch' => (string) $request->input('branch', ''),
            'count' => (int) $request->input('count', '10'),
            'criteria' => $request->arrayOf('criteria'),
            'extra' => (string) $request->input('extra', ''),
        ];

        SearchOrder::remember($filter);
        Session::flash('success', 'Der Suchauftrag steht bereit zum Kopieren.');

        return $this->to('/kundensuche');
    }

    /** Die Antwort einlesen. */
    public function import(Request $request): Response
    {
        $ergebnis = Prospects::import($request->text('antwort', 200000));

        Session::flash($ergebnis['ok'] ? 'success' : 'error', $ergebnis['meldung']);

        if ($ergebnis['ok']) {
            Audit::log('prospect.import', (string) $ergebnis['neu'], [], $request);
        }

        return $this->to('/kundensuche');
    }

    /** Eine Firma von Hand eintragen – manchmal fällt einem einfach eine ein. */
    public function add(Request $request): Response
    {
        $name = trim((string) $request->input('name', ''));

        if ($name === '') {
            Session::flash('error', 'Ohne Namen geht es nicht.');

            return $this->to('/potenzielle-kunden');
        }

        Prospects::save([
            'name' => $name,
            'branch' => (string) $request->input('branch', ''),
            'place' => (string) $request->input('place', ''),
            'website' => (string) $request->input('website', ''),
            'email' => (string) $request->input('email', ''),
            'phone' => (string) $request->input('phone', ''),
            'contact_name' => (string) $request->input('contact_name', ''),
            'research' => $request->text('research', 4000),
            'source' => 'hand',
            'status' => 'vorgemerkt',
        ]);

        Session::flash('success', 'Eingetragen.');

        return $this->to('/potenzielle-kunden');
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
