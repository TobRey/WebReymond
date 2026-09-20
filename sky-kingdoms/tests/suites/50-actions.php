<?php

declare(strict_types=1);

use SkyKingdoms\Game\Actions;
use SkyKingdoms\Game\Economy;
use SkyKingdoms\Game\Formulas;
use SkyKingdoms\Game\Logistics;
use SkyKingdoms\Game\World;

return function (): void {
    Test::suite('Spielhandlungen: Bauen, Verbessern, Routen');

    TestEnv::freshStore();
    $world = World::create('Prüfreich');

    $resourceIsland = null;
    $mainIsland = null;
    $lumber = null;
    foreach ($world['islands'] as $id => $island) {
        if ($island['type'] === 'resource') { $resourceIsland = $id; }
        if ($island['type'] === 'main') { $mainIsland = $id; }
    }
    foreach ($world['buildings'] as $id => $building) {
        if ($building['type'] === 'lumberjack') { $lumber = $id; }
    }

    // --- Bauen --------------------------------------------------------
    $result = Actions::build($world, $resourceIsland, 'lumberjack', 2, 5);
    Test::ok($result['ok'], 'Gebäude auf freiem Feld errichtet');
    $world = $result['world'];

    $blocked = Actions::build($world, $resourceIsland, 'lumberjack', 2, 5);
    Test::ok(!$blocked['ok'], 'Besetztes Feld wird abgelehnt');

    $outside = Actions::build($world, $resourceIsland, 'lumberjack', 50, 50);
    Test::ok(!$outside['ok'], 'Feld ausserhalb der Insel wird abgelehnt');

    $wrong = Actions::build($world, $resourceIsland, 'grain_farm', 1, 1);
    Test::ok(!$wrong['ok'], 'Falscher Inseltyp wird abgelehnt');

    $secondCastle = Actions::build($world, $mainIsland, 'castle', 1, 1);
    Test::ok(!$secondCastle['ok'], 'Eine zweite Burg ist nicht erlaubt');

    $locked = Actions::build($world, $resourceIsland, 'crystal_mine', 1, 1);
    Test::ok(!$locked['ok'], 'Gesperrtes Gebäude ohne Forschung wird abgelehnt');
    Test::ok(str_contains((string) $locked['error'], 'Bergbau'), 'Die Meldung nennt die fehlende Forschung');

    // --- Verbessern ----------------------------------------------------
    $before = Economy::available($world);
    $up = Actions::upgradeBuilding($world, $lumber, 1);
    Test::ok($up['ok'], 'Verbesserung um eine Stufe gelingt');
    Test::eq($up['level'], 2, 'Die Stufe steigt um genau eins');
    $world = $up['world'];
    Test::ok(Economy::available($world)['wood'] < $before['wood'], 'Die Kosten wurden abgebucht');

    $tooMuch = Actions::upgradeBuilding($world, $lumber, 100);
    Test::ok(!$tooMuch['ok'], 'Unbezahlbare Mehrfachverbesserung wird abgelehnt');
    Test::ok(!empty($tooMuch['missing']), 'Fehlende Rohstoffe werden benannt');

    $absurd = Actions::upgradeBuilding($world, $lumber, 5000);
    Test::ok(!$absurd['ok'], '5000 Stufen auf einmal werden abgelehnt');
    Test::ok(str_contains((string) $absurd['error'], 'Zahlenbereich'),
        'Die Meldung erklärt den sicheren Zahlenbereich');
    Test::eq($world['buildings'][$lumber]['level'], 2, 'Nach einer Ablehnung bleibt die Stufe unverändert');

    $unknown = Actions::upgradeBuilding($world, 'b99999', 1);
    Test::ok(!$unknown['ok'], 'Unbekanntes Gebäude wird abgelehnt');

    // Alles-oder-nichts: Ein fehlender Posten bucht gar nichts ab
    $poor = $world;
    $poor['store'] = ['wood' => 1000000, 'stone' => 0];
    $paid = Economy::pay($poor, ['wood' => 100, 'stone' => 100]);
    Test::ok(!$paid, 'Fehlt ein Posten, schlägt die Buchung fehl');
    Test::eq((int) $poor['store']['wood'], 1000000, 'Bei fehlgeschlagener Buchung wird nichts abgezogen');

    // MAX-Verbesserung
    $rich = $world;
    $rich['store'] = ['wood' => 500000, 'stone' => 500000, 'tools' => 500000, 'iron' => 500000,
        'gold' => 200000, 'grain' => 200000, 'bread' => 200000];
    $max = Actions::upgradeBuilding($rich, $lumber, 'max');
    Test::ok($max['ok'], 'MAX-Verbesserung gelingt bei vollem Lager');
    Test::greater((float) $max['steps'], 10.0, 'MAX verbessert um viele Stufen auf einmal (' . $max['steps'] . ')');
    $leftover = Economy::available($max['world']);
    $nextCost = Actions::buildingUpgradeCost($max['world']['buildings'][$lumber], 1);
    Test::ok(!Formulas::canAfford($nextCost, $leftover), 'Nach MAX ist keine weitere Stufe mehr bezahlbar');

    // --- Routen ---------------------------------------------------------
    $budget = Logistics::routeBudget($world);
    Test::eq($budget['used'], 3, 'Die Startwelt nutzt drei Routen');

    $newBuilding = $result['building'];
    $route = Actions::createRoute($rich, ['b', $newBuilding], ['store'], 'wood');
    Test::ok($route['ok'], 'Route vom Holzfällerlager zum Lager angelegt');

    $wrongWare = Actions::createRoute($rich, ['b', $newBuilding], ['store'], 'crystal');
    Test::ok(!$wrongWare['ok'], 'Route für eine nicht erzeugte Ware wird abgelehnt');

    $sameEnds = Actions::createRoute($rich, ['store'], ['store'], 'wood');
    Test::ok(!$sameEnds['ok'], 'Route von Lager zu Lager wird abgelehnt');

    // Routenplätze sind begrenzt
    $full = $route['world'];
    $guard = 0;
    while ($guard++ < 50) {
        $attempt = Actions::createRoute($full, ['b', $newBuilding], ['store'], 'wood');
        if (!$attempt['ok']) { break; }
        $full = $attempt['world'];
    }
    Test::ok($guard < 50, 'Die Zahl der Routen ist begrenzt');

    // --- Brücken ---------------------------------------------------------
    $bridge = Actions::buildBridge($rich, $mainIsland, $resourceIsland);
    Test::ok($bridge['ok'], 'Neue Brücke gebaut');
    $twice = Actions::buildBridge($bridge['world'], $mainIsland, $resourceIsland);
    Test::ok(!$twice['ok'], 'Eine zweite Brücke zwischen denselben Inseln wird abgelehnt');
    $self = Actions::buildBridge($rich, $mainIsland, $mainIsland);
    Test::ok(!$self['ok'], 'Brücke zu sich selbst wird abgelehnt');

    // --- Forschung --------------------------------------------------------
    $research = Actions::research($rich, 'logistics', 1);
    Test::ok($research['ok'], 'Forschung gelingt');
    Test::eq($research['level'], 1, 'Forschungsstufe steigt auf 1');
    $blockedResearch = Actions::research($rich, 'aether_lore', 1);
    Test::ok(!$blockedResearch['ok'], 'Gesperrte Forschung wird abgelehnt');

    // --- Abreissen --------------------------------------------------------
    $demolish = Actions::demolish($world, $newBuilding);
    Test::ok($demolish['ok'], 'Gebäude abgerissen');
    Test::ok(!isset($demolish['world']['buildings'][$newBuilding]), 'Das Gebäude ist verschwunden');
    $castleId = null;
    foreach ($world['buildings'] as $id => $building) {
        if ($building['type'] === 'castle') { $castleId = $id; }
    }
    Test::ok(!Actions::demolish($world, $castleId)['ok'], 'Die Burg kann nicht abgerissen werden');

    // --- Lagergrenze --------------------------------------------------------
    $capped = $world;
    $capacity = (int) World::capacity($capped)['bulk'];
    $capped['store'] = ['wood' => $capacity];
    $credit = Economy::credit($capped, ['stone' => 5000]);
    Test::eq($credit['stored'], [], 'Bei vollem Lager wird nichts mehr eingelagert');
    Test::eq($credit['lost']['stone'], 5000, 'Die überzählige Menge wird als Verlust gemeldet');
};
