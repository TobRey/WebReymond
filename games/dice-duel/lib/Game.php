<?php
declare(strict_types=1);

require_once __DIR__ . '/Content.php';

/**
 * Komplette Spiellogik. Alles Zufällige passiert serverseitig,
 * der Client bekommt nur fertige Ergebnisse zum Animieren.
 */
final class Game
{
    public const TOTAL_TURNS = Content::TURNS_PER_PLAYER * 2;

    private const POINTS = [0 => 120, 1 => 55, 2 => 22, 3 => 10, 4 => 5];
    private const MONEY = [0 => 70, 1 => 34, 2 => 14, 3 => 7, 4 => 4];
    private const POINTS_FAR = 2;
    private const MONEY_FAR = 2;

    /* ---------------------------------------------------------------- Raum */

    /** @return array<string, mixed> */
    public static function newRoom(string $code, string $hostName, string $hostToken): array
    {
        return [
            'code' => $code,
            'version' => 1,
            'createdAt' => time(),
            'updatedAt' => time(),
            'status' => 'lobby',
            'players' => [self::newPlayer($hostName, $hostToken, 0)],
            'turnIndex' => 0,
            'targets' => [],
            'phase' => 'select',
            'pendingTarget' => null,
            'lastRoll' => null,
            'log' => [],
            'winner' => null,
            'rematchVotes' => [],
        ];
    }

    /** @return array<string, mixed> */
    public static function newPlayer(string $name, string $token, int $slot): array
    {
        return [
            'slot' => $slot,
            'name' => $name,
            'token' => $token,
            'score' => 0,
            'money' => Content::START_MONEY,
            'streak' => 0,
            'bestStreak' => 0,
            'perfects' => 0,
            'turnsPlayed' => 0,
            'dice' => ['basic#1', 'basic#2'],
            'potions' => [],
            'lastSeen' => time(),
        ];
    }

    /**
     * Zugreihenfolge: A B B A A B B A ... damit nie derselbe Spieler
     * dauerhaft zuerst eine Zielzahl auswählen darf.
     */
    public static function slotForTurn(int $turnIndex): int
    {
        return intdiv($turnIndex + 1, 2) % 2;
    }

    /** @return list<array<string, mixed>> */
    public static function rollTargets(): array
    {
        $pool = range(2, 12);
        shuffle($pool);
        $targets = [];
        foreach (array_slice($pool, 0, 4) as $value) {
            $targets[] = [
                'value' => $value,
                'enchanted' => random_int(1, 1000) <= (int) round(Content::ENCHANT_CHANCE * 1000),
                'takenBy' => null,
            ];
        }
        usort($targets, static fn(array $a, array $b): int => $a['value'] <=> $b['value']);
        return $targets;
    }

    /** @param array<string, mixed> $room */
    public static function startMatch(array &$room): void
    {
        $room['status'] = 'playing';
        $room['turnIndex'] = 0;
        $room['phase'] = 'select';
        $room['pendingTarget'] = null;
        $room['targets'] = self::rollTargets();
        $room['lastRoll'] = null;
        $room['winner'] = null;
        $room['rematchVotes'] = [];
        $room['log'] = [self::logEntry(null, 'Match gestartet - viel Glück!')];
    }

    /* -------------------------------------------------------------- Aktionen */

    /**
     * @param array<string, mixed> $room
     * @return array{ok: bool, error?: string}
     */
    public static function selectTarget(array &$room, int $slot, int $index): array
    {
        if (($room['status'] ?? '') !== 'playing') {
            return ['ok' => false, 'error' => 'Das Match läuft gerade nicht.'];
        }
        if (self::slotForTurn((int) $room['turnIndex']) !== $slot) {
            return ['ok' => false, 'error' => 'Du bist nicht am Zug.'];
        }
        if (($room['phase'] ?? '') !== 'select') {
            return ['ok' => false, 'error' => 'Die Zielzahl steht bereits fest.'];
        }
        if (!isset($room['targets'][$index])) {
            return ['ok' => false, 'error' => 'Diese Zielzahl gibt es nicht.'];
        }
        if ($room['targets'][$index]['takenBy'] !== null) {
            return ['ok' => false, 'error' => 'Diese Zielzahl ist schon vergeben.'];
        }

        $room['targets'][$index]['takenBy'] = $slot;
        $room['pendingTarget'] = $index;
        $room['phase'] = 'roll';
        return ['ok' => true];
    }

    /**
     * @param array<string, mixed> $room
     * @param list<string> $diceIds
     * @return array{ok: bool, error?: string, roll?: array<string, mixed>}
     */
    public static function roll(array &$room, int $slot, array $diceIds): array
    {
        if (($room['status'] ?? '') !== 'playing') {
            return ['ok' => false, 'error' => 'Das Match läuft gerade nicht.'];
        }
        if (self::slotForTurn((int) $room['turnIndex']) !== $slot) {
            return ['ok' => false, 'error' => 'Du bist nicht am Zug.'];
        }
        if (($room['phase'] ?? '') !== 'roll' || $room['pendingTarget'] === null) {
            return ['ok' => false, 'error' => 'Wähle zuerst eine Zielzahl.'];
        }

        $player = &$room['players'][$slot];
        $diceIds = array_values(array_unique($diceIds));
        if (count($diceIds) < 1 || count($diceIds) > 2) {
            return ['ok' => false, 'error' => 'Wähle 1 oder 2 Würfel.'];
        }
        foreach ($diceIds as $id) {
            if (!in_array($id, $player['dice'], true)) {
                return ['ok' => false, 'error' => 'Diesen Würfel besitzt du nicht.'];
            }
        }

        $targetIndex = (int) $room['pendingTarget'];
        $target = $room['targets'][$targetIndex];
        $result = self::resolveRoll($room, $slot, $diceIds, $target);

        $player['score'] += $result['points'];
        $player['money'] += $result['money'];
        $player['streak'] = $result['streak'];
        $player['bestStreak'] = max((int) $player['bestStreak'], $result['streak']);
        $player['turnsPlayed'] = (int) $player['turnsPlayed'] + 1;
        if ($result['distance'] === 0) {
            $player['perfects'] = (int) $player['perfects'] + 1;
        }
        unset($player);

        $result['seq'] = (int) ($room['version'] ?? 0) + 1;
        $room['lastRoll'] = $result;
        $room['log'][] = self::logEntry($slot, self::describe($room, $slot, $result));
        $room['log'] = array_slice($room['log'], -20);

        self::advanceTurn($room);
        return ['ok' => true, 'roll' => $result];
    }

    /**
     * @param array<string, mixed> $room
     * @return array{ok: bool, error?: string}
     */
    public static function buy(array &$room, int $slot, string $kind, string $id): array
    {
        if (($room['status'] ?? '') !== 'playing') {
            return ['ok' => false, 'error' => 'Der Shop ist nur während des Matches offen.'];
        }
        $player = &$room['players'][$slot];

        if ($kind === 'die') {
            $catalog = Content::dice();
            if (!isset($catalog[$id]) || $id === 'basic') {
                return ['ok' => false, 'error' => 'Unbekannter Würfel.'];
            }
            if (in_array($id, $player['dice'], true)) {
                return ['ok' => false, 'error' => 'Diesen Würfel besitzt du bereits.'];
            }
            $price = (int) $catalog[$id]['price'];
            if ((int) $player['money'] < $price) {
                return ['ok' => false, 'error' => 'Nicht genug Geld.'];
            }
            $player['money'] = (int) $player['money'] - $price;
            $player['dice'][] = $id;
            $label = $catalog[$id]['name'];
        } elseif ($kind === 'potion') {
            $catalog = Content::potions();
            if (!isset($catalog[$id])) {
                return ['ok' => false, 'error' => 'Unbekannter Trank.'];
            }
            if (in_array($id, $player['potions'], true)) {
                return ['ok' => false, 'error' => 'Dieser Trank ist bereits aktiv.'];
            }
            $price = (int) $catalog[$id]['price'];
            if ((int) $player['money'] < $price) {
                return ['ok' => false, 'error' => 'Nicht genug Geld.'];
            }
            $player['money'] = (int) $player['money'] - $price;
            $player['potions'][] = $id;
            $label = $catalog[$id]['name'];
        } else {
            return ['ok' => false, 'error' => 'Unbekannte Kategorie.'];
        }

        $name = $player['name'];
        unset($player);
        $room['log'][] = self::logEntry($slot, $name . ' kauft ' . $label . '.');
        $room['log'] = array_slice($room['log'], -20);
        return ['ok' => true];
    }

    /* ---------------------------------------------------------------- Wurf */

    /**
     * @param array<string, mixed> $room
     * @param list<string> $diceIds
     * @param array<string, mixed> $target
     * @return array<string, mixed>
     */
    private static function resolveRoll(array $room, int $slot, array $diceIds, array $target): array
    {
        $player = $room['players'][$slot];
        $potions = $player['potions'];
        $catalog = Content::dice();
        $targetValue = (int) $target['value'];
        $count = count($diceIds);

        $dice = [];
        $types = [];
        foreach ($diceIds as $id) {
            $type = self::dieType($id);
            $types[] = $type;
            $faces = $catalog[$type]['faces'];
            $faceIndex = random_int(0, count($faces) - 1);
            $value = (int) $faces[$faceIndex];
            $note = null;

            if ($type === 'reroll' && random_int(1, 100) <= 35) {
                $faceIndex = random_int(0, count($faces) - 1);
                $value = (int) $faces[$faceIndex];
                $note = 'Reroll';
            }
            if ($type === 'mirror' && random_int(1, 100) <= 45) {
                $value = 7 - $value;
                $note = 'Gespiegelt';
            }
            if ($type === 'double' && $value === 6) {
                $value = 12;
                $note = 'Doppelt';
            }

            $dice[] = ['id' => $id, 'type' => $type, 'value' => $value, 'note' => $note];
        }

        $effects = [];

        // Wild Die: seltene Seite passt sich der benötigten Zahl an.
        foreach ($dice as $i => $die) {
            if ($die['type'] !== 'wild' || random_int(1, 100) > 18) {
                continue;
            }
            $others = 0;
            foreach ($dice as $j => $other) {
                if ($j !== $i) {
                    $others += (int) $other['value'];
                }
            }
            $needed = max(1, min(6, $targetValue - $others));
            $dice[$i]['value'] = $needed;
            $dice[$i]['note'] = 'Wild';
            $effects[] = ['label' => 'Wild Die', 'detail' => 'Seite angepasst'];
            break;
        }

        $total = 0;
        foreach ($dice as $die) {
            $total += (int) $die['value'];
        }

        // Lucky Die: kleine Chance auf automatischen Perfect-Treffer.
        foreach ($dice as $i => $die) {
            if ($die['type'] === 'lucky' && $total !== $targetValue && random_int(1, 100) <= 14) {
                $total = $targetValue;
                $dice[$i]['note'] = 'Lucky';
                $effects[] = ['label' => 'Lucky Die', 'detail' => 'Automatischer Perfect'];
                break;
            }
        }

        // Magnet Die: zieht das Ergebnis um 1 Richtung Ziel.
        if (in_array('magnet', $types, true) && $total !== $targetValue && random_int(1, 100) <= 70) {
            $total += $total < $targetValue ? 1 : -1;
            $effects[] = ['label' => 'Magnet Die', 'detail' => '1 Richtung Ziel'];
        }

        // Lucky Potion: kleine Chance, die Distanz um 1 zu senken.
        if (in_array('lucky', $potions, true) && $total !== $targetValue && random_int(1, 100) <= 25) {
            $total += $total < $targetValue ? 1 : -1;
            $effects[] = ['label' => 'Lucky Potion', 'detail' => 'Distanz -1'];
        }

        $distance = abs($total - $targetValue);
        $points = (float) (self::POINTS[$distance] ?? self::POINTS_FAR);
        $money = (float) (self::MONEY[$distance] ?? self::MONEY_FAR);

        // Streak
        $streak = $distance === 0 ? (int) $player['streak'] + 1 : 0;
        $streakMult = 1.0;
        if ($distance === 0 && $streak > 1) {
            $fast = in_array('streak', $potions, true);
            $streakMult = min(1.0 + ($fast ? 1.2 : 0.8) * ($streak - 1), $fast ? 7.0 : 5.0);
            $points *= $streakMult;
            $effects[] = [
                'label' => 'Streak x' . $streak,
                'detail' => 'Punkte x' . number_format($streakMult, 2, ',', ''),
            ];
        }

        $has = static fn(string $p): bool => in_array($p, $potions, true);

        // Flache Trank-Boni
        if ($has('even') && $total % 2 === 0) {
            $points += 18;
            $money += 10;
            $effects[] = ['label' => 'Even Potion', 'detail' => '+18 Punkte, +10 Geld'];
        }
        if ($has('odd') && $total % 2 === 1) {
            $points += 18;
            $money += 10;
            $effects[] = ['label' => 'Odd Potion', 'detail' => '+18 Punkte, +10 Geld'];
        }
        if ($has('closecall') && $distance === 1) {
            $points += 60;
            $money += 15;
            $effects[] = ['label' => 'Close Call Potion', 'detail' => '+60 Punkte, +15 Geld'];
        }
        if ($has('doubledice') && $count === 2) {
            $points += 14;
            $money += 8;
            $effects[] = ['label' => 'Double Dice Potion', 'detail' => '+14 Punkte, +8 Geld'];
        }
        if ($has('singledice') && $count === 1) {
            $points += 16;
            $money += 10;
            $effects[] = ['label' => 'Single Dice Potion', 'detail' => '+16 Punkte, +10 Geld'];
        }
        if ($has('perfect') && $distance === 0) {
            $money += 45;
            $effects[] = ['label' => 'Perfect Potion', 'detail' => '+45 Geld'];
        }

        // Multiplikative Effekte
        if ($has('risk')) {
            if ($distance <= 1) {
                $points *= 1.6;
                $effects[] = ['label' => 'Risk Potion', 'detail' => 'Punkte x1,6'];
            } elseif ($distance >= 3) {
                $points *= 0.4;
                $effects[] = ['label' => 'Risk Potion', 'detail' => 'Punkte x0,4'];
            }
        }
        if ($has('hightarget') && $targetValue >= 8) {
            $points *= 1.2;
            $money *= 1.2;
            $effects[] = ['label' => 'High Target Potion', 'detail' => '+20%'];
        }
        if ($has('lowtarget') && $targetValue <= 5) {
            $points *= 1.2;
            $money *= 1.2;
            $effects[] = ['label' => 'Low Target Potion', 'detail' => '+20%'];
        }
        if ($has('score')) {
            $points *= 1.15;
            $effects[] = ['label' => 'Score Potion', 'detail' => 'Punkte +15%'];
        }
        if ($has('over') && $total > $targetValue) {
            $money *= 1.5;
            $effects[] = ['label' => 'Over Potion', 'detail' => 'Geld +50%'];
        }
        if ($has('under') && $total < $targetValue) {
            $money *= 1.5;
            $effects[] = ['label' => 'Under Potion', 'detail' => 'Geld +50%'];
        }
        if ($has('golden')) {
            $money *= 1.2;
            $effects[] = ['label' => 'Golden Potion', 'detail' => 'Geld +20%'];
        }
        if (in_array('golden', $types, true)) {
            $money *= 1.4;
            $effects[] = ['label' => 'Golden Die', 'detail' => 'Geld +40%'];
        }

        $enchantHit = ((bool) $target['enchanted']) && $distance === 0;
        if ($enchantHit) {
            $money *= 2.0;
            $effects[] = ['label' => 'Verzauberte Zahl', 'detail' => 'Cash x2'];
        }

        return [
            'slot' => $slot,
            'turnIndex' => (int) $room['turnIndex'],
            'target' => $targetValue,
            'enchanted' => (bool) $target['enchanted'],
            'enchantHit' => $enchantHit,
            'dice' => $dice,
            'total' => $total,
            'distance' => $distance,
            'perfect' => $distance === 0,
            'points' => (int) round($points),
            'money' => (int) round($money),
            'streak' => $streak,
            'streakMult' => round($streakMult, 2),
            'effects' => $effects,
        ];
    }

    /* -------------------------------------------------------------- Ablauf */

    /** @param array<string, mixed> $room */
    private static function advanceTurn(array &$room): void
    {
        $room['turnIndex'] = (int) $room['turnIndex'] + 1;
        $room['pendingTarget'] = null;
        $room['phase'] = 'select';

        if ((int) $room['turnIndex'] >= self::TOTAL_TURNS) {
            $room['status'] = 'finished';
            $a = (int) $room['players'][0]['score'];
            $b = (int) $room['players'][1]['score'];
            $room['winner'] = $a === $b ? -1 : ($a > $b ? 0 : 1);
            $room['log'][] = self::logEntry(null, 'Runde beendet.');
            return;
        }

        // Nach jedem Zugpaar werden die 4 Zielzahlen komplett neu ausgelost.
        if ((int) $room['turnIndex'] % 2 === 0) {
            $room['targets'] = self::rollTargets();
        }
    }

    /**
     * @param array<string, mixed> $room
     * @param array<string, mixed> $roll
     */
    private static function describe(array $room, int $slot, array $roll): string
    {
        $name = $room['players'][$slot]['name'];
        $dice = implode(' + ', array_map(static fn(array $d): string => (string) $d['value'], $roll['dice']));
        $suffix = $roll['perfect']
            ? ' PERFECT' . ($roll['streak'] > 1 ? ' x' . $roll['streak'] : '')
            : ' (Abstand ' . $roll['distance'] . ')';
        return $name . ': Ziel ' . $roll['target'] . ' | ' . $dice . ' = ' . $roll['total'] . $suffix
            . ' | +' . $roll['points'] . ' P, +' . $roll['money'] . ' $';
    }

    /** @return array<string, mixed> */
    private static function logEntry(?int $slot, string $text): array
    {
        return ['slot' => $slot, 'text' => $text, 'at' => time()];
    }

    public static function dieType(string $id): string
    {
        $pos = strpos($id, '#');
        return $pos === false ? $id : substr($id, 0, $pos);
    }

    /* ---------------------------------------------------------------- View */

    /**
     * Oeffentliche Sicht auf den Raum - ohne Tokens der Mitspieler.
     *
     * @param array<string, mixed> $room
     * @return array<string, mixed>
     */
    public static function view(array $room, int $viewerSlot): array
    {
        $players = [];
        foreach ($room['players'] as $p) {
            $players[] = [
                'slot' => (int) $p['slot'],
                'name' => (string) $p['name'],
                'score' => (int) $p['score'],
                'money' => (int) $p['money'],
                'streak' => (int) $p['streak'],
                'bestStreak' => (int) $p['bestStreak'],
                'perfects' => (int) $p['perfects'],
                'turnsPlayed' => (int) $p['turnsPlayed'],
                'dice' => array_values($p['dice']),
                'potions' => array_values($p['potions']),
                'online' => (time() - (int) $p['lastSeen']) < 20,
            ];
        }

        return [
            'code' => $room['code'],
            'version' => (int) $room['version'],
            'status' => $room['status'],
            'players' => $players,
            'you' => $viewerSlot,
            'turnIndex' => (int) $room['turnIndex'],
            'totalTurns' => self::TOTAL_TURNS,
            'turnsPerPlayer' => Content::TURNS_PER_PLAYER,
            'currentSlot' => $room['status'] === 'playing' ? self::slotForTurn((int) $room['turnIndex']) : null,
            'phase' => $room['phase'],
            'pendingTarget' => $room['pendingTarget'],
            'targets' => $room['targets'],
            'lastRoll' => $room['lastRoll'],
            'log' => array_slice($room['log'], -12),
            'winner' => $room['winner'],
            'rematchVotes' => array_values($room['rematchVotes'] ?? []),
        ];
    }
}
