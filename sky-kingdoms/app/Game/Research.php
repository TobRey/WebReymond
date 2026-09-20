<?php

declare(strict_types=1);

namespace SkyKingdoms\Game;

use SkyKingdoms\Core\App;

/**
 * Forschung: unbegrenzte Stufen, jede Stufe wirkt als Multiplikator auf einen
 * Bereich des Spiels. Forschung hat – wie alles andere – keine Wartezeit.
 */
final class Research
{
    /** Alle Multiplikatoren eines Königreichs in einem Rutsch. */
    public static function multipliers(array $world): array
    {
        $out = [
            'transport_speed'  => 1.0,
            'storage_capacity' => 1.0,
            'mine_output'      => 1.0,
            'farm_output'      => 1.0,
            'craft_output'     => 1.0,
            'bridge_capacity'  => 1.0,
            'unit_damage'      => 1.0,
            'defense_hp'       => 1.0,
            'aether_output'    => 1.0,
        ];

        $definitions = (array) App::balance('research', []);
        foreach ((array) ($world['research'] ?? []) as $key => $level) {
            $def = $definitions[$key] ?? null;
            $level = (int) $level;
            if ($def === null || $level <= 0) {
                continue;
            }
            $effect = (string) ($def['effect'] ?? '');
            if (!isset($out[$effect])) {
                continue;
            }
            $out[$effect] *= ((float) ($def['per_level'] ?? 1.0)) ** $level;
        }

        // Prestige wirkt dauerhaft auf jede Produktion.
        $points = (int) ($world['prestige']['points'] ?? 0);
        if ($points > 0) {
            $bonus = 1.0 + $points * (float) App::balance('prestige.bonus_per_point', 0.01);
            $out['mine_output']  *= $bonus;
            $out['farm_output']  *= $bonus;
            $out['craft_output'] *= $bonus;
        }

        return $out;
    }

    /** Kosten der nächsten Stufe (Rabatt der Forschungsgilde eingerechnet). */
    public static function cost(array $world, string $key, int $count = 1): array
    {
        $def = App::balance('research.' . $key);
        if (!is_array($def)) {
            return [];
        }

        $level    = (int) ($world['research'][$key] ?? 0);
        $growth   = (float) ($def['growth'] ?? 1.18);
        $cost     = Formulas::bulkCost((array) ($def['cost'] ?? []), $level + 1, max(1, $count), $growth);
        $discount = min(0.6, World::effects($world)['research_discount'] / 100);

        if ($discount > 0) {
            foreach ($cost as $resource => $amount) {
                $cost[$resource] = (int) ceil($amount * (1 - $discount));
            }
        }

        return $cost;
    }

    /** Sind die Voraussetzungen erfüllt? */
    public static function isUnlocked(array $world, string $key): bool
    {
        $def = App::balance('research.' . $key);
        if (!is_array($def)) {
            return false;
        }

        foreach ((array) ($def['requires']['research'] ?? []) as $needed => $level) {
            if ((int) ($world['research'][$needed] ?? 0) < (int) $level) {
                return false;
            }
        }

        return true;
    }

    /** Fehlende Voraussetzung als lesbarer Text. */
    public static function requirementText(array $world, string $key): string
    {
        $def = App::balance('research.' . $key);
        if (!is_array($def)) {
            return '';
        }

        foreach ((array) ($def['requires']['research'] ?? []) as $needed => $level) {
            if ((int) ($world['research'][$needed] ?? 0) < (int) $level) {
                $name = (string) App::balance('research.' . $needed . '.name', $needed);

                return $name . ' Stufe ' . (int) $level . ' nötig';
            }
        }

        return '';
    }

    /** Übersicht für die Oberfläche. */
    public static function overview(array $world): array
    {
        $out = [];
        foreach ((array) App::balance('research', []) as $key => $def) {
            $level = (int) ($world['research'][$key] ?? 0);
            $out[] = [
                'key'         => $key,
                'name'        => (string) ($def['name'] ?? $key),
                'desc'        => (string) ($def['desc'] ?? ''),
                'level'       => $level,
                'effect'      => (string) ($def['effect'] ?? ''),
                'per_level'   => (float) ($def['per_level'] ?? 1.0),
                'cost'        => self::cost($world, (string) $key),
                'unlocked'    => self::isUnlocked($world, (string) $key),
                'requirement' => self::requirementText($world, (string) $key),
            ];
        }

        return $out;
    }
}
