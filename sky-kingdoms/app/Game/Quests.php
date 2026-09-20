<?php

declare(strict_types=1);

namespace SkyKingdoms\Game;

use SkyKingdoms\Core\App;

/**
 * Aufgaben (täglich und langfristig) sowie Erfolge.
 *
 * Die Vorgaben stehen in defaults(); beim Installieren werden sie nach
 * meta/quests.json geschrieben und sind danach im Adminbereich änderbar.
 * Der Fortschritt wird jederzeit aus dem Spielstand berechnet – es gibt keine
 * separaten Zähler, die auseinanderlaufen könnten.
 */
final class Quests
{
    public const KEY         = 'meta/quests.json';
    public const ACHIEVEMENTS = 'meta/achievements.json';

    /** Vorgaben in den Datenspeicher schreiben (Installer und Adminbereich). */
    public static function install(bool $force = false): void
    {
        $store = App::store();
        if ($force || !$store->exists(self::KEY)) {
            $store->write(self::KEY, ['items' => self::defaults()]);
        }
        if ($force || !$store->exists(self::ACHIEVEMENTS)) {
            $store->write(self::ACHIEVEMENTS, ['items' => self::achievementDefaults()]);
        }
    }

    /** @return array<int,array<mixed>> */
    public static function definitions(): array
    {
        $doc = App::store()->read(self::KEY, ['items' => self::defaults()]);

        return (array) ($doc['items'] ?? []);
    }

    /** @return array<int,array<mixed>> */
    public static function achievementDefinitions(): array
    {
        $doc = App::store()->read(self::ACHIEVEMENTS, ['items' => self::achievementDefaults()]);

        return (array) ($doc['items'] ?? []);
    }

    // =================================================================
    // Fortschritt
    // =================================================================

    /** Alle Messwerte eines Königreichs in einem Rutsch. */
    public static function metrics(array $world): array
    {
        $levels = 0;
        $storageLevels = 0;
        foreach ($world['buildings'] as $building) {
            $levels += max(1, (int) $building['level']);
            $def = World::buildingDef((string) $building['type']);
            if (($def['role'] ?? '') === 'storage') {
                $storageLevels += max(1, (int) $building['level']);
            }
        }

        $researchLevels = 0;
        foreach ((array) ($world['research'] ?? []) as $level) {
            $researchLevels += max(0, (int) $level);
        }

        $bridgeLevels = 0;
        foreach ($world['bridges'] as $bridge) {
            $bridgeLevels += max(1, (int) $bridge['level']);
        }

        $stats = (array) ($world['stats'] ?? []);

        return [
            'buildings'       => count((array) $world['buildings']),
            'building_levels' => $levels,
            'storage_levels'  => $storageLevels,
            'islands'         => count((array) $world['islands']),
            'routes'          => count((array) $world['routes']),
            'bridges'         => count((array) $world['bridges']),
            'bridge_levels'   => $bridgeLevels,
            'research_levels' => $researchLevels,
            'units'           => array_sum(array_map('intval', (array) ($world['units'] ?? []))),
            'upgrades'        => (int) ($stats['upgrades'] ?? 0),
            'attacks_won'     => (int) ($stats['attacks_won'] ?? 0),
            'defenses_won'    => (int) ($stats['defenses_won'] ?? 0),
            'score'           => World::score($world),
            'modes'           => count(array_filter((array) ($world['modes'] ?? []))),
            'carriers'        => array_sum(array_map(static fn (array $r): int => (int) ($r['carriers'] ?? 1), (array) ($world['routes'] ?? []))),
        ];
    }

    /**
     * Aufgabenliste mit Fortschritt für die Oberfläche.
     *
     * @return array<int,array<mixed>>
     */
    public static function forPlayer(array $world): array
    {
        $metrics = self::metrics($world);
        $state   = (array) ($world['quests'] ?? []);
        $today   = date('Y-m-d');
        $out     = [];

        foreach (self::definitions() as $quest) {
            $id     = (string) ($quest['id'] ?? '');
            $metric = (string) ($quest['metric'] ?? '');
            $goal   = max(1, (int) ($quest['goal'] ?? 1));
            $daily  = ($quest['type'] ?? 'main') === 'daily';

            $entry   = (array) ($state[$id] ?? []);
            $claimed = $daily
                ? (string) ($entry['day'] ?? '') === $today
                : !empty($entry['claimed']);

            $base    = $daily ? (int) ($entry['base'] ?? 0) : 0;
            $current = max(0, (int) ($metrics[$metric] ?? 0) - $base);

            $out[] = [
                'id'       => $id,
                'type'     => $daily ? 'daily' : 'main',
                'name'     => (string) ($quest['name'] ?? $id),
                'desc'     => (string) ($quest['desc'] ?? ''),
                'metric'   => $metric,
                'goal'     => $goal,
                'current'  => min($current, $goal),
                'done'     => $current >= $goal,
                'claimed'  => $claimed,
                'reward'   => (array) ($quest['reward'] ?? []),
            ];
        }

        return $out;
    }

    /**
     * Belohnung abholen.
     *
     * @return array{ok:bool,error?:string,world?:array,reward?:array}
     */
    public static function claim(array $world, string $questId): array
    {
        $quest = null;
        foreach (self::definitions() as $item) {
            if ((string) ($item['id'] ?? '') === $questId) {
                $quest = $item;
                break;
            }
        }
        if ($quest === null) {
            return ['ok' => false, 'error' => 'Diese Aufgabe gibt es nicht.'];
        }

        $progress = null;
        foreach (self::forPlayer($world) as $item) {
            if ($item['id'] === $questId) {
                $progress = $item;
                break;
            }
        }
        if ($progress === null || !$progress['done']) {
            return ['ok' => false, 'error' => 'Diese Aufgabe ist noch nicht erfüllt.'];
        }
        if ($progress['claimed']) {
            return ['ok' => false, 'error' => 'Diese Belohnung hast du schon erhalten.'];
        }

        $reward = Economy::credit($world, (array) ($quest['reward'] ?? []));

        if (($quest['type'] ?? 'main') === 'daily') {
            $metrics = self::metrics($world);
            $world['quests'][$questId] = [
                'day'  => date('Y-m-d'),
                'base' => (int) ($metrics[(string) $quest['metric']] ?? 0),
            ];
        } else {
            $world['quests'][$questId] = ['claimed' => true, 'ts' => time()];
        }

        return ['ok' => true, 'world' => $world, 'reward' => $reward['stored'], 'lost' => $reward['lost']];
    }

    /**
     * Erfolge prüfen. Neu erreichte werden vermerkt und zurückgegeben.
     *
     * @return array{world:array,unlocked:array<int,array>}
     */
    public static function checkAchievements(array $world): array
    {
        $metrics  = self::metrics($world);
        $have     = (array) ($world['achievements'] ?? []);
        $unlocked = [];

        foreach (self::achievementDefinitions() as $achievement) {
            $id = (string) ($achievement['id'] ?? '');
            if ($id === '' || isset($have[$id])) {
                continue;
            }
            $metric = (string) ($achievement['metric'] ?? '');
            if ((int) ($metrics[$metric] ?? 0) >= (int) ($achievement['goal'] ?? PHP_INT_MAX)) {
                $world['achievements'][$id] = time();
                $unlocked[] = $achievement;
            }
        }

        return ['world' => $world, 'unlocked' => $unlocked];
    }

    /** @return array<int,array<mixed>> */
    public static function achievementsForPlayer(array $world): array
    {
        $metrics = self::metrics($world);
        $have    = (array) ($world['achievements'] ?? []);
        $out     = [];

        foreach (self::achievementDefinitions() as $achievement) {
            $id   = (string) ($achievement['id'] ?? '');
            $goal = max(1, (int) ($achievement['goal'] ?? 1));
            $out[] = [
                'id'      => $id,
                'name'    => (string) ($achievement['name'] ?? $id),
                'desc'    => (string) ($achievement['desc'] ?? ''),
                'goal'    => $goal,
                'current' => min($goal, (int) ($metrics[(string) ($achievement['metric'] ?? '')] ?? 0)),
                'done'    => isset($have[$id]),
                'since'   => (int) ($have[$id] ?? 0),
            ];
        }

        return $out;
    }

    // =================================================================
    // Vorgaben
    // =================================================================

    /** @return array<int,array<mixed>> */
    public static function defaults(): array
    {
        return [
            // --- Einstieg ---------------------------------------------
            ['id' => 'first_steps',  'type' => 'main', 'metric' => 'buildings',       'goal' => 12,   'name' => 'Erste Schritte',        'desc' => 'Errichte 12 Gebäude in deinem Königreich.',            'reward' => ['gold' => 150, 'wood' => 400]],
            ['id' => 'logistician',  'type' => 'main', 'metric' => 'routes',          'goal' => 5,    'name' => 'Wegewart',              'desc' => 'Richte 5 Transportrouten ein.',                        'reward' => ['gold' => 200, 'tools' => 20]],
            ['id' => 'bridge_master','type' => 'main', 'metric' => 'bridge_levels',   'goal' => 12,   'name' => 'Brückenbaumeister',     'desc' => 'Bringe deine Brücken auf zusammen 12 Stufen.',         'reward' => ['gold' => 250, 'stone' => 600]],
            ['id' => 'storekeeper',  'type' => 'main', 'metric' => 'storage_levels',  'goal' => 20,   'name' => 'Lagerverwalter',        'desc' => 'Baue deine Lager auf zusammen 20 Stufen aus.',         'reward' => ['gold' => 300, 'parts' => 25]],
            ['id' => 'scholar',      'type' => 'main', 'metric' => 'research_levels', 'goal' => 10,   'name' => 'Gelehrter',             'desc' => 'Erforsche insgesamt 10 Stufen.',                       'reward' => ['gold' => 400, 'crystal' => 25]],
            ['id' => 'settler',      'type' => 'main', 'metric' => 'islands',         'goal' => 5,    'name' => 'Siedler',               'desc' => 'Schalte eine fünfte Insel frei.',                      'reward' => ['gold' => 800, 'wood' => 2000]],
            ['id' => 'commander',    'type' => 'main', 'metric' => 'units',           'goal' => 20,   'name' => 'Heerführer',            'desc' => 'Bilde 20 Einheiten aus.',                              'reward' => ['gold' => 500, 'weapons' => 10]],
            ['id' => 'victor',       'type' => 'main', 'metric' => 'attacks_won',     'goal' => 3,    'name' => 'Siegreich',             'desc' => 'Gewinne 3 Angriffsmissionen.',                         'reward' => ['gold' => 700, 'iron' => 800]],
            ['id' => 'fleet',        'type' => 'main', 'metric' => 'modes',           'goal' => 4,    'name' => 'Fuhrpark',              'desc' => 'Schalte 4 verschiedene Transportmittel frei.',         'reward' => ['gold' => 900, 'horse' => 4]],
            ['id' => 'grand_realm',  'type' => 'main', 'metric' => 'building_levels', 'goal' => 500,  'name' => 'Grosses Reich',         'desc' => 'Erreiche 500 Gebäudestufen insgesamt.',                'reward' => ['gold' => 2500, 'crystal' => 150]],

            // --- Täglich ------------------------------------------------
            ['id' => 'daily_upgrade', 'type' => 'daily', 'metric' => 'upgrades',      'goal' => 5,    'name' => 'Tägliche Bauarbeiten',  'desc' => 'Führe heute 5 Verbesserungen durch.',                  'reward' => ['gold' => 120]],
            ['id' => 'daily_build',   'type' => 'daily', 'metric' => 'buildings',     'goal' => 2,    'name' => 'Neues Bauwerk',         'desc' => 'Errichte heute 2 neue Gebäude.',                       'reward' => ['gold' => 100, 'bread' => 150]],
            ['id' => 'daily_carrier', 'type' => 'daily', 'metric' => 'carriers',      'goal' => 3,    'name' => 'Mehr Hände',            'desc' => 'Stelle heute 3 zusätzliche Träger ein.',               'reward' => ['gold' => 90, 'wood' => 300]],
        ];
    }

    /** @return array<int,array<mixed>> */
    public static function achievementDefaults(): array
    {
        return [
            ['id' => 'ach_build_50',   'metric' => 'buildings',       'goal' => 50,    'name' => 'Baumeister',         'desc' => '50 Gebäude gleichzeitig besitzen.'],
            ['id' => 'ach_levels_1000','metric' => 'building_levels', 'goal' => 1000,  'name' => 'Tausend Stufen',     'desc' => '1000 Gebäudestufen insgesamt.'],
            ['id' => 'ach_levels_10k', 'metric' => 'building_levels', 'goal' => 10000, 'name' => 'Zehntausend Stufen', 'desc' => '10 000 Gebäudestufen insgesamt.'],
            ['id' => 'ach_islands_8',  'metric' => 'islands',         'goal' => 8,     'name' => 'Inselkönig',         'desc' => 'Acht Inseln besitzen.'],
            ['id' => 'ach_research_50','metric' => 'research_levels', 'goal' => 50,    'name' => 'Denkerkreis',        'desc' => '50 Forschungsstufen erreichen.'],
            ['id' => 'ach_attacks_25', 'metric' => 'attacks_won',     'goal' => 25,    'name' => 'Feldherr',           'desc' => '25 Angriffe gewinnen.'],
            ['id' => 'ach_defense_25', 'metric' => 'defenses_won',    'goal' => 25,    'name' => 'Schildwache',        'desc' => '25 Angriffe abwehren.'],
            ['id' => 'ach_score_100k', 'metric' => 'score',           'goal' => 100000,'name' => 'Legende',            'desc' => '100 000 Punkte erreichen.'],
        ];
    }
}
