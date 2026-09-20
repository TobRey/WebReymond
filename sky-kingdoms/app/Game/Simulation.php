<?php

declare(strict_types=1);

namespace SkyKingdoms\Game;

use SkyKingdoms\Core\App;
use SkyKingdoms\Core\Num;

/**
 * Produktion, Transport und Verbrauch über die Zeit – auch für Zeiträume,
 * in denen der Spieler offline war.
 *
 * Verfahren: Das Königreich wird als Netz aus "Behältern" (Gebäudepuffer,
 * Lager) und "Flüssen" (Produktion, Routen, Verbrauch) beschrieben. Für einen
 * Abschnitt konstanter Flüsse lässt sich exakt ausrechnen, wann der nächste
 * Behälter voll oder leer läuft. Genau bis dorthin wird gesprungen, dann
 * werden die Flüsse neu bestimmt. So genügen wenige Dutzend Rechenschritte
 * auch für 24 Stunden Abwesenheit – ohne Endlosschleifen und ohne Cronjob.
 */
final class Simulation
{
    private const RELAX_ITERATIONS = 8;
    private const EPS              = 1e-9;

    /**
     * Welt auf den aktuellen Zeitpunkt bringen.
     *
     * @return array{world:array,summary:array}
     */
    public static function tick(array $world, ?int $now = null): array
    {
        $now      = $now ?? time();
        $last     = (int) ($world['last_tick'] ?? $now);
        $elapsed  = $now - $last;
        $minTick  = max(0, (int) App::config('min_tick_seconds', 1));

        if ($elapsed < $minTick) {
            return ['world' => $world, 'summary' => self::emptySummary()];
        }

        $maxOffline = max(60, (int) App::config('max_offline_seconds', 86400));
        $simulated  = min($elapsed, $maxOffline);
        $skipped    = max(0, $elapsed - $simulated);

        $result = self::advance($world, $simulated);
        $result['world']['last_tick'] = $now;
        $result['summary']['elapsed']   = $elapsed;
        $result['summary']['simulated'] = $simulated;
        $result['summary']['skipped']   = $skipped;

        $playtime = (int) ($result['world']['stats']['playtime'] ?? 0);
        $result['world']['stats']['playtime'] = $playtime + $simulated;

        return $result;
    }

    /**
     * Die Welt um $seconds Sekunden weiterrechnen.
     *
     * @return array{world:array,summary:array}
     */
    public static function advance(array $world, float $seconds): array
    {
        $summary = self::emptySummary();
        if ($seconds <= 0) {
            return ['world' => $world, 'summary' => $summary];
        }

        $before      = (array) ($world['store'] ?? []);
        $maxSegments = max(4, (int) App::config('max_sim_segments', 240));
        $remaining   = $seconds;
        $segments    = 0;

        $graph = self::buildGraph($world);

        while ($remaining > self::EPS && $segments < $maxSegments) {
            $segments++;

            self::relax($graph);
            $net = self::netRates($graph);
            $dt  = min($remaining, self::nextEvent($graph, $net));

            if ($dt <= self::EPS) {
                // Behälter genau an der Grenze: Grenzen festschreiben und
                // im nächsten Durchlauf mit angepassten Flüssen weiterrechnen.
                $dt = min($remaining, 0.25);
            }

            self::applyRates($graph, $net, $dt);
            $remaining -= $dt;

            // Hunger und Arbeitermangel können sich durch den Sprung ändern.
            self::refreshPenalties($graph);
        }

        $summary['segments'] = $segments;
        $summary['capped']   = $segments >= $maxSegments && $remaining > 1.0;

        self::writeBack($world, $graph);

        // Zuwachs je Rohstoff ermitteln
        foreach ((array) ($world['store'] ?? []) as $resource => $amount) {
            $diff = (float) $amount - (float) ($before[$resource] ?? 0);
            if (abs($diff) >= 1.0) {
                $summary['gained'][$resource] = (int) round($diff);
            }
        }
        $summary['notes'] = self::bottlenecks($graph);

        return ['world' => $world, 'summary' => $summary];
    }

    /**
     * Momentaufnahme der aktuellen Raten – für die Oberfläche (Produktion je
     * Minute, Routenauslastung, Engpässe), ohne die Welt zu verändern.
     */
    public static function snapshot(array $world): array
    {
        $graph = self::buildGraph($world);
        self::relax($graph);

        $production = [];
        $delivery   = [];
        foreach ($graph['edges'] as $edge) {
            $rate = $edge['rate'] * $graph['groups'][$edge['group']];
            if ($rate <= 0) {
                continue;
            }
            if ($edge['kind'] === 'produce' || $edge['kind'] === 'gold') {
                $res = (string) $edge['resource'];
                $production[$res] = ($production[$res] ?? 0.0) + $rate * 60.0;
            }
            if ($edge['kind'] === 'route' && $edge['to_store']) {
                $res = (string) $edge['resource'];
                $delivery[$res] = ($delivery[$res] ?? 0.0) + $rate * 60.0;
            }
        }

        $consumption = [];
        foreach ($graph['edges'] as $edge) {
            $rate = $edge['rate'] * $graph['groups'][$edge['group']];
            if ($rate > 0 && ($edge['kind'] === 'consume' || $edge['kind'] === 'food')) {
                $res = (string) $edge['resource'];
                $consumption[$res] = ($consumption[$res] ?? 0.0) + $rate * 60.0;
            }
        }

        // Tatsächlicher Durchsatz je Route (nach Drosselung durch leere Puffer,
        // volle Lager und Staus) plus der Grund, falls es stockt.
        $routes = $graph['plan']['routes'];
        foreach ($graph['edges'] as $edge) {
            if ($edge['kind'] !== 'route') {
                continue;
            }
            $id = (string) $edge['ref'];
            if (!isset($routes[$id])) {
                continue;
            }
            $effective = $edge['rate'] * $graph['groups'][$edge['group']];
            $routes[$id]['effective'] = $effective;
            $routes[$id]['reason']    = self::stallReason($graph, $edge, $effective, $routes[$id]);
        }
        foreach ($routes as $id => $route) {
            if (!isset($routes[$id]['effective'])) {
                $routes[$id]['effective'] = 0.0;
                $routes[$id]['reason']    = $route['ok'] ? 'Nichts zu transportieren.' : (string) $route['note'];
            }
        }
        $graph['plan']['routes'] = $routes;

        return [
            'production'  => $production,
            'delivery'    => $delivery,
            'consumption' => $consumption,
            'routes'      => $graph['plan']['routes'],
            'bridges'     => $graph['plan']['bridges'],
            'pressure'    => $graph['pressure'],
            'hunger'      => $graph['hunger'],
            'notes'       => self::bottlenecks($graph),
        ];
    }

    /** Warum läuft eine Route langsamer als möglich? */
    private static function stallReason(array $graph, array $edge, float $effective, array $route): string
    {
        $nominal = (float) ($route['nominal'] ?? 0) * (float) ($route['jam_factor'] ?? 1);
        if ($nominal <= 0) {
            return (string) ($route['note'] ?? '');
        }
        if ($effective >= $nominal - 1e-6) {
            return ($route['jam_factor'] ?? 1) < 0.98 ? 'Stau auf der Brücke.' : '';
        }

        // Zielseite voll?
        foreach ($edge['to'] as $tank) {
            $entry = $graph['tanks'][$tank] ?? null;
            if ($entry !== null && is_finite($entry['cap']) && $entry['amount'] >= $entry['cap'] - 1e-6) {
                return $entry['kind'] === 'class'
                    ? 'Das Lager ist voll – baue oder verbessere Lagergebäude.'
                    : 'Der Eingang des Zielgebäudes ist voll.';
            }
        }

        // Quelle leer?
        foreach ($edge['from'] as $tank) {
            $entry = $graph['tanks'][$tank] ?? null;
            if ($entry !== null && $entry['amount'] <= 1e-6) {
                return 'Es wird weniger hergestellt, als die Träger tragen könnten.';
            }
        }

        return 'Die Zulieferung reicht nicht für die volle Auslastung.';
    }

    // =================================================================
    // Netz aufbauen
    // =================================================================

    private static function buildGraph(array $world): array
    {
        $effects  = World::effects($world);
        $research = Research::multipliers($world);
        $plan     = Logistics::plan($world);
        $capacity = $effects['capacity'];

        $graph = [
            'tanks'    => [],
            'edges'    => [],
            'groups'   => [],
            'world'    => $world,
            'effects'  => $effects,
            'research' => $research,
            'plan'     => $plan,
            'pressure' => 1.0,
            'hunger'   => false,
            'store'    => [],
        ];

        // --- Behälter: Lager je Rohstoff und je Klasse ---------------
        $classSums = ['bulk' => 0.0, 'food' => 0.0, 'goods' => 0.0, 'precious' => 0.0, 'special' => 0.0];
        foreach ((array) App::balance('resources', []) as $key => $def) {
            $amount = (float) ($world['store'][$key] ?? 0);
            $class  = (string) ($def['class'] ?? 'bulk');
            $graph['tanks']['sr:' . $key] = ['amount' => $amount, 'cap' => INF, 'kind' => 'store', 'ref' => $key];
            $classSums[$class] = ($classSums[$class] ?? 0.0) + $amount;
        }
        foreach ($classSums as $class => $sum) {
            $graph['tanks']['sc:' . $class] = [
                'amount' => $sum,
                'cap'    => max(0.0, (float) ($capacity[$class] ?? 0.0)),
                'kind'   => 'class',
                'ref'    => $class,
            ];
        }

        // --- Arbeitermangel senkt alles gleichmässig -----------------
        $needed = max(1.0, (float) $effects['workers_needed']);
        $graph['pressure'] = min(1.0, (float) $effects['population'] / $needed);

        // --- Gebäude: Puffer und Flüsse ------------------------------
        foreach ($world['buildings'] as $id => $building) {
            $def = World::buildingDef((string) $building['type']);
            if ($def === []) {
                continue;
            }

            $level   = max(1, (int) $building['level']);
            $growth  = (float) ($def['effect_growth'] ?? App::balance('formulas.effect_growth_default', 1.01));
            $damage  = max(0.0, min(1.0, (float) ($building['damage'] ?? 0)));
            $active  = ($building['enabled'] ?? true) && $damage < 1.0;
            $bufCap  = Formulas::value((float) ($def['buffer'] ?? 0), $level, $growth);

            foreach (array_keys((array) ($def['consumes'] ?? [])) as $resource) {
                $graph['tanks']['bi:' . $id . ':' . $resource] = [
                    'amount' => (float) ($building['in'][$resource] ?? 0),
                    'cap'    => $bufCap,
                    'kind'   => 'in',
                    'ref'    => [$id, $resource],
                ];
            }
            foreach (array_keys((array) ($def['produces'] ?? [])) as $resource) {
                $graph['tanks']['bo:' . $id . ':' . $resource] = [
                    'amount' => (float) ($building['out'][$resource] ?? 0),
                    'cap'    => $bufCap,
                    'kind'   => 'out',
                    'ref'    => [$id, $resource],
                ];
            }

            if (!$active) {
                continue;
            }

            $multiplier = self::outputMultiplier((string) $building['type'], $def, $research)
                * (1.0 - $damage) * $graph['pressure'];

            $group = count($graph['groups']);
            $graph['groups'][$group] = 1.0;
            $hasFlow = false;

            foreach ((array) ($def['consumes'] ?? []) as $resource => $perMinute) {
                $graph['edges'][] = [
                    'from' => ['bi:' . $id . ':' . $resource],
                    'to'   => [],
                    'rate' => Formulas::value((float) $perMinute, $level, $growth) / 60.0 * $graph['pressure'],
                    'group'=> $group,
                    'kind' => 'consume',
                    'resource' => (string) $resource,
                    'ref'  => (string) $id,
                    'to_store' => false,
                ];
                $hasFlow = true;
            }
            foreach ((array) ($def['produces'] ?? []) as $resource => $perMinute) {
                $graph['edges'][] = [
                    'from' => [],
                    'to'   => ['bo:' . $id . ':' . $resource],
                    'rate' => Formulas::value((float) $perMinute, $level, $growth) / 60.0 * $multiplier,
                    'group'=> $group,
                    'kind' => 'produce',
                    'resource' => (string) $resource,
                    'ref'  => (string) $id,
                    'to_store' => false,
                ];
                $hasFlow = true;
            }

            // Märkte erzeugen Gold direkt im Lager.
            $goldRate = 0.0;
            foreach ((array) ($def['effects'] ?? []) as $key => $base) {
                if ($key === 'gold_rate') {
                    $goldRate = Formulas::value((float) $base, $level, $growth);
                }
            }
            if ($goldRate > 0) {
                $graph['edges'][] = [
                    'from' => [],
                    'to'   => ['sr:gold', 'sc:precious'],
                    'rate' => $goldRate / 60.0 * $graph['pressure'],
                    'group'=> $group,
                    'kind' => 'gold',
                    'resource' => 'gold',
                    'ref'  => (string) $id,
                    'to_store' => true,
                ];
                $hasFlow = true;
            }

            if (!$hasFlow) {
                array_pop($graph['groups']);
            }
        }

        // --- Routen ---------------------------------------------------
        foreach ($plan['routes'] as $id => $route) {
            if (!$route['ok'] || $route['flow'] <= 0) {
                continue;
            }
            $resource = (string) $route['resource'];
            $from     = self::endpointTanks((array) $route['src'], $resource, $graph);
            $to       = self::endpointTanks((array) $route['dst'], $resource, $graph);

            if ($from === null || $to === null) {
                continue;
            }

            $group = count($graph['groups']);
            $graph['groups'][$group] = 1.0;
            $graph['edges'][] = [
                'from' => $from,
                'to'   => $to,
                'rate' => $route['flow'] * $graph['pressure'],
                'group'=> $group,
                'kind' => 'route',
                'resource' => $resource,
                'ref'  => (string) $id,
                'to_store' => ($route['dst'][0] ?? '') === 'store',
            ];
        }

        // --- Verpflegung ---------------------------------------------
        $breadPerMinute = (float) $effects['population'] * (float) App::balance('population.bread_per_pop', 0.02);
        if ($breadPerMinute > 0 && isset($graph['tanks']['sr:bread'])) {
            $group = count($graph['groups']);
            $graph['groups'][$group] = 1.0;
            $graph['edges'][] = [
                'from' => ['sr:bread', 'sc:food'],
                'to'   => [],
                'rate' => $breadPerMinute / 60.0,
                'group'=> $group,
                'kind' => 'food',
                'resource' => 'bread',
                'ref'  => 'volk',
                'to_store' => false,
            ];
            $graph['food_group'] = $group;
            $graph['food_rate']  = $breadPerMinute / 60.0;
        }

        self::refreshPenalties($graph);

        return $graph;
    }

    /** Multiplikator aus Forschung je nach Gebäudeart. */
    private static function outputMultiplier(string $type, array $def, array $research): float
    {
        $role = (string) ($def['role'] ?? '');
        $isMine = in_array($type, ['lumberjack', 'quarry', 'iron_mine', 'copper_mine', 'coal_mine', 'crystal_mine'], true);
        $isFarm = in_array($type, ['grain_farm', 'vegetable_farm', 'orchard', 'pasture', 'horse_ranch'], true);

        if ($type === 'aether_well') {
            return $research['aether_output'];
        }
        if ($isMine) {
            return $research['mine_output'];
        }
        if ($isFarm) {
            return $research['farm_output'];
        }
        if ($role === 'converter') {
            return $research['craft_output'];
        }

        return 1.0;
    }

    /** @return string[]|null Behälter eines Routenendes */
    private static function endpointTanks(array $endpoint, string $resource, array $graph): ?array
    {
        if (($endpoint[0] ?? '') === 'b') {
            $buildingId = (string) ($endpoint[1] ?? '');
            foreach (['bo:', 'bi:'] as $prefix) {
                $key = $prefix . $buildingId . ':' . $resource;
                if (isset($graph['tanks'][$key])) {
                    return [$key];
                }
            }

            return null;
        }

        $class = World::resourceClass($resource);
        if (!isset($graph['tanks']['sr:' . $resource])) {
            return null;
        }

        return ['sr:' . $resource, 'sc:' . $class];
    }

    // =================================================================
    // Flüsse ausgleichen
    // =================================================================

    /**
     * Flüsse so weit drosseln, dass kein Behälter über seine Grenzen läuft.
     * Eine Gruppe (z. B. eine Mühle mit Eingang und Ausgang) wird immer als
     * Ganzes gedrosselt – sonst entstünde Materie aus dem Nichts.
     */
    private static function relax(array &$graph): void
    {
        foreach ($graph['groups'] as $i => $_) {
            $graph['groups'][$i] = 1.0;
        }

        for ($iteration = 0; $iteration < self::RELAX_ITERATIONS; $iteration++) {
            $changed = false;

            $in  = [];
            $out = [];
            foreach ($graph['edges'] as $edge) {
                $rate = $edge['rate'] * $graph['groups'][$edge['group']];
                if ($rate <= 0) {
                    continue;
                }
                foreach ($edge['to'] as $tank) {
                    $in[$tank] = ($in[$tank] ?? 0.0) + $rate;
                }
                foreach ($edge['from'] as $tank) {
                    $out[$tank] = ($out[$tank] ?? 0.0) + $rate;
                }
            }

            foreach ($graph['tanks'] as $key => $tank) {
                $inflow  = $in[$key] ?? 0.0;
                $outflow = $out[$key] ?? 0.0;

                // Behälter voll: Zufluss auf den Abfluss begrenzen.
                if (is_finite($tank['cap']) && $tank['amount'] >= $tank['cap'] - 1e-6 && $inflow > $outflow + 1e-9) {
                    $factor = $inflow > 0 ? $outflow / $inflow : 0.0;
                    if (self::scaleGroups($graph, $key, 'to', $factor)) {
                        $changed = true;
                    }
                }

                // Behälter leer: Abfluss auf den Zufluss begrenzen.
                if ($tank['amount'] <= 1e-6 && $outflow > $inflow + 1e-9) {
                    $factor = $outflow > 0 ? $inflow / $outflow : 0.0;
                    if (self::scaleGroups($graph, $key, 'from', $factor)) {
                        $changed = true;
                    }
                }
            }

            if (!$changed) {
                break;
            }
        }
    }

    /** Alle Gruppen drosseln, die an einem Behälter in einer Richtung hängen. */
    private static function scaleGroups(array &$graph, string $tank, string $direction, float $factor): bool
    {
        $factor = max(0.0, min(1.0, $factor));
        $groups = [];

        foreach ($graph['edges'] as $edge) {
            if (in_array($tank, $edge[$direction], true) && $edge['rate'] > 0) {
                $groups[$edge['group']] = true;
            }
        }

        $changed = false;
        foreach (array_keys($groups) as $group) {
            $target = $graph['groups'][$group] * $factor;
            if ($target < $graph['groups'][$group] - 1e-9) {
                $graph['groups'][$group] = $target;
                $changed = true;
            }
        }

        return $changed;
    }

    /** @return array<string,float> Nettorate je Behälter */
    private static function netRates(array $graph): array
    {
        $net = [];
        foreach ($graph['tanks'] as $key => $_) {
            $net[$key] = 0.0;
        }

        foreach ($graph['edges'] as $edge) {
            $rate = $edge['rate'] * $graph['groups'][$edge['group']];
            if ($rate <= 0) {
                continue;
            }
            foreach ($edge['to'] as $tank) {
                $net[$tank] = ($net[$tank] ?? 0.0) + $rate;
            }
            foreach ($edge['from'] as $tank) {
                $net[$tank] = ($net[$tank] ?? 0.0) - $rate;
            }
        }

        return $net;
    }

    /** Zeit bis zum nächsten Ereignis (Behälter voll oder leer). */
    private static function nextEvent(array $graph, array $net): float
    {
        $dt = INF;

        foreach ($graph['tanks'] as $key => $tank) {
            $rate = $net[$key] ?? 0.0;
            if (abs($rate) < 1e-9) {
                continue;
            }

            if ($rate > 0 && is_finite($tank['cap'])) {
                $space = $tank['cap'] - $tank['amount'];
                if ($space > 1e-6) {
                    $dt = min($dt, $space / $rate);
                }
            } elseif ($rate < 0) {
                if ($tank['amount'] > 1e-6) {
                    $dt = min($dt, $tank['amount'] / -$rate);
                }
            }
        }

        return is_finite($dt) ? max(0.0, $dt) : INF;
    }

    private static function applyRates(array &$graph, array $net, float $dt): void
    {
        foreach ($graph['tanks'] as $key => $tank) {
            $rate   = $net[$key] ?? 0.0;
            $amount = $tank['amount'] + $rate * $dt;

            if (is_finite($tank['cap'])) {
                $amount = min($amount, $tank['cap']);
            }
            $graph['tanks'][$key]['amount'] = max(0.0, $amount);
        }
    }

    /** Hunger und Arbeitermangel nach jedem Abschnitt neu bewerten. */
    private static function refreshPenalties(array &$graph): void
    {
        if (!isset($graph['food_group'])) {
            $graph['hunger'] = false;

            return;
        }

        $bread   = $graph['tanks']['sr:bread']['amount'] ?? 0.0;
        $rate    = (float) ($graph['food_rate'] ?? 0.0);
        $hungry  = $bread <= 1e-6 && $rate > 0;

        if ($hungry === $graph['hunger']) {
            return;
        }
        $graph['hunger'] = $hungry;

        $penalty = (float) App::balance('population.hunger_penalty', 0.55);
        foreach ($graph['edges'] as $index => $edge) {
            if ($edge['kind'] !== 'produce') {
                continue;
            }
            $graph['edges'][$index]['rate'] = $hungry
                ? $edge['rate'] * $penalty
                : $edge['rate'] / max(0.01, $penalty);
        }
    }

    // =================================================================
    // Zurückschreiben
    // =================================================================

    private static function writeBack(array &$world, array $graph): void
    {
        foreach ($graph['tanks'] as $key => $tank) {
            $amount = max(0.0, $tank['amount']);

            if (str_starts_with($key, 'sr:')) {
                $resource = substr($key, 3);
                $world['store'][$resource] = Num::clampAmount($amount);
                continue;
            }
            if (str_starts_with($key, 'bo:') || str_starts_with($key, 'bi:')) {
                [$id, $resource] = $tank['ref'];
                $slot = str_starts_with($key, 'bo:') ? 'out' : 'in';
                if (isset($world['buildings'][$id])) {
                    $world['buildings'][$id][$slot][$resource] = round($amount, 3);
                }
            }
        }

        // Leere Lagerposten entfernen, damit das Dokument klein bleibt.
        foreach ((array) $world['store'] as $resource => $amount) {
            if ((float) $amount <= 0) {
                unset($world['store'][$resource]);
            }
        }
    }

    // =================================================================
    // Engpässe erklären
    // =================================================================

    /** @return array<int,array{type:string,text:string,ref?:string}> */
    private static function bottlenecks(array $graph): array
    {
        $notes = [];

        foreach ($graph['tanks'] as $key => $tank) {
            if (!is_finite($tank['cap']) || $tank['cap'] <= 0) {
                continue;
            }
            if ($tank['amount'] < $tank['cap'] - 1e-6) {
                continue;
            }

            if ($tank['kind'] === 'class') {
                $name = (string) App::balance('storage_classes.' . $tank['ref'] . '.name', $tank['ref']);
                $notes[] = ['type' => 'storage_full', 'text' => $name . ' ist voll – baue oder verbessere Lager.', 'ref' => (string) $tank['ref']];
            } elseif ($tank['kind'] === 'out') {
                [$id, $resource] = $tank['ref'];
                $building = $graph['world']['buildings'][$id] ?? null;
                if ($building !== null) {
                    $name = (string) App::balance('buildings.' . $building['type'] . '.name', $building['type']);
                    $resName = (string) App::balance('resources.' . $resource . '.name', $resource);
                    $notes[] = [
                        'type' => 'buffer_full',
                        'text' => $name . ': Lagerplatz für ' . $resName . ' voll – es fehlt Abtransport.',
                        'ref'  => (string) $id,
                    ];
                }
            } elseif ($tank['kind'] === 'in') {
                continue;
            }
        }

        foreach ($graph['plan']['routes'] as $id => $route) {
            if (!$route['ok'] && $route['note'] !== '') {
                $notes[] = ['type' => 'route_broken', 'text' => 'Route ' . $id . ': ' . $route['note'], 'ref' => (string) $id];
            } elseif ($route['jam_factor'] < 0.95) {
                $notes[] = [
                    'type' => 'jam',
                    'text' => 'Stau auf Brücke ' . (string) $route['jam_bridge'] . ' – Route ' . $id . ' läuft nur mit '
                        . round($route['jam_factor'] * 100) . ' %.',
                    'ref'  => (string) $route['jam_bridge'],
                ];
            }
        }

        if ($graph['hunger']) {
            $notes[] = ['type' => 'hunger', 'text' => 'Das Brot ist alle – die Produktion sinkt. Baue Bäckereien.'];
        }
        if ($graph['pressure'] < 0.999) {
            $notes[] = [
                'type' => 'workers',
                'text' => 'Es fehlen Arbeiter – nur ' . round($graph['pressure'] * 100) . ' % Leistung. Baue Wohnhäuser.',
            ];
        }

        // Doppelte Hinweise entfernen und begrenzen
        $seen = [];
        $out  = [];
        foreach ($notes as $note) {
            $key = $note['type'] . '|' . ($note['ref'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $note;
            if (count($out) >= 12) {
                break;
            }
        }

        return $out;
    }

    private static function emptySummary(): array
    {
        return [
            'elapsed'   => 0,
            'simulated' => 0,
            'skipped'   => 0,
            'segments'  => 0,
            'capped'    => false,
            'gained'    => [],
            'notes'     => [],
        ];
    }
}
