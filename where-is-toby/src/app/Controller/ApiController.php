<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;

/**
 * Spiel-API. Alle Zustaende liegen serverseitig; der Browser erhaelt nur
 * freigeschaltete Informationen.
 */
final class ApiController extends Controller
{
    /* ---------------------------------------------------------
     |  Sitzung und Einstellungen
     --------------------------------------------------------- */

    public function session(Request $request): Response
    {
        $user = $this->container->auth()->currentUser();
        if ($user === null) {
            return $this->fail('Nicht angemeldet.', 401);
        }
        return $this->ok([
            'user' => [
                'id'         => (string)$user['id'],
                'username'   => (string)$user['username'],
                'role'       => (string)($user['role'] ?? 'player'),
                'agent_name' => (string)($user['agent_name'] ?? 'Agent'),
                'is_guest'   => $this->container->auth()->isGuest(),
                'settings'   => (array)($user['settings'] ?? []),
                'age_confirmed' => (bool)($user['age_confirmed'] ?? Session::get('age_confirmed', false)),
            ],
            'chat_mode' => $this->container->npcChat()->mode(),
        ]);
    }

    public function confirmAge(Request $request): Response
    {
        $auth = $this->container->auth();
        $user = $auth->requireUser();
        Session::set('age_confirmed', true);
        if (!$auth->isGuest()) {
            $this->container->users()->update((string)$user['id'], static function (array $record): array {
                $record['age_confirmed'] = true;
                return $record;
            });
        }
        return $this->ok();
    }

    public function saveSettings(Request $request): Response
    {
        $auth = $this->container->auth();
        $user = $auth->requireUser();
        $incoming = $request->arr('settings');
        $clean = [
            'volume'         => max(0.0, min(1.0, (float)($incoming['volume'] ?? 0.7))),
            'subtitles'      => (bool)($incoming['subtitles'] ?? true),
            'effects'        => (bool)($incoming['effects'] ?? true),
            'reduced_motion' => (bool)($incoming['reduced_motion'] ?? false),
            'jumpscares'     => (bool)($incoming['jumpscares'] ?? true),
            'font_scale'     => max(0.85, min(1.4, (float)($incoming['font_scale'] ?? 1.0))),
        ];
        if ($auth->isGuest()) {
            Session::set('guest_settings', $clean);
        } else {
            $this->container->users()->update((string)$user['id'], static function (array $record) use ($clean): array {
                $record['settings'] = $clean;
                return $record;
            });
        }
        return $this->ok(['settings' => $clean]);
    }

    public function cases(Request $request): Response
    {
        $user = $this->container->auth()->requireUser();
        $isAdmin = ($user['role'] ?? '') === 'admin';
        return $this->ok(['cases' => $this->container->cases()->listSummaries($isAdmin)]);
    }

    /* ---------------------------------------------------------
     |  Fallzustand
     --------------------------------------------------------- */

    public function state(Request $request, array $args): Response
    {
        [$user, $caseId, $isAdmin] = $this->context($args);
        $state = $this->container->engine()->state($caseId, (string)$user['id'], $isAdmin);
        $state['pending'] = $this->collectProactive($caseId, (string)$user['id']);
        return $this->ok(['state' => $state]);
    }

    public function start(Request $request, array $args): Response
    {
        [$user, $caseId, $isAdmin] = $this->context($args);
        $case = $this->container->cases()->get($caseId);
        $progress = $this->container->progress()->get((string)$user['id'], $caseId);
        if (($progress['started_at'] ?? '') === '' || (array)($progress['evidence'] ?? []) === []) {
            $game = new GameController($this->container);
            $progress = $game->applyStartState($case, $progress);
        }
        $progress['intro_done'] = true;
        $this->container->progress()->save($progress);
        return $this->ok(['state' => $this->container->engine()->buildState($case, $progress, $isAdmin)]);
    }

    public function reset(Request $request, array $args): Response
    {
        [$user, $caseId, $isAdmin] = $this->context($args);
        $case = $this->container->cases()->get($caseId);
        $progress = $this->container->progress()->reset((string)$user['id'], $caseId);
        $game = new GameController($this->container);
        $progress = $game->applyStartState($case, $progress);
        $this->container->progress()->save($progress);
        Logger::info('Fall zurueckgesetzt', ['case' => $caseId]);
        return $this->ok(['state' => $this->container->engine()->buildState($case, $progress, $isAdmin)]);
    }

    public function puzzle(Request $request, array $args): Response
    {
        [$user, $caseId, $isAdmin] = $this->context($args);
        $this->container->limiter()->enforce('puzzle:' . $user['id'], 90, 60);
        $puzzleId = Validator::id($request->str('puzzle', '', 64), 'Raetsel-ID');
        $answer = $request->input('answer', '');
        $result = $this->container->engine()->solvePuzzle($caseId, (string)$user['id'], $puzzleId, $answer, $isAdmin);
        return $this->ok($result);
    }

    public function evidence(Request $request, array $args): Response
    {
        [$user, $caseId, $isAdmin] = $this->context($args);
        $evidenceId = Validator::id($request->str('evidence', '', 64), 'Beweis-ID');
        return $this->ok($this->container->engine()->collectEvidence($caseId, (string)$user['id'], $evidenceId, $isAdmin));
    }

    public function deviceApp(Request $request, array $args): Response
    {
        [$user, $caseId, $isAdmin] = $this->context($args);
        $deviceId = Validator::id((string)($args['device'] ?? ''), 'Geraete-ID');
        $appId = Validator::id((string)($args['app'] ?? ''), 'App-ID');
        return $this->ok($this->container->engine()->openApp($caseId, (string)$user['id'], $deviceId, $appId, $isAdmin));
    }

    public function media(Request $request, array $args): Response
    {
        [$user, $caseId, $isAdmin] = $this->context($args);
        $mediaId = Validator::id((string)($args['media'] ?? ''), 'Medien-ID');
        return $this->ok(['media' => $this->container->engine()->media($caseId, (string)$user['id'], $mediaId, $isAdmin)]);
    }

    /* ---------------------------------------------------------
     |  Gespraeche
     --------------------------------------------------------- */

    public function chatHistory(Request $request, array $args): Response
    {
        [$user, $caseId, $isAdmin] = $this->context($args);
        $npcId = Validator::id((string)($args['npc'] ?? ''), 'NPC-ID');
        $case = $this->container->cases()->get($caseId);
        $npc = $this->container->engine()->findNpc($case, $npcId);
        if ($npc === null) {
            throw HttpException::notFound('Diese Person gibt es nicht.');
        }

        $progress = $this->container->progress()->get((string)$user['id'], $caseId);
        $state = (array)($progress['npc'][$npcId] ?? []);
        $messages = (array)($state['messages'] ?? []);

        if ($messages === []) {
            $greeting = $this->container->npcChat()->greeting($npc, $state);
            $progress = $this->container->progress()->update((string)$user['id'], $caseId, static function (array $p) use ($npcId, $greeting): array {
                $p['npc'][$npcId]['messages'][] = ['from' => 'npc', 'text' => $greeting, 'time' => gmdate('c')];
                return $p;
            });
            $messages = (array)($progress['npc'][$npcId]['messages'] ?? []);
        }

        return $this->ok([
            'npc' => [
                'id'     => $npcId,
                'name'   => (string)($npc['name'] ?? ''),
                'role'   => (string)($npc['role'] ?? ''),
                'avatar' => (string)($npc['avatar'] ?? ''),
                'age'    => (int)($npc['age'] ?? 0),
                'short'  => (string)($npc['short'] ?? ''),
                'topics' => array_values(array_map(
                    static fn(array $t): array => ['label' => (string)($t['label'] ?? ''), 'text' => (string)($t['text'] ?? '')],
                    (array)($npc['suggested_questions'] ?? [])
                )),
            ],
            'messages'   => $this->publicMessages($messages),
            'left_until' => (int)($state['left_until'] ?? 0),
            'mode'       => $this->container->npcChat()->mode(),
        ]);
    }

    public function chat(Request $request, array $args): Response
    {
        [$user, $caseId, $isAdmin] = $this->context($args);
        $perMinute = (int)$this->container->settings()->get('security.chat_per_minute', 15);
        $this->container->limiter()->enforce('chat:' . $user['id'], max(3, $perMinute), 60);

        $npcId = Validator::id($request->str('npc', '', 64), 'NPC-ID');
        $message = Validator::text($request->str('message', '', 900), 900);
        $evidenceId = $request->str('evidence', '', 64);
        if ($evidenceId !== '') {
            $evidenceId = Validator::id($evidenceId, 'Beweis-ID');
        }

        $case = $this->container->cases()->get($caseId);
        $npc = $this->container->engine()->findNpc($case, $npcId);
        if ($npc === null) {
            throw HttpException::notFound('Diese Person gibt es nicht.');
        }

        $progress = $this->container->progress()->get((string)$user['id'], $caseId);
        $state = (array)($progress['npc'][$npcId] ?? []);

        // Person hat den Chat voruebergehend verlassen
        $leftUntil = (int)($state['left_until'] ?? 0);
        if ($leftUntil > time()) {
            return $this->ok([
                'reply'      => '',
                'unavailable'=> true,
                'left_until' => $leftUntil,
                'message'    => 'Die Person hat das Gespraech abgebrochen und antwortet gerade nicht.',
            ]);
        }

        if ($evidenceId !== '' && !in_array($evidenceId, (array)($progress['evidence'] ?? []), true) && !$isAdmin) {
            throw HttpException::forbidden('Diesen Beweis hast du noch nicht.');
        }

        $context = [
            'message'         => $message,
            'evidence'        => $evidenceId,
            'player_evidence' => (array)($progress['evidence'] ?? []),
            'flags'           => array_keys(array_filter((array)($progress['flags'] ?? []))),
            'user_id'         => (string)$user['id'],
        ];

        $result = $this->container->npcChat()->reply($case, $npc, $state, $context);
        if ($result['reply'] === '') {
            return $this->fail('Die Verbindung wurde unterbrochen. Bitte erneut versuchen.', 503);
        }

        $unlocked = [];
        $horrorEvent = null;
        $progress = $this->container->progress()->update((string)$user['id'], $caseId, function (array $p) use ($result, $npcId, $message, $evidenceId, $case, &$unlocked, &$horrorEvent): array {
            $applied = $this->container->npcChat()->applyDelta($p, $npcId, $result['delta'], $message, $result['reply'], $evidenceId);
            $p = $applied['progress'];
            $unlocked = $applied['unlocked'];
            $event = $evidenceId !== '' ? 'confront' : 'chat';
            $horror = $this->container->horror()->evaluate($case, $p, $event, $npcId);
            $horrorEvent = $horror['event'];
            return $horror['progress'];
        });

        $newEvidence = [];
        foreach ($unlocked as $evidence) {
            foreach ((array)($case['evidence'] ?? []) as $item) {
                if ((string)($item['id'] ?? '') === $evidence) {
                    $newEvidence[] = [
                        'id'      => $evidence,
                        'code'    => (string)($item['code'] ?? $evidence),
                        'title'   => (string)($item['title'] ?? ''),
                        'summary' => (string)($item['summary'] ?? ''),
                    ];
                }
            }
        }

        return $this->ok([
            'reply'        => $result['reply'],
            'mode'         => $result['mode'],
            'new_evidence' => $newEvidence,
            'horror'       => $horrorEvent,
            'left_until'   => (int)($progress['npc'][$npcId]['left_until'] ?? 0),
            'state'        => $unlocked !== [] || $horrorEvent !== null
                ? $this->container->engine()->buildState($case, $progress, $isAdmin)
                : null,
        ]);
    }

    /* ---------------------------------------------------------
     |  Hinweise, Notizen, Wand, Bericht
     --------------------------------------------------------- */

    public function hint(Request $request, array $args): Response
    {
        [$user, $caseId, $isAdmin] = $this->context($args);
        $this->container->limiter()->enforce('hint:' . $user['id'], 20, 60);
        $case = $this->container->cases()->get($caseId);
        $progress = $this->container->progress()->get((string)$user['id'], $caseId);

        $result = $this->container->hints()->nextHint($case, $progress, $isAdmin);
        if ($result['ok']) {
            $this->container->progress()->save($result['progress']);
        }
        unset($result['progress']);
        return $this->ok($result);
    }

    public function note(Request $request, array $args): Response
    {
        [$user, $caseId] = $this->context($args);
        $note = $this->container->engine()->addNote(
            $caseId,
            (string)$user['id'],
            $request->str('text', '', 1200),
            $request->str('ref_type', '', 30),
            $request->str('ref_id', '', 64)
        );
        return $this->ok(['note' => $note]);
    }

    public function deleteNote(Request $request, array $args): Response
    {
        [$user, $caseId] = $this->context($args);
        $this->container->engine()->deleteNote($caseId, (string)$user['id'], $request->str('id', '', 64));
        return $this->ok();
    }

    public function board(Request $request, array $args): Response
    {
        [$user, $caseId] = $this->context($args);
        $result = $this->container->engine()->saveBoard($caseId, (string)$user['id'], $request->arr('board'));
        return $this->ok($result);
    }

    public function saveReport(Request $request, array $args): Response
    {
        [$user, $caseId] = $this->context($args);
        return $this->ok($this->container->engine()->saveReport($caseId, (string)$user['id'], $request->arr('answers')));
    }

    public function submitReport(Request $request, array $args): Response
    {
        [$user, $caseId, $isAdmin] = $this->context($args);
        $case = $this->container->cases()->get($caseId);

        $answers = $request->arr('answers');
        if ($answers !== []) {
            $this->container->engine()->saveReport($caseId, (string)$user['id'], $answers);
        }

        $progress = $this->container->progress()->get((string)$user['id'], $caseId);
        $evaluation = $this->container->report()->evaluate($case, $progress);

        $progress = $this->container->progress()->update((string)$user['id'], $caseId, static function (array $p) use ($evaluation): array {
            $p['result'] = $evaluation;
            $p['completed_at'] = gmdate('c');
            $p['flags']['bericht_abgegeben'] = true;
            return $p;
        });

        if (!$this->container->auth()->isGuest()) {
            $this->container->users()->update((string)$user['id'], static function (array $record) use ($evaluation, $progress): array {
                $stats = (array)($record['stats'] ?? []);
                $stats['cases_completed'] = (int)($stats['cases_completed'] ?? 0) + 1;
                $ranks = ['F' => 0, 'D' => 1, 'C' => 2, 'B' => 3, 'A' => 4, 'S' => 5];
                $best = (string)($stats['best_rank'] ?? 'F');
                if (($ranks[$evaluation['rank']] ?? 0) > ($ranks[$best] ?? 0)) {
                    $stats['best_rank'] = $evaluation['rank'];
                }
                $stats['total_playtime'] = (int)($stats['total_playtime'] ?? 0) + (int)($progress['playtime'] ?? 0);
                $record['stats'] = $stats;
                return $record;
            });
        }

        $horror = $this->container->engine()->triggerHorror($caseId, (string)$user['id'], 'finish', $evaluation['ending']['id'] ?? '');
        Logger::info('Fall abgeschlossen', ['case' => $caseId, 'rank' => $evaluation['rank'], 'percent' => $evaluation['percent']]);

        return $this->ok([
            'result' => $evaluation,
            'horror' => $horror,
            'state'  => $this->container->engine()->buildState($case, $progress, $isAdmin),
        ]);
    }

    public function heartbeat(Request $request, array $args): Response
    {
        [$user, $caseId] = $this->context($args);
        $this->container->engine()->heartbeat($caseId, (string)$user['id'], $request->int('seconds', 20));
        return $this->ok(['pending' => $this->collectProactive($caseId, (string)$user['id'])]);
    }

    public function event(Request $request, array $args): Response
    {
        [$user, $caseId] = $this->context($args);
        $event = Validator::text($request->str('event', '', 40), 40);
        $subject = Validator::text($request->str('subject', '', 64), 64);
        $allowed = ['open_panel', 'idle', 'start', 'hint', 'report', 'open_device'];
        if (!in_array($event, $allowed, true)) {
            throw HttpException::badRequest('Unbekanntes Ereignis.');
        }
        $horror = $this->container->engine()->triggerHorror($caseId, (string)$user['id'], $event, $subject);
        return $this->ok(['horror' => $horror]);
    }

    /* ---------------------------------------------------------
     |  Helfer
     --------------------------------------------------------- */

    /** @return array{0:array,1:string,2:bool} */
    private function context(array $args): array
    {
        $user = $this->container->auth()->requireUser();
        $caseId = Validator::id((string)($args['case'] ?? ''), 'Fall-ID');
        $isAdmin = ($user['role'] ?? '') === 'admin';
        return [$user, $caseId, $isAdmin];
    }

    private function publicMessages(array $messages): array
    {
        $out = [];
        foreach ($messages as $message) {
            $out[] = [
                'from'     => (string)($message['from'] ?? 'npc'),
                'text'     => (string)($message['text'] ?? ''),
                'time'     => (string)($message['time'] ?? ''),
                'evidence' => (string)($message['evidence'] ?? ''),
            ];
        }
        return $out;
    }

    /** Ungefragte Nachrichten der Figuren einsammeln. */
    private function collectProactive(string $caseId, string $userId): array
    {
        $case = $this->container->cases()->get($caseId);
        $progress = $this->container->progress()->get($userId, $caseId);
        $flags = array_keys(array_filter((array)($progress['flags'] ?? [])));
        $evidence = (array)($progress['evidence'] ?? []);
        $pending = [];

        foreach ((array)($case['npcs'] ?? []) as $npc) {
            $npcId = (string)($npc['id'] ?? '');
            $state = (array)($progress['npc'][$npcId] ?? []);
            $message = $this->container->npcChat()->proactive($npc, $state, $flags, $evidence);
            if ($message === null) {
                continue;
            }
            $this->container->progress()->update($userId, $caseId, static function (array $p) use ($npcId, $message): array {
                $p['npc'][$npcId]['proactive_sent'][] = (string)$message['id'];
                $p['npc'][$npcId]['proactive_sent'] = array_values(array_unique($p['npc'][$npcId]['proactive_sent']));
                $p['npc'][$npcId]['messages'][] = ['from' => 'npc', 'text' => (string)$message['text'], 'time' => gmdate('c')];
                $p['npc'][$npcId]['unread'] = (int)($p['npc'][$npcId]['unread'] ?? 0) + 1;
                foreach ((array)($message['effects']['flags'] ?? []) as $flag) {
                    $p['flags'][(string)$flag] = true;
                }
                foreach ((array)($message['effects']['evidence'] ?? []) as $evidenceId) {
                    if (!in_array($evidenceId, $p['evidence'] ?? [], true)) {
                        $p['evidence'][] = (string)$evidenceId;
                    }
                }
                return $p;
            });
            $pending[] = [
                'npc'    => $npcId,
                'name'   => (string)($npc['name'] ?? ''),
                'avatar' => (string)($npc['avatar'] ?? ''),
                'text'   => (string)$message['text'],
            ];
        }
        return $pending;
    }
}
