<?php

declare(strict_types=1);

use SkyKingdoms\Core\App;
use SkyKingdoms\Game\Logistics;
use SkyKingdoms\Game\Simulation;
use SkyKingdoms\Game\World;

return function (): void {
    Test::suite('Simulation: Produktion, Transport, Lager, Offline');

    TestEnv::freshStore();
    $world = World::create('Testreich');

    // --- Aufbau der Startwelt ---------------------------------------
    Test::eq(count($world['islands']), 4, 'Startwelt hat vier Inseln');
    Test::eq(count($world['bridges']), 3, 'Startwelt hat drei Brücken');
    Test::eq(count($world['routes']), 3, 'Startwelt hat drei Transportrouten');

    $effects = World::effects($world);
    Test::greater((float) $effects['population'], (float) $effects['workers_needed'], 'Zu Beginn gibt es genug Arbeiter');
    Test::greater($effects['capacity']['bulk'], 0.0, 'Es gibt Lagerplatz für Baustoffe');
    Test::greater((float) $effects['route_slots'], 3.0, 'Es ist mindestens ein Routenplatz frei');

    // --- Eine Minute produzieren -------------------------------------
    $woodBefore = (float) ($world['store']['wood'] ?? 0);
    $result = Simulation::advance($world, 60);
    $after  = $result['world'];
    $wood   = (float) ($after['store']['wood'] ?? 0);

    Test::near($wood - $woodBefore, 12.0, 1.5, 'Eine Minute liefert rund 12 Holz ins Lager');
    Test::greater((float) ($after['store']['stone'] ?? 0), 400.0, 'Auch Stein wird geliefert');
    Test::ok((float) ($after['store']['bread'] ?? 0) < 220.0, 'Die Bevölkerung verbraucht Brot');
    Test::ok($result['summary']['segments'] > 0, 'Die Berechnung verwendet Ereignisabschnitte');

    // --- Puffer läuft voll, wenn die Route fehlt ----------------------
    $isolated = $world;
    $isolated['routes'] = [];
    $long = Simulation::advance($isolated, 7200);
    $lumberId = null;
    foreach ($long['world']['buildings'] as $id => $building) {
        if ($building['type'] === 'lumberjack') {
            $lumberId = $id;
        }
    }
    $buffer = (float) ($long['world']['buildings'][$lumberId]['out']['wood'] ?? 0);
    Test::near($buffer, 240.0, 1.0, 'Ohne Abtransport läuft der Puffer bis zur Grenze voll');
    Test::eq((float) ($long['world']['store']['wood'] ?? 0), 600.0, 'Ohne Route kommt nichts im Lager an');

    $hasBufferNote = false;
    foreach ($long['summary']['notes'] as $note) {
        if ($note['type'] === 'buffer_full') {
            $hasBufferNote = true;
        }
    }
    Test::ok($hasBufferNote, 'Der volle Puffer wird als Engpass gemeldet');

    // --- Volles Lager bremst die Zulieferung --------------------------
    $full = $world;
    $capacity = World::capacity($full)['bulk'];
    $full['store']['wood'] = (int) $capacity;
    $full['store']['stone'] = 0;
    $fullResult = Simulation::advance($full, 3600);
    $storedClass = World::storedByClass($fullResult['world'])['bulk'];
    Test::ok($storedClass <= $capacity + 1.0, 'Die Lagergrenze wird nie überschritten');

    $hasStorageNote = false;
    foreach ($fullResult['summary']['notes'] as $note) {
        if ($note['type'] === 'storage_full') {
            $hasStorageNote = true;
        }
    }
    Test::ok($hasStorageNote, 'Das volle Lager wird als Engpass gemeldet');

    // --- Unterbrochene Route ------------------------------------------
    $broken = $world;
    $broken['bridges'] = [];
    $plan = Logistics::plan($broken);
    $anyBroken = false;
    foreach ($plan['routes'] as $route) {
        if (!$route['ok'] && str_contains($route['note'], 'Brücke')) {
            $anyBroken = true;
        }
    }
    Test::ok($anyBroken, 'Ohne Brücke meldet die Route eine fehlende Verbindung');

    // --- Überlastete Brücke -------------------------------------------
    $jam = $world;
    foreach ($jam['routes'] as $id => $route) {
        $jam['routes'][$id]['carriers'] = 400;
    }
    $jamPlan = Logistics::plan($jam);
    $jammed = false;
    foreach ($jamPlan['routes'] as $route) {
        if ($route['jam_factor'] < 0.9) {
            $jammed = true;
        }
    }
    Test::ok($jammed, 'Zu viel Verkehr erzeugt einen Stau auf der Brücke');
    $bridgeFactors = array_column($jamPlan['bridges'], 'factor');
    Test::ok(min($bridgeFactors) < 1.0, 'Die überlastete Brücke meldet einen Drosselfaktor');

    // --- Offline-Grenze --------------------------------------------------
    App::setConfig('max_offline_seconds', 3600);
    $old = $world;
    $old['last_tick'] = time() - 86400 * 3;
    $ticked = Simulation::tick($old);
    Test::eq($ticked['summary']['simulated'], 3600, 'Offline-Zeit wird auf das Maximum begrenzt');
    Test::greater((float) $ticked['summary']['skipped'], 0.0, 'Die übersprungene Zeit wird ausgewiesen');
    Test::eq($ticked['world']['last_tick'] <= time(), true, 'Der Zeitstempel wird auf jetzt gesetzt');
    App::setConfig('max_offline_seconds', 86400);

    // --- Nachvollziehbarkeit ---------------------------------------------
    $a = Simulation::advance($world, 600)['world']['store'];
    $b = Simulation::advance($world, 600)['world']['store'];
    Test::eq($a, $b, 'Dieselbe Ausgangslage ergibt dasselbe Ergebnis');

    // --- Begrenzte Rechenzeit ---------------------------------------------
    $start = microtime(true);
    Simulation::advance($world, 86400);
    $duration = microtime(true) - $start;
    Test::ok($duration < 1.5, 'Ein ganzer Tag Offline-Zeit rechnet in unter 1,5 Sekunden (' . round($duration * 1000) . ' ms)');

    // --- Momentaufnahme für die Oberfläche --------------------------------
    $snapshot = Simulation::snapshot($world);
    Test::greater((float) ($snapshot['production']['wood'] ?? 0), 0.0, 'Die Momentaufnahme kennt die Holzproduktion');
    Test::greater((float) ($snapshot['delivery']['wood'] ?? 0), 0.0, 'Die Momentaufnahme kennt die Anlieferung');
    Test::ok(isset($snapshot['consumption']['bread']), 'Die Momentaufnahme kennt den Brotverbrauch');
};
