<?php

declare(strict_types=1);

namespace WebAtze\Http;

use WebAtze\Core\{Audit, Config, ConfigFile, Crypto, Db, Logger, Request, Response, Session, View};
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
            ] + $this->schluesselLage()),
        ]))->noCache()->noIndex();
    }

    /**
     * Was den Tresor am Verschlüsseln hindert – und ob es sich von hier
     * aus beheben lässt.
     *
     * Alles in einem try, und das aus Erfahrung: Diese Angaben sind
     * Beiwerk, die Liste ist der Zweck der Seite. Eine Seite, die an
     * ihrer eigenen Fehlerdiagnose stirbt, ist die schlechteste aller
     * Möglichkeiten – und genau das ist hier einmal passiert, weil auf
     * dem Server die Erweiterung «sodium» fehlte und schon das Erzeugen
     * eines Vorschlagsschlüssels daran hing.
     *
     * @return array<string, mixed>
     */
    private function schluesselLage(): array
    {
        try {
            $fehler = Crypto::status();
            $verschluesselt = Vault::encryptedCount();
            $schreibbar = ConfigFile::isWritable();

            return [
                'schluesselFehler' => $fehler,
                'verschluesselt' => $verschluesselt,
                'schluesselSchreibbar' => $schreibbar,
                // Reparieren geht nur, wenn die Erweiterung da ist, nichts
                // Verschlüsseltes verloren gehen kann und die Datei sich
                // schreiben lässt.
                // Reparieren heisst: einen neuen Schlüssel schreiben. Das
                // hilft nur, wenn überhaupt ein Verfahren da ist und nichts
                // Verschlüsseltes verloren gehen kann.
                'reparierbar' => $fehler !== ''
                    && Crypto::method() !== ''
                    && $verschluesselt === 0
                    && $schreibbar,
                'vorschlagSchluessel' => $fehler === '' ? '' : Crypto::newKey(),
                'verfahren' => Crypto::method(),
                'diagnose' => $fehler === '' ? [] : Crypto::diagnosis(),
            ];
        } catch (\Throwable $e) {
            Logger::exception($e);

            return [
                'schluesselFehler' => 'Die Verschlüsselung lässt sich auf diesem Server nicht '
                    . 'prüfen: ' . $e->getMessage(),
                'verschluesselt' => 0,
                'schluesselSchreibbar' => false,
                'reparierbar' => false,
                'vorschlagSchluessel' => '',
                'verfahren' => '',
                'diagnose' => [],
            ];
        }
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

    /**
     * Einen fehlenden oder unbrauchbaren crypto_key ersetzen.
     *
     * Nur wenn nichts Verschlüsseltes herumliegt: Ein neuer Schlüssel
     * macht alles Bisherige unlesbar. Wo schon etwas liegt, ist das eine
     * Entscheidung, die niemand nebenbei per Knopfdruck treffen sollte.
     */
    public function repairKey(Request $request): Response
    {
        if (Crypto::isReady()) {
            Session::flash('info', 'Der Schlüssel ist in Ordnung – da gibt es nichts zu reparieren.');

            return $this->to('/passwoerter');
        }

        if (Crypto::method() === '') {
            Session::flash('error', Crypto::status());

            return $this->to('/passwoerter');
        }

        $vorhanden = Vault::encryptedCount();

        if ($vorhanden > 0) {
            Session::flash(
                'error',
                'Es liegen ' . $vorhanden . ' verschlüsselte Einträge vor. Ein neuer Schlüssel '
                . 'würde sie unlesbar machen. Trage stattdessen den ursprünglichen crypto_key '
                . 'wieder in app/config.php ein – oder lösche die betroffenen Einträge zuerst.'
            );

            return $this->to('/passwoerter');
        }

        $fehler = ConfigFile::set('crypto_key', Crypto::newKey());

        if ($fehler !== '') {
            Session::flash('error', $fehler);

            return $this->to('/passwoerter');
        }

        Audit::log('vault.key.repaired', '', [], $request);
        Session::flash('success', 'Ein neuer Schlüssel steht in app/config.php. Der Tresor ist bereit.');

        return $this->to('/passwoerter');
    }

    private function to(string $pfad): Response
    {
        $base = '/' . trim((string) Config::get('create_path', 'create'), '/');

        return Response::redirect($base . $pfad)->noCache()->noIndex();
    }
}
