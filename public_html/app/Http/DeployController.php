<?php

declare(strict_types=1);

namespace WebAtze\Http;

use WebAtze\Build\{FtpDeployer, ZipExporter};
use WebAtze\Core\{Audit, Config, Crypto, Db, Jobs, Logger, Request, Response, Session, View};

/**
 * Paket erzeugen, herunterladen und die Website hochladen.
 */
final class DeployController
{
    public function show(Request $request): Response
    {
        $project = ProjectController::find($request->paramInt('id'));
        if ($project === null) {
            return Response::notFound();
        }

        $target = Db::first(
            'SELECT * FROM deploy_targets WHERE project_id = :p ORDER BY id DESC LIMIT 1',
            ['p' => (int) $project['id']]
        );

        $providers = require APP_DIR . '/Support/providers.php';

        return Response::html(View::partial('layouts/admin', [
            'title' => 'Veröffentlichen: ' . (string) $project['name'],
            'content' => View::partial('admin/deploy', [
                'project' => $project,
                'target' => $target,
                'builds' => ZipExporter::listFor((int) $project['id']),
                'job' => Jobs::activeFor((int) $project['id']),
                'providers' => $providers,
                'brief' => json_decode((string) $project['brief'], true) ?: [],
                // Was der letzte Verbindungstest dort gefunden hat.
                'gefunden' => (array) Session::get('ftp_ordner_' . (int) $project['id'], []),
            ]),
        ]))->noCache()->noIndex();
    }

    /** Ein neues Paket schnüren. */
    public function createZip(Request $request): Response
    {
        $project = ProjectController::find($request->paramInt('id'));
        if ($project === null) {
            return Response::notFound();
        }

        try {
            $result = ZipExporter::create($project);
            Audit::log('project.zipped', (string) $project['name'], ['version' => $result['version']], $request);
            Session::flash('success', sprintf(
                'Paket Version %d erstellt (%s).',
                $result['version'],
                format_bytes((int) $result['bytes'])
            ));
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
        }

        return $this->back($project);
    }

    /**
     * Ein Paket herunterladen.
     *
     * Der Pfad wird über die Datenbank aufgelöst und muss zum Projekt
     * gehören – über die Adresse lässt sich nichts anderes erreichen.
     */
    public function download(Request $request): Response
    {
        $project = ProjectController::find($request->paramInt('id'));
        if ($project === null) {
            return Response::notFound();
        }

        $path = ZipExporter::pathFor((int) $project['id'], $request->paramInt('build'));
        if ($path === null) {
            return Response::notFound('Dieses Paket gibt es nicht.');
        }

        Audit::log('project.download', (string) $project['name'], ['datei' => basename($path)], $request);

        return Response::file($path, 'application/zip', true, basename($path))
            ->noCache()
            ->noIndex();
    }

    /** FTP-Zugangsdaten speichern. */
    public function saveTarget(Request $request): Response
    {
        $project = ProjectController::find($request->paramInt('id'));
        if ($project === null) {
            return Response::notFound();
        }

        $protocol = $request->input('protocol', 'sftp');
        $port = $request->int('port', $protocol === 'sftp' ? 22 : 21);

        // Das Passwort wird verschlüsselt abgelegt. Geht das nicht,
        // soll das hier stehen und nicht als Fehlerseite erscheinen.
        $grund = Crypto::status();

        if ($grund !== '' && $request->input('password') !== '') {
            Session::flash('error', 'Das Passwort lässt sich nicht verschlüsseln, deshalb wurde '
                . 'nichts gespeichert. ' . $grund);

            return $this->back($project);
        }

        FtpDeployer::saveTarget((int) $project['id'], [
            'protocol' => $protocol,
            'host' => $request->input('host'),
            'port' => $port,
            'username' => $request->input('username'),
            'password' => $request->input('password'),
            'path' => $request->input('path', '/public_html'),
        ]);

        Audit::log('deploy.target_saved', (string) $project['name'], [
            'host' => $request->input('host'),
            'protokoll' => $protocol,
        ], $request);

        Session::flash('success', 'Zugangsdaten gespeichert. Am besten gleich die Verbindung testen.');

        return $this->back($project);
    }

    /** Verbindung prüfen, ohne etwas hochzuladen. */
    public function testTarget(Request $request): Response
    {
        $project = ProjectController::find($request->paramInt('id'));
        if ($project === null) {
            return Response::notFound();
        }

        // Auch der Test selbst darf nicht abstuerzen. Ein Fehler 500 sagt
        // dem Betreiber nichts - und genau der kam frueher, wenn dem
        // Server die FTP-Erweiterung fehlte.
        try {
            $result = FtpDeployer::test((int) $project['id']);
        } catch (\Throwable $e) {
            Logger::exception($e);

            Session::flash('error',
                'Der Verbindungstest ist abgestuerzt. Das sollte nicht passieren - '
                . 'bitte melde dich. Versuch es solange mit SFTP auf Port 22.');

            return $this->back($project);
        }

        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);

        // Die gefundenen Verzeichnisse merken, damit die Seite sie
        // anbieten kann. Bei einer Subdomain ist das der Unterschied
        // zwischen Raten und Auswaehlen.
        Session::put('ftp_ordner_' . (int) $project['id'], [
            'ordner' => (array) ($result['ordner'] ?? []),
            'vorschlag' => (string) ($result['vorschlag'] ?? ''),
        ]);

        return $this->back($project);
    }

    /** Die Website hochladen. */
    public function deploy(Request $request): Response
    {
        $project = ProjectController::find($request->paramInt('id'));
        if ($project === null) {
            return Response::notFound();
        }

        if (Jobs::activeFor((int) $project['id']) !== null) {
            Session::flash('warning', 'Für dieses Projekt läuft bereits ein Auftrag.');
            return $this->back($project);
        }

        $dist = STORAGE_DIR . '/projects/' . (string) $project['slug'] . '/dist';
        if (!is_dir($dist)) {
            Session::flash('error', 'Die Website muss zuerst gebaut werden.');
            return $this->back($project);
        }

        Jobs::enqueue('deploy', [], (int) $project['id']);
        Jobs::nudge();

        Audit::log('deploy.started', (string) $project['name'], [], $request);
        Session::flash('success', 'Der Upload läuft. Der Fortschritt erscheint gleich hier.');

        return $this->back($project);
    }

    private function back(array $project): Response
    {
        return Response::redirect(
            '/' . trim((string) Config::get('create_path', 'create'), '/')
            . '/projekt/' . $project['id'] . '/veroeffentlichen'
        )->noCache()->noIndex();
    }
}
