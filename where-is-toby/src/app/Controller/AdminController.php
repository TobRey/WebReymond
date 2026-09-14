<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Service\AudioSynth;
use App\Service\UploadService;

/**
 * Adminbereich (Ansichten). Alle Schreibvorgaenge laufen ueber AdminApiController.
 */
final class AdminController extends Controller
{
    private function guard(): array
    {
        $auth = $this->container->auth();
        if ($auth->currentUser() === null) {
            Session::flash('error', 'Bitte als Administrator anmelden.');
            throw new \App\Core\HttpException(302, 'Anmeldung erforderlich.');
        }
        return $auth->requireAdmin();
    }

    /** Leitet nicht angemeldete Besucher sauber zur Anmeldung. */
    private function adminView(string $template, array $data, callable $build): Response
    {
        $auth = $this->container->auth();
        $user = $auth->currentUser();
        if ($user === null) {
            return Response::redirect('/login');
        }
        if (($user['role'] ?? '') !== 'admin') {
            throw HttpException::forbidden('Dieser Bereich ist Administratoren vorbehalten.');
        }
        $data = array_merge($data, $build($user));
        $data['adminUser'] = $user;
        $data['mustChangePassword'] = (bool)($user['must_change_password'] ?? false);
        return $this->render($template, $data, 'layout_admin');
    }

    public function dashboard(Request $request): Response
    {
        return $this->adminView('admin/dashboard', ['title' => 'Adminbereich', 'nav' => 'dashboard'], function (array $user): array {
            $cases = $this->container->cases()->listSummaries(true);
            $users = $this->container->users()->all();
            $diagnostics = $this->container->diagnostics()->run(false);
            $recentLogs = \App\Core\Logger::tail('app.log', 12);
            return [
                'cases'       => $cases,
                'userCount'   => count($users),
                'adminCount'  => count(array_filter($users, static fn(array $u): bool => ($u['role'] ?? '') === 'admin')),
                'diagnostics' => $diagnostics,
                'logs'        => $recentLogs,
                'aiProvider'  => $this->container->ai()->providerName(),
                'aiModel'     => $this->container->ai()->model(),
                'chatMode'    => $this->container->npcChat()->mode(),
                'backups'     => array_slice($this->container->backups()->listBackups(), 0, 5),
            ];
        });
    }

    public function cases(Request $request): Response
    {
        return $this->adminView('admin/cases', ['title' => 'Fallverwaltung', 'nav' => 'cases'], function (): array {
            $summaries = $this->container->cases()->listSummaries(true);
            $validation = [];
            foreach ($summaries as $summary) {
                $case = $this->container->cases()->find($summary['id']);
                if ($case !== null) {
                    $validation[$summary['id']] = $this->container->caseValidator()->validate($case)['stats'];
                }
            }
            return ['cases' => $summaries, 'validation' => $validation];
        });
    }

    public function caseEditor(Request $request, array $args): Response
    {
        $caseId = (string)($args['case'] ?? '');
        return $this->adminView('admin/case_editor', ['title' => 'Fall-Editor', 'nav' => 'cases'], function () use ($caseId): array {
            if ($caseId === 'neu') {
                $case = $this->blankCase();
            } else {
                $case = $this->container->cases()->find(Validator::id($caseId, 'Fall-ID'));
                if ($case === null) {
                    throw HttpException::notFound('Fall nicht gefunden.');
                }
            }
            return [
                'caseData'   => $case,
                'isNew'      => $caseId === 'neu',
                'validation' => $this->container->caseValidator()->validate($case),
                'versions'   => $caseId === 'neu' ? [] : $this->container->cases()->versions($caseId),
                'mediaLibrary' => $this->mediaLibrary(),
                'aiAvailable'  => !$this->container->ai()->isOffline(),
                'audioTracks'  => AudioSynth::tracks(),
            ];
        });
    }

    public function media(Request $request): Response
    {
        return $this->adminView('admin/media', ['title' => 'Medienverwaltung', 'nav' => 'media'], function (): array {
            return [
                'media'      => $this->mediaLibrary(),
                'extensions' => UploadService::allowedExtensions(),
                'maxBytes'   => UploadService::maxBytes(),
                'gdAvailable'=> $this->container->mediaGenerator()->available(),
                'fontsOk'    => $this->container->mediaGenerator()->hasFonts(),
            ];
        });
    }

    public function players(Request $request): Response
    {
        return $this->adminView('admin/players', ['title' => 'Spielerkonten', 'nav' => 'players'], function (): array {
            $users = $this->container->users()->all();
            $progress = [];
            foreach ($users as $user) {
                $progress[$user['id']] = array_map(static fn(array $p): array => [
                    'case'      => (string)$p['case_id'],
                    'percent'   => 0,
                    'completed' => $p['completed_at'] !== null,
                    'rank'      => (string)($p['result']['rank'] ?? ''),
                    'playtime'  => (int)($p['playtime'] ?? 0),
                    'updated'   => (string)($p['updated_at'] ?? ''),
                ], $this->container->progress()->listForUser((string)$user['id']));
            }
            return ['players' => $users, 'progress' => $progress];
        });
    }

    public function settings(Request $request): Response
    {
        return $this->adminView('admin/settings', ['title' => 'Systemeinstellungen', 'nav' => 'settings'], function (): array {
            return ['config' => $this->container->settings()->publicSettings()];
        });
    }

    public function ai(Request $request): Response
    {
        return $this->adminView('admin/ai', ['title' => 'KI-Konfiguration', 'nav' => 'ai'], function (): array {
            return [
                'config'   => $this->container->settings()->publicSettings(),
                'chatMode' => $this->container->npcChat()->mode(),
                'presets'  => [
                    [
                        'id' => 'gemini',
                        'name' => 'Google Gemini (kostenloses Kontingent)',
                        'provider' => 'gemini',
                        'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
                        'model' => 'gemini-2.0-flash',
                        'hint' => 'Schluessel kostenlos unter aistudio.google.com/apikey erstellen. Modellname bei Bedarf anpassen.',
                    ],
                    [
                        'id' => 'openrouter',
                        'name' => 'OpenRouter (Modelle mit :free-Endung)',
                        'provider' => 'openai_compatible',
                        'base_url' => 'https://openrouter.ai/api/v1',
                        'model' => 'meta-llama/llama-3.3-70b-instruct:free',
                        'hint' => 'Kostenlose Modelle enden auf ":free". Verfuegbarkeit aendert sich - aktuelle Liste auf openrouter.ai/models.',
                    ],
                    [
                        'id' => 'groq',
                        'name' => 'Groq (kostenloses Kontingent)',
                        'provider' => 'openai_compatible',
                        'base_url' => 'https://api.groq.com/openai/v1',
                        'model' => 'llama-3.3-70b-versatile',
                        'hint' => 'Schluessel unter console.groq.com. Sehr schnelle Antworten.',
                    ],
                    [
                        'id' => 'local',
                        'name' => 'Lokaler Server (Ollama, LM Studio)',
                        'provider' => 'openai_compatible',
                        'base_url' => 'http://localhost:11434/v1',
                        'model' => 'llama3.1',
                        'hint' => 'Nur sinnvoll, wenn der Webserver den Dienst erreichen kann. Kein Schluessel noetig.',
                    ],
                    [
                        'id' => 'offline',
                        'name' => 'Offline-Modus (ohne Internet)',
                        'provider' => 'offline',
                        'base_url' => '',
                        'model' => '',
                        'hint' => 'NPCs antworten ueber das regelbasierte Dialogsystem. Der Fall "Toby" bleibt vollstaendig loesbar.',
                    ],
                ],
            ];
        });
    }

    public function backups(Request $request): Response
    {
        return $this->adminView('admin/backups', ['title' => 'Sicherung und Wiederherstellung', 'nav' => 'backup'], function (): array {
            return ['backups' => $this->container->backups()->listBackups(), 'zipAvailable' => class_exists(\ZipArchive::class)];
        });
    }

    public function logs(Request $request): Response
    {
        return $this->adminView('admin/logs', ['title' => 'Protokolle', 'nav' => 'logs'], function (): array {
            return [
                'files'   => \App\Core\Logger::files(),
                'entries' => \App\Core\Logger::tail('app.log', 150),
                'current' => 'app.log',
            ];
        });
    }

    public function diagnostics(Request $request): Response
    {
        return $this->adminView('admin/diagnostics', ['title' => 'Diagnose', 'nav' => 'diagnostics'], function (): array {
            return ['result' => $this->container->diagnostics()->run(false)];
        });
    }

    public function account(Request $request): Response
    {
        return $this->adminView('admin/account', ['title' => 'Admin-Konto', 'nav' => 'account'], function (array $user): array {
            return ['account' => $user];
        });
    }

    public function testCase(Request $request, array $args): Response
    {
        $caseId = Validator::id((string)($args['case'] ?? ''), 'Fall-ID');
        $auth = $this->container->auth();
        $user = $auth->currentUser();
        if ($user === null || ($user['role'] ?? '') !== 'admin') {
            return Response::redirect('/login');
        }
        $case = $this->container->cases()->find($caseId);
        if ($case === null) {
            throw HttpException::notFound('Fall nicht gefunden.');
        }
        if (!$this->container->progress()->exists((string)$user['id'], $caseId)) {
            $progress = $this->container->progress()->reset((string)$user['id'], $caseId);
            $game = new GameController($this->container);
            $this->container->progress()->save($game->applyStartState($case, $progress));
        }
        return Response::redirect('/spielen/' . $caseId);
    }

    /* --------------------------------------------------- */

    private function mediaLibrary(): array
    {
        $library = $this->container->store()->read('media/library.json', ['items' => []]);
        return array_values((array)($library['items'] ?? []));
    }

    public function blankCase(): array
    {
        return [
            'id'            => '',
            'code'          => 'WIT-' . gmdate('Y') . '-' . random_int(1000, 9999),
            'title'         => 'Neuer Fall',
            'subtitle'      => '',
            'status'        => 'draft',
            'order'         => 10,
            'difficulty'    => 'mittel',
            'duration'      => '15-25 Min.',
            'incident_date' => gmdate('Y-m-d'),
            'location'      => '',
            'summary'       => '',
            'briefing'      => '',
            'content_warning' => 'Dieser Fall enthaelt Gewaltdarstellungen und verstoerende Inhalte. Ab 18 Jahren.',
            'cover'         => 'assets/img/scenes/cover-generic.svg',
            'missing_person'=> ['name' => '', 'age' => 0, 'photo' => '', 'description' => '', 'last_seen' => '', 'height' => '', 'clothing' => '', 'traits' => ''],
            'start'         => ['evidence' => [], 'devices' => [], 'flags' => [], 'locations' => []],
            'locations'     => [],
            'timeline_truth'=> [],
            'npcs'          => [],
            'devices'       => [],
            'media'         => ['photos' => [], 'videos' => [], 'audios' => [], 'documents' => []],
            'evidence'      => [],
            'puzzles'       => [],
            'board_links'   => [],
            'horror_events' => [],
            'report'        => ['questions' => [], 'correct' => [], 'weights' => [], 'hints' => []],
            'endings'       => [],
            'scoring'       => ['target_minutes' => 25],
            'solution'      => ['culprit' => '', 'location' => '', 'motive' => '', 'summary' => ''],
        ];
    }
}
