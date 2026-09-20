<?php

declare(strict_types=1);

use SkyKingdoms\Core\App;
use SkyKingdoms\Game\Combat\Mission;
use SkyKingdoms\Game\Combat\Rng;
use SkyKingdoms\Game\World;

return function (): void {
    Test::suite('Kampf: Nachvollziehbarkeit und Manipulationsschutz');

    TestEnv::freshStore();

    // --- Zufallsgenerator -------------------------------------------
    $a = new Rng(12345);
    $b = new Rng(12345);
    $sequenceA = [];
    $sequenceB = [];
    for ($i = 0; $i < 20; $i++) {
        $sequenceA[] = $a->next();
        $sequenceB[] = $b->next();
    }
    Test::eq($sequenceA, $sequenceB, 'Gleicher Startwert liefert dieselbe Zahlenfolge');
    Test::ok((new Rng(999))->next() !== $sequenceA[0], 'Anderer Startwert liefert andere Zahlen');

    $rng = new Rng(7);
    $inRange = true;
    for ($i = 0; $i < 200; $i++) {
        $value = $rng->float();
        if ($value < 0 || $value >= 1) { $inRange = false; }
    }
    Test::ok($inRange, 'Kommazahlen liegen immer zwischen 0 und 1');

    // --- Verteidiger mit echter Verteidigung --------------------------
    $defender = World::create('Festung');
    $islandId = array_key_first($defender['islands']);
    foreach ([['tower', 2, 2], ['tower', 4, 2], ['wall', 6, 2], ['wall', 6, 3], ['patrol_post', 8, 4]] as [$type, $x, $y]) {
        World::addBuilding($defender, $islandId, $type, $x, $y, 5);
    }

    $setup = Mission::setup($defender, 'loot_storage', 4242);
    Test::greater((float) count($setup['towers']), 0.0, 'Aus Türmen entstehen Turmstellungen');
    Test::greater((float) count($setup['walls']), 0.0, 'Aus Mauern entstehen Hindernisse');
    Test::eq(count($setup['patrols']), 1, 'Aus dem Wachposten entsteht eine Patrouille');

    $same = Mission::setup($defender, 'loot_storage', 4242);
    Test::eq(json_encode($same), json_encode($setup), 'Gleicher Startwert ergibt denselben Aufbau (Wiederholung möglich)');

    $other = Mission::setup($defender, 'loot_storage', 4243);
    Test::ok(json_encode($other) !== json_encode($setup), 'Anderer Startwert ergibt einen anderen Aufbau');

    // --- Truppe -------------------------------------------------------
    $attacker = World::create('Angreifer');
    $attacker['units'] = ['spearman' => 6, 'raider' => 4];
    $attacker['unit_levels'] = ['spearman' => 3];

    $squad = Mission::buildSquad($attacker, ['spearman' => 6, 'raider' => 4]);
    Test::eq(count($squad), 10, 'Die Truppe hat die gewünschte Grösse');

    $tooMany = Mission::buildSquad($attacker, ['spearman' => 500]);
    Test::eq(count($tooMany), 6, 'Es können nie mehr Einheiten mitgenommen werden als vorhanden');

    $cheated = Mission::buildSquad($attacker, ['knight' => 50]);
    Test::eq(count($cheated), 0, 'Nicht vorhandene Einheiten werden ignoriert');

    $capped = Mission::buildSquad(array_merge($attacker, ['units' => ['spearman' => 999]]), ['spearman' => 999]);
    Test::eq(count($capped), (int) App::balance('combat.max_squad', 20), 'Die Truppengrösse ist begrenzt');

    // --- Ablauf ---------------------------------------------------------
    $actions = [
        ['t' => 5,  'a' => 'lane',    'v' => '0'],
        ['t' => 30, 'a' => 'ability', 'v' => 'sprint'],
        ['t' => 90, 'a' => 'ability', 'v' => 'shield'],
    ];

    $first  = Mission::run($setup, $squad, $actions);
    $second = Mission::run($setup, $squad, $actions);
    Test::eq($first['progress'], $second['progress'], 'Dieselben Entscheidungen führen zum selben Ergebnis');
    Test::eq($first['ticks'], $second['ticks'], 'Auch die Dauer ist reproduzierbar');
    Test::ok($first['progress'] > 0, 'Die Truppe kommt voran');
    Test::ok(in_array($first['reason'], ['success', 'defeated', 'timeout'], true), 'Es gibt ein eindeutiges Ergebnis');

    // Andere Entscheidungen führen zu anderen Ergebnissen
    $different = Mission::run($setup, $squad, [['t' => 5, 'a' => 'lane', 'v' => '2']]);
    Test::ok($different['ticks'] !== $first['ticks'] || $different['progress'] !== $first['progress'],
        'Andere Entscheidungen verändern den Verlauf');

    // --- Manipulationsversuche --------------------------------------------
    $spam = [];
    for ($i = 0; $i < 400; $i++) {
        $spam[] = ['t' => $i, 'a' => 'ability', 'v' => 'sprint'];
    }
    $spamResult = Mission::run($setup, $squad, $spam);
    Test::ok($spamResult['progress'] <= 1.0, 'Dauerfeuer an Fähigkeiten sprengt den Fortschritt nicht');

    $unlimited = Mission::run($setup, $squad, array_map(
        static fn (int $i): array => ['t' => $i * 2, 'a' => 'ability', 'v' => 'sprint'],
        range(0, 199)
    ));
    Test::ok($unlimited['ticks'] >= $first['ticks'] - 200, 'Abklingzeiten verhindern Dauerbeschleunigung');

    $garbage = Mission::run($setup, $squad, [
        ['t' => -500, 'a' => 'lane', 'v' => '99'],
        ['t' => 999999, 'a' => 'unsinn', 'v' => 'x'],
        ['t' => 10, 'a' => 'ability', 'v' => 'gibtesnicht'],
    ]);
    Test::ok(isset($garbage['progress']), 'Unsinnige Aktionen werden verworfen, nicht übernommen');

    $noActions = Mission::run($setup, $squad, []);
    Test::ok($noActions['progress'] > 0, 'Auch ohne Eingaben läuft die Mission ab');

    // --- Ohne Verteidigung ist es leicht, mit sehr viel schwerer ------------
    $empty = Mission::setup(World::create('Wehrlos'), 'loot_storage', 4242);
    $easy  = Mission::run($empty, $squad, []);
    Test::ok($easy['success'], 'Ohne Verteidigung erreicht die Truppe das Ziel');
    Test::eq($easy['losses'], [], 'Ohne Verteidigung gibt es keine Verluste');

    $strong = World::create('Bollwerk');
    for ($i = 0; $i < 9; $i++) {
        World::addBuilding($strong, array_key_first($strong['islands']), 'tower', $i % 9, 2 + intdiv($i, 9), 60);
    }
    $hard = Mission::run(Mission::setup($strong, 'loot_storage', 99), $squad, []);
    Test::ok(!$hard['success'] || $hard['survivors'] < count($squad),
        'Gegen starke Verteidigung wird es teuer');
};
