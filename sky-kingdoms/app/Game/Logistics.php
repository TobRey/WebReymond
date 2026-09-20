<?php

declare(strict_types=1);

namespace SkyKingdoms\Game;

use SkyKingdoms\Core\App;

/**
 * Das Transportwesen: Wege, Träger, Tempo, Ladung – und Staus auf Brücken.
 *
 * Grundgedanke: Eine Route ist eine feste Verbindung zwischen einer Quelle und
 * einem Ziel. Der Durchsatz ergibt sich aus Anzahl Träger, Ladung je Fahrt und
 * der Dauer einer Rundfahrt. Kreuzen mehrere Routen dieselbe Brücke und
 * übersteigt ihre Nachfrage deren Kapazität, verlangsamen sich alle
 * gleichmässig – der sichtbare Stau.
 */
final class Logistics
{
    /**
     * Vollständige Transportplanung eines Königreichs.
     *
     * @return array{routes:array<string,array>,bridges:array<string,array>}
     */
    public static function plan(array $world): array
    {
        $research = Research::multipliers($world);
        $effects  = World::effects($world);
        $cfg      = (array) App::balance('transport', []);
        $modes    = (array) App::balance('transport_modes', []);

        $baseLoad  = (float) ($cfg['base_load_time'] ?? 6.0);
        $baseDist  = (float) ($cfg['base_distance'] ?? 14.0);
        $speedGrow = (float) ($cfg['speed_growth'] ?? 1.015);
        $capGrow   = (float) ($cfg['capacity_growth'] ?? 1.012);
        $loadBonus = 1.0 + ($effects['load_speed'] / 100.0);

        $routes  = [];
        $bridges = [];

        foreach ($world['bridges'] as $id => $bridge) {
            $level   = max(1, (int) $bridge['level']);
            $growth  = (float) App::balance('bridges.capacity_growth', 1.018);
            $perMin  = Formulas::value((float) App::balance('bridges.capacity', 60.0), $level, $growth);
            $damage  = max(0.0, min(1.0, (float) ($bridge['damage'] ?? 0)));

            $bridges[$id] = [
                'id'       => (string) $id,
                'level'    => $level,
                'damage'   => $damage,
                'capacity' => $perMin * $research['bridge_capacity'] * (1.0 - $damage) / 60.0, // pro Sekunde
                'demand'   => 0.0,
                'factor'   => 1.0,
            ];
        }

        foreach ((array) ($world['routes'] ?? []) as $id => $route) {
            $entry = self::describeRoute($world, (array) $route, $modes, $research, $baseDist, $baseLoad, $loadBonus, $speedGrow, $capGrow);
            $routes[$id] = $entry;

            if ($entry['ok'] && ($route['enabled'] ?? true)) {
                foreach ($entry['path'] as $bridgeId) {
                    if (isset($bridges[$bridgeId])) {
                        $bridges[$bridgeId]['demand'] += $entry['nominal'];
                    }
                }
            }
        }

        // Stau: Nachfrage über Kapazität bremst alle Routen auf dieser Brücke.
        foreach ($bridges as $id => $bridge) {
            $bridges[$id]['factor'] = $bridge['demand'] > $bridge['capacity'] && $bridge['demand'] > 0
                ? max(0.02, $bridge['capacity'] / $bridge['demand'])
                : 1.0;
        }

        foreach ($routes as $id => $route) {
            $factor = 1.0;
            $jamOn  = null;
            foreach ($route['path'] as $bridgeId) {
                $bridgeFactor = $bridges[$bridgeId]['factor'] ?? 1.0;
                if ($bridgeFactor < $factor) {
                    $factor = $bridgeFactor;
                    $jamOn  = $bridgeId;
                }
            }
            $routes[$id]['jam_factor'] = $factor;
            $routes[$id]['jam_bridge'] = $jamOn;
            $routes[$id]['flow']       = $route['ok'] ? $route['nominal'] * $factor : 0.0;
            if ($factor < 0.98 && $route['ok']) {
                $routes[$id]['note'] = 'Stau auf der Brücke – nur ' . round($factor * 100) . ' % Durchsatz.';
            }
        }

        return ['routes' => $routes, 'bridges' => $bridges];
    }

    /** Einzelne Route berechnen. */
    private static function describeRoute(
        array $world,
        array $route,
        array $modes,
        array $research,
        float $baseDist,
        float $baseLoad,
        float $loadBonus,
        float $speedGrow,
        float $capGrow
    ): array {
        $mode    = (string) ($route['mode'] ?? 'foot');
        $modeDef = (array) ($modes[$mode] ?? $modes['foot'] ?? []);
        $level   = max(1, (int) ($route['level'] ?? 1));
        $carriers= max(1, (int) ($route['carriers'] ?? 1));

        $srcIsland = World::endpointIsland($world, (array) ($route['src'] ?? ['store']));
        $dstIsland = World::endpointIsland($world, (array) ($route['dst'] ?? ['store']));

        $entry = [
            'id'        => (string) ($route['id'] ?? ''),
            'resource'  => (string) ($route['resource'] ?? ''),
            'mode'      => $mode,
            'mode_name' => (string) ($modeDef['name'] ?? 'Träger'),
            'level'     => $level,
            'carriers'  => $carriers,
            'enabled'   => (bool) ($route['enabled'] ?? true),
            'src'       => (array) ($route['src'] ?? ['store']),
            'dst'       => (array) ($route['dst'] ?? ['store']),
            'src_island'=> $srcIsland,
            'dst_island'=> $dstIsland,
            'path'      => [],
            'hops'      => 0,
            'distance'  => 0.0,
            'speed'     => 0.0,
            'capacity'  => 0.0,
            'trip_time' => 0.0,
            'nominal'   => 0.0,
            'flow'      => 0.0,
            'jam_factor'=> 1.0,
            'jam_bridge'=> null,
            'ok'        => false,
            'note'      => '',
        ];

        if ($srcIsland === null || $dstIsland === null) {
            $entry['note'] = 'Start oder Ziel dieser Route fehlt.';

            return $entry;
        }
        if (!($route['enabled'] ?? true)) {
            $entry['note'] = 'Route ist angehalten.';
        }

        $path = World::bridgePath($world, $srcIsland, $dstIsland);
        if ($path === null) {
            $entry['note'] = 'Keine Brückenverbindung – es fehlt eine Brücke.';

            return $entry;
        }

        $entry['path']     = $path;
        $entry['hops']     = count($path);
        $entry['distance'] = $path === [] ? 5.0 : $baseDist * count($path) + 6.0;

        $speed    = (float) ($modeDef['speed'] ?? 1.0);
        $capacity = (float) ($modeDef['capacity'] ?? 10.0);

        $entry['speed']    = Formulas::value($speed, $level, $speedGrow) * $research['transport_speed'];
        $entry['capacity'] = Formulas::value($capacity, $level, $capGrow);

        $tripTime = (2.0 * $entry['distance'] / max(0.05, $entry['speed'])) + ($baseLoad / max(0.2, $loadBonus));
        $entry['trip_time'] = $tripTime;
        $entry['nominal']   = $entry['enabled'] ? ($carriers * $entry['capacity']) / max(0.5, $tripTime) : 0.0;
        $entry['ok']        = $entry['enabled'];

        return $entry;
    }

    /** Wie viele Routen darf der Spieler haben und wie viele nutzt er? */
    public static function routeBudget(array $world): array
    {
        $effects = World::effects($world);
        $used    = count((array) ($world['routes'] ?? []));

        return [
            'used'  => $used,
            'max'   => max(1, (int) $effects['route_slots']),
            'free'  => max(0, (int) $effects['route_slots'] - $used),
        ];
    }

    /** Kosten einer weiteren Route. */
    public static function routeCost(array $world): array
    {
        $count  = count((array) ($world['routes'] ?? []));
        $base   = (array) App::balance('transport.route_cost', []);
        $growth = (float) App::balance('transport.route_cost_growth', 1.42);

        return Formulas::costAt($base, $count + 1, $growth);
    }

    /** Kosten eines weiteren Trägers auf einer Route. */
    public static function carrierCost(array $route): array
    {
        $base   = (array) App::balance('transport.carrier_cost', []);
        $growth = (float) App::balance('transport.carrier_growth', 1.30);

        return Formulas::costAt($base, max(1, (int) ($route['carriers'] ?? 1)) + 1, $growth);
    }

    /** Kosten für Routenstufen (Tempo und Ladung). */
    public static function routeUpgradeCost(array $route, int $count = 1): array
    {
        $base   = ['wood' => 90, 'stone' => 60, 'tools' => 2];
        $level  = max(1, (int) ($route['level'] ?? 1));

        return Formulas::bulkCost($base, $level, max(1, $count), 1.062);
    }

    /** Ist ein Transportmittel für dieses Königreich freigeschaltet? */
    public static function modeAvailable(array $world, string $mode): bool
    {
        $def = App::balance('transport_modes.' . $mode);
        if (!is_array($def)) {
            return false;
        }
        if ($mode === 'foot') {
            return true;
        }
        if (!empty($world['modes'][$mode])) {
            return true;
        }

        return false;
    }

    /** Voraussetzungen eines Transportmittels prüfen (für den Kauf). */
    public static function modeRequirement(array $world, string $mode): string
    {
        $def = App::balance('transport_modes.' . $mode);
        if (!is_array($def)) {
            return 'Unbekanntes Transportmittel.';
        }

        foreach ((array) ($def['requires']['research'] ?? []) as $key => $level) {
            if ((int) ($world['research'][$key] ?? 0) < (int) $level) {
                return (string) App::balance('research.' . $key . '.name', $key) . ' Stufe ' . (int) $level . ' nötig';
            }
        }

        $needBuilding = (string) ($def['requires']['building'] ?? '');
        if ($needBuilding !== '') {
            foreach ($world['buildings'] as $building) {
                if ($building['type'] === $needBuilding) {
                    return '';
                }
            }

            return (string) App::balance('buildings.' . $needBuilding . '.name', $needBuilding) . ' nötig';
        }

        return '';
    }

    /** Kosten einer neuen Brücke zwischen zwei Inseln. */
    public static function bridgeCost(array $world): array
    {
        $count  = count((array) ($world['bridges'] ?? []));
        $base   = (array) App::balance('bridges.cost', []);
        $growth = (float) App::balance('bridges.cost_growth', 1.09);

        return Formulas::costAt($base, $count + 1, $growth);
    }
}
