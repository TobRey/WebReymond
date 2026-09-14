<?php
declare(strict_types=1);

namespace App\Service;

use App\Core\Logger;

/**
 * Wiederverwendbare Raetsel-Engine.
 *
 * Unterstuetzte Typen:
 *  text, password, pin, number, pattern, choice, multi, sequence, pairs,
 *  locate (Kartenklick), hotspot (Bildpunkt), timecode (Videozeitpunkt),
 *  contradiction (zwei Beweise), timeline (Reihenfolge), search (Datei/Ordner)
 */
final class PuzzleEngine
{
    /**
     * Prueft, ob alle Voraussetzungen eines Raetsels erfuellt sind.
     *
     * @return array{ok:bool,missing:array<string,string[]>}
     */
    public function requirementsMet(array $puzzle, array $progress): array
    {
        $requires = $puzzle['requires'] ?? [];
        $missing = ['puzzles' => [], 'evidence' => [], 'flags' => [], 'devices' => []];

        foreach ((array)($requires['puzzles'] ?? []) as $needed) {
            if (!in_array($needed, $progress['solved'] ?? [], true)) {
                $missing['puzzles'][] = (string)$needed;
            }
        }
        foreach ((array)($requires['evidence'] ?? []) as $needed) {
            if (!in_array($needed, $progress['evidence'] ?? [], true)) {
                $missing['evidence'][] = (string)$needed;
            }
        }
        foreach ((array)($requires['flags'] ?? []) as $needed) {
            if (empty($progress['flags'][$needed])) {
                $missing['flags'][] = (string)$needed;
            }
        }
        foreach ((array)($requires['devices'] ?? []) as $needed) {
            if (!in_array($needed, $progress['devices'] ?? [], true)) {
                $missing['devices'][] = (string)$needed;
            }
        }

        $hasMissing = $missing['puzzles'] || $missing['evidence'] || $missing['flags'] || $missing['devices'];
        return ['ok' => !$hasMissing, 'missing' => $missing];
    }

    /**
     * Prueft eine Antwort.
     *
     * @param mixed $answer Freitext, Zahl, Liste oder Koordinaten
     * @return array{correct:bool,close:bool,normalized:string}
     */
    public function check(array $puzzle, mixed $answer): array
    {
        $type = (string)($puzzle['type'] ?? 'text');
        $caseSensitive = (bool)($puzzle['case_sensitive'] ?? false);

        return match ($type) {
            'locate'       => $this->checkLocate($puzzle, $answer),
            'timecode'     => $this->checkTimecode($puzzle, $answer),
            'multi', 'contradiction', 'pairs' => $this->checkSet($puzzle, $answer),
            'sequence', 'timeline' => $this->checkSequence($puzzle, $answer),
            default        => $this->checkScalar($puzzle, $answer, $caseSensitive, $type),
        };
    }

    private function checkScalar(array $puzzle, mixed $answer, bool $caseSensitive, string $type): array
    {
        $given = $this->normalizeScalar(is_array($answer) ? implode('', $answer) : (string)$answer, $type, $caseSensitive);
        $accepted = array_merge(
            (array)($puzzle['solutions'] ?? []),
            (array)($puzzle['alternatives'] ?? [])
        );
        foreach ($accepted as $solution) {
            $normalized = $this->normalizeScalar((string)$solution, $type, $caseSensitive);
            if ($normalized !== '' && $normalized === $given) {
                return ['correct' => true, 'close' => false, 'normalized' => $given];
            }
        }
        // "Fast richtig" fuer hilfreiche Rueckmeldung (nur bei Text/Passwort)
        $close = false;
        if ($given !== '' && in_array($type, ['text', 'password'], true)) {
            foreach ($accepted as $solution) {
                $normalized = $this->normalizeScalar((string)$solution, $type, $caseSensitive);
                if ($normalized === '') {
                    continue;
                }
                $distance = levenshtein(mb_substr($normalized, 0, 60), mb_substr($given, 0, 60));
                if ($distance > 0 && $distance <= max(1, (int)floor(mb_strlen($normalized) * 0.2))) {
                    $close = true;
                    break;
                }
            }
        }
        return ['correct' => false, 'close' => $close, 'normalized' => $given];
    }

    private function normalizeScalar(string $value, string $type, bool $caseSensitive): string
    {
        $value = trim($value);
        if ($type === 'pin' || $type === 'number' || $type === 'pattern') {
            return preg_replace('~\D~', '', $value) ?? '';
        }
        $value = preg_replace('~\s+~u', ' ', $value) ?? '';
        if (!$caseSensitive) {
            $value = mb_strtolower($value);
            $value = strtr($value, [
                'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
                'á' => 'a', 'à' => 'a', 'é' => 'e', 'è' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            ]);
        }
        return $value;
    }

    private function checkLocate(array $puzzle, mixed $answer): array
    {
        if (!is_array($answer)) {
            return ['correct' => false, 'close' => false, 'normalized' => ''];
        }
        // Variante A: Auswahl einer Orts-ID
        if (isset($answer['location'])) {
            $given = (string)$answer['location'];
            $solutions = array_map('strval', (array)($puzzle['solutions'] ?? []));
            return [
                'correct'    => in_array($given, $solutions, true),
                'close'      => false,
                'normalized' => $given,
            ];
        }
        // Variante B: Koordinaten auf der Karte (relative 0..1 Werte)
        $x = (float)($answer['x'] ?? -1);
        $y = (float)($answer['y'] ?? -1);
        $target = $puzzle['target'] ?? null;
        if (!is_array($target)) {
            return ['correct' => false, 'close' => false, 'normalized' => sprintf('%.3f/%.3f', $x, $y)];
        }
        $radius = (float)($target['r'] ?? 0.06);
        $distance = sqrt((($x - (float)($target['x'] ?? 0)) ** 2) + (($y - (float)($target['y'] ?? 0)) ** 2));
        return [
            'correct'    => $distance <= $radius,
            'close'      => $distance > $radius && $distance <= $radius * 2.2,
            'normalized' => sprintf('%.3f/%.3f', $x, $y),
        ];
    }

    private function checkTimecode(array $puzzle, mixed $answer): array
    {
        $seconds = $this->toSeconds($answer);
        $tolerance = (float)($puzzle['tolerance'] ?? 4);
        foreach ((array)($puzzle['solutions'] ?? []) as $solution) {
            $target = $this->toSeconds($solution);
            if (abs($seconds - $target) <= $tolerance) {
                return ['correct' => true, 'close' => false, 'normalized' => (string)$seconds];
            }
            if (abs($seconds - $target) <= $tolerance * 3) {
                return ['correct' => false, 'close' => true, 'normalized' => (string)$seconds];
            }
        }
        return ['correct' => false, 'close' => false, 'normalized' => (string)$seconds];
    }

    private function toSeconds(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float)$value;
        }
        $text = trim((string)$value);
        if (preg_match('~^(\d{1,2}):(\d{2})(?::(\d{2}))?$~', $text, $m)) {
            return isset($m[3]) && $m[3] !== ''
                ? ((int)$m[1] * 3600 + (int)$m[2] * 60 + (int)$m[3])
                : ((int)$m[1] * 60 + (int)$m[2]);
        }
        return (float)preg_replace('~\D~', '', $text);
    }

    /** Mengenvergleich (Reihenfolge egal) */
    private function checkSet(array $puzzle, mixed $answer): array
    {
        $given = array_values(array_unique(array_map(
            static fn($v): string => mb_strtolower(trim((string)$v)),
            is_array($answer) ? $answer : [$answer]
        )));
        sort($given);

        $solutionSets = $this->solutionSets($puzzle);
        foreach ($solutionSets as $set) {
            $expected = array_values(array_unique(array_map(
                static fn($v): string => mb_strtolower(trim((string)$v)),
                $set
            )));
            sort($expected);
            if ($expected === $given) {
                return ['correct' => true, 'close' => false, 'normalized' => implode(',', $given)];
            }
            $overlap = count(array_intersect($expected, $given));
            if ($overlap > 0 && $overlap >= count($expected) - 1 && count($given) <= count($expected) + 1) {
                return ['correct' => false, 'close' => true, 'normalized' => implode(',', $given)];
            }
        }
        return ['correct' => false, 'close' => false, 'normalized' => implode(',', $given)];
    }

    /** Reihenfolgevergleich (Reihenfolge entscheidend) */
    private function checkSequence(array $puzzle, mixed $answer): array
    {
        $given = array_map(static fn($v): string => mb_strtolower(trim((string)$v)), is_array($answer) ? $answer : [$answer]);
        foreach ($this->solutionSets($puzzle) as $set) {
            $expected = array_map(static fn($v): string => mb_strtolower(trim((string)$v)), $set);
            if ($expected === $given) {
                return ['correct' => true, 'close' => false, 'normalized' => implode('>', $given)];
            }
            if (count($expected) === count($given)) {
                $correctPositions = 0;
                foreach ($expected as $index => $value) {
                    if (($given[$index] ?? null) === $value) {
                        $correctPositions++;
                    }
                }
                if ($correctPositions >= count($expected) - 2 && $correctPositions < count($expected)) {
                    return ['correct' => false, 'close' => true, 'normalized' => implode('>', $given)];
                }
            }
        }
        return ['correct' => false, 'close' => false, 'normalized' => implode('>', $given)];
    }

    /** @return array<int,array<int,string>> */
    private function solutionSets(array $puzzle): array
    {
        $sets = [];
        $solutions = $puzzle['solutions'] ?? [];
        if ($solutions !== [] && is_array($solutions)) {
            $sets[] = is_array($solutions[0] ?? null) ? null : $solutions;
            if ($sets[0] === null) {
                $sets = [];
                foreach ($solutions as $set) {
                    if (is_array($set)) {
                        $sets[] = $set;
                    }
                }
            }
        }
        foreach ((array)($puzzle['alternatives'] ?? []) as $alternative) {
            if (is_array($alternative)) {
                $sets[] = $alternative;
            }
        }
        return array_values(array_filter($sets, static fn($set): bool => is_array($set) && $set !== []));
    }

    /**
     * Wendet die Erfolgswirkung eines Raetsels auf den Fortschritt an.
     *
     * @return array{progress:array,unlocked:array{evidence:string[],devices:string[],apps:string[],flags:string[],locations:string[]}}
     */
    public function applySuccess(array $puzzle, array $progress): array
    {
        $effect = $puzzle['on_success'] ?? [];
        $unlocked = ['evidence' => [], 'devices' => [], 'apps' => [], 'flags' => [], 'locations' => []];

        $puzzleId = (string)$puzzle['id'];
        if (!in_array($puzzleId, $progress['solved'] ?? [], true)) {
            $progress['solved'][] = $puzzleId;
        }

        foreach ((array)($effect['evidence'] ?? []) as $evidenceId) {
            if (!in_array($evidenceId, $progress['evidence'] ?? [], true)) {
                $progress['evidence'][] = (string)$evidenceId;
                $unlocked['evidence'][] = (string)$evidenceId;
            }
        }
        foreach ((array)($effect['devices'] ?? []) as $deviceId) {
            if (!in_array($deviceId, $progress['devices'] ?? [], true)) {
                $progress['devices'][] = (string)$deviceId;
                $unlocked['devices'][] = (string)$deviceId;
            }
        }
        foreach ((array)($effect['apps'] ?? []) as $appKey) {
            if (!in_array($appKey, $progress['apps'] ?? [], true)) {
                $progress['apps'][] = (string)$appKey;
                $unlocked['apps'][] = (string)$appKey;
            }
        }
        foreach ((array)($effect['flags'] ?? []) as $flag) {
            if (empty($progress['flags'][$flag])) {
                $progress['flags'][(string)$flag] = true;
                $unlocked['flags'][] = (string)$flag;
            }
        }
        foreach ((array)($effect['locations'] ?? []) as $locationId) {
            if (!in_array($locationId, $progress['visited'] ?? [], true)) {
                $progress['visited'][] = (string)$locationId;
                $unlocked['locations'][] = (string)$locationId;
            }
        }
        foreach ((array)($effect['npc'] ?? []) as $npcId => $changes) {
            $state = $progress['npc'][$npcId] ?? [];
            $state['trust'] = max(0, min(100, (int)($state['trust'] ?? 50) + (int)($changes['trust'] ?? 0)));
            $state['stress'] = max(0, min(100, (int)($state['stress'] ?? 10) + (int)($changes['stress'] ?? 0)));
            foreach ((array)($changes['unlock'] ?? []) as $topic) {
                $state['unlocked'][] = (string)$topic;
                $state['unlocked'] = array_values(array_unique($state['unlocked']));
            }
            $progress['npc'][$npcId] = $state;
        }

        $progress['score_events'][] = [
            'puzzle' => $puzzleId,
            'score'  => (int)($effect['score'] ?? 10),
            'ts'     => time(),
        ];

        Logger::debug('Raetsel geloest', ['puzzle' => $puzzleId]);
        return ['progress' => $progress, 'unlocked' => $unlocked];
    }

    public function registerAttempt(array $progress, string $puzzleId, bool $correct): array
    {
        $attempts = $progress['attempts'][$puzzleId] ?? ['tries' => 0, 'wrong' => 0];
        $attempts['tries'] = (int)$attempts['tries'] + 1;
        if (!$correct) {
            $attempts['wrong'] = (int)$attempts['wrong'] + 1;
        }
        $attempts['last'] = time();
        $progress['attempts'][$puzzleId] = $attempts;
        return $progress;
    }

    public function attemptsFor(array $progress, string $puzzleId): int
    {
        return (int)($progress['attempts'][$puzzleId]['tries'] ?? 0);
    }
}
