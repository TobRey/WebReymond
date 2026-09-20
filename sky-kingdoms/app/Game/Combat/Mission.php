<?php

declare(strict_types=1);

namespace SkyKingdoms\Game\Combat;

use SkyKingdoms\Core\App;
use SkyKingdoms\Game\Formulas;
use SkyKingdoms\Game\Research;
use SkyKingdoms\Game\World;

/**
 * Die eigentliche Kampfsimulation.
 *
 * Aufbau: eine kleine Taktikkarte mit drei Wegen (Spuren) vom Eingang zum Ziel.
 * Auf den Wegen stehen Türme, Mauern und Patrouillen des Verteidigers – erzeugt
 * aus dessen tatsächlichen Verteidigungsgebäuden. Der Angreifer wählt vor dem
 * Start seine Truppe und entscheidet während der Mission über Spur, Ziel und
 * Spezialfähigkeiten.
 *
 * Die Simulation läuft in festen Schritten (10 pro Sekunde) und benutzt nur
 * Grundrechenarten sowie sqrt – dadurch liefert sie im Browser und auf dem
 * Server dasselbe Ergebnis. Massgeblich ist immer das Ergebnis des Servers.
 */
final class Mission
{
    public const FIELD_WIDTH  = 100.0;
    public const FIELD_HEIGHT = 60.0;
    public const LANES        = [12.0, 30.0, 48.0];
    public const OBJECTIVE_X  = 92.0;

    /**
     * Aufbau einer Mission aus dem Spielstand des Verteidigers erzeugen.
     * Gleicher Startwert = gleicher Aufbau (für Wiederholungen).
     */
    public static function setup(array $defenderWorld, string $missionKey, int $seed): array
    {
        $rng      = new Rng($seed);
        $mission  = (array) App::balance('missions.' . $missionKey, []);
        $research = Research::multipliers($defenderWorld);

        $towers  = [];
        $walls   = [];
        $patrols = [];

        foreach ((array) ($defenderWorld['buildings'] ?? []) as $building) {
            $def = World::buildingDef((string) $building['type']);
            if (($def['role'] ?? '') !== 'defense') {
                continue;
            }

            $level  = max(1, (int) $building['level']);
            $growth = (float) ($def['effect_growth'] ?? 1.012);
            $damage = (float) ($building['damage'] ?? 0);
            if ($damage >= 1.0) {
                continue;
            }
            $health = 1.0 - $damage;
            $lane   = $rng->int(0, count(self::LANES) - 1);

            if ($building['type'] === 'tower' && count($towers) < 10) {
                $towers[] = [
                    'lane'   => $lane,
                    'x'      => 22.0 + $rng->float() * 58.0,
                    'y'      => self::LANES[$lane] + ($rng->float() * 10.0 - 5.0),
                    'damage' => Formulas::value((float) ($def['effects']['defense_damage'] ?? 12), $level, $growth) * $health,
                    'range'  => Formulas::value((float) ($def['effects']['defense_range'] ?? 3.2), $level, 1.004) * 3.0,
                    'hp'     => Formulas::value(260.0, $level, $growth) * $research['defense_hp'] * $health,
                    'reload' => 9,
                ];
            } elseif ($building['type'] === 'wall' && count($walls) < 9) {
                $walls[] = [
                    'lane' => $lane,
                    'x'    => 28.0 + $rng->float() * 50.0,
                    'hp'   => Formulas::value((float) ($def['effects']['defense_hp'] ?? 220), $level, $growth) * $research['defense_hp'] * $health,
                ];
            } elseif ($building['type'] === 'patrol_post' && count($patrols) < 6) {
                $patrols[] = [
                    'lane'   => $lane,
                    'x'      => 30.0 + $rng->float() * 45.0,
                    'range'  => 12.0,
                    'speed'  => 0.22,
                    'dir'    => $rng->int(0, 1) === 0 ? -1.0 : 1.0,
                    'hp'     => Formulas::value(180.0, $level, $growth) * $research['defense_hp'] * $health,
                    'damage' => Formulas::value((float) ($def['effects']['patrol_strength'] ?? 18), $level, $growth) * $health,
                ];
            }
        }

        return [
            'mission'  => $missionKey,
            'seed'     => $seed,
            'duration' => max(30, (int) ($mission['duration'] ?? 80)),
            'towers'   => $towers,
            'walls'    => $walls,
            'patrols'  => $patrols,
            'bonus'    => (float) App::balance('combat.defender_bonus', 1.15),
        ];
    }

    /**
     * Truppe aus den Angaben des Angreifers erzeugen (serverseitig geprüft).
     *
     * @param array<string,int> $squad  Einheitentyp => Anzahl
     */
    public static function buildSquad(array $attackerWorld, array $squad): array
    {
        $research = Research::multipliers($attackerWorld);
        $maxSquad = (int) App::balance('combat.max_squad', 20);
        $units    = [];

        foreach ($squad as $type => $count) {
            $def = App::balance('units.' . $type);
            if (!is_array($def)) {
                continue;
            }
            $available = (int) (($attackerWorld['units'][$type] ?? 0));
            $count     = max(0, min((int) $count, $available));
            $level     = max(1, (int) ($attackerWorld['unit_levels'][$type] ?? 1));

            $hp     = Formulas::value((float) $def['hp'], $level, (float) App::balance('unit_training.hp_growth', 1.01));
            $damage = Formulas::value((float) $def['damage'], $level, (float) App::balance('unit_training.damage_growth', 1.008))
                * $research['unit_damage'];
            $speed  = Formulas::value((float) $def['speed'], $level, (float) App::balance('unit_training.speed_growth', 1.005));

            for ($i = 0; $i < $count; $i++) {
                if (count($units) >= $maxSquad) {
                    break 2;
                }
                $units[] = [
                    'type'   => (string) $type,
                    'hp'     => $hp,
                    'maxHp'  => $hp,
                    'damage' => $damage,
                    'speed'  => $speed * 1.6,
                    'carry'  => (float) ($def['carry'] ?? 20),
                    'struct' => (float) ($def['structure_damage'] ?? 1.0),
                    'stealth'=> (float) ($def['stealth'] ?? 1.0),
                ];
            }
        }

        return $units;
    }

    /**
     * Mission durchrechnen.
     *
     * @param array<int,array{t:int,a:string,v:string|int}> $actions
     * @return array{success:bool,progress:float,ticks:int,survivors:int,carry:float,
     *               losses:array<string,int>,structure:float,events:array,reason:string}
     */
    public static function run(array $setup, array $units, array $actions): array
    {
        $tickRate  = (int) App::balance('combat.tick_rate', 10);
        $maxTicks  = $setup['duration'] * $tickRate;
        $cooldown  = (int) App::balance('combat.action_cooldown', 6);
        $abilities = (array) App::balance('combat.abilities', []);

        // --- Aktionen nach Zeitpunkt sortieren und begrenzen -------------
        $actions = array_slice($actions, 0, (int) App::balance('combat.max_actions', 200));
        usort($actions, static fn (array $a, array $b): int => ((int) $a['t']) <=> ((int) $b['t']));

        $byTick = [];
        $lastAction = -999;
        foreach ($actions as $action) {
            $tick = max(0, min((int) ($action['t'] ?? 0), $maxTicks));
            $kind = (string) ($action['a'] ?? '');
            if (!in_array($kind, ['lane', 'ability'], true)) {
                continue;
            }
            if ($kind === 'ability' && $tick - $lastAction < $cooldown) {
                continue; // zu schnell hintereinander – wird verworfen
            }
            if ($kind === 'ability') {
                $lastAction = $tick;
            }
            $byTick[$tick][] = ['a' => $kind, 'v' => $action['v'] ?? ''];
        }

        // --- Zustand ------------------------------------------------------
        $lane      = 1;
        $x         = 2.0;
        $events    = [];
        $active    = [];   // Fähigkeit => verbleibende Ticks
        $cooldowns = [];
        $walls     = $setup['walls'];
        $towers    = $setup['towers'];
        $patrols   = $setup['patrols'];
        $structure = 0.0;
        $losses    = [];
        $bonus     = (float) $setup['bonus'];

        $alive = static function (array $units): int {
            $count = 0;
            foreach ($units as $unit) {
                if ($unit['hp'] > 0.0) {
                    $count++;
                }
            }

            return $count;
        };

        $tick = 0;
        $reason = 'timeout';

        for (; $tick < $maxTicks; $tick++) {
            // 1. Befehle des Spielers
            foreach ($byTick[$tick] ?? [] as $action) {
                if ($action['a'] === 'lane') {
                    $target = (int) $action['v'];
                    if ($target >= 0 && $target < count(self::LANES) && $x < 70.0) {
                        $lane = $target;
                        $events[] = ['t' => $tick, 'e' => 'lane', 'v' => $lane];
                    }
                } elseif ($action['a'] === 'ability') {
                    $key = (string) $action['v'];
                    $def = $abilities[$key] ?? null;
                    if ($def !== null && ($cooldowns[$key] ?? 0) <= $tick) {
                        $active[$key]    = (int) ($def['duration'] ?? 40);
                        $cooldowns[$key] = $tick + (int) ($def['cooldown'] ?? 120);
                        $events[] = ['t' => $tick, 'e' => 'ability', 'v' => $key];
                    }
                }
            }

            // 2. Wirkung der Fähigkeiten
            $speedFactor  = 1.0;
            $damageTaken  = 1.0;
            $towerHit     = 1.0;
            $structFactor = 1.0;
            foreach ($active as $key => $remaining) {
                if ($remaining <= 0) {
                    unset($active[$key]);
                    continue;
                }
                $def = $abilities[$key] ?? [];
                $speedFactor  *= (float) ($def['speed'] ?? 1.0);
                $damageTaken  *= (float) ($def['damage_taken'] ?? 1.0);
                $towerHit     *= (float) ($def['tower_accuracy'] ?? 1.0);
                $structFactor *= (float) ($def['structure_damage'] ?? 1.0);
                $active[$key]  = $remaining - 1;
            }

            $living = $alive($units);
            if ($living === 0) {
                $reason = 'defeated';
                break;
            }

            // 3. Mauer im Weg?
            $blocking = null;
            foreach ($walls as $index => $wall) {
                if ($wall['lane'] !== $lane || $wall['hp'] <= 0.0) {
                    continue;
                }
                if ($x + 1.2 >= $wall['x'] && $x <= $wall['x'] + 1.2) {
                    $blocking = $index;
                    break;
                }
            }

            // 4. Patrouille im Weg?
            $fighting = null;
            foreach ($patrols as $index => $patrol) {
                if ($patrol['hp'] <= 0.0) {
                    continue;
                }
                // Patrouille bewegt sich hin und her
                $patrols[$index]['x'] += $patrol['dir'] * $patrol['speed'];
                if ($patrols[$index]['x'] > $patrol['x'] + $patrol['range']) {
                    $patrols[$index]['dir'] = -1.0;
                }
                if ($patrols[$index]['x'] < $patrol['x'] - $patrol['range']) {
                    $patrols[$index]['dir'] = 1.0;
                }
                if ($patrol['lane'] === $lane && $fighting === null) {
                    $dx = $patrols[$index]['x'] - $x;
                    if ($dx < 1.5 && $dx > -1.5) {
                        $fighting = $index;
                    }
                }
            }

            // 5. Angriff der Truppe
            $groupDamage  = 0.0;
            $structDamage = 0.0;
            foreach ($units as $unit) {
                if ($unit['hp'] <= 0.0) {
                    continue;
                }
                $groupDamage  += $unit['damage'] / 10.0;
                $structDamage += ($unit['damage'] * $unit['struct']) / 10.0;
            }

            if ($blocking !== null) {
                $hit = $structDamage * $structFactor;
                $walls[$blocking]['hp'] -= $hit;
                $structure += $hit;
                if ($walls[$blocking]['hp'] <= 0.0) {
                    $events[] = ['t' => $tick, 'e' => 'wall', 'v' => $blocking];
                }
            } elseif ($fighting !== null) {
                $patrols[$fighting]['hp'] -= $groupDamage;
                if ($patrols[$fighting]['hp'] <= 0.0) {
                    $events[] = ['t' => $tick, 'e' => 'patrol', 'v' => $fighting];
                }
            } else {
                // 6. Vorwärts
                $speed = 0.0;
                $count = 0;
                foreach ($units as $unit) {
                    if ($unit['hp'] > 0.0) {
                        $speed += $unit['speed'];
                        $count++;
                    }
                }
                $x += ($speed / $count) * $speedFactor / 10.0;
            }

            // 7. Verteidiger schiessen
            if ($fighting !== null) {
                $target = self::weakestIndex($units);
                if ($target >= 0) {
                    $units[$target]['hp'] -= $patrols[$fighting]['damage'] * $damageTaken * $bonus / 10.0;
                    if ($units[$target]['hp'] <= 0.0) {
                        $losses[$units[$target]['type']] = ($losses[$units[$target]['type']] ?? 0) + 1;
                        $events[] = ['t' => $tick, 'e' => 'fall', 'v' => $target];
                    }
                }
            }

            foreach ($towers as $index => $tower) {
                if ($tower['hp'] <= 0.0) {
                    continue;
                }
                if ($tick % $tower['reload'] !== $index % $tower['reload']) {
                    continue;
                }
                $dx = $tower['x'] - $x;
                $laneGap = $tower['lane'] === $lane ? 0.0 : 14.0;
                $distance = self::distance($dx, $laneGap);
                if ($distance > $tower['range']) {
                    continue;
                }

                $target = self::weakestIndex($units);
                if ($target < 0) {
                    continue;
                }
                $hit = $tower['damage'] * $towerHit * $damageTaken * $bonus / 10.0 * (float) $tower['reload'];
                $units[$target]['hp'] -= $hit;
                $events[] = ['t' => $tick, 'e' => 'shot', 'v' => $index];
                if ($units[$target]['hp'] <= 0.0) {
                    $losses[$units[$target]['type']] = ($losses[$units[$target]['type']] ?? 0) + 1;
                    $events[] = ['t' => $tick, 'e' => 'fall', 'v' => $target];
                }
            }

            // 8. Ziel erreicht?
            if ($x >= self::OBJECTIVE_X) {
                $reason = 'success';
                $tick++;
                break;
            }
        }

        $survivors = $alive($units);
        $carry     = 0.0;
        foreach ($units as $unit) {
            if ($unit['hp'] > 0.0) {
                $carry += $unit['carry'];
            }
        }

        $progress = ($x - 2.0) / (self::OBJECTIVE_X - 2.0);
        $progress = $progress < 0.0 ? 0.0 : ($progress > 1.0 ? 1.0 : $progress);

        return [
            'success'   => $reason === 'success',
            'reason'    => $reason,
            'progress'  => round($progress, 4),
            'ticks'     => $tick,
            'survivors' => $survivors,
            'carry'     => round($carry, 2),
            'losses'    => $losses,
            'structure' => round($structure, 2),
            'events'    => array_slice($events, 0, 400),
        ];
    }

    /** Index der Einheit mit den wenigsten Lebenspunkten (deterministisch). */
    private static function weakestIndex(array $units): int
    {
        $best  = -1;
        $value = 0.0;
        foreach ($units as $index => $unit) {
            if ($unit['hp'] <= 0.0) {
                continue;
            }
            if ($best === -1 || $unit['hp'] < $value) {
                $best  = $index;
                $value = $unit['hp'];
            }
        }

        return $best;
    }

    /** Entfernung – nur Grundrechenarten und sqrt, damit PHP und JS gleich rechnen. */
    private static function distance(float $dx, float $dy): float
    {
        return sqrt($dx * $dx + $dy * $dy);
    }
}
