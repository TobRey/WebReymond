<?php

declare(strict_types=1);

namespace SkyKingdoms\Game;

use SkyKingdoms\Core\App;
use SkyKingdoms\Core\Ids;

/**
 * Aufbau und Struktur eines Königreichs: Inseln, Gebäude, Brücken, Routen.
 *
 * Der gesamte Spielstand eines Spielers liegt in EINEM Dokument (world.json).
 * Diese Klasse kennt dessen Form und liefert alle abgeleiteten Werte
 * (Lagerplatz, Einwohner, Punkte, Wegstrecken).
 */
final class World
{
    public const VERSION = 1;

    /** Feste Plätze der Inseln auf der Weltkarte (in Insel-Einheiten). */
    public const SLOTS = [
        [0.0, 0.0], [-1.35, -0.75], [1.35, -0.75], [0.0, 1.35],
        [-1.65, 0.65], [1.65, 0.65], [-0.8, -1.95], [0.8, -1.95],
        [0.0, -2.55], [-2.3, -0.35], [2.3, -0.35], [0.0, 2.55],
    ];

    // =================================================================
    // Neues Königreich
    // =================================================================

    /** Erzeugt die Startwelt eines neuen Spielers. */
    public static function create(string $kingdomName = 'Neues Königreich'): array
    {
        $now = time();

        $world = [
            'v'          => self::VERSION,
            'name'       => $kingdomName,
            'created_at' => $now,
            'last_tick'  => $now,
            'islands'    => [],
            'buildings'  => [],
            'bridges'    => [],
            'routes'     => [],
            'store'      => ['wood' => 600, 'stone' => 420, 'bread' => 220, 'grain' => 120, 'gold' => 300],
            'units'      => [],
            'unit_levels'=> [],
            'research'   => [],
            'modes'      => ['foot' => true],
            'quests'     => [],
            'achievements' => [],
            'stats'      => [
                'produced' => [], 'delivered' => [], 'spent' => [],
                'attacks_won' => 0, 'attacks_lost' => 0, 'defenses_won' => 0, 'defenses_lost' => 0,
                'looted' => 0, 'lost_to_raids' => 0, 'upgrades' => 0, 'playtime' => 0,
            ],
            'flags'      => ['tutorial' => 1],
            'prestige'   => ['points' => 0, 'runs' => 0],
        ];

        // --- Inseln -------------------------------------------------
        $main    = self::addIsland($world, 'main', 'Königsinsel', 0);
        $storage = self::addIsland($world, 'storage', 'Lagerhafen', 3);
        $res     = self::addIsland($world, 'resource', 'Felsklippe', 1);
        $farm    = self::addIsland($world, 'farm', 'Grünes Eiland', 2);

        // --- Brücken: alles führt zum Lagerhafen ---------------------
        self::addBridge($world, $main, $storage);
        self::addBridge($world, $res, $storage);
        self::addBridge($world, $farm, $storage);

        // --- Startgebäude -------------------------------------------
        $castle = self::addBuilding($world, $main, 'castle', 5, 3);
        self::addBuilding($world, $main, 'house', 4, 6);
        self::addBuilding($world, $main, 'house', 7, 6);

        $lumber = self::addBuilding($world, $res, 'lumberjack', 2, 2);
        $quarry = self::addBuilding($world, $res, 'quarry', 6, 3);

        $grain  = self::addBuilding($world, $farm, 'grain_farm', 3, 2);

        self::addBuilding($world, $storage, 'warehouse', 3, 2);
        self::addBuilding($world, $storage, 'granary', 6, 2);
        self::addBuilding($world, $storage, 'transport_office', 4, 5);

        // --- Startrouten (Träger zu Fuss) ---------------------------
        self::addRoute($world, ['b', $lumber], ['store'], 'wood', 2);
        self::addRoute($world, ['b', $quarry], ['store'], 'stone', 2);
        self::addRoute($world, ['b', $grain], ['store'], 'grain', 2);

        unset($castle);

        return $world;
    }

    /** Insel anlegen und ihre Kennung zurückgeben. */
    public static function addIsland(array &$world, string $type, string $name, int $slot): string
    {
        $id = 'i' . (count($world['islands']) + 1);
        $world['islands'][$id] = [
            'id'    => $id,
            'type'  => $type,
            'name'  => $name,
            'slot'  => $slot,
            'since' => time(),
        ];

        return $id;
    }

    /** Gebäude platzieren (ohne Prüfung – Prüfung macht canPlace()). */
    public static function addBuilding(array &$world, string $islandId, string $type, int $x, int $y, int $level = 1): string
    {
        $id  = 'b' . self::nextNumber($world['buildings'] ?? [], 'b');
        $def = self::buildingDef($type);

        $world['buildings'][$id] = [
            'id'      => $id,
            'island'  => $islandId,
            'type'    => $type,
            'x'       => $x,
            'y'       => $y,
            'level'   => max(1, $level),
            'in'      => [],
            'out'     => [],
            'damage'  => 0.0,
            'enabled' => true,
        ];

        // Eingangs- und Ausgangspuffer vorbereiten
        foreach (array_keys((array) ($def['consumes'] ?? [])) as $resource) {
            $world['buildings'][$id]['in'][$resource] = 0.0;
        }
        foreach (array_keys((array) ($def['produces'] ?? [])) as $resource) {
            $world['buildings'][$id]['out'][$resource] = 0.0;
        }

        return $id;
    }

    public static function addBridge(array &$world, string $a, string $b, int $level = 1): string
    {
        $id = 'br' . self::nextNumber($world['bridges'] ?? [], 'br');
        $world['bridges'][$id] = [
            'id'     => $id,
            'a'      => $a,
            'b'      => $b,
            'level'  => max(1, $level),
            'damage' => 0.0,
        ];

        return $id;
    }

    /**
     * Route anlegen. Quelle und Ziel sind entweder ['b', gebäudeId] oder ['store'].
     *
     * @param array{0:string,1?:string} $src
     * @param array{0:string,1?:string} $dst
     */
    public static function addRoute(array &$world, array $src, array $dst, string $resource, int $carriers = 1, string $mode = 'foot'): string
    {
        $id = 'r' . self::nextNumber($world['routes'] ?? [], 'r');
        $world['routes'][$id] = [
            'id'       => $id,
            'src'      => $src,
            'dst'      => $dst,
            'resource' => $resource,
            'mode'     => $mode,
            'level'    => 1,
            'carriers' => max(1, $carriers),
            'enabled'  => true,
        ];

        return $id;
    }

    // =================================================================
    // Definitionen
    // =================================================================

    public static function buildingDef(string $type): array
    {
        $def = App::balance('buildings.' . $type);

        return is_array($def) ? $def : [];
    }

    public static function islandDef(string $type): array
    {
        $def = App::balance('island_types.' . $type);

        return is_array($def) ? $def : [];
    }

    public static function resourceDef(string $key): array
    {
        $def = App::balance('resources.' . $key);

        return is_array($def) ? $def : [];
    }

    public static function resourceClass(string $key): string
    {
        return (string) (self::resourceDef($key)['class'] ?? 'bulk');
    }

    // =================================================================
    // Platzierung auf dem Raster
    // =================================================================

    /** Maske der bebaubaren Felder einer Insel. */
    public static function mask(string $islandType): array
    {
        $def = self::islandDef($islandType);

        return (array) ($def['mask'] ?? []);
    }

    public static function gridSize(string $islandType): array
    {
        $def = self::islandDef($islandType);
        $grid = (array) ($def['grid'] ?? [10, 8]);

        return [(int) ($grid[0] ?? 10), (int) ($grid[1] ?? 8)];
    }

    /** Ist die Zelle (x|y) auf dieser Insel grundsätzlich bebaubar? */
    public static function isBuildable(string $islandType, int $x, int $y): bool
    {
        $mask = self::mask($islandType);
        if (!isset($mask[$y])) {
            return false;
        }
        $row = (string) $mask[$y];

        return isset($row[$x]) && $row[$x] === '#';
    }

    /**
     * Kann ein Gebäude hier stehen? Prüft Maske, Grenzen, Überschneidungen,
     * Inselzugehörigkeit und Stückzahlbegrenzung.
     *
     * @return array{ok:bool,error?:string}
     */
    public static function canPlace(array $world, string $islandId, string $type, int $x, int $y, ?string $ignoreBuildingId = null): array
    {
        $island = $world['islands'][$islandId] ?? null;
        if ($island === null) {
            return ['ok' => false, 'error' => 'Diese Insel gibt es nicht.'];
        }

        $def = self::buildingDef($type);
        if ($def === []) {
            return ['ok' => false, 'error' => 'Unbekannter Gebäudetyp.'];
        }

        $allowed = (array) ($def['islands'] ?? []);
        if ($allowed !== [] && !in_array($island['type'], $allowed, true)) {
            return ['ok' => false, 'error' => 'Dieses Gebäude passt nicht auf ' . (self::islandDef($island['type'])['name'] ?? 'diese Insel') . '.'];
        }

        [$w, $h] = (array) ($def['size'] ?? [1, 1]);
        $w = max(1, (int) $w);
        $h = max(1, (int) $h);

        for ($dx = 0; $dx < $w; $dx++) {
            for ($dy = 0; $dy < $h; $dy++) {
                if (!self::isBuildable((string) $island['type'], $x + $dx, $y + $dy)) {
                    return ['ok' => false, 'error' => 'Hier ist kein fester Boden.'];
                }
            }
        }

        foreach ($world['buildings'] as $other) {
            if ($other['island'] !== $islandId || $other['id'] === $ignoreBuildingId) {
                continue;
            }
            $otherDef = self::buildingDef((string) $other['type']);
            [$ow, $oh] = (array) ($otherDef['size'] ?? [1, 1]);
            $ow = max(1, (int) $ow);
            $oh = max(1, (int) $oh);

            $overlap = $x < (int) $other['x'] + $ow && $x + $w > (int) $other['x']
                && $y < (int) $other['y'] + $oh && $y + $h > (int) $other['y'];
            if ($overlap) {
                return ['ok' => false, 'error' => 'Dort steht bereits ein Gebäude.'];
            }
        }

        $limit = (int) ($def['limit'] ?? 0);
        if ($limit > 0) {
            $count = 0;
            foreach ($world['buildings'] as $other) {
                if ($other['type'] === $type && $other['id'] !== $ignoreBuildingId) {
                    $count++;
                }
            }
            if ($count >= $limit) {
                return ['ok' => false, 'error' => 'Von diesem Gebäude ist bereits die Höchstzahl vorhanden.'];
            }
        }

        return ['ok' => true];
    }

    /** Erste freie Stelle für ein Gebäude finden (für Beispieldaten und Vorschläge). */
    public static function findFreeSpot(array $world, string $islandId, string $type): ?array
    {
        $island = $world['islands'][$islandId] ?? null;
        if ($island === null) {
            return null;
        }
        [$cols, $rows] = self::gridSize((string) $island['type']);

        for ($y = 0; $y < $rows; $y++) {
            for ($x = 0; $x < $cols; $x++) {
                if (self::canPlace($world, $islandId, $type, $x, $y)['ok']) {
                    return [$x, $y];
                }
            }
        }

        return null;
    }

    // =================================================================
    // Wege zwischen Inseln
    // =================================================================

    /**
     * Kürzester Weg zwischen zwei Inseln über Brücken (Breitensuche).
     *
     * @return string[]|null Liste der Brückenkennungen oder null, wenn getrennt
     */
    public static function bridgePath(array $world, string $from, string $to): ?array
    {
        if ($from === $to) {
            return [];
        }

        $neighbours = [];
        foreach ($world['bridges'] as $bridge) {
            $neighbours[$bridge['a']][] = [$bridge['b'], $bridge['id']];
            $neighbours[$bridge['b']][] = [$bridge['a'], $bridge['id']];
        }

        $queue   = [[$from, []]];
        $visited = [$from => true];

        while ($queue !== []) {
            [$island, $path] = array_shift($queue);
            foreach ($neighbours[$island] ?? [] as [$next, $bridgeId]) {
                if (isset($visited[$next])) {
                    continue;
                }
                $newPath = array_merge($path, [$bridgeId]);
                if ($next === $to) {
                    return $newPath;
                }
                $visited[$next] = true;
                $queue[]        = [$next, $newPath];
            }
        }

        return null;
    }

    /** Insel, auf der die Waren eingelagert werden (erste Lagerinsel). */
    public static function storageIsland(array $world): ?string
    {
        foreach ($world['islands'] as $id => $island) {
            if ($island['type'] === 'storage') {
                return (string) $id;
            }
        }
        // Notfall: Insel mit dem meisten Lagerplatz
        foreach ($world['buildings'] as $building) {
            $def = self::buildingDef((string) $building['type']);
            if (($def['role'] ?? '') === 'storage') {
                return (string) $building['island'];
            }
        }

        return array_key_first($world['islands']) ?: null;
    }

    /** Insel eines Routenendes ermitteln. */
    public static function endpointIsland(array $world, array $endpoint): ?string
    {
        if (($endpoint[0] ?? '') === 'b') {
            $building = $world['buildings'][$endpoint[1] ?? ''] ?? null;

            return $building === null ? null : (string) $building['island'];
        }

        return self::storageIsland($world);
    }

    // =================================================================
    // Abgeleitete Werte
    // =================================================================

    /**
     * Alles, was sich aus den Gebäuden ergibt – in einem Durchlauf.
     *
     * @return array{
     *   population:int, workers_needed:int, route_slots:int, carriers_max:int,
     *   capacity:array<string,float>, defense_hp:float, defense_damage:float,
     *   army_capacity:int, research_discount:float, trade_slots:int,
     *   gold_rate:float, load_speed:float, patrol:float, building_levels:int
     * }
     */
    public static function effects(array $world): array
    {
        $out = [
            'population'        => (float) App::balance('population.base', 20),
            'workers_needed'    => 0.0,
            'route_slots'       => 0.0,
            'carriers_max'      => 6.0,
            'capacity'          => ['bulk' => 0.0, 'food' => 0.0, 'goods' => 0.0, 'precious' => 0.0, 'special' => 0.0],
            'defense_hp'        => 0.0,
            'defense_damage'    => 0.0,
            'army_capacity'     => 0.0,
            'research_discount' => 0.0,
            'trade_slots'       => 0.0,
            'gold_rate'         => 0.0,
            'load_speed'        => 0.0,
            'patrol'            => 0.0,
            'convoy_safety'     => 0.0,
            'building_levels'   => 0,
        ];

        $research = Research::multipliers($world);

        foreach ($world['buildings'] as $building) {
            $type  = (string) $building['type'];
            $def   = self::buildingDef($type);
            if ($def === []) {
                continue;
            }
            $level  = max(1, (int) $building['level']);
            $growth = (float) ($def['effect_growth'] ?? App::balance('formulas.effect_growth_default', 1.01));
            $out['building_levels'] += $level;

            $out['workers_needed'] += (float) ($def['workers'] ?? 0);

            foreach ((array) ($def['effects'] ?? []) as $key => $base) {
                $value = Formulas::value((float) $base, $level, $growth);
                switch ($key) {
                    case 'population':        $out['population']        += $value; break;
                    case 'route_slots':       $out['route_slots']       += $value; break;
                    case 'carriers':          $out['carriers_max']      += $value; break;
                    case 'defense_hp':        $out['defense_hp']        += $value * $research['defense_hp']; break;
                    case 'defense_damage':    $out['defense_damage']    += $value; break;
                    case 'army_capacity':     $out['army_capacity']     += $value; break;
                    case 'research_discount': $out['research_discount'] += $value; break;
                    case 'trade_slots':       $out['trade_slots']       += $value; break;
                    case 'gold_rate':         $out['gold_rate']         += $value; break;
                    case 'load_speed':        $out['load_speed']        += $value; break;
                    case 'patrol_strength':   $out['patrol']            += $value; break;
                    case 'convoy_safety':     $out['convoy_safety']     += $value; break;
                    default: break;
                }
            }

            if (isset($def['storage'])) {
                $class  = (string) ($def['storage']['class'] ?? 'bulk');
                $amount = Formulas::value((float) ($def['storage']['amount'] ?? 0), $level, $growth);
                $out['capacity'][$class] = ($out['capacity'][$class] ?? 0.0) + $amount * $research['storage_capacity'];
            }
        }

        // Träger kosten Arbeitskraft
        foreach ($world['routes'] as $route) {
            $out['workers_needed'] += (float) ($route['carriers'] ?? 1);
        }

        $out['population']     = floor($out['population']);
        $out['workers_needed'] = ceil($out['workers_needed']);
        $out['route_slots']    = (int) floor($out['route_slots']);
        $out['carriers_max']   = (int) floor($out['carriers_max']);
        $out['army_capacity']  = (int) floor($out['army_capacity']);
        $out['trade_slots']    = (int) floor($out['trade_slots']);

        return $out;
    }

    /** Lagerkapazität je Rohstoffklasse. */
    public static function capacity(array $world): array
    {
        return self::effects($world)['capacity'];
    }

    /** Aktuell eingelagerte Menge je Klasse. */
    public static function storedByClass(array $world): array
    {
        $out = ['bulk' => 0.0, 'food' => 0.0, 'goods' => 0.0, 'precious' => 0.0, 'special' => 0.0];
        foreach ((array) ($world['store'] ?? []) as $resource => $amount) {
            $class = self::resourceClass((string) $resource);
            $out[$class] = ($out[$class] ?? 0.0) + (float) $amount;
        }

        return $out;
    }

    /** Punktestand des Königreichs. */
    public static function score(array $world): int
    {
        $cfg   = (array) App::balance('score', []);
        $score = 0.0;

        foreach ($world['buildings'] as $building) {
            $score += (float) ($cfg['per_building_level'] ?? 4) * max(1, (int) $building['level']);
        }
        foreach ((array) ($world['research'] ?? []) as $level) {
            $score += (float) ($cfg['per_research_level'] ?? 12) * max(0, (int) $level);
        }
        foreach ((array) ($world['units'] ?? []) as $count) {
            $score += (float) ($cfg['per_unit'] ?? 2) * max(0, (int) $count);
        }
        foreach ($world['bridges'] as $bridge) {
            $score += (float) ($cfg['per_bridge_level'] ?? 3) * max(1, (int) $bridge['level']);
        }
        $score += (float) ($cfg['per_island'] ?? 120) * count($world['islands']);
        $score += (float) ($cfg['battle_win'] ?? 15) * (int) ($world['stats']['attacks_won'] ?? 0);
        $score += (float) ($cfg['battle_loss'] ?? -5) * (int) ($world['stats']['attacks_lost'] ?? 0);

        return (int) max(0, floor($score));
    }

    /** Spielerlevel aus dem Punktestand (rein kosmetisch, wächst logarithmisch). */
    public static function level(int $score): int
    {
        return (int) max(1, floor(sqrt(max(0, $score) / 45)) + 1);
    }

    /** Fortlaufende Nummer für neue Kennungen. */
    private static function nextNumber(array $collection, string $prefix): int
    {
        $max = 0;
        foreach (array_keys($collection) as $key) {
            if (preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/', (string) $key, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return $max + 1;
    }
}
