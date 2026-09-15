<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use App\Core\Json;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

/**
 * Admin-API: Fallverwaltung, Medien, Konten, Einstellungen, Sicherungen,
 * Diagnose und KI-Vorschlaege. Alle Vorschlaege sind Entwuerfe und werden
 * erst nach manueller Bestaetigung uebernommen.
 */
final class AdminApiController extends Controller
{
    private function admin(): array
    {
        return $this->container->auth()->requireAdmin();
    }

    /* =========================================================
     |  Faelle
     ========================================================= */

    public function saveCase(Request $request): Response
    {
        $this->admin();
        $payload = $request->arr('case');
        if ($payload === []) {
            $raw = $request->str('json', '', 900000);
            $payload = Json::decode($raw) ?? [];
        }
        if ($payload === []) {
            return $this->fail('Es wurden keine Falldaten uebermittelt.');
        }
        $payload['id'] = Validator::id((string)($payload['id'] ?? ''), 'Fall-ID');

        $validation = $this->container->caseValidator()->validate($payload, false);
        $publish = $request->bool('publish', false);
        if ($publish) {
            if (!$validation['ok']) {
                return $this->fail('Veroeffentlichung abgelehnt: Der Fall enthaelt ' . $validation['stats']['errors'] . ' Fehler. Bitte zuerst die Pruefung abarbeiten.');
            }
            $payload['status'] = 'published';
        }

        $case = $this->container->cases()->save($payload);
        return $this->ok([
            'case'       => $this->container->cases()->summary($case),
            'validation' => $this->container->caseValidator()->validate($case),
            'message'    => $publish ? 'Fall gespeichert und veroeffentlicht.' : 'Fall gespeichert.',
        ]);
    }

    public function setStatus(Request $request): Response
    {
        $this->admin();
        $caseId = Validator::id($request->str('case', '', 64), 'Fall-ID');
        $status = $request->str('status', 'draft', 20);
        if ($status === 'published') {
            $case = $this->container->cases()->get($caseId);
            $validation = $this->container->caseValidator()->validate($case);
            if (!$validation['ok']) {
                return $this->fail('Der Fall hat ' . $validation['stats']['errors'] . ' Fehler und kann nicht veroeffentlicht werden.');
            }
        }
        $case = $this->container->cases()->setStatus($caseId, $status);
        return $this->ok(['status' => $case['status'], 'message' => 'Status geaendert: ' . $case['status']]);
    }

    public function deleteCase(Request $request): Response
    {
        $this->admin();
        $caseId = Validator::id($request->str('case', '', 64), 'Fall-ID');
        if ($request->str('confirm') !== $caseId) {
            return $this->fail('Zur Bestaetigung bitte die Fall-ID eingeben.');
        }
        $this->container->cases()->delete($caseId);
        return $this->ok(['message' => 'Fall geloescht. Eine Version wurde gesichert.']);
    }

    public function duplicateCase(Request $request): Response
    {
        $this->admin();
        $source = Validator::id($request->str('case', '', 64), 'Fall-ID');
        $target = Validator::id($request->str('new_id', '', 64), 'Neue Fall-ID');
        if ($this->container->cases()->exists($target)) {
            return $this->fail('Diese Fall-ID ist bereits vergeben.');
        }
        $case = $this->container->cases()->duplicate($source, $target, $request->str('new_title', 'Kopie', 120));
        return $this->ok(['case' => $this->container->cases()->summary($case), 'message' => 'Fall dupliziert.']);
    }

    public function exportCase(Request $request, array $args): Response
    {
        $this->admin();
        $caseId = Validator::id((string)($args['case'] ?? ''), 'Fall-ID');
        return Response::download($this->container->cases()->export($caseId), 'fall_' . $caseId . '.json', 'application/json');
    }

    public function rawCase(Request $request, array $args): Response
    {
        $this->admin();
        $caseId = Validator::id((string)($args['case'] ?? ''), 'Fall-ID');
        return $this->ok(['case' => $this->container->cases()->get($caseId)]);
    }

    public function importCase(Request $request): Response
    {
        $this->admin();
        $json = $request->str('json', '', 2000000);
        $file = $request->file('file');
        if ($json === '' && is_array($file) && ($file['error'] ?? 1) === UPLOAD_ERR_OK) {
            if ((int)($file['size'] ?? 0) > 4194304) {
                return $this->fail('Die Datei ist zu gross (max. 4 MB).');
            }
            $json = (string)file_get_contents((string)$file['tmp_name']);
        }
        if (trim($json) === '') {
            return $this->fail('Keine Daten zum Importieren erhalten.');
        }
        $result = $this->container->cases()->import($json, $request->str('new_id', '', 64) ?: null);
        return $this->ok([
            'case'       => $this->container->cases()->summary($result['case']),
            'warnings'   => $result['warnings'],
            'validation' => $this->container->caseValidator()->validate($result['case']),
            'message'    => 'Fall importiert (als Entwurf).',
        ]);
    }

    public function validateCase(Request $request, array $args): Response
    {
        $this->admin();
        $caseId = Validator::id((string)($args['case'] ?? ''), 'Fall-ID');
        return $this->ok(['validation' => $this->container->caseValidator()->validate($this->container->cases()->get($caseId))]);
    }

    public function versions(Request $request, array $args): Response
    {
        $this->admin();
        $caseId = Validator::id((string)($args['case'] ?? ''), 'Fall-ID');
        return $this->ok(['versions' => $this->container->cases()->versions($caseId)]);
    }

    public function restoreVersion(Request $request): Response
    {
        $this->admin();
        $caseId = Validator::id($request->str('case', '', 64), 'Fall-ID');
        $versionId = Validator::id($request->str('version', '', 64), 'Versions-ID');
        $case = $this->container->cases()->restoreVersion($caseId, $versionId);
        return $this->ok(['case' => $this->container->cases()->summary($case), 'message' => 'Version wiederhergestellt.']);
    }

    public function resetTest(Request $request): Response
    {
        $user = $this->admin();
        $caseId = Validator::id($request->str('case', '', 64), 'Fall-ID');
        $case = $this->container->cases()->get($caseId);
        $progress = $this->container->progress()->reset((string)$user['id'], $caseId);
        $game = new GameController($this->container);
        $this->container->progress()->save($game->applyStartState($case, $progress));
        return $this->ok(['message' => 'Testfortschritt zurueckgesetzt.']);
    }

    /* =========================================================
     |  KI-Vorschlaege (immer nur Entwuerfe)
     ========================================================= */

    public function suggestHint(Request $request): Response
    {
        $this->admin();
        $caseId = Validator::id($request->str('case', '', 64), 'Fall-ID');
        $puzzleId = Validator::id($request->str('puzzle', '', 64), 'Raetsel-ID');
        $level = max(1, min(3, $request->int('level', 1)));
        $case = $this->container->cases()->get($caseId);
        $puzzle = $this->container->engine()->findPuzzle($case, $puzzleId);
        if ($puzzle === null) {
            return $this->fail('Raetsel nicht gefunden.');
        }
        return $this->ok($this->container->hints()->suggest($case, $puzzle, $level));
    }

    public function suggest(Request $request): Response
    {
        $this->admin();
        $type = $request->str('type', 'idee', 40);
        $context = Validator::text($request->str('context', '', 4000), 4000);
        $ai = $this->container->ai();
        if ($ai->isOffline()) {
            return $this->fail('Fuer KI-Vorschlaege muss ein KI-Anbieter eingerichtet sein. Im Offline-Modus stehen nur manuelle Eingaben zur Verfuegung.');
        }

        $tasks = [
            'idee'        => 'Entwirf eine kurze Fallidee (3-5 Saetze) fuer ein realistisches FBI-Ermittlungsspiel mit Horror-Elementen.',
            'hintergrund' => 'Schreibe eine Hintergrundgeschichte (max. 180 Woerter) zur beschriebenen Figur oder zum Fall.',
            'npc'         => 'Entwirf eine NPC-Persoenlichkeit: Name, Alter, Rolle, Persoenlichkeit, Sprachstil, Beziehung zur vermissten Person. Kurz und stichpunktartig.',
            'stil'        => 'Beschreibe den Dialogstil dieser Figur in 3 Stichpunkten inkl. typischer Formulierungen und Tippfehler.',
            'luege'       => 'Entwirf eine glaubwuerdige Luege fuer diese Figur: Behauptung, Wahrheit, welcher Beweis sie widerlegt, wie sie reagiert, wenn sie ueberfuehrt wird.',
            'alibi'       => 'Entwirf ein Alibi mit genauer Uhrzeit, Ort, Zeugen und einer pruefbaren Schwachstelle.',
            'beweis'      => 'Entwirf einen Beweis: Titel, Fundort, was er zeigt, welche Schlussfolgerung er erlaubt.',
            'spur'        => 'Entwirf eine glaubwuerdige falsche Spur, die spaeter widerlegt werden kann.',
            'raetsel'     => 'Entwirf ein Raetsel: Typ, Aufgabe, Loesung, drei Hinweisstufen, Begruendung warum es logisch loesbar ist.',
            'horror'      => 'Entwirf ein subtiles Horror-Ereignis (kein Jumpscare): Ausloeser, was passiert, warum es beunruhigt.',
            'hinweis'     => 'Formuliere drei Hinweisstufen (subtil, konkret, fast direkt) zu der beschriebenen Aufgabe.',
            'aufloesung'  => 'Entwirf eine logisch geschlossene Aufloesung: Taeter, Motiv, Ablauf, welche Beweise sie stuetzen.',
            'konsistenz'  => 'Pruefe den beschriebenen Fall auf Widersprueche und nenne konkrete Verbesserungen als Liste.',
        ];
        $task = $tasks[$type] ?? $tasks['idee'];

        $system = 'Du unterstuetzt beim Entwurf eines fiktiven Ermittlungsspiels fuer Erwachsene. '
            . 'Antworte auf Deutsch, sachlich, ohne Einleitung und ohne Markdown-Ueberschriften. '
            . 'Alle Inhalte sind erfunden. Keine Anleitungen zu realen Straftaten.';
        $result = $ai->suggest($system, $task . "\n\nKontext:\n" . $context, 800);
        if (!$result->ok) {
            return $this->fail('KI-Vorschlag fehlgeschlagen: ' . $ai->humanError($result));
        }
        Logger::info('KI-Vorschlag erzeugt', ['type' => $type]);
        return $this->ok([
            'suggestion' => trim($result->text),
            'type'       => $type,
            'draft'      => true,
            'notice'     => 'Entwurf - wird erst nach Bestaetigung uebernommen.',
        ]);
    }

    public function testAi(Request $request): Response
    {
        $this->admin();
        $override = null;
        if ($request->bool('use_form', false)) {
            $key = $request->str('api_key', '', 400);
            $override = [
                'provider' => $request->str('provider', 'offline', 40),
                'base_url' => $request->str('base_url', '', 300),
                'model'    => $request->str('model', '', 140),
                'api_key'  => $key !== '' ? $key : $this->container->settings()->apiKey(),
            ];
            if (($override['provider'] ?? '') === 'offline') {
                $override = null;
            }
        }
        return $this->ok($this->container->ai()->testConnection($override));
    }

    public function saveAi(Request $request): Response
    {
        $this->admin();
        $settings = $this->container->settings();
        $provider = $request->str('provider', 'offline', 40);
        if (!in_array($provider, ['offline', 'gemini', 'openai_compatible'], true)) {
            return $this->fail('Unbekannter Anbieter.');
        }
        $baseUrl = trim($request->str('base_url', '', 300));
        if ($baseUrl !== '' && !preg_match('~^https?://~i', $baseUrl)) {
            return $this->fail('Die Basis-URL muss mit http:// oder https:// beginnen.');
        }

        $settings->save(['ai' => [
            'provider'        => $provider,
            'base_url'        => $baseUrl,
            'model'           => Validator::text($request->str('model', '', 140), 140),
            'timeout'         => max(5, min(120, $request->int('timeout', 30))),
            'retries'         => max(0, min(5, $request->int('retries', 2))),
            'max_tokens'      => max(64, min(2048, $request->int('max_tokens', 420))),
            'temperature'     => max(0.0, min(1.5, (float)$request->str('temperature', '0.85', 10))),
            'rate_per_minute' => max(1, min(120, $request->int('rate_per_minute', 12))),
            'rate_per_hour'   => max(10, min(5000, $request->int('rate_per_hour', 180))),
            'fallback_offline'=> $request->bool('fallback_offline', true),
        ]]);

        $apiKey = $request->str('api_key', '', 400);
        if ($apiKey === '__CLEAR__') {
            $settings->setApiKey('');
        } elseif (trim($apiKey) !== '') {
            $settings->setApiKey(trim($apiKey));
        }

        Logger::info('KI-Konfiguration gespeichert', ['provider' => $provider]);
        return $this->ok(['message' => 'KI-Einstellungen gespeichert.', 'mode' => $this->container->npcChat()->mode()]);
    }

    /* =========================================================
     |  Einstellungen
     ========================================================= */

    public function saveSettings(Request $request): Response
    {
        $this->admin();
        $settings = $this->container->settings();
        $settings->save([
            'site' => [
                'name'           => Validator::text($request->str('site_name', 'WHERE IS TOBY?', 120), 120),
                'tagline'        => Validator::text($request->str('tagline', '', 160), 160),
                'allow_guests'   => $request->bool('allow_guests', true),
                'allow_register' => $request->bool('allow_register', true),
                'imprint'        => Validator::text($request->str('imprint', '', 4000), 4000),
                'privacy'        => Validator::text($request->str('privacy', '', 6000), 6000),
            ],
            'gameplay' => [
                'hints_per_case'   => max(0, min(10, $request->int('hints_per_case', 4))),
                'horror_intensity' => $request->str('horror_intensity', 'normal', 20),
                'jumpscares'       => $request->bool('jumpscares', true),
                'autosave_seconds' => max(5, min(120, $request->int('autosave_seconds', 20))),
                'default_case'     => Validator::text($request->str('default_case', 'toby', 64), 64),
                'show_timer'       => $request->bool('show_timer', true),
            ],
            'security' => [
                'max_login_attempts'    => max(3, min(20, $request->int('max_login_attempts', 6))),
                'lockout_minutes'       => max(1, min(120, $request->int('lockout_minutes', 15))),
                'chat_per_minute'       => max(3, min(60, $request->int('chat_per_minute', 15))),
                'api_per_minute'        => max(30, min(600, $request->int('api_per_minute', 120))),
                'registration_per_hour' => max(1, min(100, $request->int('registration_per_hour', 8))),
            ],
        ]);
        return $this->ok([
            'message'  => 'Einstellungen gespeichert.',
            'settings' => $this->container->settings()->publicSettings(),
        ]);
    }

    /* =========================================================
     |  Medien
     ========================================================= */

    public function listMedia(Request $request): Response
    {
        $this->admin();
        $library = $this->container->store()->read('media/library.json', ['items' => []]);
        return $this->ok(['media' => array_values((array)($library['items'] ?? []))]);
    }

    public function uploadMedia(Request $request): Response
    {
        $this->admin();
        $file = $request->file('file');
        if ($file === null) {
            return $this->fail('Keine Datei empfangen.');
        }
        $record = $this->container->uploads()->store(
            $file,
            $request->str('title', '', 140),
            $request->str('category', 'bild', 40),
            $request->bool('rights_confirmed', false)
        );
        $this->container->store()->update('media/library.json', static function (array $library) use ($record): array {
            $library['items'][] = $record;
            return $library;
        }, ['items' => []]);
        return $this->ok(['media' => $record, 'message' => 'Datei gespeichert.']);
    }

    public function deleteMedia(Request $request): Response
    {
        $this->admin();
        $id = Validator::text($request->str('id', '', 64), 64);
        $removed = null;
        $this->container->store()->update('media/library.json', static function (array $library) use ($id, &$removed): array {
            $items = [];
            foreach ((array)($library['items'] ?? []) as $item) {
                if ((string)($item['id'] ?? '') === $id) {
                    $removed = $item;
                    continue;
                }
                $items[] = $item;
            }
            $library['items'] = $items;
            return $library;
        }, ['items' => []]);
        if ($removed !== null) {
            $this->container->uploads()->delete((string)$removed['file'], (string)($removed['thumb'] ?? ''));
        }
        return $this->ok(['message' => 'Medium geloescht.']);
    }

    public function generateMedia(Request $request): Response
    {
        $this->admin();
        $mediaId = Validator::text($request->str('id', '', 64), 64);
        $library = $this->container->store()->read('media/library.json', ['items' => []]);
        $source = null;
        foreach ((array)($library['items'] ?? []) as $item) {
            if ((string)($item['id'] ?? '') === $mediaId) {
                $source = $item;
                break;
            }
        }
        if ($source === null) {
            return $this->fail('Quellbild nicht in der Bibliothek gefunden.');
        }

        $data = [
            'name'      => Validator::text($request->str('name', '', 120), 120),
            'age'       => Validator::text($request->str('age', '', 10), 10),
            'last_seen' => Validator::text($request->str('last_seen', '', 80), 80),
            'location'  => Validator::text($request->str('location', '', 80), 80),
            'height'    => Validator::text($request->str('height', '', 40), 40),
            'clothing'  => Validator::text($request->str('clothing', '', 80), 80),
            'case_code' => Validator::text($request->str('case_code', '', 40), 40),
            'contact'   => Validator::text($request->str('contact', '1-800-CALL-FBI', 60), 60),
            'title'     => Validator::text($request->str('title', '', 60), 60),
            'subtitle'  => Validator::text($request->str('subtitle', '', 80), 80),
            'agent'     => Validator::text($request->str('agent', 'SPECIAL AGENT', 60), 60),
            'status'    => Validator::text($request->str('status', 'VERMISST', 40), 40),
            'note'      => Validator::text($request->str('note', '', 400), 400),
        ];

        $files = $this->container->mediaGenerator()->generateSet((string)$source['file'], $data);
        $records = [];
        foreach ($files as $variant => $filename) {
            $records[] = [
                'id'        => 'm_' . bin2hex(random_bytes(8)),
                'file'      => $filename,
                'title'     => ($data['name'] !== '' ? $data['name'] . ' - ' : '') . $variant,
                'category'  => 'generiert:' . $variant,
                'mime'      => 'image/jpeg',
                'extension' => 'jpg',
                'size'      => (int)@filesize(WIT_UPLOADS . '/media/' . $filename),
                'uploaded'  => gmdate('c'),
                'thumb'     => null,
                'source'    => $mediaId,
            ];
        }
        $this->container->store()->update('media/library.json', static function (array $library) use ($records): array {
            foreach ($records as $record) {
                $library['items'][] = $record;
            }
            return $library;
        }, ['items' => []]);

        return $this->ok(['media' => $records, 'message' => count($records) . ' Grafiken erzeugt.']);
    }

    /* =========================================================
     |  Konten
     ========================================================= */

    public function saveUser(Request $request): Response
    {
        $admin = $this->admin();
        $users = $this->container->users();
        $id = $request->str('id', '', 64);

        if ($id === '') {
            $username = $request->str('username', '', 64);
            $password = $request->str('password', '', 200);
            $validator = \App\Core\Validator::make(['username' => $username, 'password' => $password])->username()->password();
            if ($validator->fails()) {
                return $this->fail($validator->firstError());
            }
            if ($users->usernameTaken($username)) {
                return $this->fail('Dieser Benutzername ist bereits vergeben.');
            }
            $user = $users->create($username, $password, $request->str('email', '', 190), $request->str('role', 'player', 20), [
                'age_confirmed' => true,
                'must_change_password' => $request->bool('must_change', false),
            ]);
            return $this->ok(['user' => $this->publicUser($user), 'message' => 'Konto angelegt.']);
        }

        $role = $request->str('role', 'player', 20);
        $newPassword = $request->str('password', '', 200);
        if ($newPassword !== '') {
            $validator = \App\Core\Validator::make(['password' => $newPassword])->password();
            if ($validator->fails()) {
                return $this->fail($validator->firstError());
            }
        }
        if ($id === (string)$admin['id'] && $role !== 'admin') {
            return $this->fail('Das eigene Konto kann nicht herabgestuft werden.');
        }

        $user = $users->update($id, static function (array $record) use ($request, $role, $newPassword): array {
            $record['role'] = in_array($role, ['admin', 'player'], true) ? $role : 'player';
            $record['agent_name'] = \App\Core\Validator::text($request->str('agent_name', (string)($record['agent_name'] ?? ''), 60), 60);
            $record['email'] = mb_strtolower(trim($request->str('email', (string)($record['email'] ?? ''), 190)));
            $record['locked_until'] = $request->bool('unlock', false) ? null : ($record['locked_until'] ?? null);
            if ($request->bool('unlock', false)) {
                $record['failed_logins'] = 0;
            }
            if ($newPassword !== '') {
                $record['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
                $record['must_change_password'] = $request->bool('must_change', false);
            }
            return $record;
        });
        if ($user === null) {
            return $this->fail('Konto nicht gefunden.', 404);
        }
        return $this->ok(['user' => $this->publicUser($user), 'message' => 'Konto aktualisiert.']);
    }

    public function deleteUser(Request $request): Response
    {
        $admin = $this->admin();
        $id = $request->str('id', '', 64);
        if ($id === (string)$admin['id']) {
            return $this->fail('Das eigene Konto kann hier nicht geloescht werden.');
        }
        $users = $this->container->users();
        $target = $users->findById($id);
        if ($target === null) {
            return $this->fail('Konto nicht gefunden.', 404);
        }
        if (($target['role'] ?? '') === 'admin') {
            $adminCount = count(array_filter($users->all(), static fn(array $u): bool => ($u['role'] ?? '') === 'admin'));
            if ($adminCount <= 1) {
                return $this->fail('Das letzte Administratorkonto kann nicht geloescht werden.');
            }
        }
        $users->delete($id);
        return $this->ok(['message' => 'Konto und Spielstaende geloescht.']);
    }

    public function resetProgress(Request $request): Response
    {
        $this->admin();
        $userId = $request->str('id', '', 64);
        $caseId = $request->str('case', '', 64);
        if ($caseId !== '') {
            $this->container->progress()->delete($userId, Validator::id($caseId, 'Fall-ID'));
        } else {
            foreach ($this->container->progress()->listForUser($userId) as $progress) {
                $this->container->progress()->delete($userId, (string)$progress['case_id']);
            }
        }
        return $this->ok(['message' => 'Spielfortschritt zurueckgesetzt.']);
    }

    public function changePassword(Request $request): Response
    {
        $admin = $this->admin();
        $result = $this->container->auth()->changePassword(
            $admin,
            $request->str('current_password', '', 200),
            $request->str('password', '', 200),
            $request->str('password_repeat', '', 200)
        );
        if (!$result['ok']) {
            return $this->fail((string)$result['error']);
        }
        return $this->ok(['message' => 'Passwort geaendert.']);
    }

    /* =========================================================
     |  Sicherungen, Protokolle, Diagnose
     ========================================================= */

    public function createBackup(Request $request): Response
    {
        $this->admin();
        $result = $this->container->backups()->create($request->bool('include_uploads', false), 'manuell');
        $this->container->backups()->prune(12);
        return $this->ok(['backup' => $result, 'message' => 'Sicherung erstellt (' . $result['entries'] . ' Dateien).']);
    }

    public function restoreBackup(Request $request): Response
    {
        $this->admin();
        $file = $request->str('file', '', 140);
        if ($request->str('confirm') !== 'WIEDERHERSTELLEN') {
            return $this->fail('Zur Bestaetigung bitte WIEDERHERSTELLEN eingeben.');
        }
        $result = $this->container->backups()->restore($file);
        $this->container->store()->clearCache();
        return $this->ok([
            'result'  => $result,
            'message' => $result['restored'] . ' Dateien wiederhergestellt. Sicherheitskopie: ' . $result['safety'],
        ]);
    }

    public function deleteBackup(Request $request): Response
    {
        $this->admin();
        $this->container->backups()->delete($request->str('file', '', 140));
        return $this->ok(['message' => 'Sicherung geloescht.']);
    }

    public function downloadBackup(Request $request): Response
    {
        $this->admin();
        $file = Validator::filename($request->str('file', '', 140));
        $path = $this->container->backups()->path($file);
        return Response::download((string)file_get_contents($path), $file, str_ends_with($file, '.zip') ? 'application/zip' : 'application/json');
    }

    public function logs(Request $request): Response
    {
        $this->admin();
        $file = basename($request->str('file', 'app.log', 60));
        return $this->ok([
            'entries' => \App\Core\Logger::tail($file, max(10, min(500, $request->int('limit', 150)))),
            'files'   => \App\Core\Logger::files(),
            'file'    => $file,
        ]);
    }

    public function clearLogs(Request $request): Response
    {
        $this->admin();
        \App\Core\Logger::clear(basename($request->str('file', 'app.log', 60)));
        return $this->ok(['message' => 'Protokolldatei geleert.']);
    }

    public function diagnostics(Request $request): Response
    {
        $this->admin();
        return $this->ok(['result' => $this->container->diagnostics()->run($request->bool('deep', false))]);
    }

    private function publicUser(array $user): array
    {
        unset($user['password_hash']);
        return $user;
    }
}
