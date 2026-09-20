<?php

declare(strict_types=1);

namespace SkyKingdoms\Game;

use SkyKingdoms\Core\App;

/**
 * Alle Spielhandlungen. Jede Handlung prüft selbst, ob sie erlaubt ist, und
 * gibt eine verständliche deutsche Fehlermeldung zurück.
 *
 * Wichtig für die Sicherheit: Diese Klasse vertraut KEINER Angabe aus dem
 * Browser. Kosten, Stufen und Mengen werden ausschliesslich hier berechnet.
 * Der Aufrufer (Api-Controller) hält währenddessen die Dateisperre des
 * Spielers, sodass zwei gleichzeitige Anfragen nicht doppelt bezahlen können.
 *
 * Es gibt nirgends eine Bau- oder Wartezeit: Wer bezahlen kann, baut sofort.
 */
final class Actions
{
    /** Erfolgreiches Ergebnis. */
    private static function ok(array $world, array $extra = []): array
    {
        return ['ok' => true, 'world' => $world] + $extra;
    }

    /** Fehlgeschlagenes Ergebnis – die Welt bleibt unverändert. */
    private static function fail(string $message, array $extra = []): array
    {
        return ['ok' => false, 'error' => $message] + $extra;
    }

    // =================================================================
    // Gebäude
    // =================================================================

    /** Kosten der nächsten $count Stufen eines Gebäudes. */
    public static function buildingUpgradeCost(array $building, int $count = 1): array
    {
        $def    = World::buildingDef((string) $building['type']);
        $base   = (array) ($def['cost'] ?? []);
        $growth = (float) ($def['cost_growth'] ?? Formulas::defaultCostGrowth());

        return Formulas::bulkCost($base, (int) $building['level'] + 1, max(1, $count), $growth);
    }

    /** Sind die Voraussetzungen für einen Gebäudetyp erfüllt? */
    public static function buildingRequirement(array $world, string $type): string
    {
        $def = World::buildingDef($type);
        if ($def === []) {
            return 'Unbekanntes Gebäude.';
        }

        $requires = (array) ($def['requires'] ?? []);

        if (isset($requires['castle'])) {
            $castleLevel = 0;
            foreach ($world['buildings'] as $building) {
                if ($building['type'] === 'castle') {
                    $castleLevel = max($castleLevel, (int) $building['level']);
                }
            }
            if ($castleLevel < (int) $requires['castle']) {
                return 'Burg Stufe ' . (int) $requires['castle'] . ' nötig';
            }
        }

        foreach ((array) ($requires['research'] ?? []) as $key => $level) {
            if ((int) ($world['research'][$key] ?? 0) < (int) $level) {
                return (string) App::balance('research.' . $key . '.name', $key) . ' Stufe ' . (int) $level . ' nötig';
            }
        }

        return '';
    }

    /** Neues Gebäude errichten. */
    public static function build(array $world, string $islandId, string $type, int $x, int $y): array
    {
        $def = World::buildingDef($type);
        if ($def === []) {
            return self::fail('Dieses Gebäude kennen wir nicht.');
        }

        $requirement = self::buildingRequirement($world, $type);
        if ($requirement !== '') {
            return self::fail('Noch nicht verfügbar: ' . $requirement . '.');
        }

        $place = World::canPlace($world, $islandId, $type, $x, $y);
        if (!$place['ok']) {
            return self::fail((string) $place['error']);
        }

        $cost = (array) ($def['cost'] ?? []);
        if (!Formulas::withinSafeRange($cost)) {
            return self::fail('Die Kosten übersteigen den sicheren Zahlenbereich.');
        }
        if (!Economy::pay($world, $cost)) {
            return self::fail('Dafür fehlen noch Rohstoffe.', ['missing' => Formulas::missing($cost, Economy::available($world))]);
        }

        $id = World::addBuilding($world, $islandId, $type, $x, $y);
        $world['stats']['upgrades'] = (int) ($world['stats']['upgrades'] ?? 0) + 1;

        return self::ok($world, ['building' => $id, 'cost' => $cost]);
    }

    /**
     * Gebäude verbessern. $steps ist eine Zahl oder 'max'.
     * Wirkt sofort – es gibt keine Bauzeit.
     */
    public static function upgradeBuilding(array $world, string $buildingId, int|string $steps = 1): array
    {
        $building = $world['buildings'][$buildingId] ?? null;
        if ($building === null) {
            return self::fail('Dieses Gebäude gibt es nicht.');
        }

        $def    = World::buildingDef((string) $building['type']);
        $base   = (array) ($def['cost'] ?? []);
        $growth = (float) ($def['cost_growth'] ?? Formulas::defaultCostGrowth());
        $level  = (int) $building['level'];

        if ($steps === 'max') {
            $count = Formulas::maxAffordable($base, $level + 1, Economy::available($world), $growth);
            if ($count < 1) {
                return self::fail('Für eine weitere Stufe fehlen noch Rohstoffe.', [
                    'missing' => Formulas::missing(Formulas::costAt($base, $level + 1, $growth), Economy::available($world)),
                ]);
            }
        } else {
            $count = max(1, min((int) $steps, Formulas::MAX_STEPS_PER_ACTION));
        }

        $cost = Formulas::bulkCost($base, $level + 1, $count, $growth);
        if (!Formulas::withinSafeRange($cost)) {
            return self::fail('Die Kosten übersteigen den sicheren Zahlenbereich. Bitte in kleineren Schritten verbessern.');
        }
        if (!Economy::pay($world, $cost)) {
            return self::fail('Dafür fehlen noch Rohstoffe.', ['missing' => Formulas::missing($cost, Economy::available($world))]);
        }

        $world['buildings'][$buildingId]['level'] = $level + $count;
        $world['stats']['upgrades'] = (int) ($world['stats']['upgrades'] ?? 0) + $count;

        return self::ok($world, [
            'level' => $level + $count,
            'steps' => $count,
            'cost'  => $cost,
            'tier'  => Formulas::tier($level + $count),
        ]);
    }

    /** Gebäude abreissen (gibt ein Drittel der Baukosten zurück). */
    public static function demolish(array $world, string $buildingId): array
    {
        $building = $world['buildings'][$buildingId] ?? null;
        if ($building === null) {
            return self::fail('Dieses Gebäude gibt es nicht.');
        }
        if ($building['type'] === 'castle') {
            return self::fail('Die Burg kann nicht abgerissen werden.');
        }

        $def     = World::buildingDef((string) $building['type']);
        $growth  = (float) ($def['cost_growth'] ?? Formulas::defaultCostGrowth());
        $paid    = Formulas::bulkCost((array) ($def['cost'] ?? []), 1, max(1, (int) $building['level']), $growth);
        $refund  = [];
        foreach ($paid as $resource => $amount) {
            $refund[$resource] = (int) floor($amount * 0.33);
        }

        unset($world['buildings'][$buildingId]);

        // Routen entfernen, die an diesem Gebäude hingen.
        foreach ((array) ($world['routes'] ?? []) as $routeId => $route) {
            $src = (array) ($route['src'] ?? []);
            $dst = (array) ($route['dst'] ?? []);
            if (($src[0] ?? '') === 'b' && ($src[1] ?? '') === $buildingId) {
                unset($world['routes'][$routeId]);
                continue;
            }
            if (($dst[0] ?? '') === 'b' && ($dst[1] ?? '') === $buildingId) {
                unset($world['routes'][$routeId]);
            }
        }

        $credited = Economy::credit($world, $refund);

        return self::ok($world, ['refund' => $credited['stored']]);
    }

    /** Gebäude verschieben (kostenlos, aber nur auf freie Felder). */
    public static function moveBuilding(array $world, string $buildingId, int $x, int $y): array
    {
        $building = $world['buildings'][$buildingId] ?? null;
        if ($building === null) {
            return self::fail('Dieses Gebäude gibt es nicht.');
        }

        $place = World::canPlace($world, (string) $building['island'], (string) $building['type'], $x, $y, $buildingId);
        if (!$place['ok']) {
            return self::fail((string) $place['error']);
        }

        $world['buildings'][$buildingId]['x'] = $x;
        $world['buildings'][$buildingId]['y'] = $y;

        return self::ok($world);
    }

    /** Beschädigtes Gebäude oder beschädigte Brücke sofort reparieren. */
    public static function repair(array $world, string $targetId): array
    {
        $factor = (float) App::balance('combat.repair_factor', 0.30);

        if (isset($world['buildings'][$targetId])) {
            $building = $world['buildings'][$targetId];
            $damage   = (float) ($building['damage'] ?? 0);
            if ($damage <= 0.001) {
                return self::fail('Dieses Gebäude ist unbeschädigt.');
            }

            $def    = World::buildingDef((string) $building['type']);
            $growth = (float) ($def['cost_growth'] ?? Formulas::defaultCostGrowth());
            $full   = Formulas::costAt((array) ($def['cost'] ?? []), (int) $building['level'], $growth);
            $cost   = [];
            foreach ($full as $resource => $amount) {
                $value = (int) ceil($amount * $factor * $damage);
                if ($value > 0) {
                    $cost[$resource] = $value;
                }
            }

            if (!Economy::pay($world, $cost)) {
                return self::fail('Für die Reparatur fehlen Rohstoffe.', ['missing' => Formulas::missing($cost, Economy::available($world))]);
            }
            $world['buildings'][$targetId]['damage'] = 0.0;

            return self::ok($world, ['cost' => $cost]);
        }

        if (isset($world['bridges'][$targetId])) {
            $bridge = $world['bridges'][$targetId];
            $damage = (float) ($bridge['damage'] ?? 0);
            if ($damage <= 0.001) {
                return self::fail('Diese Brücke ist unbeschädigt.');
            }

            $repairFactor = (float) App::balance('bridges.repair_factor', 0.35);
            $full = Formulas::costAt((array) App::balance('bridges.cost', []), (int) $bridge['level'], (float) App::balance('bridges.cost_growth', 1.09));
            $cost = [];
            foreach ($full as $resource => $amount) {
                $value = (int) ceil($amount * $repairFactor * $damage);
                if ($value > 0) {
                    $cost[$resource] = $value;
                }
            }

            if (!Economy::pay($world, $cost)) {
                return self::fail('Für die Reparatur fehlen Rohstoffe.', ['missing' => Formulas::missing($cost, Economy::available($world))]);
            }
            $world['bridges'][$targetId]['damage'] = 0.0;

            return self::ok($world, ['cost' => $cost]);
        }

        return self::fail('Dieses Bauwerk gibt es nicht.');
    }

    // =================================================================
    // Brücken
    // =================================================================

    public static function buildBridge(array $world, string $islandA, string $islandB): array
    {
        if ($islandA === $islandB) {
            return self::fail('Eine Brücke braucht zwei verschiedene Inseln.');
        }
        if (!isset($world['islands'][$islandA], $world['islands'][$islandB])) {
            return self::fail('Mindestens eine der Inseln gibt es nicht.');
        }

        foreach ($world['bridges'] as $bridge) {
            $pair = [$bridge['a'], $bridge['b']];
            if (in_array($islandA, $pair, true) && in_array($islandB, $pair, true)) {
                return self::fail('Zwischen diesen Inseln steht bereits eine Brücke.');
            }
        }

        $cost = Logistics::bridgeCost($world);
        if (!Economy::pay($world, $cost)) {
            return self::fail('Für die Brücke fehlen Rohstoffe.', ['missing' => Formulas::missing($cost, Economy::available($world))]);
        }

        $id = World::addBridge($world, $islandA, $islandB);

        return self::ok($world, ['bridge' => $id, 'cost' => $cost]);
    }

    public static function upgradeBridge(array $world, string $bridgeId, int|string $steps = 1): array
    {
        $bridge = $world['bridges'][$bridgeId] ?? null;
        if ($bridge === null) {
            return self::fail('Diese Brücke gibt es nicht.');
        }

        $base   = (array) App::balance('bridges.cost', []);
        $growth = (float) App::balance('bridges.cost_growth', 1.09);
        $level  = (int) $bridge['level'];

        $count = $steps === 'max'
            ? Formulas::maxAffordable($base, $level + 1, Economy::available($world), $growth)
            : max(1, min((int) $steps, Formulas::MAX_STEPS_PER_ACTION));

        if ($count < 1) {
            return self::fail('Für eine weitere Brückenstufe fehlen Rohstoffe.');
        }

        $cost = Formulas::bulkCost($base, $level + 1, $count, $growth);
        if (!Formulas::withinSafeRange($cost)) {
            return self::fail('Die Kosten übersteigen den sicheren Zahlenbereich.');
        }
        if (!Economy::pay($world, $cost)) {
            return self::fail('Dafür fehlen noch Rohstoffe.', ['missing' => Formulas::missing($cost, Economy::available($world))]);
        }

        $world['bridges'][$bridgeId]['level'] = $level + $count;

        return self::ok($world, ['level' => $level + $count, 'steps' => $count, 'cost' => $cost]);
    }

    // =================================================================
    // Routen
    // =================================================================

    public static function createRoute(array $world, array $src, array $dst, string $resource, string $mode = 'foot'): array
    {
        $budget = Logistics::routeBudget($world);
        if ($budget['free'] < 1) {
            return self::fail('Alle Routenplätze sind belegt. Baue ein Transportkontor oder verbessere die Burg.');
        }
        if (!isset(App::balance('resources')[$resource])) {
            return self::fail('Diese Ware kennen wir nicht.');
        }
        if (!Logistics::modeAvailable($world, $mode)) {
            return self::fail('Dieses Transportmittel ist noch nicht freigeschaltet.');
        }

        $check = self::checkEndpoint($world, $src, $resource, 'out');
        if ($check !== '') {
            return self::fail($check);
        }
        $check = self::checkEndpoint($world, $dst, $resource, 'in');
        if ($check !== '') {
            return self::fail($check);
        }
        if ($src === $dst) {
            return self::fail('Start und Ziel müssen sich unterscheiden.');
        }

        foreach ($world['routes'] as $route) {
            if ($route['src'] === $src && $route['dst'] === $dst && $route['resource'] === $resource) {
                return self::fail('Diese Route gibt es bereits.');
            }
        }

        $cost = Logistics::routeCost($world);
        if (!Economy::pay($world, $cost)) {
            return self::fail('Für eine neue Route fehlen Rohstoffe.', ['missing' => Formulas::missing($cost, Economy::available($world))]);
        }

        $id = World::addRoute($world, $src, $dst, $resource, 1, $mode);

        return self::ok($world, ['route' => $id, 'cost' => $cost]);
    }

    /** Prüft, ob ein Routenende die Ware überhaupt abgeben bzw. annehmen kann. */
    private static function checkEndpoint(array $world, array $endpoint, string $resource, string $direction): string
    {
        if (($endpoint[0] ?? '') === 'store') {
            return '';
        }
        if (($endpoint[0] ?? '') !== 'b') {
            return 'Ungültiger Start- oder Zielpunkt.';
        }

        $building = $world['buildings'][$endpoint[1] ?? ''] ?? null;
        if ($building === null) {
            return 'Das gewählte Gebäude gibt es nicht.';
        }

        $def = World::buildingDef((string) $building['type']);
        if ($direction === 'out' && !isset(((array) ($def['produces'] ?? []))[$resource])) {
            return (string) ($def['name'] ?? 'Dieses Gebäude') . ' stellt diese Ware nicht her.';
        }
        if ($direction === 'in' && !isset(((array) ($def['consumes'] ?? []))[$resource])) {
            return (string) ($def['name'] ?? 'Dieses Gebäude') . ' benötigt diese Ware nicht.';
        }

        return '';
    }

    public static function deleteRoute(array $world, string $routeId): array
    {
        if (!isset($world['routes'][$routeId])) {
            return self::fail('Diese Route gibt es nicht.');
        }
        unset($world['routes'][$routeId]);

        return self::ok($world);
    }

    public static function toggleRoute(array $world, string $routeId, bool $enabled): array
    {
        if (!isset($world['routes'][$routeId])) {
            return self::fail('Diese Route gibt es nicht.');
        }
        $world['routes'][$routeId]['enabled'] = $enabled;

        return self::ok($world, ['enabled' => $enabled]);
    }

    /** Weiteren Träger anstellen (kostet Rohstoffe und Einwohner). */
    public static function addCarrier(array $world, string $routeId, int $count = 1): array
    {
        $route = $world['routes'][$routeId] ?? null;
        if ($route === null) {
            return self::fail('Diese Route gibt es nicht.');
        }

        $effects = World::effects($world);
        $count   = max(1, min($count, 100));
        if ((int) $route['carriers'] + $count > $effects['carriers_max']) {
            return self::fail('So viele Träger kannst du nicht stellen. Baue ein Transportkontor.');
        }
        if ($effects['workers_needed'] + $count > $effects['population']) {
            return self::fail('Es fehlen Einwohner für weitere Träger. Baue Wohnhäuser.');
        }

        $total = [];
        for ($i = 0; $i < $count; $i++) {
            $stepRoute = $route;
            $stepRoute['carriers'] = (int) $route['carriers'] + $i;
            foreach (Logistics::carrierCost($stepRoute) as $resource => $amount) {
                $total[$resource] = ($total[$resource] ?? 0) + $amount;
            }
        }

        if (!Economy::pay($world, $total)) {
            return self::fail('Für weitere Träger fehlen Rohstoffe.', ['missing' => Formulas::missing($total, Economy::available($world))]);
        }

        $world['routes'][$routeId]['carriers'] = (int) $route['carriers'] + $count;

        return self::ok($world, ['carriers' => $world['routes'][$routeId]['carriers'], 'cost' => $total]);
    }

    public static function removeCarrier(array $world, string $routeId): array
    {
        $route = $world['routes'][$routeId] ?? null;
        if ($route === null) {
            return self::fail('Diese Route gibt es nicht.');
        }
        if ((int) $route['carriers'] <= 1) {
            return self::fail('Mindestens ein Träger muss bleiben.');
        }
        $world['routes'][$routeId]['carriers'] = (int) $route['carriers'] - 1;

        return self::ok($world, ['carriers' => $world['routes'][$routeId]['carriers']]);
    }

    /** Route verbessern: mehr Tempo und mehr Ladung je Stufe. */
    public static function upgradeRoute(array $world, string $routeId, int|string $steps = 1): array
    {
        $route = $world['routes'][$routeId] ?? null;
        if ($route === null) {
            return self::fail('Diese Route gibt es nicht.');
        }

        $base   = ['wood' => 90, 'stone' => 60, 'tools' => 2];
        $growth = 1.062;
        $level  = (int) ($route['level'] ?? 1);

        $count = $steps === 'max'
            ? Formulas::maxAffordable($base, $level + 1, Economy::available($world), $growth)
            : max(1, min((int) $steps, Formulas::MAX_STEPS_PER_ACTION));

        if ($count < 1) {
            return self::fail('Für eine weitere Routenstufe fehlen Rohstoffe.');
        }

        $cost = Formulas::bulkCost($base, $level + 1, $count, $growth);
        if (!Formulas::withinSafeRange($cost)) {
            return self::fail('Die Kosten übersteigen den sicheren Zahlenbereich.');
        }
        if (!Economy::pay($world, $cost)) {
            return self::fail('Dafür fehlen noch Rohstoffe.', ['missing' => Formulas::missing($cost, Economy::available($world))]);
        }

        $world['routes'][$routeId]['level'] = $level + $count;

        return self::ok($world, ['level' => $level + $count, 'steps' => $count, 'cost' => $cost]);
    }

    /** Transportmittel einer Route wechseln (z. B. von Fuss auf Pferdewagen). */
    public static function setRouteMode(array $world, string $routeId, string $mode): array
    {
        if (!isset($world['routes'][$routeId])) {
            return self::fail('Diese Route gibt es nicht.');
        }
        if (!Logistics::modeAvailable($world, $mode)) {
            return self::fail('Dieses Transportmittel ist noch nicht freigeschaltet.');
        }

        $world['routes'][$routeId]['mode'] = $mode;

        return self::ok($world, ['mode' => $mode]);
    }

    /** Ein Transportmittel dauerhaft freischalten. */
    public static function unlockMode(array $world, string $mode): array
    {
        $def = App::balance('transport_modes.' . $mode);
        if (!is_array($def)) {
            return self::fail('Dieses Transportmittel kennen wir nicht.');
        }
        if (!empty($world['modes'][$mode])) {
            return self::fail('Dieses Transportmittel ist bereits freigeschaltet.');
        }

        $requirement = Logistics::modeRequirement($world, $mode);
        if ($requirement !== '') {
            return self::fail('Noch nicht verfügbar: ' . $requirement . '.');
        }

        $cost = (array) ($def['cost'] ?? []);
        if (!Economy::pay($world, $cost)) {
            return self::fail('Dafür fehlen noch Rohstoffe.', ['missing' => Formulas::missing($cost, Economy::available($world))]);
        }

        $world['modes'][$mode] = true;

        return self::ok($world, ['mode' => $mode, 'cost' => $cost]);
    }

    // =================================================================
    // Forschung, Inseln, Truppen
    // =================================================================

    public static function research(array $world, string $key, int|string $steps = 1): array
    {
        $def = App::balance('research.' . $key);
        if (!is_array($def)) {
            return self::fail('Diese Forschung kennen wir nicht.');
        }
        if (!Research::isUnlocked($world, $key)) {
            return self::fail('Noch nicht verfügbar: ' . Research::requirementText($world, $key) . '.');
        }

        $level    = (int) ($world['research'][$key] ?? 0);
        $growth   = (float) ($def['growth'] ?? 1.18);
        $base     = (array) ($def['cost'] ?? []);
        $discount = min(0.6, World::effects($world)['research_discount'] / 100);

        $scaled = [];
        foreach ($base as $resource => $amount) {
            $scaled[$resource] = (float) $amount * (1 - $discount);
        }

        $count = $steps === 'max'
            ? Formulas::maxAffordable($scaled, $level + 1, Economy::available($world), $growth)
            : max(1, min((int) $steps, Formulas::MAX_STEPS_PER_ACTION));

        if ($count < 1) {
            return self::fail('Für die nächste Forschungsstufe fehlen Rohstoffe.');
        }

        $cost = Formulas::bulkCost($scaled, $level + 1, $count, $growth);
        if (!Formulas::withinSafeRange($cost)) {
            return self::fail('Die Kosten übersteigen den sicheren Zahlenbereich.');
        }
        if (!Economy::pay($world, $cost)) {
            return self::fail('Dafür fehlen noch Rohstoffe.', ['missing' => Formulas::missing($cost, Economy::available($world))]);
        }

        $world['research'][$key] = $level + $count;

        return self::ok($world, ['level' => $level + $count, 'steps' => $count, 'cost' => $cost]);
    }

    /** Weitere Insel freischalten. */
    public static function unlockIsland(array $world, string $type, string $name = ''): array
    {
        $def = World::islandDef($type);
        if ($def === []) {
            return self::fail('Diesen Inseltyp kennen wir nicht.');
        }

        $slotCount = count($world['islands']);
        $free      = (int) App::balance('island_slots.free', 4);
        $perSlot   = (int) App::balance('island_slots.castle_per_slot', 5);

        $castleLevel = 0;
        foreach ($world['buildings'] as $building) {
            if ($building['type'] === 'castle') {
                $castleLevel = max($castleLevel, (int) $building['level']);
            }
        }
        $needed = max(0, ($slotCount - $free + 1)) * $perSlot;
        if ($castleLevel < $needed) {
            return self::fail('Dafür braucht die Burg Stufe ' . $needed . '.');
        }
        if ($slotCount >= count(World::SLOTS)) {
            return self::fail('Mehr Inselplätze gibt es im Himmel nicht.');
        }

        foreach ((array) ($def['requires']['research'] ?? []) as $key => $level) {
            if ((int) ($world['research'][$key] ?? 0) < (int) $level) {
                return self::fail('Noch nicht verfügbar: ' . App::balance('research.' . $key . '.name', $key) . ' Stufe ' . (int) $level . '.');
            }
        }

        // Grundpreis des Inseltyps plus Aufschlag je bereits belegtem Platz
        $slotBase   = (array) App::balance('island_slots.slot_base', []);
        $slotGrowth = (float) App::balance('island_slots.slot_growth', 1.85);
        $slotCost   = Formulas::costAt($slotBase, max(1, $slotCount - $free + 1), $slotGrowth);

        $cost = (array) ($def['unlock_cost'] ?? []);
        foreach ($slotCost as $resource => $amount) {
            $cost[$resource] = (int) ($cost[$resource] ?? 0) + (int) $amount;
        }

        if (!Economy::pay($world, $cost)) {
            return self::fail('Für eine neue Insel fehlen Rohstoffe.', ['missing' => Formulas::missing($cost, Economy::available($world))]);
        }

        $name = $name !== '' ? $name : (string) ($def['name'] ?? 'Neue Insel');
        $id   = World::addIsland($world, $type, $name, $slotCount);

        return self::ok($world, ['island' => $id, 'cost' => $cost]);
    }

    /** Einheiten ausbilden – sofort, ohne Wartezeit. */
    public static function trainUnits(array $world, string $unit, int $count): array
    {
        $def = App::balance('units.' . $unit);
        if (!is_array($def)) {
            return self::fail('Diese Einheit kennen wir nicht.');
        }

        foreach ((array) ($def['requires']['research'] ?? []) as $key => $level) {
            if ((int) ($world['research'][$key] ?? 0) < (int) $level) {
                return self::fail('Noch nicht verfügbar: ' . App::balance('research.' . $key . '.name', $key) . ' Stufe ' . (int) $level . '.');
            }
        }

        $count   = max(1, min($count, 1000));
        $effects = World::effects($world);
        $army    = 0;
        foreach ((array) ($world['units'] ?? []) as $type => $amount) {
            $army += (int) $amount * (int) App::balance('units.' . $type . '.pop', 1);
        }
        $pop = (int) ($def['pop'] ?? 1);
        if ($army + $count * $pop > $effects['army_capacity']) {
            return self::fail('Deine Kasernen fassen nicht so viele Truppen. Baue oder verbessere eine Kaserne.');
        }

        $have   = (int) ($world['units'][$unit] ?? 0);
        $growth = (float) App::balance('unit_training.cost_growth', 1.0075);
        $cost   = Formulas::bulkCost((array) ($def['cost'] ?? []), $have + 1, $count, $growth);

        if (!Economy::pay($world, $cost)) {
            return self::fail('Für die Ausbildung fehlen Rohstoffe.', ['missing' => Formulas::missing($cost, Economy::available($world))]);
        }

        $world['units'][$unit] = $have + $count;

        return self::ok($world, ['units' => $world['units'][$unit], 'cost' => $cost]);
    }

    /** Ausbildungsstufe eines Einheitentyps erhöhen (unbegrenzt). */
    public static function upgradeUnit(array $world, string $unit, int|string $steps = 1): array
    {
        if (!is_array(App::balance('units.' . $unit))) {
            return self::fail('Diese Einheit kennen wir nicht.');
        }

        $level  = (int) ($world['unit_levels'][$unit] ?? 1);
        $base   = (array) App::balance('unit_training.level_cost', []);
        $growth = (float) App::balance('unit_training.level_growth', 1.11);

        $count = $steps === 'max'
            ? Formulas::maxAffordable($base, $level + 1, Economy::available($world), $growth)
            : max(1, min((int) $steps, Formulas::MAX_STEPS_PER_ACTION));

        if ($count < 1) {
            return self::fail('Für die nächste Ausbildungsstufe fehlen Rohstoffe.');
        }

        $cost = Formulas::bulkCost($base, $level + 1, $count, $growth);
        if (!Formulas::withinSafeRange($cost)) {
            return self::fail('Die Kosten übersteigen den sicheren Zahlenbereich.');
        }
        if (!Economy::pay($world, $cost)) {
            return self::fail('Dafür fehlen noch Rohstoffe.', ['missing' => Formulas::missing($cost, Economy::available($world))]);
        }

        $world['unit_levels'][$unit] = $level + $count;

        return self::ok($world, ['level' => $level + $count, 'steps' => $count, 'cost' => $cost]);
    }

    // =================================================================
    // Markt (nur Spielwährung)
    // =================================================================

    public static function shopBuy(array $world, string $pack): array
    {
        $def = App::balance('shop.packs.' . $pack);
        if (!is_array($def)) {
            return self::fail('Dieses Angebot gibt es nicht.');
        }

        $price = (array) ($def['price'] ?? []);
        if (!Economy::pay($world, $price)) {
            return self::fail('Dafür fehlt Gold.', ['missing' => Formulas::missing($price, Economy::available($world))]);
        }

        $result = Economy::credit($world, (array) ($def['give'] ?? []));

        return self::ok($world, ['received' => $result['stored'], 'lost' => $result['lost'], 'price' => $price]);
    }

    public static function shopSell(array $world, string $resource, int $amount): array
    {
        $rate = (float) App::balance('shop.sell_rates.' . $resource, 0);
        if ($rate <= 0) {
            return self::fail('Diese Ware nimmt der Markt nicht an.');
        }

        $amount = max(1, min($amount, (int) ($world['store'][$resource] ?? 0)));
        if ($amount < 1) {
            return self::fail('Davon hast du nichts im Lager.');
        }

        $gold = (int) floor($amount * $rate);
        if ($gold < 1) {
            return self::fail('Diese Menge ist zu klein für einen Verkauf.');
        }

        Economy::take($world, [$resource => $amount]);
        $result = Economy::credit($world, ['gold' => $gold]);

        return self::ok($world, ['sold' => $amount, 'gold' => $result['stored']['gold'] ?? 0]);
    }
}
