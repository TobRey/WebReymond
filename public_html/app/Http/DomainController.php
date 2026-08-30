<?php

declare(strict_types=1);

namespace WebAtze\Http;

use WebAtze\Build\DomainWizard;
use WebAtze\Core\{Audit, Config, Db, Request, Response, Session, Validator, View};

/**
 * Der Assistent für den Domainumzug.
 */
final class DomainController
{
    public function show(Request $request): Response
    {
        $project = ProjectController::find($request->paramInt('id'));
        if ($project === null) {
            return Response::notFound();
        }

        $transfer = Db::first(
            'SELECT * FROM domain_transfers WHERE project_id = :p ORDER BY id DESC LIMIT 1',
            ['p' => (int) $project['id']]
        );

        $providers = require APP_DIR . '/Support/providers.php';

        return Response::html(View::partial('layouts/admin', [
            'title' => 'Domain: ' . (string) $project['name'],
            'content' => View::partial('admin/domain', [
                'project' => $project,
                'transfer' => $transfer,
                'steps' => $transfer !== null ? DomainWizard::steps($transfer) : [],
                'checks' => $transfer !== null
                    ? (json_decode((string) $transfer['checks'], true) ?: [])
                    : [],
                'providers' => $providers,
            ]),
        ]))->noCache()->noIndex();
    }

    public function save(Request $request): Response
    {
        $project = ProjectController::find($request->paramInt('id'));
        if ($project === null) {
            return Response::notFound();
        }

        $domain = Validator::normaliseDomain($request->input('domain'));
        if ($domain !== '' && !Validator::isDomain($domain)) {
            Session::flash('error', 'Das ist kein gültiger Domainname. Beispiel: beispiel.ch');
            return $this->back($project);
        }

        $mode = $request->input('mode', 'point');
        if (!in_array($mode, ['none', 'point', 'transfer'], true)) {
            $mode = 'point';
        }

        $targetIp = trim($request->input('target_ip'));
        if ($targetIp !== '' && !filter_var($targetIp, FILTER_VALIDATE_IP)) {
            Session::flash('error', 'Die Serveradresse ist keine gültige IP-Adresse.');
            return $this->back($project);
        }

        $providers = require APP_DIR . '/Support/providers.php';
        $registrar = $request->input('registrar', 'other');
        if (!isset($providers['registrar'][$registrar])) {
            $registrar = 'other';
        }

        $existing = Db::first(
            'SELECT id FROM domain_transfers WHERE project_id = :p ORDER BY id DESC LIMIT 1',
            ['p' => (int) $project['id']]
        );

        $values = [
            'domain' => $domain,
            'mode' => $mode,
            'registrar' => $registrar,
            'target_ip' => $targetIp,
            'updated_at' => Db::now(),
        ];

        if ($existing !== null) {
            Db::update('domain_transfers', $values, 'id = :id', ['id' => (int) $existing['id']]);
        } else {
            Db::insert('domain_transfers', array_merge($values, [
                'project_id' => (int) $project['id'],
                'current_step' => 0,
                'steps' => [],
                'checks' => [],
                'created_at' => Db::now(),
            ]));
        }

        // Die Domain gehört auch ans Projekt – sie taucht in sitemap.xml
        // und in den Referenzen auf.
        Db::update('projects', ['domain' => $domain, 'updated_at' => Db::now()],
            'id = :id', ['id' => (int) $project['id']]);

        Audit::log('domain.saved', $domain, ['modus' => $mode], $request);
        Session::flash('success', 'Gespeichert. Der Assistent führt jetzt Schritt für Schritt durch den Umzug.');

        return $this->back($project);
    }

    /** Nachsehen, ob die Änderung angekommen ist. */
    public function check(Request $request): Response
    {
        $project = ProjectController::find($request->paramInt('id'));
        if ($project === null) {
            return Response::notFound();
        }

        $transfer = Db::first(
            'SELECT * FROM domain_transfers WHERE project_id = :p ORDER BY id DESC LIMIT 1',
            ['p' => (int) $project['id']]
        );

        if ($transfer === null) {
            Session::flash('error', 'Für dieses Projekt ist keine Domain hinterlegt.');
            return $this->back($project);
        }

        $result = DomainWizard::runCheck((int) $transfer['id']);

        if (!$result['ok']) {
            Session::flash('error', (string) $result['error']);
            return $this->back($project);
        }

        $dns = $result['result']['dns'] ?? [];
        Session::flash(
            ($dns['ok'] ?? false) ? 'success' : 'warning',
            (string) ($dns['message'] ?? 'Prüfung abgeschlossen.')
        );

        return $this->back($project);
    }

    /** Einen Schritt abhaken oder zurücknehmen. */
    public function step(Request $request): Response
    {
        $project = ProjectController::find($request->paramInt('id'));
        if ($project === null) {
            return Response::notFound();
        }

        $transfer = Db::first(
            'SELECT id FROM domain_transfers WHERE project_id = :p ORDER BY id DESC LIMIT 1',
            ['p' => (int) $project['id']]
        );

        if ($transfer === null) {
            Session::flash('error', 'Für dieses Projekt ist keine Domain hinterlegt.');
            return $this->back($project);
        }

        $forward = $request->input('direction', 'next') !== 'back';
        $result = DomainWizard::advance((int) $transfer['id'], $forward);

        if (!$result['ok']) {
            Session::flash('error', (string) $result['error']);
            return $this->back($project);
        }

        Audit::log('domain.step', (string) $project['domain'], [
            'schritt' => $result['step'],
            'richtung' => $forward ? 'vor' : 'zurueck',
        ], $request);

        Session::flash(
            'success',
            $result['done']
                ? 'Alle Schritte abgehakt. Zum Nachsehen, ob die Domain angekommen ist, '
                  . 'genügt "Jetzt nachsehen".'
                : 'Weiter zum nächsten Schritt.'
        );

        return $this->back($project);
    }

    private function back(array $project): Response
    {
        return Response::redirect(
            '/' . trim((string) Config::get('create_path', 'create'), '/')
            . '/projekt/' . $project['id'] . '/domain'
        )->noCache()->noIndex();
    }
}
