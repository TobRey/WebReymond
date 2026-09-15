<?php
declare(strict_types=1);

namespace App\Service;

use App\Core\HttpException;
use App\Repository\SettingsRepository;
use App\Service\Ai\AiClient;

/**
 * Hinweissystem (Gluehbirne).
 *
 * Drei Stufen:
 *   1 subtile Andeutung, 2 konkreter Hinweis, 3 nahezu direkte Hilfestellung
 *
 * Normale Spielende haben pro Fall ein Budget (Standard: 2 Hinweise),
 * Administratoren haben unbegrenzt viele.
 */
final class HintService
{
    public function __construct(
        private SettingsRepository $settings,
        private AiClient $ai
    ) {
    }

    public function limit(bool $isAdmin): int
    {
        return $isAdmin ? PHP_INT_MAX : max(0, (int)$this->settings->get('gameplay.hints_per_case', 4));
    }

    public function remaining(array $progress, bool $isAdmin): int
    {
        if ($isAdmin) {
            return -1;
        }
        return max(0, $this->limit(false) - (int)($progress['hints_used'] ?? 0));
    }

    /**
     * Ermittelt den naechsten sinnvollen Schritt und liefert den passenden Hinweis.
     *
     * @return array{ok:bool,hint:string,level:int,target:string,title:string,remaining:int,message:string,progress:array}
     */
    public function nextHint(array $case, array $progress, bool $isAdmin): array
    {
        $remaining = $this->remaining($progress, $isAdmin);
        if (!$isAdmin && $remaining <= 0) {
            return [
                'ok' => false,
                'hint' => '',
                'level' => 0,
                'target' => '',
                'title' => '',
                'remaining' => 0,
                'message' => 'Keine Hinweise mehr verfuegbar. Fuer diesen Fall sind alle Tipps verbraucht.',
                'progress' => $progress,
            ];
        }

        $target = $this->currentTarget($case, $progress);
        if ($target === null) {
            return [
                'ok' => false,
                'hint' => '',
                'level' => 0,
                'target' => '',
                'title' => '',
                'remaining' => $remaining,
                'message' => 'Alle Spuren sind ausgewertet. Schreibe jetzt den Abschlussbericht.',
                'progress' => $progress,
            ];
        }

        $usedLevels = (int)($progress['hint_levels'][$target['id']] ?? 0);
        $level = min(3, $usedLevels + 1);
        $hints = $this->hintsFor($case, $target);
        $hint = (string)($hints[$level - 1] ?? end($hints) ?: 'Sieh dir die bereits gefundenen Beweise noch einmal genau an.');

        $progress['hint_levels'][$target['id']] = $level;
        $progress['hints_used'] = (int)($progress['hints_used'] ?? 0) + ($isAdmin ? 0 : 1);
        $progress['hint_log'][] = [
            'target' => $target['id'],
            'level'  => $level,
            'time'   => gmdate('c'),
            'admin'  => $isAdmin,
        ];

        return [
            'ok'        => true,
            'hint'      => $hint,
            'level'     => $level,
            'target'    => (string)$target['id'],
            'title'     => (string)($target['title'] ?? ''),
            'remaining' => $isAdmin ? -1 : max(0, $remaining - 1),
            'message'   => '',
            'progress'  => $progress,
        ];
    }

    /** Naechstes offenes, erreichbares Raetsel bzw. naechster Schritt. */
    public function currentTarget(array $case, array $progress): ?array
    {
        $solved = (array)($progress['solved'] ?? []);
        $evidence = (array)($progress['evidence'] ?? []);
        $flags = (array)($progress['flags'] ?? []);
        $engine = new PuzzleEngine();

        $candidates = [];
        foreach ((array)($case['puzzles'] ?? []) as $puzzle) {
            $id = (string)($puzzle['id'] ?? '');
            if ($id === '' || in_array($id, $solved, true)) {
                continue;
            }
            if (!$engine->requirementsMet($puzzle, $progress)['ok']) {
                continue;
            }
            $candidates[] = $puzzle;
        }

        if ($candidates !== []) {
            usort($candidates, static fn(array $a, array $b): int => (int)($a['hint_order'] ?? 50) <=> (int)($b['hint_order'] ?? 50));
            return $candidates[0];
        }

        // Kein erreichbares Raetsel: Auf blockierte Raetsel oder Gespraeche hinweisen
        foreach ((array)($case['puzzles'] ?? []) as $puzzle) {
            if (in_array((string)($puzzle['id'] ?? ''), $solved, true)) {
                continue;
            }
            $missing = $engine->requirementsMet($puzzle, $progress)['missing'];
            $hints = (array)($puzzle['blocked_hints'] ?? []);
            if ($hints !== []) {
                return array_merge($puzzle, ['hints' => $hints]);
            }
            if ($missing['evidence'] !== [] || $missing['flags'] !== []) {
                return array_merge($puzzle, [
                    'hints' => [
                        'Dafuer fehlt noch eine Information aus einem Gespraech oder einem Geraet.',
                        'Sprich mit den Personen, die zum Zeitraum etwas sagen koennen, und durchsuche die freigeschalteten Geraete gruendlich.',
                        'Konkret fehlen noch: ' . implode(', ', array_merge($missing['evidence'], $missing['flags'])) . '. Suche gezielt danach.',
                    ],
                ]);
            }
        }

        // Alles geloest: Bericht
        if (($progress['result'] ?? null) === null && (array)($case['report']['questions'] ?? []) !== []) {
            return [
                'id'    => 'report',
                'title' => 'Abschlussbericht',
                'hints' => (array)($case['report']['hints'] ?? [
                    'Du hast genug Material. Fasse zusammen, wer wann wo war.',
                    'Vergleiche die Zeitangaben der Aussagen mit den technischen Zeitstempeln.',
                    'Trage im Bericht Taeter, Ort, Motiv und die tragenden Beweise ein.',
                ]),
            ];
        }
        return null;
    }

    /** @return string[] */
    private function hintsFor(array $case, array $target): array
    {
        $hints = array_values(array_filter(array_map('strval', (array)($target['hints'] ?? []))));
        if ($hints !== []) {
            return $hints;
        }
        $override = (array)($case['hints'][$target['id'] ?? ''] ?? []);
        if ($override !== []) {
            return array_map('strval', $override);
        }
        return [
            'Es gibt noch eine offene Spur. Sieh dir die zuletzt gefundenen Beweise genauer an.',
            'Vergleiche Zeitangaben: zwei Quellen widersprechen sich.',
            'Konfrontiere die Person, deren Aussage nicht zu den technischen Daten passt.',
        ];
    }

    /**
     * KI-Vorschlag fuer einen Hinweis (Adminbereich).
     * Der Vorschlag muss vor der Uebernahme bestaetigt werden.
     */
    public function suggest(array $case, array $puzzle, int $level): array
    {
        if ($this->ai->isOffline()) {
            throw HttpException::badRequest('Fuer KI-Vorschlaege muss ein KI-Anbieter konfiguriert sein.');
        }
        $levelText = match ($level) {
            1 => 'eine sehr subtile Andeutung, die nur die Richtung zeigt',
            2 => 'ein konkreter Hinweis, der die Quelle benennt, aber nicht die Loesung',
            default => 'eine nahezu direkte Hilfestellung ohne die Loesung woertlich zu nennen',
        };
        $system = 'Du hilfst beim Entwerfen von Hinweisen fuer ein Ermittlungsspiel. Antworte mit genau einem deutschen Satz, ohne Anfuehrungszeichen, ohne Einleitung.';
        $prompt = "Fall: " . (string)($case['title'] ?? '') . "\n"
            . "Raetsel: " . (string)($puzzle['title'] ?? '') . "\n"
            . "Aufgabe fuer Spielende: " . (string)($puzzle['prompt'] ?? '') . "\n"
            . "Loesungsweg (geheim): " . (string)($puzzle['solution_explanation'] ?? '') . "\n"
            . "Schreibe " . $levelText . ".";

        $result = $this->ai->suggest($system, $prompt, 160);
        if (!$result->ok) {
            throw HttpException::badRequest('KI-Vorschlag fehlgeschlagen: ' . $this->ai->humanError($result));
        }
        return ['suggestion' => trim($result->text), 'level' => $level, 'draft' => true];
    }
}
