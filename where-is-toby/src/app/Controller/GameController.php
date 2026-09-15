<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;

final class GameController extends Controller
{
    public function caseList(Request $request): Response
    {
        $auth = $this->container->auth();
        $user = $auth->requireUser();
        $isAdmin = ($user['role'] ?? '') === 'admin';
        $cases = $this->container->cases()->listSummaries($isAdmin);

        $progressMap = [];
        foreach ($cases as $summary) {
            if ($this->container->progress()->exists((string)$user['id'], $summary['id'])) {
                $progress = $this->container->progress()->get((string)$user['id'], $summary['id']);
                $progressMap[$summary['id']] = [
                    'percent'   => (int)($this->container->engine()->buildState(
                        $this->container->cases()->get($summary['id']),
                        $progress,
                        $isAdmin
                    )['progress']['percent'] ?? 0),
                    'completed' => $progress['completed_at'] !== null,
                    'rank'      => (string)($progress['result']['rank'] ?? ''),
                    'playtime'  => (int)($progress['playtime'] ?? 0),
                ];
            }
        }

        return $this->render('game/cases', [
            'title'      => 'Fallauswahl',
            'cases'      => $cases,
            'progress'   => $progressMap,
            'ageConfirmed' => (bool)Session::get('age_confirmed', false) || (bool)($user['age_confirmed'] ?? false),
        ], 'layout_public');
    }

    public function play(Request $request, array $args): Response
    {
        $auth = $this->container->auth();
        $user = $auth->requireUser();
        $isAdmin = ($user['role'] ?? '') === 'admin';
        $caseId = Validator::id((string)($args['case'] ?? ''), 'Fall-ID');

        $case = $this->container->cases()->find($caseId);
        if ($case === null) {
            throw HttpException::notFound('Diesen Fall gibt es nicht.');
        }
        if (($case['status'] ?? 'draft') !== 'published' && !$isAdmin) {
            throw HttpException::forbidden('Dieser Fall ist nicht freigegeben.');
        }

        // Fortschritt anlegen, falls es der erste Besuch ist
        if (!$this->container->progress()->exists((string)$user['id'], $caseId)) {
            $progress = $this->container->progress()->reset((string)$user['id'], $caseId);
            $progress = $this->applyStartState($case, $progress);
            $this->container->progress()->save($progress);
        }

        $state = $this->container->engine()->state($caseId, (string)$user['id'], $isAdmin);

        return $this->render('game/play', [
            'title'      => (string)$case['title'] . ' - Ermittlungsterminal',
            'caseId'     => $caseId,
            'state'      => $state,
            'agentName'  => (string)($user['agent_name'] ?? 'Agent'),
            'userSettings' => (array)($user['settings'] ?? []),
            'chatMode'   => $this->container->npcChat()->mode(),
            'ageConfirmed' => (bool)Session::get('age_confirmed', false) || (bool)($user['age_confirmed'] ?? false),
            'gameplay'   => [
                'hints'      => (int)$this->container->settings()->get('gameplay.hints_per_case', 4),
                'autosave'   => (int)$this->container->settings()->get('gameplay.autosave_seconds', 20),
                'showTimer'  => (bool)$this->container->settings()->get('gameplay.show_timer', true),
                'horror'     => (string)$this->container->settings()->get('gameplay.horror_intensity', 'normal'),
                'jumpscares' => (bool)$this->container->settings()->get('gameplay.jumpscares', true),
            ],
        ], 'layout_game');
    }

    /** Startzustand eines Falls (Startbeweise, Startgeraete, Startflags). */
    public function applyStartState(array $case, array $progress): array
    {
        foreach ((array)($case['start']['evidence'] ?? []) as $evidenceId) {
            if (!in_array($evidenceId, $progress['evidence'], true)) {
                $progress['evidence'][] = (string)$evidenceId;
            }
        }
        foreach ((array)($case['start']['devices'] ?? []) as $deviceId) {
            if (!in_array($deviceId, $progress['devices'], true)) {
                $progress['devices'][] = (string)$deviceId;
            }
        }
        foreach ((array)($case['start']['flags'] ?? []) as $flag) {
            $progress['flags'][(string)$flag] = true;
        }
        foreach ((array)($case['start']['locations'] ?? []) as $locationId) {
            if (!in_array($locationId, $progress['visited'], true)) {
                $progress['visited'][] = (string)$locationId;
            }
        }
        foreach ((array)($case['npcs'] ?? []) as $npc) {
            $id = (string)($npc['id'] ?? '');
            $progress['npc'][$id] = array_merge([
                'trust'  => (int)($npc['initial_trust'] ?? 45),
                'stress' => (int)($npc['initial_stress'] ?? 15),
                'alibi'  => (string)($npc['alibi_claimed'] ?? ''),
                'messages' => [],
                'revealed' => [],
                'confronted' => [],
                'used_rules' => [],
                'message_count' => 0,
            ], (array)($progress['npc'][$id] ?? []));
        }
        return $progress;
    }
}
