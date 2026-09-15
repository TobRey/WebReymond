<?php
declare(strict_types=1);

namespace App\Service;

use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Validator;
use App\Repository\CaseRepository;
use App\Repository\ProgressRepository;
use App\Repository\SettingsRepository;

/**
 * Zentrale Fall-Engine: baut den fuer Spielende sichtbaren Zustand,
 * verwaltet Freischaltungen, Geraete, Beweise, Notizen und Ermittlungswand.
 *
 * Grundsatz: Loesungen, echte Alibis, Luegen-Wahrheiten, Raetselantworten und
 * Horror-Definitionen verlassen niemals den Server.
 */
final class CaseEngine
{
    public function __construct(
        private CaseRepository $cases,
        private ProgressRepository $progress,
        private PuzzleEngine $puzzles,
        private HorrorService $horror,
        private SettingsRepository $settings
    ) {
    }

    /* =========================================================
     |  Zustand fuer den Browser
     ========================================================= */

    public function state(string $caseId, string $userId, bool $isAdmin = false): array
    {
        $case = $this->cases->get($caseId);
        if (($case['status'] ?? 'draft') !== 'published' && !$isAdmin) {
            throw HttpException::forbidden('Dieser Fall ist derzeit nicht freigegeben.');
        }
        $progress = $this->progress->get($userId, $caseId);
        return $this->buildState($case, $progress, $isAdmin);
    }

    public function buildState(array $case, array $progress, bool $isAdmin = false): array
    {
        $foundEvidence = (array)($progress['evidence'] ?? []);
        $hintLimit = (int)$this->settings->get('gameplay.hints_per_case', 4);

        return [
            'case' => [
                'id'          => (string)$case['id'],
                'code'        => (string)($case['code'] ?? ''),
                'title'       => (string)($case['title'] ?? ''),
                'subtitle'    => (string)($case['subtitle'] ?? ''),
                'summary'     => (string)($case['summary'] ?? ''),
                'briefing'    => (string)($case['briefing'] ?? ''),
                'location'    => (string)($case['location'] ?? ''),
                'incident_date' => (string)($case['incident_date'] ?? ''),
                'report_deadline' => (string)($case['report_deadline'] ?? ''),
                'cover'       => (string)($case['cover'] ?? ''),
                'content_warning' => (string)($case['content_warning'] ?? ''),
                'missing_person' => $case['missing_person'] ?? [],
                'file_entries'   => $this->publicFileEntries($case, $progress),
                'reference'      => $case['reference'] ?? [],
                'intro'          => $case['intro'] ?? [],
                'map'            => $case['map'] ?? [],
            ],
            'locations' => $this->publicLocations($case, $progress),
            'npcs'      => $this->publicNpcs($case, $progress),
            'devices'   => $this->publicDevices($case, $progress),
            'evidence'  => $this->publicEvidence($case, $progress),
            'evidence_total' => count($case['evidence'] ?? []),
            'puzzles'   => $this->publicPuzzles($case, $progress),
            'objectives' => $this->publicObjectives($case, $progress),
            'board'     => $progress['board'] ?? ['nodes' => [], 'links' => [], 'view' => ['x' => 0, 'y' => 0, 'zoom' => 1]],
            'notes'     => array_values((array)($progress['notes'] ?? [])),
            'report'    => [
                'questions' => $this->publicReportQuestions($case),
                'answers'   => (array)($progress['report'] ?? []),
                'submitted' => $progress['result'] !== null,
                'result'    => $progress['result'] ?? null,
            ],
            'progress' => [
                'solved'      => array_values((array)($progress['solved'] ?? [])),
                'flags'       => array_keys(array_filter((array)($progress['flags'] ?? []))),
                'evidence'    => array_values($foundEvidence),
                'devices'     => array_values((array)($progress['devices'] ?? [])),
                'apps'        => array_values((array)($progress['apps'] ?? [])),
                'visited'     => array_values((array)($progress['visited'] ?? [])),
                'hints_used'  => (int)($progress['hints_used'] ?? 0),
                'hints_left'  => $isAdmin ? -1 : max(0, $hintLimit - (int)($progress['hints_used'] ?? 0)),
                'started_at'  => (string)($progress['started_at'] ?? ''),
                'playtime'    => (int)($progress['playtime'] ?? 0),
                'completed'   => $progress['completed_at'] !== null,
                'intro_done'  => (bool)($progress['intro_done'] ?? false),
                'percent'     => $this->completionPercent($case, $progress),
                'is_admin'    => $isAdmin,
            ],
        ];
    }

    private function completionPercent(array $case, array $progress): int
    {
        $totalPuzzles = max(1, count(array_filter(
            (array)($case['puzzles'] ?? []),
            static fn(array $p): bool => (bool)($p['counts_for_progress'] ?? true)
        )));
        $totalEvidence = max(1, count($case['evidence'] ?? []));
        $puzzleShare = count((array)($progress['solved'] ?? [])) / $totalPuzzles;
        $evidenceShare = count((array)($progress['evidence'] ?? [])) / $totalEvidence;
        return (int)round(min(100, (($puzzleShare * 0.6) + ($evidenceShare * 0.4)) * 100));
    }

    /* =========================================================
     |  Oeffentliche (gefilterte) Sichten
     ========================================================= */
    /** Aktenstuecke, deren Voraussetzungen erfuellt sind. */
    private function publicFileEntries(array $case, array $progress): array
    {
        $out = [];
        foreach ((array)($case['file_entries'] ?? []) as $entry) {
            $visible = true;
            foreach ((array)($entry['requires_flags'] ?? []) as $flag) {
                if (empty($progress['flags'][$flag])) {
                    $visible = false;
                }
            }
            if (($entry['requires_puzzle'] ?? '') !== '' && !in_array($entry['requires_puzzle'], (array)($progress['solved'] ?? []), true)) {
                $visible = false;
            }
            if (!$visible) {
                continue;
            }
            unset($entry['requires_flags'], $entry['requires_puzzle']);
            $out[] = $entry;
        }
        return $out;
    }


    private function publicLocations(array $case, array $progress): array
    {
        $out = [];
        foreach ((array)($case['locations'] ?? []) as $location) {
            $visible = (bool)($location['always_visible'] ?? true);
            foreach ((array)($location['requires_flags'] ?? []) as $flag) {
                if (empty($progress['flags'][$flag])) {
                    $visible = false;
                }
            }
            if (!$visible && !in_array($location['id'] ?? '', (array)($progress['visited'] ?? []), true)) {
                continue;
            }
            $out[] = [
                'id'          => (string)$location['id'],
                'name'        => (string)($location['name'] ?? ''),
                'type'        => (string)($location['type'] ?? 'ort'),
                'description' => (string)($location['description'] ?? ''),
                'x'           => (float)($location['x'] ?? 0.5),
                'y'           => (float)($location['y'] ?? 0.5),
                'address'     => (string)($location['address'] ?? ''),
                'visited'     => in_array($location['id'] ?? '', (array)($progress['visited'] ?? []), true),
                'notes'       => (string)($location['notes'] ?? ''),
                'evidence'    => array_values(array_intersect(
                    (array)($location['evidence'] ?? []),
                    (array)($progress['evidence'] ?? [])
                )),
            ];
        }
        return $out;
    }

    private function publicNpcs(array $case, array $progress): array
    {
        $out = [];
        foreach ((array)($case['npcs'] ?? []) as $npc) {
            $id = (string)($npc['id'] ?? '');
            $state = $progress['npc'][$id] ?? [];
            $available = true;
            foreach ((array)($npc['requires_flags'] ?? []) as $flag) {
                if (empty($progress['flags'][$flag])) {
                    $available = false;
                }
            }
            if (!$available && !(bool)($npc['show_locked'] ?? false)) {
                continue;
            }
            $out[] = [
                'id'          => $id,
                'name'        => (string)($npc['name'] ?? ''),
                'age'         => (int)($npc['age'] ?? 0),
                'role'        => (string)($npc['role'] ?? ''),
                'relation'    => (string)($npc['relationship'] ?? ''),
                'avatar'      => (string)($npc['avatar'] ?? ''),
                'short'       => (string)($npc['short'] ?? ''),
                'status'      => (string)($npc['status'] ?? 'erreichbar'),
                'phone'       => (string)($npc['phone'] ?? ''),
                'available'   => $available,
                'locked_hint' => $available ? '' : (string)($npc['locked_hint'] ?? 'Noch kein Kontakt moeglich.'),
                'statement'   => (string)($npc['alibi_claimed'] ?? ''),
                'unread'      => (int)($state['unread'] ?? 0),
                'messages'    => count((array)($state['messages'] ?? [])),
                'left_until'  => (int)($state['left_until'] ?? 0),
                'confronted'  => array_values((array)($state['confronted'] ?? [])),
                'tags'        => (array)($npc['tags'] ?? []),
            ];
        }
        return $out;
    }

    private function publicDevices(array $case, array $progress): array
    {
        $out = [];
        foreach ((array)($case['devices'] ?? []) as $device) {
            $id = (string)($device['id'] ?? '');
            $unlocked = in_array($id, (array)($progress['devices'] ?? []), true) || !isset($device['lock']);
            $visible = true;
            foreach ((array)($device['requires_flags'] ?? []) as $flag) {
                if (empty($progress['flags'][$flag])) {
                    $visible = false;
                }
            }
            if (!$visible) {
                continue;
            }
            $out[] = [
                'id'        => $id,
                'name'      => (string)($device['name'] ?? ''),
                'type'      => (string)($device['type'] ?? 'phone'),
                'owner'     => (string)($device['owner'] ?? ''),
                'image'     => (string)($device['image'] ?? ''),
                'note'      => (string)($device['note'] ?? ''),
                'evidence_tag' => (string)($device['evidence_tag'] ?? ''),
                'unlocked'  => $unlocked,
                'lock'      => $unlocked ? null : [
                    'type'   => (string)($device['lock']['type'] ?? 'pin'),
                    'puzzle' => (string)($device['lock']['puzzle'] ?? ''),
                    'label'  => (string)($device['lock']['label'] ?? 'Gesperrt'),
                    'hint'   => (string)($device['lock']['hint'] ?? ''),
                    'length' => (int)($device['lock']['length'] ?? 4),
                    'user'   => (string)($device['lock']['user'] ?? ''),
                ],
                'apps'      => $unlocked ? $this->publicApps($device, $progress) : [],
            ];
        }
        return $out;
    }

    private function publicApps(array $device, array $progress): array
    {
        $out = [];
        foreach ((array)($device['apps'] ?? []) as $app) {
            $key = (string)($device['id'] ?? '') . ':' . (string)($app['id'] ?? '');
            $locked = false;
            foreach ((array)($app['requires_flags'] ?? []) as $flag) {
                if (empty($progress['flags'][$flag])) {
                    $locked = true;
                }
            }
            if (($app['requires_puzzle'] ?? '') !== '' && !in_array($app['requires_puzzle'], (array)($progress['solved'] ?? []), true)) {
                $locked = true;
            }
            $out[] = [
                'id'     => (string)($app['id'] ?? ''),
                'key'    => $key,
                'type'   => (string)($app['type'] ?? 'files'),
                'label'  => (string)($app['label'] ?? ''),
                'icon'   => (string)($app['icon'] ?? ''),
                'badge'  => (int)($app['badge'] ?? 0),
                'locked' => $locked,
                'locked_hint' => $locked ? (string)($app['locked_hint'] ?? 'Zugriff verweigert.') : '',
                'puzzle' => (string)($app['requires_puzzle'] ?? ''),
            ];
        }
        return $out;
    }

    private function publicEvidence(array $case, array $progress): array
    {
        $found = (array)($progress['evidence'] ?? []);
        $out = [];
        foreach ((array)($case['evidence'] ?? []) as $evidence) {
            $id = (string)($evidence['id'] ?? '');
            if (!in_array($id, $found, true)) {
                continue;
            }
            $out[] = [
                'id'        => $id,
                'code'      => (string)($evidence['code'] ?? $id),
                'title'     => (string)($evidence['title'] ?? ''),
                'category'  => (string)($evidence['category'] ?? 'dokument'),
                'summary'   => (string)($evidence['summary'] ?? ''),
                'detail'    => (string)($evidence['detail'] ?? ''),
                'source'    => (string)($evidence['source'] ?? ''),
                'timestamp' => (string)($evidence['timestamp'] ?? ''),
                'media'     => $evidence['media'] ?? null,
                'importance'=> (string)($evidence['importance'] ?? 'neben'),
                'tags'      => (array)($evidence['tags'] ?? []),
                'related'   => (array)($evidence['related'] ?? []),
            ];
        }
        return $out;
    }

    private function publicPuzzles(array $case, array $progress): array
    {
        $out = [];
        foreach ((array)($case['puzzles'] ?? []) as $puzzle) {
            $id = (string)($puzzle['id'] ?? '');
            $solved = in_array($id, (array)($progress['solved'] ?? []), true);
            if ((bool)($puzzle['hidden'] ?? false) && !$solved) {
                $requirements = $this->puzzles->requirementsMet($puzzle, $progress);
                if (!$requirements['ok']) {
                    continue;
                }
            }
            $requirements ??= $this->puzzles->requirementsMet($puzzle, $progress);
            $out[] = [
                'id'        => $id,
                'type'      => (string)($puzzle['type'] ?? 'text'),
                'title'     => (string)($puzzle['title'] ?? ''),
                'prompt'    => (string)($puzzle['prompt'] ?? ''),
                'placeholder' => (string)($puzzle['placeholder'] ?? ''),
                'context'   => (string)($puzzle['context'] ?? ''),
                'options'   => $this->publicOptions($puzzle),
                'tokens'    => $this->publicTokens($puzzle),
                'records'   => $this->publicRecords($puzzle),
                'columns'   => array_values((array)($puzzle['columns'] ?? [])),
                'filters'   => array_values((array)($puzzle['filters'] ?? [])),
                'left'      => $this->publicSide($puzzle, 'left'),
                'right'     => $this->publicSide($puzzle, 'right'),
                'left_title'  => (string)($puzzle['left_title'] ?? ''),
                'right_title' => (string)($puzzle['right_title'] ?? ''),
                'length'    => (int)($puzzle['length'] ?? 0),
                'media'     => (string)($puzzle['media'] ?? ''),
                'panel'     => (string)($puzzle['panel'] ?? ''),
                'solved'    => $solved,
                'available' => $requirements['ok'],
                'attempts'  => $this->puzzles->attemptsFor($progress, $id),
                'max_attempts' => (int)($puzzle['max_attempts'] ?? 0),
                'explanation'  => $solved ? (string)($puzzle['solution_explanation'] ?? '') : '',
                'counts_for_progress' => (bool)($puzzle['counts_for_progress'] ?? true),
            ];
            unset($requirements);
        }
        return $out;
    }

    /**
     * Aktuelle Arbeitsauftraege.
     *
     * Der Fall ist bewusst frei begehbar, aber ohne jeden Wegweiser weiss niemand,
     * wo er anfangen soll. Darum wird immer nur das aktuelle Kapitel gezeigt: die
     * offenen Punkte daraus, jeder mit dem Bereich, in dem er zu erledigen ist.
     *
     * @return array{chapter:int,chapters:int,title:string,open:array,done:int,total:int}|null
     */
    private function publicObjectives(array $case, array $progress): array|null
    {
        $objectives = (array)($case['objectives'] ?? []);
        if ($objectives === []) {
            return null;
        }

        $chapters = [];
        foreach ($objectives as $objective) {
            $chapter = (int)($objective['chapter'] ?? 1);
            $chapters[$chapter] ??= ['title' => (string)($objective['chapter_title'] ?? ''), 'items' => []];
            if ($chapters[$chapter]['title'] === '') {
                $chapters[$chapter]['title'] = (string)($objective['chapter_title'] ?? '');
            }
            $chapters[$chapter]['items'][] = $objective;
        }
        ksort($chapters);

        $doneTotal = 0;
        $current = null;
        $currentNumber = 0;
        foreach ($chapters as $number => $chapter) {
            $open = [];
            foreach ($chapter['items'] as $objective) {
                if ($this->objectiveDone($objective, $progress)) {
                    $doneTotal++;
                    continue;
                }
                /* Punkte, deren Voraussetzungen noch fehlen, bleiben verborgen -
                   sonst steht dort eine Aufgabe, die noch gar nicht loesbar ist. */
                if (!$this->puzzles->requirementsMet($objective, $progress)['ok']) {
                    continue;
                }
                $open[] = [
                    'id'     => (string)($objective['id'] ?? ''),
                    'title'  => (string)($objective['title'] ?? ''),
                    'detail' => (string)($objective['detail'] ?? ''),
                    'panel'  => (string)($objective['panel'] ?? ''),
                ];
            }
            if ($current === null && $open !== []) {
                $current = ['title' => $chapter['title'], 'open' => $open];
                $currentNumber = (int)$number;
            }
        }

        $total = count($objectives);
        if ($current === null) {
            return [
                'chapter'  => count($chapters),
                'chapters' => count($chapters),
                'title'    => 'Abschluss',
                'open'     => [],
                'done'     => $doneTotal,
                'total'    => $total,
            ];
        }

        return [
            'chapter'  => $currentNumber,
            'chapters' => count($chapters),
            'title'    => $current['title'],
            'open'     => array_slice($current['open'], 0, 3),
            'done'     => $doneTotal,
            'total'    => $total,
        ];
    }

    /** Ein Auftrag gilt als erledigt, wenn alle genannten Bedingungen erfuellt sind. */
    private function objectiveDone(array $objective, array $progress): bool
    {
        $done = (array)($objective['done'] ?? []);
        if ($done === []) {
            return false;
        }
        return $this->puzzles->requirementsMet(['requires' => $done], $progress)['ok'];
    }

    /**
     * Textbausteine fuer den Typ "mark": Der Spieler klickt verdaechtige Stellen
     * direkt im Text an. Ausgeliefert werden nur Kennung und Text - welche Stelle
     * richtig ist, bleibt auf dem Server.
     */
    private function publicTokens(array $puzzle): array
    {
        $out = [];
        foreach ((array)($puzzle['tokens'] ?? []) as $token) {
            if (is_string($token)) {
                $out[] = ['id' => '', 'text' => $token, 'markable' => false];
                continue;
            }
            $out[] = [
                'id'       => (string)($token['id'] ?? ''),
                'text'     => (string)($token['text'] ?? ''),
                'markable' => (string)($token['id'] ?? '') !== '',
                'break'    => (bool)($token['break'] ?? false),
            ];
        }
        return $out;
    }

    /** Protokollzeilen fuer den Typ "record" (filterbare Tabelle). */
    private function publicRecords(array $puzzle): array
    {
        $out = [];
        foreach ((array)($puzzle['records'] ?? []) as $record) {
            $out[] = [
                'id'     => (string)($record['id'] ?? ''),
                'cells'  => array_map('strval', (array)($record['cells'] ?? [])),
                'tags'   => array_map('strval', (array)($record['tags'] ?? [])),
            ];
        }
        return $out;
    }

    /** Eine Seite des Typs "link" (zwei Listen, die verbunden werden). */
    private function publicSide(array $puzzle, string $side): array
    {
        $out = [];
        foreach ((array)($puzzle[$side] ?? []) as $item) {
            $out[] = [
                'id'    => (string)($item['id'] ?? ''),
                'label' => (string)($item['label'] ?? ''),
                'note'  => (string)($item['note'] ?? ''),
            ];
        }
        return $out;
    }

    private function publicOptions(array $puzzle): array
    {
        $options = [];
        foreach ((array)($puzzle['options'] ?? []) as $option) {
            if (is_string($option)) {
                $options[] = ['id' => $option, 'label' => $option];
                continue;
            }
            $options[] = [
                'id'    => (string)($option['id'] ?? ''),
                'label' => (string)($option['label'] ?? ''),
                'note'  => (string)($option['note'] ?? ''),
            ];
        }
        return $options;
    }

    private function publicReportQuestions(array $case): array
    {
        $out = [];
        foreach ((array)($case['report']['questions'] ?? []) as $question) {
            $out[] = [
                'id'      => (string)($question['id'] ?? ''),
                'label'   => (string)($question['label'] ?? ''),
                'help'    => (string)($question['help'] ?? ''),
                'type'    => (string)($question['type'] ?? 'text'),
                'options' => $this->publicOptions($question),
                'required'=> (bool)($question['required'] ?? true),
                'max'     => (int)($question['max'] ?? 0),
            ];
        }
        return $out;
    }

    /* =========================================================
     |  Aktionen
     ========================================================= */

    /** @return array{ok:bool,message:string,state:array,horror:?array,unlocked:array,close?:bool} */
    public function solvePuzzle(string $caseId, string $userId, string $puzzleId, mixed $answer, bool $isAdmin = false): array
    {
        $case = $this->cases->get($caseId);
        $puzzle = $this->findPuzzle($case, $puzzleId);
        if ($puzzle === null) {
            throw HttpException::notFound('Unbekanntes Raetsel.');
        }

        $result = ['ok' => false, 'message' => '', 'horror' => null, 'unlocked' => [], 'close' => false];

        $progress = $this->progress->update($userId, $caseId, function (array $progress) use ($case, $puzzle, $answer, &$result, $isAdmin): array {
            $puzzleId = (string)$puzzle['id'];

            if (in_array($puzzleId, $progress['solved'] ?? [], true)) {
                $result = ['ok' => true, 'message' => 'Bereits geloest.', 'horror' => null, 'unlocked' => [], 'close' => false];
                return $progress;
            }

            $requirements = $this->puzzles->requirementsMet($puzzle, $progress);
            if (!$requirements['ok'] && !$isAdmin) {
                $result['message'] = (string)($puzzle['locked_message'] ?? 'Dafuer fehlen noch Informationen.');
                return $progress;
            }

            $maxAttempts = (int)($puzzle['max_attempts'] ?? 0);
            $tries = $this->puzzles->attemptsFor($progress, $puzzleId);
            if ($maxAttempts > 0 && $tries >= $maxAttempts && !$isAdmin) {
                $result['message'] = (string)($puzzle['on_fail']['blocked_message'] ?? 'Zu viele Fehlversuche. Das System hat gesperrt.');
                return $progress;
            }

            $check = $this->puzzles->check($puzzle, $answer);
            $progress = $this->puzzles->registerAttempt($progress, $puzzleId, $check['correct']);

            if (!$check['correct']) {
                $result['close'] = $check['close'];
                $result['message'] = $check['close']
                    ? (string)($puzzle['on_fail']['close_message'] ?? 'Knapp daneben. Pruefe Schreibweise und Details.')
                    : (string)($puzzle['on_fail']['message'] ?? 'Falsch. Das passt nicht zu den Fakten.');
                $horror = $this->horror->evaluate($case, $progress, 'fail', $puzzleId);
                $progress = $horror['progress'];
                $result['horror'] = $horror['event'];
                return $progress;
            }

            $applied = $this->puzzles->applySuccess($puzzle, $progress);
            $progress = $applied['progress'];
            $result['ok'] = true;
            $result['unlocked'] = $applied['unlocked'];
            $result['message'] = (string)($puzzle['on_success']['message'] ?? 'Richtig.');
            $result['explanation'] = (string)($puzzle['solution_explanation'] ?? '');

            $horrorId = (string)($puzzle['on_success']['horror'] ?? '');
            if ($horrorId !== '') {
                $forced = $this->horror->forceEvent($case, $horrorId);
                if ($forced !== null) {
                    $progress['horror_seen'][] = $horrorId;
                    $progress['horror_seen'] = array_values(array_unique($progress['horror_seen']));
                    $progress['horror_last'] = time();
                    $result['horror'] = $forced;
                }
            }
            if ($result['horror'] === null) {
                $horror = $this->horror->evaluate($case, $progress, 'solve', $puzzleId);
                $progress = $horror['progress'];
                $result['horror'] = $horror['event'];
            }
            return $progress;
        });

        Logger::debug('Raetselversuch', ['case' => $caseId, 'puzzle' => $puzzleId, 'ok' => $result['ok']]);
        $result['state'] = $this->buildState($case, $progress, $isAdmin);
        return $result;
    }

    /** Beweis aufnehmen (z. B. durch Anklicken eines Bilddetails). */
    public function collectEvidence(string $caseId, string $userId, string $evidenceId, bool $isAdmin = false): array
    {
        $case = $this->cases->get($caseId);
        $evidence = null;
        foreach ((array)($case['evidence'] ?? []) as $item) {
            if ((string)($item['id'] ?? '') === $evidenceId) {
                $evidence = $item;
                break;
            }
        }
        if ($evidence === null) {
            throw HttpException::notFound('Unbekannter Beweis.');
        }

        $already = false;
        $horrorEvent = null;
        $progress = $this->progress->update($userId, $caseId, function (array $progress) use ($case, $evidenceId, &$already, &$horrorEvent): array {
            if (in_array($evidenceId, $progress['evidence'] ?? [], true)) {
                $already = true;
                return $progress;
            }
            $progress['evidence'][] = $evidenceId;
            $horror = $this->horror->evaluate($case, $progress, 'evidence', $evidenceId);
            $horrorEvent = $horror['event'];
            return $horror['progress'];
        });

        return [
            'ok'       => true,
            'already'  => $already,
            'evidence' => [
                'id'      => $evidenceId,
                'title'   => (string)($evidence['title'] ?? ''),
                'summary' => (string)($evidence['summary'] ?? ''),
            ],
            'horror' => $horrorEvent,
            'state'  => $this->buildState($case, $progress, $isAdmin),
        ];
    }

    /** Inhalte einer App eines entsperrten Geraets liefern. */
    public function openApp(string $caseId, string $userId, string $deviceId, string $appId, bool $isAdmin = false): array
    {
        $case = $this->cases->get($caseId);
        $progress = $this->progress->get($userId, $caseId);

        $device = null;
        foreach ((array)($case['devices'] ?? []) as $item) {
            if ((string)($item['id'] ?? '') === $deviceId) {
                $device = $item;
                break;
            }
        }
        if ($device === null) {
            throw HttpException::notFound('Geraet nicht gefunden.');
        }
        $unlocked = in_array($deviceId, (array)($progress['devices'] ?? []), true) || !isset($device['lock']);
        if (!$unlocked && !$isAdmin) {
            throw HttpException::forbidden('Das Geraet ist gesperrt.');
        }

        $app = null;
        foreach ((array)($device['apps'] ?? []) as $item) {
            if ((string)($item['id'] ?? '') === $appId) {
                $app = $item;
                break;
            }
        }
        if ($app === null) {
            throw HttpException::notFound('App nicht gefunden.');
        }
        if (($app['requires_puzzle'] ?? '') !== '' && !in_array($app['requires_puzzle'], (array)($progress['solved'] ?? []), true) && !$isAdmin) {
            throw HttpException::forbidden((string)($app['locked_hint'] ?? 'Zugriff verweigert.'));
        }
        foreach ((array)($app['requires_flags'] ?? []) as $flag) {
            if (empty($progress['flags'][$flag]) && !$isAdmin) {
                throw HttpException::forbidden((string)($app['locked_hint'] ?? 'Zugriff verweigert.'));
            }
        }

        $content = $this->filterAppContent($app, $progress, $isAdmin);

        $horrorEvent = null;
        $progress = $this->progress->update($userId, $caseId, function (array $progress) use ($case, $deviceId, $appId, &$horrorEvent): array {
            $key = $deviceId . ':' . $appId;
            if (!in_array($key, $progress['discovered'] ?? [], true)) {
                $progress['discovered'][] = $key;
            }
            $horror = $this->horror->evaluate($case, $progress, 'open_app', $key);
            $horrorEvent = $horror['event'];
            return $horror['progress'];
        });

        return [
            'ok'      => true,
            'device'  => ['id' => $deviceId, 'name' => (string)($device['name'] ?? '')],
            'app'     => [
                'id'    => $appId,
                'type'  => (string)($app['type'] ?? 'files'),
                'label' => (string)($app['label'] ?? ''),
            ],
            'content' => $content,
            'horror'  => $horrorEvent,
        ];
    }

    /**
     * Entfernt Inhalte, die noch nicht freigeschaltet sind
     * (z. B. geloeschte Dateien vor der Wiederherstellung).
     */
    private function filterAppContent(array $app, array $progress, bool $isAdmin): array
    {
        $content = $app['content'] ?? [];
        $solved = (array)($progress['solved'] ?? []);
        $flags = (array)($progress['flags'] ?? []);

        $visible = function (array $item) use ($solved, $flags, $isAdmin): bool {
            if ($isAdmin) {
                return true;
            }
            if (($item['requires_puzzle'] ?? '') !== '' && !in_array($item['requires_puzzle'], $solved, true)) {
                return false;
            }
            foreach ((array)($item['requires_flags'] ?? []) as $flag) {
                if (empty($flags[$flag])) {
                    return false;
                }
            }
            return true;
        };

        $walk = function (array $items) use (&$walk, $visible): array {
            $out = [];
            foreach ($items as $key => $item) {
                if (is_array($item)) {
                    if (isset($item['requires_puzzle']) || isset($item['requires_flags'])) {
                        if (!$visible($item)) {
                            continue;
                        }
                        unset($item['requires_puzzle'], $item['requires_flags']);
                    }
                    $out[$key] = $walk($item);
                } else {
                    $out[$key] = $item;
                }
            }
            return array_is_list($items) ? array_values($out) : $out;
        };

        return $walk(is_array($content) ? $content : []);
    }

    /** Ein Medium (Foto, Audio, Video, Dokument) ausliefern, wenn freigeschaltet. */
    public function media(string $caseId, string $userId, string $mediaId, bool $isAdmin = false): array
    {
        $case = $this->cases->get($caseId);
        $progress = $this->progress->get($userId, $caseId);

        foreach (['photos', 'videos', 'audios', 'documents'] as $group) {
            foreach ((array)($case['media'][$group] ?? []) as $item) {
                if ((string)($item['id'] ?? '') !== $mediaId) {
                    continue;
                }
                if (!$isAdmin) {
                    foreach ((array)($item['requires_flags'] ?? []) as $flag) {
                        if (empty($progress['flags'][$flag])) {
                            throw HttpException::forbidden('Dieses Medium ist noch nicht freigegeben.');
                        }
                    }
                    if (($item['requires_puzzle'] ?? '') !== '' && !in_array($item['requires_puzzle'], (array)($progress['solved'] ?? []), true)) {
                        throw HttpException::forbidden('Dieses Medium ist noch nicht freigegeben.');
                    }
                }
                $clean = $item;
                unset($clean['requires_flags'], $clean['requires_puzzle'], $clean['solution'], $clean['secret']);
                foreach ($clean['hotspots'] ?? [] as $index => $hotspot) {
                    unset($clean['hotspots'][$index]['solution']);
                }
                $clean['group'] = $group;
                return $clean;
            }
        }
        throw HttpException::notFound('Medium nicht gefunden.');
    }

    /* ---------------- Notizen, Wand, Bericht ---------------- */

    public function addNote(string $caseId, string $userId, string $text, string $refType = '', string $refId = ''): array
    {
        $text = Validator::text($text, 1200);
        if ($text === '') {
            throw HttpException::badRequest('Die Notiz ist leer.');
        }
        $note = [
            'id'      => 'n_' . bin2hex(random_bytes(5)),
            'text'    => $text,
            'ref'     => ['type' => Validator::text($refType, 30), 'id' => Validator::text($refId, 64)],
            'created' => gmdate('c'),
        ];
        $this->progress->update($userId, $caseId, static function (array $progress) use ($note): array {
            $progress['notes'][] = $note;
            return $progress;
        });
        return $note;
    }

    public function deleteNote(string $caseId, string $userId, string $noteId): void
    {
        $this->progress->update($userId, $caseId, static function (array $progress) use ($noteId): array {
            $progress['notes'] = array_values(array_filter(
                (array)($progress['notes'] ?? []),
                static fn(array $note): bool => ($note['id'] ?? '') !== $noteId
            ));
            return $progress;
        });
    }

    public function saveBoard(string $caseId, string $userId, array $board): array
    {
        $clean = [
            'nodes' => [],
            'links' => [],
            'view'  => [
                'x'    => (float)($board['view']['x'] ?? 0),
                'y'    => (float)($board['view']['y'] ?? 0),
                'zoom' => max(0.4, min(2.5, (float)($board['view']['zoom'] ?? 1))),
            ],
        ];
        foreach (array_slice((array)($board['nodes'] ?? []), 0, 120) as $node) {
            $clean['nodes'][] = [
                'id'    => Validator::text((string)($node['id'] ?? ''), 64),
                'type'  => Validator::text((string)($node['type'] ?? 'note'), 24),
                'ref'   => Validator::text((string)($node['ref'] ?? ''), 64),
                'label' => Validator::text((string)($node['label'] ?? ''), 140),
                'text'  => Validator::text((string)($node['text'] ?? ''), 600),
                'x'     => (float)($node['x'] ?? 0),
                'y'     => (float)($node['y'] ?? 0),
                'color' => Validator::text((string)($node['color'] ?? 'default'), 24),
            ];
        }
        foreach (array_slice((array)($board['links'] ?? []), 0, 200) as $link) {
            $clean['links'][] = [
                'id'    => Validator::text((string)($link['id'] ?? ''), 64),
                'from'  => Validator::text((string)($link['from'] ?? ''), 64),
                'to'    => Validator::text((string)($link['to'] ?? ''), 64),
                'label' => Validator::text((string)($link['label'] ?? ''), 80),
            ];
        }

        $case = $this->cases->get($caseId);
        $unlockedFlags = [];
        $horrorEvent = null;

        $progress = $this->progress->update($userId, $caseId, function (array $progress) use ($clean, $case, &$unlockedFlags, &$horrorEvent): array {
            $progress['board'] = $clean;

            // Richtige Verbindungen koennen Fortschritt ausloesen - ohne Rueckmeldung,
            // ob eine einzelne Verbindung richtig oder falsch war.
            foreach ((array)($case['board_links'] ?? []) as $rule) {
                $a = (string)($rule['a'] ?? '');
                $b = (string)($rule['b'] ?? '');
                $flag = (string)($rule['flag'] ?? '');
                if ($flag === '' || !empty($progress['flags'][$flag])) {
                    continue;
                }
                foreach ($clean['links'] as $link) {
                    $from = $this->nodeRef($clean['nodes'], (string)$link['from']);
                    $to = $this->nodeRef($clean['nodes'], (string)$link['to']);
                    if (($from === $a && $to === $b) || ($from === $b && $to === $a)) {
                        $progress['flags'][$flag] = true;
                        $unlockedFlags[] = $flag;
                        foreach ((array)($rule['evidence'] ?? []) as $evidenceId) {
                            if (!in_array($evidenceId, $progress['evidence'] ?? [], true)) {
                                $progress['evidence'][] = (string)$evidenceId;
                            }
                        }
                    }
                }
            }
            if ($unlockedFlags !== []) {
                $horror = $this->horror->evaluate($case, $progress, 'board', $unlockedFlags[0]);
                $horrorEvent = $horror['event'];
                return $horror['progress'];
            }
            return $progress;
        });

        return [
            'ok'      => true,
            'flags'   => $unlockedFlags,
            'horror'  => $horrorEvent,
            'state'   => $unlockedFlags !== [] ? $this->buildState($case, $progress) : null,
        ];
    }

    private function nodeRef(array $nodes, string $nodeId): string
    {
        foreach ($nodes as $node) {
            if (($node['id'] ?? '') === $nodeId) {
                return (string)($node['ref'] !== '' ? $node['ref'] : $node['id']);
            }
        }
        return '';
    }

    public function saveReport(string $caseId, string $userId, array $answers): array
    {
        $clean = [];
        foreach ($answers as $key => $value) {
            $key = Validator::text((string)$key, 48);
            if (is_array($value)) {
                $clean[$key] = array_slice(array_map(static fn($v): string => Validator::text((string)$v, 120), $value), 0, 30);
            } else {
                $clean[$key] = Validator::text((string)$value, 2500);
            }
        }
        $this->progress->update($userId, $caseId, static function (array $progress) use ($clean): array {
            $progress['report'] = $clean;
            return $progress;
        });
        return ['ok' => true, 'answers' => $clean];
    }

    public function heartbeat(string $caseId, string $userId, int $seconds): void
    {
        $seconds = max(0, min(600, $seconds));
        $this->progress->update($userId, $caseId, static function (array $progress) use ($seconds): array {
            $progress['playtime'] = (int)($progress['playtime'] ?? 0) + $seconds;
            $progress['last_seen'] = time();
            return $progress;
        });
    }

    public function markIntroDone(string $caseId, string $userId): void
    {
        $this->progress->update($userId, $caseId, static function (array $progress): array {
            $progress['intro_done'] = true;
            return $progress;
        });
    }

    public function triggerHorror(string $caseId, string $userId, string $event, string $subject = ''): ?array
    {
        $case = $this->cases->get($caseId);
        $result = null;
        $this->progress->update($userId, $caseId, function (array $progress) use ($case, $event, $subject, &$result): array {
            $outcome = $this->horror->evaluate($case, $progress, $event, $subject);
            $result = $outcome['event'];
            return $outcome['progress'];
        });
        return $result;
    }

    public function findPuzzle(array $case, string $puzzleId): ?array
    {
        foreach ((array)($case['puzzles'] ?? []) as $puzzle) {
            if ((string)($puzzle['id'] ?? '') === $puzzleId) {
                return $puzzle;
            }
        }
        return null;
    }

    public function findNpc(array $case, string $npcId): ?array
    {
        foreach ((array)($case['npcs'] ?? []) as $npc) {
            if ((string)($npc['id'] ?? '') === $npcId) {
                return $npc;
            }
        }
        return null;
    }
}
