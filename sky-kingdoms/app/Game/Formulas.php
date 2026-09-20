<?php

declare(strict_types=1);

namespace SkyKingdoms\Game;

use SkyKingdoms\Core\App;
use SkyKingdoms\Core\Num;

/**
 * Die Mathematik hinter allen Verbesserungen.
 *
 *   Wirkung:  wert(L)   = basis  * wachstum^(L-1)
 *   Kosten:   kosten(L) = basis  * kostenwachstum^(L-1)
 *   Kosten für n Stufen ab L: geometrische Reihe (geschlossene Formel,
 *   keine Schleife über tausende Level).
 *
 * Es gibt KEIN Maximallevel. Begrenzt wird ausschliesslich durch den sicheren
 * Zahlenbereich: Kosten oberhalb von balance('formulas.max_amount') werden
 * abgelehnt, weil sie nicht mehr exakt darstellbar wären.
 */
final class Formulas
{
    /** Obergrenze für Stufen in einem einzigen Klick (Sicherheitsnetz). */
    public const MAX_STEPS_PER_ACTION = 5000;

    public static function maxAmount(): float
    {
        return (float) App::balance('formulas.max_amount', 9.0e17);
    }

    public static function defaultCostGrowth(): float
    {
        return (float) App::balance('formulas.cost_growth_default', 1.0575);
    }

    public static function defaultEffectGrowth(): float
    {
        return (float) App::balance('formulas.effect_growth_default', 1.01);
    }

    // =================================================================
    // Wirkung
    // =================================================================

    /** Wert eines Merkmals auf Stufe $level. Stufe 1 = Basiswert. */
    public static function value(float $base, int $level, ?float $growth = null): float
    {
        $growth = $growth ?? self::defaultEffectGrowth();
        $level  = max(1, $level);

        $value = $base * ($growth ** ($level - 1));

        return is_finite($value) ? $value : self::maxAmount();
    }

    /** Prozentualer Zuwachs pro Stufe, z. B. 0.01 für +1 %. */
    public static function gainPercent(?float $growth = null): float
    {
        return ($growth ?? self::defaultEffectGrowth()) - 1.0;
    }

    /**
     * Vorschau: wie verändert sich ein Wert von $from auf $to Stufen?
     *
     * @return array{from:float,to:float,diff:float,percent:float}
     */
    public static function preview(float $base, int $fromLevel, int $toLevel, ?float $growth = null): array
    {
        $a = self::value($base, $fromLevel, $growth);
        $b = self::value($base, $toLevel, $growth);

        return [
            'from'    => $a,
            'to'      => $b,
            'diff'    => $b - $a,
            'percent' => $a > 0 ? ($b / $a) - 1.0 : 0.0,
        ];
    }

    // =================================================================
    // Kosten
    // =================================================================

    /**
     * Kosten einer einzelnen Stufe.
     *
     * @param array<string,float|int> $baseCost
     * @return array<string,int>
     */
    public static function costAt(array $baseCost, int $level, ?float $growth = null): array
    {
        $growth = $growth ?? self::defaultCostGrowth();
        $factor = $growth ** max(0, $level - 1);

        $out = [];
        foreach ($baseCost as $resource => $amount) {
            $value = (float) $amount * $factor;
            $out[$resource] = self::toAmount($value);
        }

        return $out;
    }

    /**
     * Kosten für $count Stufen, beginnend bei $fromLevel (geschlossene Formel).
     *
     * @param array<string,float|int> $baseCost
     * @return array<string,int>
     */
    public static function bulkCost(array $baseCost, int $fromLevel, int $count, ?float $growth = null): array
    {
        $growth = $growth ?? self::defaultCostGrowth();
        $count  = max(0, $count);
        if ($count === 0) {
            return [];
        }

        $start = $growth ** max(0, $fromLevel - 1);
        $sum   = abs($growth - 1.0) < 1e-9
            ? (float) $count
            : (($growth ** $count) - 1.0) / ($growth - 1.0);

        $out = [];
        foreach ($baseCost as $resource => $amount) {
            $value = (float) $amount * $start * $sum;
            $out[$resource] = self::toAmount($value);
        }

        return $out;
    }

    /**
     * Wie viele Stufen sind mit den vorhandenen Mitteln bezahlbar?
     *
     * Rechenweg: geschlossene Auflösung der geometrischen Reihe über den
     * Logarithmus, danach höchstens 64 Korrekturschritte. Keine langen
     * Schleifen, auch nicht bei riesigen Beständen.
     *
     * @param array<string,float|int> $baseCost
     * @param array<string,float|int> $available
     */
    public static function maxAffordable(array $baseCost, int $fromLevel, array $available, ?float $growth = null, int $cap = self::MAX_STEPS_PER_ACTION): int
    {
        $baseCost = array_filter($baseCost, static fn ($v): bool => (float) $v > 0);
        if ($baseCost === []) {
            return $cap;
        }

        $growth = $growth ?? self::defaultCostGrowth();
        $start  = $growth ** max(0, $fromLevel - 1);
        $guess  = $cap;

        foreach ($baseCost as $resource => $amount) {
            $have = (float) ($available[$resource] ?? 0);
            $unit = (float) $amount * $start;

            if ($unit <= 0) {
                continue;
            }
            if ($have < $unit) {
                return 0;
            }

            if (abs($growth - 1.0) < 1e-9) {
                $n = (int) floor($have / $unit);
            } else {
                $ratio = 1.0 + ($have * ($growth - 1.0) / $unit);
                $n     = $ratio <= 1.0 ? 0 : (int) floor(log($ratio) / log($growth));
            }

            $guess = min($guess, $n);
            if ($guess <= 0) {
                return 0;
            }
        }

        $guess = max(0, min($guess, $cap));

        // Korrektur gegen Rundungsfehler der Gleitkommarechnung.
        for ($i = 0; $i < 64 && $guess > 0; $i++) {
            if (self::canAfford(self::bulkCost($baseCost, $fromLevel, $guess, $growth), $available)) {
                break;
            }
            $guess--;
        }
        for ($i = 0; $i < 64 && $guess < $cap; $i++) {
            if (!self::canAfford(self::bulkCost($baseCost, $fromLevel, $guess + 1, $growth), $available)) {
                break;
            }
            $guess++;
        }

        return $guess;
    }

    /**
     * @param array<string,float|int> $cost
     * @param array<string,float|int> $available
     */
    public static function canAfford(array $cost, array $available): bool
    {
        foreach ($cost as $resource => $amount) {
            if ((float) ($available[$resource] ?? 0) + 1e-6 < (float) $amount) {
                return false;
            }
        }

        return true;
    }

    /** Liegen die Kosten noch im sicher darstellbaren Bereich? */
    public static function withinSafeRange(array $cost): bool
    {
        $max = self::maxAmount();
        foreach ($cost as $amount) {
            if (!is_finite((float) $amount) || (float) $amount >= $max) {
                return false;
            }
        }

        return true;
    }

    /** Fehlende Mengen ermitteln (für die Anzeige „dir fehlen noch …“). */
    public static function missing(array $cost, array $available): array
    {
        $missing = [];
        foreach ($cost as $resource => $amount) {
            $lack = (float) $amount - (float) ($available[$resource] ?? 0);
            if ($lack > 0) {
                $missing[$resource] = self::toAmount($lack);
            }
        }

        return $missing;
    }

    // =================================================================
    // Meilensteine für die Optik
    // =================================================================

    /** Optische Ausbaustufe (0–6) eines Gebäudes anhand seines Levels. */
    public static function tier(int $level): int
    {
        $milestones = (array) App::balance('formulas.milestones', [10, 25, 50, 100, 250, 500]);
        $tier       = 0;
        foreach ($milestones as $milestone) {
            if ($level >= (int) $milestone) {
                $tier++;
            }
        }

        return $tier;
    }

    /** Nächster optischer Meilenstein oder null, wenn alle erreicht sind. */
    public static function nextMilestone(int $level): ?int
    {
        foreach ((array) App::balance('formulas.milestones', []) as $milestone) {
            if ($level < (int) $milestone) {
                return (int) $milestone;
            }
        }

        return null;
    }

    private static function toAmount(float $value): int
    {
        return Num::clampAmount(ceil($value));
    }
}
