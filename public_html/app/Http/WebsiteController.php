<?php

declare(strict_types=1);

namespace WebAtze\Http;

use WebAtze\Core\{Audit, Config, Db, Request, Response, Session, View};
use WebAtze\Domain\{Monitor, Visits, Websites};

/**
 * Alle Websites an einem Ort – die selbst gebauten und die
 * hinzugefügten.
 *
 * Selbst gebaute führen weiter auf ihre Projektseite: Dort stehen
 * Auftrag, Abschnitte und Pakete. Hinzugefügte haben nichts davon und
 * bekommen deshalb ihre eigene, schlichte Seite.
 */
final class WebsiteController
{
    public function index(Request $request): Response
    {
        $suche = trim((string) $request->query('suche', ''));
        $herkunft = (string) $request->query('herkunft', '');
        $kunde = $request->int('kunde');

        return $this->page('Websites', 'admin/websites', [
            'websites' => Websites::all($suche, $herkunft, $kunde),
            'suche' => $suche,
            'herkunft' => isset(Websites::SOURCES[$herkunft]) ? $herkunft : '',
            'kundeId' => $kunde,
            'zahlen' => Websites::counts(),
            'kunden' => Db::all('SELECT id, name FROM customers ORDER BY name ASC'),
            'status' => Websites::STATUS,
            'herkuenfte' => Websites::SOURCES,
        ]);
    }

    /** Das leere Formular. */
    public function blank(Request $request): Response
    {
        return $this->page('Website hinzufügen', 'admin/website', [
            'website' => null,
            'kunden' => Db::all('SELECT id, name FROM customers ORDER BY name ASC'),
            'status' => Websites::STATUS,
            'plattformen' => Websites::PLATFORMS,
            'waechter' => null,
        ]);
    }

    /**
     * Eine hinzugefügte Website im Einzelnen.
     *
     * Eine selbst gebaute gehört nicht hierher – für sie gibt es die
     * Projektseite mit allem, was zum Aufbau dazugehört.
     */
    public function show(Request $request): Response
    {
        $website = Websites::find($request->paramInt('id'));

        if ($website === null) {
            return Response::text('Diese Website gibt es nicht.', 404)->noCache();
        }

        if ((string) $website['source'] !== 'hand') {
            return Response::redirect(self::base() . '/projekt/' . (int) $website['id'])
                ->noCache()
                ->noIndex();
        }

        return $this->page($website['name'], 'admin/website', [
            'website' => $website,
            'kunden' => Db::all('SELECT id, name FROM customers ORDER BY name ASC'),
            'status' => Websites::STATUS,
            'plattformen' => Websites::PLATFORMS,
            'waechter' => Websites::monitorFor($website),
        ]);
    }

    public function save(Request $request): Response
    {
        $id = (int) $request->input('website_id', '0');

        $ergebnis = Websites::save([
            'name' => (string) $request->input('name', ''),
            'domain' => (string) $request->input('domain', ''),
            'customer_id' => (int) $request->input('customer_id', '0'),
            'status' => (string) $request->input('status', 'live'),
            'platform' => (string) $request->input('platform', ''),
            'hosting' => (string) $request->input('hosting', ''),
            'notes' => $request->text('notes', 4000),
            'live_since' => (string) $request->input('live_since', ''),
        ], $id > 0 ? $id : null);

        if (!$ergebnis['ok']) {
            Session::flash('error', $ergebnis['meldung']);

            return $this->to($id > 0 ? '/websites/' . $id : '/websites/neu');
        }

        Audit::log('website.save', (string) $request->input('name', ''), ['id' => $ergebnis['id']], $request);

        // Wer eine Domain angibt, bekommt Überwachung und Zählung -
        // ohne Haken, ohne daran zu denken. Das war vorher eine
        // Entscheidung, und wer sie vergass, hatte eine Website, die
        // niemand prüft und die niemand zählt.
        $frisch = Websites::find($ergebnis['id']);
        $adresse = $frisch !== null ? Websites::url($frisch) : '';

        if ($adresse !== '') {
            // Der Zählschlüssel entsteht sofort - der Einzeiler dazu
            // steht auf der Seite der Website.
            Visits::key($ergebnis['id']);

            // Steht sie schon unter Aufsicht, passiert hier nichts.
            @set_time_limit(60);
            Monitor::adopt(
                $adresse,
                (string) ($frisch['name'] ?? ''),
                ((int) ($frisch['customer_id'] ?? 0)) ?: null,
                $ergebnis['id']
            );
        }

        Session::flash('success', $ergebnis['meldung']);

        return $this->to('/websites/' . $ergebnis['id']);
    }

    /** Einem Kunden zuordnen – auch von der Liste aus. */
    public function assign(Request $request): Response
    {
        $id = (int) $request->input('website_id', '0');

        if (Websites::assign($id, (int) $request->input('customer_id', '0'))) {
            Audit::log('website.assign', (string) $id, [], $request);
            Session::flash('success', 'Zugeordnet.');
        } else {
            Session::flash('error', 'Diese Website gibt es nicht.');
        }

        return $this->to('/websites');
    }

    public function destroy(Request $request): Response
    {
        $id = (int) $request->input('website_id', '0');

        if (Websites::remove($id)) {
            Audit::log('website.delete', (string) $id, [], $request);
            Session::flash('success', 'Aus der Liste entfernt.');

            return $this->to('/websites');
        }

        Session::flash(
            'error',
            'Nur hinzugefügte Websites lassen sich hier entfernen. Eine selbst gebaute wird '
            . 'auf ihrer Projektseite gelöscht – dort wird auch alles aufgeräumt, was daran hängt.'
        );

        return $this->to('/websites/' . $id);
    }

    // ------------------------------------------------------------------

    private function page(string $titel, string $vorlage, array $daten): Response
    {
        return Response::html(View::partial('layouts/admin', [
            'title' => $titel,
            'content' => View::partial($vorlage, $daten),
        ]))->noCache()->noIndex();
    }

    private static function base(): string
    {
        return '/' . trim((string) Config::get('create_path', 'create'), '/');
    }

    private function to(string $pfad): Response
    {
        return Response::redirect(self::base() . $pfad)->noCache()->noIndex();
    }
}
