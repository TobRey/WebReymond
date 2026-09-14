<?php
declare(strict_types=1);

namespace App\Service;

/**
 * Automatische Konsistenzpruefung eines Falls.
 *
 * Prueft unter anderem auf: doppelte IDs, unloesbare Raetsel, nie erreichbare
 * Beweise, fehlende Medien, widerspruechliche Zeitangaben, unerreichbare
 * Story-Zustaende und fehlende Hinweisstufen.
 */
final class CaseValidator
{
    /** @return array{ok:bool,errors:array<int,array{level:string,area:string,message:string}>,stats:array<string,int>} */
    public function validate(array $case, bool $checkFiles = true): array
    {
        $issues = [];
        $add = static function (string $level, string $area, string $message) use (&$issues): void {
            $issues[] = ['level' => $level, 'area' => $area, 'message' => $message];
        };

        $puzzleIds = $this->ids($case['puzzles'] ?? []);
        $evidenceIds = $this->ids($case['evidence'] ?? []);
        $npcIds = $this->ids($case['npcs'] ?? []);
        $deviceIds = $this->ids($case['devices'] ?? []);
        $locationIds = $this->ids($case['locations'] ?? []);
        $mediaIds = [];
        foreach (['photos', 'videos', 'audios', 'documents'] as $group) {
            foreach ((array)($case['media'][$group] ?? []) as $item) {
                $mediaIds[] = (string)($item['id'] ?? '');
            }
        }

        /* Pflichtfelder */
        foreach (['id', 'title', 'summary'] as $field) {
            if (trim((string)($case[$field] ?? '')) === '') {
                $add('error', 'Stammdaten', 'Pflichtfeld fehlt: ' . $field);
            }
        }
        if (($case['missing_person']['name'] ?? '') === '') {
            $add('error', 'Stammdaten', 'Die vermisste Person braucht einen Namen.');
        }

        /* Doppelte IDs */
        foreach ([
            'Raetsel' => $puzzleIds, 'Beweise' => $evidenceIds, 'NPCs' => $npcIds,
            'Geraete' => $deviceIds, 'Orte' => $locationIds, 'Medien' => $mediaIds,
        ] as $label => $ids) {
            $duplicates = array_keys(array_filter(array_count_values(array_filter($ids)), static fn(int $count): bool => $count > 1));
            foreach ($duplicates as $duplicate) {
                $add('error', $label, 'Doppelte ID: ' . $duplicate);
            }
            foreach ($ids as $id) {
                if ($id === '') {
                    $add('error', $label, 'Ein Eintrag hat keine ID.');
                }
            }
        }

        /* Flags, die irgendwo gesetzt werden */
        $settableFlags = $this->collectSettableFlags($case);

        /* Raetsel */
        foreach ((array)($case['puzzles'] ?? []) as $puzzle) {
            $id = (string)($puzzle['id'] ?? '?');
            $type = (string)($puzzle['type'] ?? 'text');
            $needsSolution = !in_array($type, ['locate'], true) || !isset($puzzle['target']);
            if ($needsSolution && (array)($puzzle['solutions'] ?? []) === []) {
                $add('error', 'Raetsel', $id . ': keine Loesung hinterlegt - das Raetsel waere unloesbar.');
            }
            $hints = array_values(array_filter((array)($puzzle['hints'] ?? []), static fn($h): bool => trim((string)$h) !== ''));
            if (count($hints) < 3) {
                $add('warn', 'Raetsel', $id . ': weniger als drei Hinweisstufen hinterlegt (' . count($hints) . ').');
            }
            foreach ((array)($puzzle['requires']['puzzles'] ?? []) as $required) {
                if (!in_array((string)$required, $puzzleIds, true)) {
                    $add('error', 'Raetsel', $id . ': Voraussetzung verweist auf unbekanntes Raetsel ' . $required . '.');
                }
            }
            foreach ((array)($puzzle['requires']['evidence'] ?? []) as $required) {
                if (!in_array((string)$required, $evidenceIds, true)) {
                    $add('error', 'Raetsel', $id . ': Voraussetzung verweist auf unbekannten Beweis ' . $required . '.');
                }
            }
            foreach ((array)($puzzle['requires']['flags'] ?? []) as $required) {
                if (!in_array((string)$required, $settableFlags, true)) {
                    $add('error', 'Raetsel', $id . ': benoetigt Zustand "' . $required . '", der nirgends gesetzt wird - unerreichbar.');
                }
            }
            foreach ((array)($puzzle['on_success']['evidence'] ?? []) as $unlocked) {
                if (!in_array((string)$unlocked, $evidenceIds, true)) {
                    $add('error', 'Raetsel', $id . ': schaltet unbekannten Beweis ' . $unlocked . ' frei.');
                }
            }
            foreach ((array)($puzzle['on_success']['devices'] ?? []) as $device) {
                if (!in_array((string)$device, $deviceIds, true)) {
                    $add('error', 'Raetsel', $id . ': schaltet unbekanntes Geraet ' . $device . ' frei.');
                }
            }
            if ($this->hasCircularDependency($case, $id)) {
                $add('error', 'Raetsel', $id . ': zirkulaere Abhaengigkeit erkannt.');
            }
        }

        /* Beweise: irgendwo erreichbar? */
        $reachableEvidence = $this->collectReachableEvidence($case);
        foreach ($evidenceIds as $evidenceId) {
            if ($evidenceId !== '' && !in_array($evidenceId, $reachableEvidence, true)) {
                $add('warn', 'Beweise', $evidenceId . ': wird von keinem Raetsel, Gespraech, Bildpunkt oder Ereignis freigeschaltet.');
            }
        }

        /* Geraete */
        foreach ((array)($case['devices'] ?? []) as $device) {
            $id = (string)($device['id'] ?? '?');
            $lockPuzzle = (string)($device['lock']['puzzle'] ?? '');
            if (isset($device['lock']) && $lockPuzzle === '') {
                $add('error', 'Geraete', $id . ': Sperre ohne zugehoeriges Raetsel.');
            } elseif ($lockPuzzle !== '' && !in_array($lockPuzzle, $puzzleIds, true)) {
                $add('error', 'Geraete', $id . ': Sperre verweist auf unbekanntes Raetsel ' . $lockPuzzle . '.');
            }
            foreach ((array)($device['apps'] ?? []) as $app) {
                $requiresPuzzle = (string)($app['requires_puzzle'] ?? '');
                if ($requiresPuzzle !== '' && !in_array($requiresPuzzle, $puzzleIds, true)) {
                    $add('error', 'Geraete', $id . '/' . (string)($app['id'] ?? '?') . ': verweist auf unbekanntes Raetsel ' . $requiresPuzzle . '.');
                }
            }
        }

        /* NPCs */
        foreach ((array)($case['npcs'] ?? []) as $npc) {
            $id = (string)($npc['id'] ?? '?');
            if ((array)($npc['offline']['rules'] ?? []) === []) {
                $add('warn', 'NPCs', $id . ': keine Offline-Dialogregeln - im Offline-Modus antwortet die Figur nur allgemein.');
            }
            if (($npc['alibi_claimed'] ?? '') === '') {
                $add('warn', 'NPCs', $id . ': kein behauptetes Alibi hinterlegt.');
            }
            foreach ((array)($npc['can_unlock_evidence'] ?? []) as $evidenceId) {
                if (!in_array((string)$evidenceId, $evidenceIds, true)) {
                    $add('error', 'NPCs', $id . ': darf unbekannten Beweis ' . $evidenceId . ' freischalten.');
                }
            }
            foreach ((array)($npc['lies'] ?? []) as $lie) {
                $flag = (string)($lie['solved_flag'] ?? '');
                if ($flag !== '' && !in_array($flag, $settableFlags, true)) {
                    $add('warn', 'NPCs', $id . ': Luege "' . mb_substr((string)($lie['claim'] ?? ''), 0, 40) . '" kann nie als aufgedeckt markiert werden.');
                }
            }
        }

        /* Medien */
        foreach (['photos', 'videos', 'audios', 'documents'] as $group) {
            foreach ((array)($case['media'][$group] ?? []) as $item) {
                $id = (string)($item['id'] ?? '?');
                $sources = [];
                if (isset($item['src'])) {
                    $sources[] = (string)$item['src'];
                }
                foreach ((array)($item['frames'] ?? []) as $frame) {
                    if (isset($frame['src'])) {
                        $sources[] = (string)$frame['src'];
                    }
                }
                if ($checkFiles) {
                    foreach ($sources as $source) {
                        if ($source === '' || str_starts_with($source, 'http') || str_starts_with($source, 'data:')) {
                            continue;
                        }
                        $path = str_starts_with($source, 'uploads/')
                            ? WIT_UPLOADS . '/' . substr($source, 8)
                            : WIT_ROOT . '/' . ltrim($source, '/');
                        if (!is_file($path)) {
                            $add('error', 'Medien', $id . ': Datei fehlt (' . $source . ').');
                        }
                    }
                }
            }
        }

        /* Zeitachse */
        $lastMinutes = -1;
        foreach ((array)($case['timeline_truth'] ?? []) as $entry) {
            $time = (string)($entry['time'] ?? '');
            if ($time === '') {
                continue;
            }
            if (!preg_match('~^\d{1,2}:\d{2}$~', $time)) {
                $add('warn', 'Zeitachse', 'Ungueltige Zeitangabe: ' . $time);
                continue;
            }
            [$hours, $minutes] = array_map('intval', explode(':', $time));
            $absolute = ((int)($entry['day_offset'] ?? 0) * 1440) + $hours * 60 + $minutes;
            if ($absolute < $lastMinutes) {
                $add('warn', 'Zeitachse', 'Eintrag "' . mb_substr((string)($entry['event'] ?? ''), 0, 40) . '" (' . $time . ') steht nach einem spaeteren Zeitpunkt - Reihenfolge pruefen.');
            }
            $lastMinutes = $absolute;
        }

        /* Bericht */
        $questionIds = [];
        foreach ((array)($case['report']['questions'] ?? []) as $question) {
            $questionIds[] = (string)($question['id'] ?? '');
            foreach ((array)($question['options'] ?? []) as $option) {
                $optionId = is_array($option) ? (string)($option['id'] ?? '') : (string)$option;
                if ($optionId === '') {
                    $add('warn', 'Bericht', 'Antwortoption ohne ID bei Frage ' . (string)($question['id'] ?? '?') . '.');
                }
            }
        }
        foreach (array_keys((array)($case['report']['correct'] ?? [])) as $questionId) {
            if (!in_array((string)$questionId, $questionIds, true)) {
                $add('error', 'Bericht', 'Musterloesung fuer unbekannte Frage: ' . $questionId . '.');
            }
        }
        if ((array)($case['endings'] ?? []) === []) {
            $add('error', 'Enden', 'Es ist kein Ende definiert.');
        }

        /* Horror */
        foreach ((array)($case['horror_events'] ?? []) as $horror) {
            foreach ((array)($horror['adds_evidence'] ?? []) as $evidenceId) {
                if (!in_array((string)$evidenceId, $evidenceIds, true)) {
                    $add('error', 'Horror', (string)($horror['id'] ?? '?') . ': unbekannter Beweis ' . $evidenceId . '.');
                }
            }
        }

        $errors = array_values(array_filter($issues, static fn(array $i): bool => $i['level'] === 'error'));
        return [
            'ok'     => $errors === [],
            'errors' => $issues,
            'stats'  => [
                'puzzles'  => count($puzzleIds),
                'evidence' => count($evidenceIds),
                'npcs'     => count($npcIds),
                'devices'  => count($deviceIds),
                'media'    => count($mediaIds),
                'errors'   => count($errors),
                'warnings' => count($issues) - count($errors),
            ],
        ];
    }

    /** @return string[] */
    private function ids(array $items): array
    {
        return array_map(static fn($item): string => (string)($item['id'] ?? ''), $items);
    }

    /** @return string[] */
    private function collectSettableFlags(array $case): array
    {
        $flags = [];
        foreach ((array)($case['puzzles'] ?? []) as $puzzle) {
            foreach ((array)($puzzle['on_success']['flags'] ?? []) as $flag) {
                $flags[] = (string)$flag;
            }
        }
        foreach ((array)($case['npcs'] ?? []) as $npc) {
            foreach ((array)($npc['can_set_flags'] ?? []) as $flag) {
                $flags[] = (string)$flag;
            }
            foreach ((array)($npc['offline']['rules'] ?? []) as $rule) {
                foreach ((array)($rule['effects']['flags'] ?? []) as $flag) {
                    $flags[] = (string)$flag;
                }
            }
            foreach ((array)($npc['offline']['confront'] ?? []) as $confront) {
                foreach ((array)($confront['effects']['flags'] ?? []) as $flag) {
                    $flags[] = (string)$flag;
                }
            }
        }
        foreach ((array)($case['horror_events'] ?? []) as $horror) {
            foreach ((array)($horror['sets_flags'] ?? []) as $flag) {
                $flags[] = (string)$flag;
            }
        }
        foreach ((array)($case['board_links'] ?? []) as $rule) {
            if (($rule['flag'] ?? '') !== '') {
                $flags[] = (string)$rule['flag'];
            }
        }
        foreach ((array)($case['start']['flags'] ?? []) as $flag) {
            $flags[] = (string)$flag;
        }
        return array_values(array_unique($flags));
    }

    /** @return string[] */
    private function collectReachableEvidence(array $case): array
    {
        $ids = [];
        foreach ((array)($case['start']['evidence'] ?? []) as $evidenceId) {
            $ids[] = (string)$evidenceId;
        }
        foreach ((array)($case['puzzles'] ?? []) as $puzzle) {
            foreach ((array)($puzzle['on_success']['evidence'] ?? []) as $evidenceId) {
                $ids[] = (string)$evidenceId;
            }
        }
        foreach ((array)($case['npcs'] ?? []) as $npc) {
            foreach ((array)($npc['can_unlock_evidence'] ?? []) as $evidenceId) {
                $ids[] = (string)$evidenceId;
            }
            foreach ((array)($npc['offline']['rules'] ?? []) as $rule) {
                foreach ((array)($rule['effects']['evidence'] ?? []) as $evidenceId) {
                    $ids[] = (string)$evidenceId;
                }
            }
            foreach ((array)($npc['offline']['confront'] ?? []) as $confront) {
                foreach ((array)($confront['effects']['evidence'] ?? []) as $evidenceId) {
                    $ids[] = (string)$evidenceId;
                }
            }
        }
        foreach ((array)($case['horror_events'] ?? []) as $horror) {
            foreach ((array)($horror['adds_evidence'] ?? []) as $evidenceId) {
                $ids[] = (string)$evidenceId;
            }
        }
        foreach ((array)($case['board_links'] ?? []) as $rule) {
            foreach ((array)($rule['evidence'] ?? []) as $evidenceId) {
                $ids[] = (string)$evidenceId;
            }
        }
        foreach (['photos', 'videos', 'audios', 'documents'] as $group) {
            foreach ((array)($case['media'][$group] ?? []) as $item) {
                foreach ((array)($item['hotspots'] ?? []) as $hotspot) {
                    if (($hotspot['evidence'] ?? '') !== '') {
                        $ids[] = (string)$hotspot['evidence'];
                    }
                }
            }
        }
        return array_values(array_unique($ids));
    }

    private function hasCircularDependency(array $case, string $puzzleId, array $seen = []): bool
    {
        if (in_array($puzzleId, $seen, true)) {
            return true;
        }
        $seen[] = $puzzleId;
        foreach ((array)($case['puzzles'] ?? []) as $puzzle) {
            if ((string)($puzzle['id'] ?? '') !== $puzzleId) {
                continue;
            }
            foreach ((array)($puzzle['requires']['puzzles'] ?? []) as $required) {
                if ($this->hasCircularDependency($case, (string)$required, $seen)) {
                    return true;
                }
            }
        }
        return false;
    }
}
