<?php

declare(strict_types=1);

namespace WebAtze\Http;

use WebAtze\Build\Backup;
use WebAtze\Core\{Audit, Config, Db, Request, Response, Session, Settings, View};
use WebAtze\Domain\Monitor;

/**
 * Das Wartungscenter: läuft alles, und ist alles gesichert?
 *
 * Die beiden Fragen gehören zusammen. Eine Website, die läuft, aber
 * nicht gesichert ist, ist ein Ausfall in der Zukunft; eine gesicherte,
 * die nicht läuft, ist einer in der Gegenwart.
 */
final class GuardController
{
    public function index(Request $request): Response
    {
        $sicherungen = Backup::listAll(20);

        $bytes = 0;
        foreach (Backup::listAll(400) as $zeile) {
            $bytes += (int) $zeile['bytes'];
        }

        return $this->page('Wartungscenter', 'admin/guard', [
            'waechter' => Monitor::all(),
            'kurz' => Monitor::summary(),
            'unten' => Monitor::down(),
            'sicherungen' => $sicherungen,
            'bytes' => $bytes,
            'letzteEigene' => Db::first(
                "SELECT * FROM backups WHERE scope = 'self' ORDER BY created_at DESC LIMIT 1"
            ),
            'behalten' => (int) Settings::get('backup_keep_per_site', '2'),
            'kunden' => Db::all('SELECT id, name FROM customers ORDER BY name ASC'),
            'projekte' => Db::all('SELECT id, name FROM projects ORDER BY name ASC'),
            'certWarnDays' => Monitor::CERT_WARN_DAYS,
        ]);
    }

    /** Ein einzelner Wächter mit seinem Verlauf. */
    public function show(Request $request): Response
    {
        $id = (int) $request->param('id');
        $waechter = Monitor::find($id);

        if ($waechter === null) {
            return Response::text('Diese Überwachung gibt es nicht.', 404)->noCache();
        }

        return $this->page($waechter['label'] ?: 'Überwachung', 'admin/guard-detail', [
            'waechter' => $waechter,
            'verlauf' => Monitor::history($id, 60),
            'quote30' => Monitor::uptime($id, 30),
            'quote7' => Monitor::uptime($id, 7),
            'kunden' => Db::all('SELECT id, name FROM customers ORDER BY name ASC'),
            'projekte' => Db::all('SELECT id, name FROM projects ORDER BY name ASC'),
        ]);
    }

    // ----------------------------------------------------- Überwachung

    public function save(Request $request): Response
    {
        $id = (int) $request->input('monitor_id', '0');

        $ergebnis = Monitor::save([
            'url' => (string) $request->input('url', ''),
            'label' => (string) $request->input('label', ''),
            'expect' => (string) $request->input('expect', ''),
            'customer_id' => (int) $request->input('customer_id', '0'),
            'project_id' => (int) $request->input('project_id', '0'),
            'every_minutes' => (int) $request->input('every_minutes', '15'),
            'active' => $request->bool('active'),
            'notify' => $request->bool('notify'),
        ], $id > 0 ? $id : null);

        Session::flash($ergebnis['ok'] ? 'success' : 'error', $ergebnis['meldung']);

        if ($ergebnis['ok']) {
            Audit::log('monitor.save', (string) $ergebnis['id'], [], $request);

            // Gleich einmal nachsehen – sonst steht die neue Zeile
            // bis zum nächsten Cron-Lauf ohne Zustand da.
            //
            // Eine tote Adresse braucht bis zu zwanzig Sekunden, bis
            // curl aufgibt. Das Zeitlimit auf gemietetem Hosting liegt
            // oft bei dreissig – zu knapp, um es dem Zufall zu
            // überlassen.
            @set_time_limit(60);

            $frisch = Monitor::find($ergebnis['id']);

            if ($frisch !== null && (int) $frisch['active'] === 1) {
                Monitor::run($frisch);
            }
        }

        return $this->to($id > 0 ? '/wartung/' . $id : '/wartung');
    }

    public function check(Request $request): Response
    {
        $id = (int) $request->input('monitor_id', '0');
        $waechter = Monitor::find($id);

        if ($waechter === null) {
            Session::flash('error', 'Diese Überwachung gibt es nicht.');

            return $this->to('/wartung');
        }

        $ergebnis = Monitor::run($waechter);

        Session::flash(
            $ergebnis['ok'] ? 'success' : 'error',
            $ergebnis['ok']
                ? 'Erreichbar, ' . $ergebnis['ms'] . ' ms.'
                : 'Antwortet nicht: ' . ($ergebnis['note'] !== '' ? $ergebnis['note'] : 'unbekannter Grund')
        );

        return $this->to('/wartung/' . $id);
    }

    public function checkAll(Request $request): Response
    {
        $geprueft = 0;
        $unten = 0;

        foreach (Monitor::all() as $waechter) {
            if ((int) $waechter['active'] !== 1) {
                continue;
            }

            $ergebnis = Monitor::run($waechter);
            $geprueft++;

            if (!$ergebnis['ok']) {
                $unten++;
            }
        }

        Session::flash(
            $unten > 0 ? 'error' : 'success',
            $geprueft === 0
                ? 'Es wird noch keine Website überwacht.'
                : $geprueft . ' geprüft, ' . ($unten === 0 ? 'alle erreichbar.' : $unten . ' antworten nicht.')
        );

        return $this->to('/wartung');
    }

    public function destroy(Request $request): Response
    {
        $id = (int) $request->input('monitor_id', '0');

        if (Monitor::remove($id)) {
            Audit::log('monitor.delete', (string) $id, [], $request);
            Session::flash('success', 'Die Überwachung ist beendet.');
        }

        return $this->to('/wartung');
    }

    // ------------------------------------------------------ Sicherungen

    public function runBackup(Request $request): Response
    {
        $eigen = Backup::daily();
        $projekt = Backup::nextProject();

        Audit::log('backup.run', $projekt !== '' ? $projekt : 'nur eigene', [], $request);

        $teile = [];

        if ($eigen) {
            $teile[] = 'die eigene Datenbank';
        }

        if ($projekt !== '') {
            $teile[] = 'die Website ' . $projekt;
        }

        Session::flash(
            $teile === [] ? 'info' : 'success',
            $teile === []
                ? 'Alles ist schon gesichert – heute gibt es nichts Neues zu tun.'
                : 'Gesichert: ' . implode(' und ', $teile) . '.'
        );

        return $this->to('/wartung');
    }

    /**
     * Wie viele Stände je Website aufbewahrt werden.
     *
     * Eins ist erlaubt, aber nicht empfohlen, und die Meldung sagt auch
     * warum: Wird eine beschädigte Seite über den letzten guten Stand
     * gesichert, ist der gute Stand weg.
     */
    public function saveRetention(Request $request): Response
    {
        $behalten = max(1, min(30, (int) $request->input('behalten', '2')));
        Settings::put('backup_keep_per_site', (string) $behalten);
        Backup::prune();

        Session::flash(
            'success',
            $behalten === 1
                ? 'Es wird nur noch der neueste Stand behalten. Bedenke: Wird eine bereits '
                  . 'beschädigte Seite gesichert, überschreibt sie den letzten guten Stand.'
                : 'Es werden ' . $behalten . ' Stände je Website behalten.'
        );

        return $this->to('/wartung');
    }

    public function downloadBackup(Request $request): Response
    {
        $pfad = Backup::pathFor((int) $request->param('id'));

        if ($pfad === null) {
            return Response::text('Diese Sicherung gibt es nicht mehr.', 404)->noCache();
        }

        Audit::log('backup.download', basename($pfad), [], $request);

        return Response::file($pfad, 'application/zip', true, basename($pfad))->noCache()->noIndex();
    }

    /** Eine Sicherung entfernen. */
    public function deleteBackup(Request $request): Response
    {
        $id = (int) $request->input('backup_id', '0');

        $zeile = \WebAtze\Core\Db::first('SELECT path FROM backups WHERE id = :id', ['id' => $id]);
        $name = $zeile !== null ? basename((string) $zeile['path']) : '';

        if (!Backup::remove($id)) {
            Session::flash('error', 'Diese Sicherung gibt es nicht mehr.');

            return $this->zurueck();
        }

        Audit::log('backup.delete', $name, ['id' => $id], $request);
        Session::flash('success', 'Sicherung gelöscht.');

        return $this->zurueck();
    }

    /** Jetzt sichern, ohne auf die stündliche Pflege zu warten. */
    public function backupNow(Request $request): Response
    {
        @set_time_limit(120);

        $art = (string) $request->input('art', 'dateien');

        if ($art === 'datenbank') {
            \WebAtze\Core\Settings::put('backup_self_on', '');
            $ok = Backup::daily();
            $was = 'Datenbanksicherung';
        } else {
            \WebAtze\Core\Settings::put('backup_files_on', '');
            $ok = Backup::dailyFiles();
            $was = 'Dateisicherung';
        }

        Audit::log('backup.now', $was, ['erfolg' => $ok], $request);

        Session::flash(
            $ok ? 'success' : 'error',
            $ok ? $was . ' angelegt.' : $was . ' hat nicht geklappt – siehe Protokoll.'
        );

        return $this->zurueck();
    }

    private function zurueck(): Response
    {
        return Response::redirect(
            '/' . trim((string) Config::get('create_path', 'create'), '/') . '/wartung#sicherungen'
        )->noCache()->noIndex();
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
