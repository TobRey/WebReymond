<?php
declare(strict_types=1);

/**
 * Statische Spielinhalte: Spezialwürfel und Tränke.
 * Alle Werte sind bewusst zentral gehalten, damit Balancing an einer Stelle passiert.
 */
final class Content
{
    /** Startgeld je Spieler. */
    public const START_MONEY = 120;

    /** Züge pro Spieler (40 Züge insgesamt). */
    public const TURNS_PER_PLAYER = 20;

    /** Chance, dass eine Zielzahl verzaubert ist. */
    public const ENCHANT_CHANCE = 0.22;

    /** @return array<string, array<string, mixed>> */
    public static function dice(): array
    {
        return [
            'basic' => [
                'name' => 'Standard',
                'icon' => 'D',
                'price' => 0,
                'faces' => [1, 2, 3, 4, 5, 6],
                'desc' => 'Klassische Seiten 1-6.',
                'tag' => 'Basis',
            ],
            'low' => [
                'name' => 'Low Die',
                'icon' => 'L',
                'price' => 95,
                'faces' => [1, 1, 2, 2, 3, 3],
                'desc' => 'Fast immer niedrige Zahlen (1-3).',
                'tag' => 'Kontrolle',
            ],
            'high' => [
                'name' => 'High Die',
                'icon' => 'H',
                'price' => 95,
                'faces' => [4, 4, 5, 5, 6, 6],
                'desc' => 'Fast immer hohe Zahlen (4-6).',
                'tag' => 'Kontrolle',
            ],
            'precision' => [
                'name' => 'Precision Die',
                'icon' => 'P',
                'price' => 120,
                'faces' => [3, 3, 4, 4, 4, 5],
                'desc' => 'Konzentriert sich stark auf mittlere Werte.',
                'tag' => 'Kontrolle',
            ],
            'lucky' => [
                'name' => 'Lucky Die',
                'icon' => '*',
                'price' => 175,
                'faces' => [1, 2, 3, 4, 5, 6],
                'desc' => '14% Chance auf einen automatischen Perfect-Treffer.',
                'tag' => 'Glück',
            ],
            'golden' => [
                'name' => 'Golden Die',
                'icon' => 'G',
                'price' => 150,
                'faces' => [2, 3, 3, 4, 4, 5],
                'desc' => '+40% Geld auf diesen Zug.',
                'tag' => 'Ökonomie',
            ],
            'double' => [
                'name' => 'Double Die',
                'icon' => 'x2',
                'price' => 130,
                'faces' => [1, 2, 3, 4, 5, 6],
                'desc' => 'Die 6er-Seite zählt doppelt (= 12).',
                'tag' => 'Risiko',
            ],
            'magnet' => [
                'name' => 'Magnet Die',
                'icon' => 'M',
                'price' => 165,
                'faces' => [1, 2, 3, 4, 5, 6],
                'desc' => '70% Chance, das Ergebnis um 1 Richtung Ziel zu ziehen.',
                'tag' => 'Präzision',
            ],
            'mirror' => [
                'name' => 'Mirror Die',
                'icon' => 'MR',
                'price' => 115,
                'faces' => [1, 2, 3, 4, 5, 6],
                'desc' => '45% Chance, den Wert zu spiegeln (7 - Wert).',
                'tag' => 'Chaos',
            ],
            'reroll' => [
                'name' => 'Reroll Die',
                'icon' => 'R',
                'price' => 140,
                'faces' => [1, 2, 3, 4, 5, 6],
                'desc' => '35% Chance auf einen automatischen erneuten Wurf.',
                'tag' => 'Glück',
            ],
            'wild' => [
                'name' => 'Wild Die',
                'icon' => 'W',
                'price' => 200,
                'faces' => [1, 2, 3, 4, 5, 6],
                'desc' => '18% Chance: Seite passt sich der benötigten Zahl an.',
                'tag' => 'Legendär',
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function potions(): array
    {
        return [
            'over' => [
                'name' => 'Over Potion',
                'icon' => '^',
                'price' => 165,
                'desc' => 'Ergebnis über der Zielzahl: +50% Geld.',
            ],
            'under' => [
                'name' => 'Under Potion',
                'icon' => 'v',
                'price' => 165,
                'desc' => 'Ergebnis unter der Zielzahl: +50% Geld.',
            ],
            'even' => [
                'name' => 'Even Potion',
                'icon' => 'E',
                'price' => 150,
                'desc' => 'Gerades Ergebnis: +18 Punkte, +10 Geld.',
            ],
            'odd' => [
                'name' => 'Odd Potion',
                'icon' => 'O',
                'price' => 150,
                'desc' => 'Ungerades Ergebnis: +18 Punkte, +10 Geld.',
            ],
            'perfect' => [
                'name' => 'Perfect Potion',
                'icon' => '!',
                'price' => 240,
                'desc' => 'Punktlandung gibt zusätzlich +45 Geld.',
            ],
            'streak' => [
                'name' => 'Streak Potion',
                'icon' => 'S',
                'price' => 300,
                'desc' => 'Streak-Multiplikator wächst deutlich schneller.',
            ],
            'closecall' => [
                'name' => 'Close Call Potion',
                'icon' => '1',
                'price' => 210,
                'desc' => 'Abstand 1: +60 Punkte, +15 Geld.',
            ],
            'doubledice' => [
                'name' => 'Double Dice Potion',
                'icon' => 'II',
                'price' => 170,
                'desc' => 'Mit 2 Würfeln: +14 Punkte, +8 Geld.',
            ],
            'singledice' => [
                'name' => 'Single Dice Potion',
                'icon' => 'I',
                'price' => 170,
                'desc' => 'Mit 1 Würfel: +16 Punkte, +10 Geld.',
            ],
            'lucky' => [
                'name' => 'Lucky Potion',
                'icon' => '~',
                'price' => 260,
                'desc' => '25% Chance, die Distanz nach dem Wurf um 1 zu senken.',
            ],
            'golden' => [
                'name' => 'Golden Potion',
                'icon' => '$',
                'price' => 220,
                'desc' => 'Alle Geldbelohnungen +20%.',
            ],
            'score' => [
                'name' => 'Score Potion',
                'icon' => '#',
                'price' => 250,
                'desc' => 'Alle Punkte +15%.',
            ],
            'risk' => [
                'name' => 'Risk Potion',
                'icon' => '!!',
                'price' => 270,
                'desc' => 'Abstand 0-1: +60% Punkte. Abstand ab 3: -60% Punkte.',
            ],
            'hightarget' => [
                'name' => 'High Target Potion',
                'icon' => 'H+',
                'price' => 180,
                'desc' => 'Zielzahl ab 8: +20% Punkte und Geld.',
            ],
            'lowtarget' => [
                'name' => 'Low Target Potion',
                'icon' => 'L+',
                'price' => 180,
                'desc' => 'Zielzahl bis 5: +20% Punkte und Geld.',
            ],
        ];
    }
}
