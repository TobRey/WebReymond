<?php

declare(strict_types=1);

namespace SkyKingdoms\Game;

use SkyKingdoms\Core\App;
use SkyKingdoms\Core\Num;

/**
 * Buchhaltung: Bestände prüfen, abbuchen, gutschreiben – immer mit
 * Lagergrenzen und sicheren Zahlenbereichen.
 *
 * WICHTIG: Nur eingelagerte Waren (world['store']) zählen. Was noch im Puffer
 * eines Gebäudes liegt oder unterwegs ist, kann nicht ausgegeben werden.
 */
final class Economy
{
    /** @return array<string,int> */
    public static function available(array $world): array
    {
        $out = [];
        foreach ((array) ($world['store'] ?? []) as $resource => $amount) {
            $out[(string) $resource] = (int) $amount;
        }

        return $out;
    }

    public static function canPay(array $world, array $cost): bool
    {
        return Formulas::canAfford($cost, self::available($world));
    }

    /**
     * Kosten abbuchen. Gibt false zurück, wenn es nicht reicht – dann wird
     * NICHTS abgebucht (Alles-oder-nichts).
     */
    public static function pay(array &$world, array $cost): bool
    {
        if (!self::canPay($world, $cost)) {
            return false;
        }

        foreach ($cost as $resource => $amount) {
            $amount = (int) $amount;
            if ($amount <= 0) {
                continue;
            }
            $current = (int) ($world['store'][$resource] ?? 0);
            $left    = max(0, $current - $amount);

            if ($left === 0) {
                unset($world['store'][$resource]);
            } else {
                $world['store'][$resource] = $left;
            }

            $world['stats']['spent'][$resource] = (int) ($world['stats']['spent'][$resource] ?? 0) + $amount;
        }

        return true;
    }

    /**
     * Gutschreiben mit Lagergrenze.
     *
     * @return array{stored:array<string,int>,lost:array<string,int>}
     */
    public static function credit(array &$world, array $gains): array
    {
        $capacity = World::capacity($world);
        $byClass  = World::storedByClass($world);
        $stored   = [];
        $lost     = [];

        foreach ($gains as $resource => $amount) {
            $amount = (int) $amount;
            if ($amount <= 0) {
                continue;
            }

            $class = World::resourceClass((string) $resource);
            $cap   = (float) ($capacity[$class] ?? 0);
            $used  = (float) ($byClass[$class] ?? 0);
            $free  = max(0.0, $cap - $used);
            $take  = (int) min($amount, $free);

            if ($take > 0) {
                $world['store'][$resource] = Num::clampAmount((float) ($world['store'][$resource] ?? 0) + $take);
                $byClass[$class] = $used + $take;
                $stored[$resource] = $take;
            }
            if ($amount - $take > 0) {
                $lost[$resource] = $amount - $take;
            }
        }

        return ['stored' => $stored, 'lost' => $lost];
    }

    /**
     * Waren wegnehmen, so weit vorhanden (für Plünderungen).
     *
     * @return array<string,int> tatsächlich entnommene Mengen
     */
    public static function take(array &$world, array $wanted): array
    {
        $taken = [];
        foreach ($wanted as $resource => $amount) {
            $have = (int) ($world['store'][$resource] ?? 0);
            $get  = (int) min($have, max(0, (int) $amount));
            if ($get <= 0) {
                continue;
            }
            $left = $have - $get;
            if ($left === 0) {
                unset($world['store'][$resource]);
            } else {
                $world['store'][$resource] = $left;
            }
            $taken[$resource] = $get;
        }

        return $taken;
    }

    /** Lagerübersicht für die Oberfläche. */
    public static function storageOverview(array $world): array
    {
        $capacity = World::capacity($world);
        $byClass  = World::storedByClass($world);
        $out      = [];

        foreach ((array) App::balance('storage_classes', []) as $class => $def) {
            $cap  = (float) ($capacity[$class] ?? 0);
            $used = (float) ($byClass[$class] ?? 0);
            $out[$class] = [
                'name'    => (string) ($def['name'] ?? $class),
                'used'    => (int) $used,
                'cap'     => (int) $cap,
                'ratio'   => $cap > 0 ? min(1.0, $used / $cap) : 0.0,
                'full'    => $cap > 0 && $used >= $cap - 0.5,
            ];
        }

        return $out;
    }

    /** Gesamtwert des Besitzes in Gold (für Rangliste und Beuteberechnung). */
    public static function netWorth(array $world): int
    {
        $rates = (array) App::balance('shop.sell_rates', []);
        $sum   = 0.0;
        foreach ((array) ($world['store'] ?? []) as $resource => $amount) {
            $sum += (float) $amount * (float) ($rates[$resource] ?? 0.05);
        }

        return (int) round($sum);
    }
}
