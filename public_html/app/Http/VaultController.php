<?php

declare(strict_types=1);

namespace WebAtze\Http;

use WebAtze\Core\{Config, Db, Request, Response, Session, View};
use WebAtze\Domain\Vault;

/**
 * Die Passwortverwaltung.
 *
 * Die Regeln stehen im Kopf von Domain/Vault. Hier kommt eine dazu, die
 * nur diesen Teil betrifft: Ein Passwort verlässt den Server einzeln,
 * auf ausdrücklichen Knopfdruck, als JSON – niemals eingebettet in eine
 * Seite. Eine Seite landet im Verlauf des Browsers, im Zwischenspeicher
 * und womöglich in einem Ausdruck; eine Antwort mit no-store nicht.
 */
final class VaultController
{
    public function index(Request $request): Response
    {
        $suche = trim((string) $request->query('suche', ''));

        return Response::html(View::partial('layouts/admin', [
            'title' => 'Passwörter',
            'content' => View::partial('admin/vault', [
                'eintraege' => Vault::listAll($suche),
                'suche' => $suche,
                'offen' => Vault::isOpen(),
                'restzeit' => Vault::openSecondsLeft(),
                'arten' => Vault::KINDS,
                'kunden' => Db::all('SELECT id, name FROM customers ORDER BY name ASC'),
                'vorschlag' => Vault::suggest(),
            ]),
        ]))->noCache()->noIndex();
    }

    /** Aufschliessen mit dem eigenen Anmeldepasswort. */
    public function unlock(Request $request): Response
    {
        $ergebnis = Vault::unlock((string) $request->input('password', ''), $request->ip());

        Session::flash($ergebnis['ok'] ? 'success' : 'error', $ergebnis['meldung']);

        return $this->to('/passwoerter');
    }

    public function lock(Request $request): Response
    {
        Vault::lock();
        Session::flash('info', 'Der Tresor ist wieder zu.');

        return $this->to('/passwoerter');
    }

    /**
     * Ein einzelnes Passwort holen.
     *
     * Antwortet als JSON, damit der Knopf es in die Zwischenablage legen
     * kann, ohne dass es je im Seitenquelltext stand.
     */
    public function reveal(Request $request): Response
    {
        $ergebnis = Vault::reveal((int) $request->input('secret_id', '0'));

        return Response::json([
            'ok' => $ergebnis['ok'],
            // 'error' erwartet die Bedienung im Browser, 'meldung' ist
            // dasselbe in der Sprache des uebrigen Programms.
            'error' => $ergebnis['meldung'],
            'meldung' => $ergebnis['meldung'],
            'geheimnis' => $ergebnis['geheimnis'],
        ], $ergebnis['ok'] ? 200 : 403)->noCache()->noIndex();
    }

    public function save(Request $request): Response
    {
        $id = (int) $request->input('secret_id', '0');

        $ergebnis = Vault::save([
            'label' => (string) $request->input('label', ''),
            'kind' => (string) $request->input('kind', 'weiteres'),
            'username' => (string) $request->input('username', ''),
            'url' => (string) $request->input('url', ''),
            'note' => $request->text('note', 2000),
            'customer_id' => (int) $request->input('customer_id', '0'),
            'secret' => (string) $request->input('secret', ''),
        ], $id > 0 ? $id : null);

        Session::flash($ergebnis['ok'] ? 'success' : 'error', $ergebnis['meldung']);

        return $this->to('/passwoerter');
    }

    public function destroy(Request $request): Response
    {
        if (!Vault::isOpen()) {
            Session::flash('error', 'Zum Löschen muss der Tresor offen sein.');

            return $this->to('/passwoerter');
        }

        if (Vault::remove((int) $request->input('secret_id', '0'))) {
            Session::flash('success', 'Gelöscht.');
        }

        return $this->to('/passwoerter');
    }

    /** Die FTP-Zugänge aus den Projekten hereinholen. */
    public function adopt(Request $request): Response
    {
        if (!Vault::isOpen()) {
            Session::flash('error', 'Dafür muss der Tresor offen sein.');

            return $this->to('/passwoerter');
        }

        $anzahl = Vault::adoptDeployTargets();

        Session::flash(
            $anzahl > 0 ? 'success' : 'info',
            $anzahl > 0
                ? $anzahl . ' ' . ($anzahl === 1 ? 'Zugang' : 'Zugänge') . ' übernommen.'
                : 'Es gab nichts zu übernehmen.'
        );

        return $this->to('/passwoerter');
    }

    private function to(string $pfad): Response
    {
        $base = '/' . trim((string) Config::get('create_path', 'create'), '/');

        return Response::redirect($base . $pfad)->noCache()->noIndex();
    }
}
