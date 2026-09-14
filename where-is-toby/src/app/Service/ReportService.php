<?php
declare(strict_types=1);

namespace App\Service;

/**
 * Auswertung des FBI-Abschlussberichts: Bewertung, Rang und Ende.
 */
final class ReportService
{
    /**
     * @return array{score:int,max:int,percent:int,rank:string,ending:array,breakdown:array,details:array}
     */
    public function evaluate(array $case, array $progress): array
    {
        $answers = (array)($progress['report'] ?? []);
        $correct = (array)($case['report']['correct'] ?? []);
        $weights = (array)($case['report']['weights'] ?? []);

        $breakdown = [];
        $score = 0;
        $max = 0;
        $questionResults = [];

        foreach ((array)($case['report']['questions'] ?? []) as $question) {
            $id = (string)($question['id'] ?? '');
            $weight = (int)($weights[$id] ?? (int)($question['weight'] ?? 10));
            $max += $weight;
            $given = $answers[$id] ?? '';
            $expected = $correct[$id] ?? null;
            $result = $this->scoreQuestion((string)($question['type'] ?? 'text'), $given, $expected, $question);
            $earned = (int)round($weight * $result['ratio']);
            $score += $earned;
            $questionResults[$id] = [
                'label'   => (string)($question['label'] ?? ''),
                'ratio'   => $result['ratio'],
                'points'  => $earned,
                'weight'  => $weight,
                'correct' => $result['ratio'] >= 0.999,
                'partial' => $result['ratio'] > 0 && $result['ratio'] < 0.999,
                'feedback'=> $result['feedback'],
            ];
        }

        /* Zusatzwertungen */
        $foundEvidence = (array)($progress['evidence'] ?? []);
        $allEvidence = array_map(static fn(array $e): string => (string)($e['id'] ?? ''), (array)($case['evidence'] ?? []));
        $keyEvidence = array_values(array_filter(
            (array)($case['evidence'] ?? []),
            static fn(array $e): bool => (string)($e['importance'] ?? '') === 'kern'
        ));
        $keyEvidenceIds = array_map(static fn(array $e): string => (string)$e['id'], $keyEvidence);
        $keyFound = count(array_intersect($keyEvidenceIds, $foundEvidence));

        $liesTotal = 0;
        $liesFound = 0;
        foreach ((array)($case['npcs'] ?? []) as $npc) {
            foreach ((array)($npc['lies'] ?? []) as $lie) {
                $liesTotal++;
                $flag = (string)($lie['solved_flag'] ?? '');
                if ($flag !== '' && !empty($progress['flags'][$flag])) {
                    $liesFound++;
                }
            }
        }

        $evidenceBonus = count($allEvidence) > 0
            ? (int)round(20 * (count(array_intersect($allEvidence, $foundEvidence)) / count($allEvidence)))
            : 0;
        $lieBonus = $liesTotal > 0 ? (int)round(15 * ($liesFound / $liesTotal)) : 0;
        $hintsUsed = (int)($progress['hints_used'] ?? 0);
        $hintPenalty = $hintsUsed * 5;
        $wrongAccusations = $this->countWrongAccusations($case, $answers);
        $accusationPenalty = $wrongAccusations * 10;

        $playtime = (int)($progress['playtime'] ?? 0);
        $timeTarget = (int)($case['scoring']['target_minutes'] ?? 25) * 60;
        $timeBonus = $playtime > 0 && $playtime <= $timeTarget ? 10 : ($playtime <= $timeTarget * 1.6 ? 5 : 0);

        $max += 45; // Bonuspunkte (Beweise 20, Luegen 15, Zeit 10)
        $score += $evidenceBonus + $lieBonus + $timeBonus - $hintPenalty - $accusationPenalty;
        $score = max(0, $score);
        $percent = $max > 0 ? (int)round(min(100, ($score / $max) * 100)) : 0;

        $breakdown = [
            ['label' => 'Theorie und Berichtsfragen', 'value' => array_sum(array_column($questionResults, 'points')), 'max' => $max - 45],
            ['label' => 'Gefundene Beweise (' . count(array_intersect($allEvidence, $foundEvidence)) . '/' . count($allEvidence) . ')', 'value' => $evidenceBonus, 'max' => 20],
            ['label' => 'Aufgedeckte Luegen (' . $liesFound . '/' . $liesTotal . ')', 'value' => $lieBonus, 'max' => 15],
            ['label' => 'Bearbeitungszeit', 'value' => $timeBonus, 'max' => 10],
            ['label' => 'Verwendete Hinweise (' . $hintsUsed . ')', 'value' => -$hintPenalty, 'max' => 0],
            ['label' => 'Falsche Anschuldigungen (' . $wrongAccusations . ')', 'value' => -$accusationPenalty, 'max' => 0],
        ];

        $culpritCorrect = ($questionResults[$this->culpritQuestionId($case)]['correct'] ?? false) === true;
        $locationCorrect = ($questionResults[$this->locationQuestionId($case)]['correct'] ?? false) === true;

        $ending = $this->pickEnding($case, [
            'percent'          => $percent,
            'culprit_correct'  => $culpritCorrect,
            'location_correct' => $locationCorrect,
            'key_evidence'     => $keyFound,
            'key_evidence_total' => count($keyEvidenceIds),
            'lies_found'       => $liesFound,
            'flags'            => (array)($progress['flags'] ?? []),
            'wrong_accusations'=> $wrongAccusations,
        ]);

        $rank = $this->rank($percent, $culpritCorrect, $locationCorrect);

        return [
            'score'    => $score,
            'max'      => $max,
            'percent'  => $percent,
            'rank'     => $rank,
            'ending'   => $ending,
            'breakdown'=> $breakdown,
            'details'  => [
                'questions'         => $questionResults,
                'evidence_found'    => count(array_intersect($allEvidence, $foundEvidence)),
                'evidence_total'    => count($allEvidence),
                'key_evidence'      => $keyFound,
                'key_evidence_total'=> count($keyEvidenceIds),
                'missed_evidence'   => $this->missedEvidence($case, $foundEvidence),
                'lies_found'        => $liesFound,
                'lies_total'        => $liesTotal,
                'missed_lies'       => $this->missedLies($case, $progress),
                'hints_used'        => $hintsUsed,
                'playtime'          => $playtime,
                'wrong_accusations' => $wrongAccusations,
                'culprit_correct'   => $culpritCorrect,
                'location_correct'  => $locationCorrect,
            ],
        ];
    }

    /** @return array{ratio:float,feedback:string} */
    private function scoreQuestion(string $type, mixed $given, mixed $expected, array $question): array
    {
        if ($expected === null) {
            return ['ratio' => trim((string)(is_array($given) ? implode(' ', $given) : $given)) !== '' ? 1.0 : 0.0, 'feedback' => ''];
        }

        if (in_array($type, ['choice', 'select'], true)) {
            $expectedList = array_map('strval', is_array($expected) ? $expected : [$expected]);
            $ok = in_array((string)$given, $expectedList, true);
            return ['ratio' => $ok ? 1.0 : 0.0, 'feedback' => $ok ? 'Richtig.' : 'Diese Einschaetzung passt nicht zu den Beweisen.'];
        }

        if (in_array($type, ['multi', 'evidence'], true)) {
            $expectedList = array_map('strval', (array)$expected);
            $givenList = array_map('strval', (array)$given);
            if ($expectedList === []) {
                return ['ratio' => 0.0, 'feedback' => ''];
            }
            $hits = count(array_intersect($expectedList, $givenList));
            $wrong = count(array_diff($givenList, $expectedList));
            $ratio = max(0.0, ($hits / count($expectedList)) - ($wrong * 0.12));
            return [
                'ratio' => min(1.0, $ratio),
                'feedback' => $hits . ' von ' . count($expectedList) . ' tragenden Punkten getroffen'
                    . ($wrong > 0 ? ', ' . $wrong . ' unpassend' : '') . '.',
            ];
        }

        if (in_array($type, ['sequence', 'timeline'], true)) {
            $expectedList = array_map('strval', (array)$expected);
            $givenList = array_map('strval', (array)$given);
            if ($expectedList === []) {
                return ['ratio' => 0.0, 'feedback' => ''];
            }
            $correctPositions = 0;
            foreach ($expectedList as $index => $value) {
                if (($givenList[$index] ?? null) === $value) {
                    $correctPositions++;
                }
            }
            $ratio = $correctPositions / count($expectedList);
            return ['ratio' => $ratio, 'feedback' => $correctPositions . ' von ' . count($expectedList) . ' Ereignissen an der richtigen Stelle.'];
        }

        // Freitext: Bewertung ueber Schluesselbegriffe
        $text = mb_strtolower((string)(is_array($given) ? implode(' ', $given) : $given));
        $keywords = array_map('mb_strtolower', array_map('strval', (array)($question['keywords'] ?? (array)$expected)));
        if ($keywords === []) {
            return ['ratio' => trim($text) !== '' ? 1.0 : 0.0, 'feedback' => ''];
        }
        $hits = 0;
        foreach ($keywords as $keyword) {
            if ($keyword !== '' && str_contains($text, $keyword)) {
                $hits++;
            }
        }
        $needed = max(1, (int)ceil(count($keywords) * 0.6));
        $ratio = min(1.0, $hits / $needed);
        return [
            'ratio' => $ratio,
            'feedback' => $hits . ' von ' . count($keywords) . ' erwarteten Kernaussagen erkannt.',
        ];
    }

    private function countWrongAccusations(array $case, array $answers): int
    {
        $count = 0;
        $culpritId = $this->culpritQuestionId($case);
        $expected = (array)($case['report']['correct'][$culpritId] ?? []);
        $expected = array_map('strval', is_array($expected) ? $expected : [$expected]);
        $given = (string)($answers[$culpritId] ?? '');
        if ($given !== '' && !in_array($given, $expected, true)) {
            $count++;
        }
        // Zusaetzlich: als Luegner markierte Personen, die nicht gelogen haben
        $liarsQuestion = (string)($case['report']['liars_question'] ?? 'q_liars');
        $expectedLiars = array_map('strval', (array)($case['report']['correct'][$liarsQuestion] ?? []));
        foreach ((array)($answers[$liarsQuestion] ?? []) as $liar) {
            if (!in_array((string)$liar, $expectedLiars, true)) {
                $count++;
            }
        }
        return $count;
    }

    private function culpritQuestionId(array $case): string
    {
        return (string)($case['report']['culprit_question'] ?? 'q_culprit');
    }

    private function locationQuestionId(array $case): string
    {
        return (string)($case['report']['location_question'] ?? 'q_location');
    }

    private function missedEvidence(array $case, array $found): array
    {
        $missed = [];
        foreach ((array)($case['evidence'] ?? []) as $evidence) {
            $id = (string)($evidence['id'] ?? '');
            if (!in_array($id, $found, true)) {
                $missed[] = [
                    'code'  => (string)($evidence['code'] ?? $id),
                    'title' => (string)($evidence['title'] ?? ''),
                    'where' => (string)($evidence['found_hint'] ?? ''),
                    'key'   => (string)($evidence['importance'] ?? '') === 'kern',
                ];
            }
        }
        return $missed;
    }

    private function missedLies(array $case, array $progress): array
    {
        $missed = [];
        foreach ((array)($case['npcs'] ?? []) as $npc) {
            foreach ((array)($npc['lies'] ?? []) as $lie) {
                $flag = (string)($lie['solved_flag'] ?? '');
                if ($flag !== '' && empty($progress['flags'][$flag])) {
                    $missed[] = [
                        'npc'   => (string)($npc['name'] ?? ''),
                        'claim' => (string)($lie['claim'] ?? ''),
                        'truth' => (string)($lie['truth'] ?? ''),
                    ];
                }
            }
        }
        return $missed;
    }

    private function pickEnding(array $case, array $context): array
    {
        foreach ((array)($case['endings'] ?? []) as $ending) {
            $conditions = (array)($ending['conditions'] ?? []);
            $matches = true;
            foreach ($conditions as $key => $value) {
                $matches = $matches && match ($key) {
                    'min_percent'       => $context['percent'] >= (int)$value,
                    'max_percent'       => $context['percent'] <= (int)$value,
                    'culprit_correct'   => $context['culprit_correct'] === (bool)$value,
                    'location_correct'  => $context['location_correct'] === (bool)$value,
                    'min_key_evidence'  => $context['key_evidence'] >= (int)$value,
                    'min_lies'          => $context['lies_found'] >= (int)$value,
                    'max_wrong'         => $context['wrong_accusations'] <= (int)$value,
                    'requires_flag'     => !empty($context['flags'][(string)$value]),
                    default             => true,
                };
                if (!$matches) {
                    break;
                }
            }
            if ($matches) {
                return [
                    'id'      => (string)($ending['id'] ?? 'ending'),
                    'title'   => (string)($ending['title'] ?? ''),
                    'text'    => (string)($ending['text'] ?? ''),
                    'tone'    => (string)($ending['tone'] ?? 'neutral'),
                    'epilogue'=> (string)($ending['epilogue'] ?? ''),
                    'image'   => (string)($ending['image'] ?? ''),
                ];
            }
        }
        return [
            'id'    => 'ending_open',
            'title' => 'Fall bleibt offen',
            'text'  => 'Der Bericht reicht nicht aus. Die Akte wandert ungeloest ins Archiv.',
            'tone'  => 'bad',
            'epilogue' => '',
            'image' => '',
        ];
    }

    private function rank(int $percent, bool $culpritCorrect, bool $locationCorrect): string
    {
        if (!$culpritCorrect) {
            return $percent >= 55 ? 'D' : 'F';
        }
        if ($percent >= 92 && $locationCorrect) {
            return 'S';
        }
        if ($percent >= 80 && $locationCorrect) {
            return 'A';
        }
        if ($percent >= 65) {
            return 'B';
        }
        return 'C';
    }
}
