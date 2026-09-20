<?php

declare(strict_types=1);

namespace SkyKingdoms\Game\Combat;

/**
 * Zufallsgenerator mit festem Startwert (xorshift32).
 *
 * Wichtig: Diese Klasse ist Zeichen für Zeichen identisch zur JavaScript-Fassung
 * in assets/js/combat/rng.js. Dadurch erzeugen Browser und Server bei gleichem
 * Startwert exakt dieselbe Abfolge – Voraussetzung dafür, dass der Server jeden
 * Kampf unabhängig nachrechnen kann.
 */
final class Rng
{
    private int $state;

    public function __construct(int $seed)
    {
        $seed = $seed & 0xFFFFFFFF;
        $this->state = $seed === 0 ? 0x9E3779B9 : $seed;
    }

    /** Nächste Ganzzahl 0 … 4294967295 */
    public function next(): int
    {
        $x = $this->state;
        $x ^= ($x << 13) & 0xFFFFFFFF;
        $x ^= ($x >> 17);
        $x ^= ($x << 5) & 0xFFFFFFFF;
        $this->state = $x & 0xFFFFFFFF;

        return $this->state;
    }

    /** Kommazahl 0 … 1 (ohne 1) */
    public function float(): float
    {
        return $this->next() / 4294967296.0;
    }

    /** Ganzzahl von $min bis $max (beide einschliesslich) */
    public function int(int $min, int $max): int
    {
        if ($max <= $min) {
            return $min;
        }

        return $min + (int) floor($this->float() * ($max - $min + 1));
    }

    /** Zufällig eine Position aus einer Liste */
    public function pick(array $items): mixed
    {
        if ($items === []) {
            return null;
        }
        $keys = array_keys($items);

        return $items[$keys[$this->int(0, count($keys) - 1)]];
    }
}
