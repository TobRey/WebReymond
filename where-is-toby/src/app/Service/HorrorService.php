<?php
declare(strict_types=1);

namespace App\Service;

use App\Repository\SettingsRepository;

/**
 * Horror-Ereignisse: selten, fallabhaengig, nie als reine Jumpscare-Kette.
 *
 * Ausloeser (trigger.event):
 *   solve, open_device, open_app, open_panel, evidence, chat, confront,
 *   report, idle, start, hint, finish
 */
final class HorrorService
{
    private const GLOBAL_COOLDOWN = 70; // Sekunden zwischen zwei Ereignissen

    public function __construct(private SettingsRepository $settings)
    {
    }

    /**
     * Sucht ein passendes Ereignis und markiert es als gesehen.
     *
     * @param array $case     Falldaten
     * @param array $progress Fortschritt (wird veraendert zurueckgegeben)
     * @param string $event   Ausloeser
     * @param string $subject Betroffene ID (Raetsel, Geraet, Panel ...)
     * @return array{progress:array,event:?array}
     */
    public function evaluate(array $case, array $progress, string $event, string $subject = ''): array
    {
        $intensity = (string)$this->settings->get('gameplay.horror_intensity', 'normal');
        if ($intensity === 'off') {
            return ['progress' => $progress, 'event' => null];
        }
        $allowJumpscares = (bool)$this->settings->get('gameplay.jumpscares', true);
        $now = time();
        $lastEvent = (int)($progress['horror_last'] ?? 0);
        $seen = (array)($progress['horror_seen'] ?? []);

        $candidates = [];
        foreach ((array)($case['horror_events'] ?? []) as $horror) {
            $id = (string)($horror['id'] ?? '');
            if ($id === '') {
                continue;
            }
            if (($horror['once'] ?? true) && in_array($id, $seen, true)) {
                continue;
            }
            $trigger = $horror['trigger'] ?? [];
            if ((string)($trigger['event'] ?? '') !== $event) {
                continue;
            }
            $match = (string)($trigger['match'] ?? '');
            if ($match !== '' && $match !== $subject) {
                continue;
            }
            foreach ((array)($trigger['requires_flags'] ?? []) as $flag) {
                if (empty($progress['flags'][$flag])) {
                    continue 2;
                }
            }
            foreach ((array)($trigger['forbid_flags'] ?? []) as $flag) {
                if (!empty($progress['flags'][$flag])) {
                    continue 2;
                }
            }
            $minSolved = (int)($trigger['min_solved'] ?? 0);
            if (count((array)($progress['solved'] ?? [])) < $minSolved) {
                continue;
            }
            $minEvidence = (int)($trigger['min_evidence'] ?? 0);
            if (count((array)($progress['evidence'] ?? [])) < $minEvidence) {
                continue;
            }
            if (!$allowJumpscares && (bool)($horror['payload']['jumpscare'] ?? false)) {
                continue;
            }
            if ($intensity === 'mild' && (string)($horror['intensity'] ?? 'normal') === 'intense') {
                continue;
            }

            $cooldown = (int)($trigger['cooldown'] ?? self::GLOBAL_COOLDOWN);
            if (!(bool)($trigger['ignore_cooldown'] ?? false) && ($now - $lastEvent) < $cooldown) {
                continue;
            }

            $chance = (float)($trigger['chance'] ?? 1.0);
            if ($intensity === 'intense') {
                $chance = min(1.0, $chance * 1.35);
            } elseif ($intensity === 'mild') {
                $chance *= 0.6;
            }
            if ($chance < 1.0 && (random_int(0, 9999) / 10000) > $chance) {
                continue;
            }

            $candidates[] = $horror;
        }

        if ($candidates === []) {
            return ['progress' => $progress, 'event' => null];
        }

        usort($candidates, static fn(array $a, array $b): int => (int)($b['priority'] ?? 0) <=> (int)($a['priority'] ?? 0));
        $chosen = $candidates[0];

        $progress['horror_seen'][] = (string)$chosen['id'];
        $progress['horror_seen'] = array_values(array_unique($progress['horror_seen']));
        $progress['horror_last'] = $now;

        foreach ((array)($chosen['sets_flags'] ?? []) as $flag) {
            $progress['flags'][(string)$flag] = true;
        }
        foreach ((array)($chosen['adds_evidence'] ?? []) as $evidenceId) {
            if (!in_array($evidenceId, $progress['evidence'] ?? [], true)) {
                $progress['evidence'][] = (string)$evidenceId;
            }
        }

        return ['progress' => $progress, 'event' => $this->publicEvent($chosen)];
    }

    /** Entfernt interne Felder, bevor das Ereignis an den Browser geht. */
    public function publicEvent(array $horror): array
    {
        $payload = $horror['payload'] ?? [];
        return [
            'id'        => (string)$horror['id'],
            'type'      => (string)($horror['type'] ?? 'ui'),
            'intensity' => (string)($horror['intensity'] ?? 'normal'),
            'payload'   => [
                'title'     => (string)($payload['title'] ?? ''),
                'text'      => (string)($payload['text'] ?? ''),
                'effect'    => (string)($payload['effect'] ?? 'flicker'),
                'duration'  => (int)($payload['duration'] ?? 2600),
                'image'     => (string)($payload['image'] ?? ''),
                'sound'     => (string)($payload['sound'] ?? ''),
                'sender'    => (string)($payload['sender'] ?? ''),
                'panel'     => (string)($payload['panel'] ?? ''),
                'jumpscare' => (bool)($payload['jumpscare'] ?? false),
                'reverse'   => (string)($payload['reverse'] ?? ''),
            ],
        ];
    }

    /** Fuer den Admin-Testmodus: Ereignis direkt ausloesen. */
    public function forceEvent(array $case, string $horrorId): ?array
    {
        foreach ((array)($case['horror_events'] ?? []) as $horror) {
            if ((string)($horror['id'] ?? '') === $horrorId) {
                return $this->publicEvent($horror);
            }
        }
        return null;
    }
}
