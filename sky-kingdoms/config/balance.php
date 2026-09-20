<?php
/**
 * Sky Kingdoms – Balance: Rohstoffe, Inseln, Gebäude, Transport, Einheiten, Forschung.
 * -----------------------------------------------------------------------------------
 * ALLE Spielwerte stehen hier. Der Adminbereich schreibt Änderungen nach
 * storage/data/meta/balance.json; dort gefundene Werte überschreiben diese Datei.
 *
 * Grundregeln (ausführlich in GAME_DESIGN.md):
 *   Wirkung  : wert(L)   = basis  * wachstum^(L-1)        -> z. B. +1,0 % je Level
 *   Kosten   : kosten(L) = basis  * kostenwachstum^(L-1)
 *   Es gibt KEIN Maximallevel. Die einzige Grenze ist der sichere Zahlenbereich.
 *
 * Produktionsangaben sind Einheiten pro Minute auf Level 1.
 */

declare(strict_types=1);

defined('SK_ROOT') || exit('Direkter Zugriff nicht erlaubt.');

return [
    // =================================================================
    // 1. Formel-Konstanten
    // =================================================================
    'formulas' => [
        'cost_growth_default'   => 1.0575,
        'effect_growth_default' => 1.0100,
        'max_amount'            => 9.0e17,
        'bulk_steps'            => [1, 10, 100],
        'milestones'            => [10, 25, 50, 100, 250, 500],
    ],

    // =================================================================
    // 2. Rohstoffe   (class: bulk | food | goods | precious | special)
    // =================================================================
    'resources' => [
        'wood'       => ['name' => 'Holz',       'class' => 'bulk',     'order' => 10,  'hud' => true,  'color' => '#a9743f'],
        'stone'      => ['name' => 'Stein',      'class' => 'bulk',     'order' => 20,  'hud' => true,  'color' => '#9aa3ad'],
        'iron'       => ['name' => 'Eisen',      'class' => 'bulk',     'order' => 30,  'hud' => true,  'color' => '#7d8894'],
        'copper'     => ['name' => 'Kupfer',     'class' => 'bulk',     'order' => 40,  'hud' => false, 'color' => '#c87f4a'],
        'coal'       => ['name' => 'Kohle',      'class' => 'bulk',     'order' => 50,  'hud' => false, 'color' => '#4a4a55'],
        'grain'      => ['name' => 'Getreide',   'class' => 'food',     'order' => 60,  'hud' => false, 'color' => '#e0b551'],
        'vegetables' => ['name' => 'Gemüse',     'class' => 'food',     'order' => 70,  'hud' => false, 'color' => '#6fbf5e'],
        'fruit'      => ['name' => 'Obst',       'class' => 'food',     'order' => 80,  'hud' => false, 'color' => '#e2604f'],
        'meat'       => ['name' => 'Fleisch',    'class' => 'food',     'order' => 90,  'hud' => false, 'color' => '#c0574f'],
        'flour'      => ['name' => 'Mehl',       'class' => 'food',     'order' => 100, 'hud' => false, 'color' => '#efe3c7'],
        'bread'      => ['name' => 'Brot',       'class' => 'food',     'order' => 110, 'hud' => true,  'color' => '#d8a05a'],
        'tools'      => ['name' => 'Werkzeuge',  'class' => 'goods',    'order' => 120, 'hud' => false, 'color' => '#8fb2c9'],
        'parts'      => ['name' => 'Bauteile',   'class' => 'goods',    'order' => 130, 'hud' => false, 'color' => '#b09a6b'],
        'weapons'    => ['name' => 'Waffen',     'class' => 'goods',    'order' => 140, 'hud' => false, 'color' => '#9c6b8f'],
        'horse'      => ['name' => 'Pferde',     'class' => 'special',  'order' => 150, 'hud' => false, 'color' => '#a8763f'],
        'crystal'    => ['name' => 'Kristalle',  'class' => 'precious', 'order' => 160, 'hud' => true,  'color' => '#6fd8ff'],
        'aether'     => ['name' => 'Ätherstaub', 'class' => 'precious', 'order' => 170, 'hud' => false, 'color' => '#c79bff'],
        'gold'       => ['name' => 'Goldmünzen', 'class' => 'precious', 'order' => 180, 'hud' => true,  'color' => '#ffc94a'],
    ],

    'storage_classes' => [
        'bulk'     => ['name' => 'Baustofflager'],
        'food'     => ['name' => 'Speicher'],
        'goods'    => ['name' => 'Warenhaus'],
        'precious' => ['name' => 'Schatzkammer'],
        'special'  => ['name' => 'Stallungen'],
    ],

    // =================================================================
    // 3. Inseltypen
    // grid: Spalten x Zeilen; mask: '#' = bebaubar, '.' = Fels/Rand
    // =================================================================
    'island_types' => [
        'main' => [
            'name' => 'Hauptinsel', 'desc' => 'Burg, Verwaltung, Werkstätten und Forschung.',
            'grid' => [12, 9], 'tint' => '#7ec97a', 'unlock_cost' => [],
            'mask' => [
                '...######...',
                '.##########.',
                '############',
                '############',
                '############',
                '############',
                '.##########.',
                '..########..',
                '....####....',
            ],
        ],
        'farm' => [
            'name' => 'Bauerninsel', 'desc' => 'Getreide, Gemüse, Obst, Tiere und Verarbeitung.',
            'grid' => [11, 8], 'tint' => '#9ad86f',
            'unlock_cost' => ['wood' => 1200, 'stone' => 900, 'gold' => 250],
            'mask' => [
                '..#######..',
                '.#########.',
                '###########',
                '###########',
                '###########',
                '###########',
                '.#########.',
                '...#####...',
            ],
        ],
        'resource' => [
            'name' => 'Rohstoffinsel', 'desc' => 'Holz, Stein, Erze, Kohle und Kristalle.',
            'grid' => [11, 8], 'tint' => '#a89d84',
            'unlock_cost' => ['wood' => 1500, 'stone' => 1500, 'gold' => 300],
            'mask' => [
                '.#########.',
                '###########',
                '###########',
                '###########',
                '###########',
                '###########',
                '.#########.',
                '..#######..',
            ],
        ],
        'storage' => [
            'name' => 'Lagerinsel', 'desc' => 'Zentrale Einlagerung – erst hier zählt eine Ware.',
            'grid' => [10, 8], 'tint' => '#c7b48d',
            'unlock_cost' => ['wood' => 900, 'stone' => 1400, 'gold' => 200],
            'mask' => [
                '..######..',
                '.########.',
                '##########',
                '##########',
                '##########',
                '##########',
                '.########.',
                '...####...',
            ],
        ],
        'military' => [
            'name' => 'Militärinsel', 'desc' => 'Kasernen, Übungsplätze und schwere Verteidigung.',
            'grid' => [10, 8], 'tint' => '#b59a9a',
            'unlock_cost' => ['wood' => 8000, 'stone' => 9000, 'iron' => 4000, 'gold' => 1500],
            'requires' => ['research' => ['tactics' => 5]],
            'mask' => [
                '.########.',
                '##########',
                '##########',
                '##########',
                '##########',
                '##########',
                '.########.',
                '..######..',
            ],
        ],
        'trade' => [
            'name' => 'Handelsinsel', 'desc' => 'Märkte, Kontore und Handelsrouten.',
            'grid' => [10, 7], 'tint' => '#d3b98f',
            'unlock_cost' => ['wood' => 9000, 'stone' => 7000, 'gold' => 3000],
            'requires' => ['research' => ['logistics' => 8]],
            'mask' => [
                '..######..',
                '.########.',
                '##########',
                '##########',
                '##########',
                '.########.',
                '...####...',
            ],
        ],
        'research' => [
            'name' => 'Forschungsinsel', 'desc' => 'Bibliotheken, Observatorien und Labore.',
            'grid' => [10, 7], 'tint' => '#9fb7d8',
            'unlock_cost' => ['stone' => 12000, 'crystal' => 600, 'gold' => 4000],
            'requires' => ['research' => ['crafting' => 10]],
            'mask' => [
                '..######..',
                '.########.',
                '##########',
                '##########',
                '##########',
                '.########.',
                '...####...',
            ],
        ],
        'harbor' => [
            'name' => 'Hafeninsel', 'desc' => 'Luftschiffhäfen und schwere Lastenaufzüge.',
            'grid' => [11, 7], 'tint' => '#8fc4cf',
            'unlock_cost' => ['wood' => 20000, 'iron' => 9000, 'parts' => 1500, 'gold' => 8000],
            'requires' => ['research' => ['logistics' => 20]],
            'mask' => [
                '..#######..',
                '.#########.',
                '###########',
                '###########',
                '###########',
                '.#########.',
                '...#####...',
            ],
        ],
        'magic' => [
            'name' => 'Magische Insel', 'desc' => 'Ätherquellen und schwebende Kristallgärten.',
            'grid' => [10, 7], 'tint' => '#b79ddb',
            'unlock_cost' => ['crystal' => 4000, 'aether' => 400, 'gold' => 15000],
            'requires' => ['research' => ['aether_lore' => 5]],
            'mask' => [
                '...####...',
                '.########.',
                '##########',
                '##########',
                '##########',
                '.########.',
                '...####...',
            ],
        ],
        'ruin' => [
            'name' => 'Ruineninsel', 'desc' => 'Vergessene Bauten mit seltenen Funden.',
            'grid' => [10, 7], 'tint' => '#9c9384',
            'unlock_cost' => ['gold' => 25000, 'weapons' => 500],
            'requires' => ['research' => ['tactics' => 15]],
            'mask' => [
                '..#.####..',
                '.###..###.',
                '##########',
                '###..#####',
                '##########',
                '.####..##.',
                '...####...',
            ],
        ],
    ],

    // Kosten für weitere Inselplätze: basis * wachstum^(bereits belegte Plätze)
    'island_slots' => [
        'free'        => 4,        // zu Beginn belegte Plätze (Haupt, Bauern, Rohstoff, Lager)
        'slot_base'   => ['wood' => 2500, 'stone' => 2500, 'gold' => 800],
        'slot_growth' => 1.85,
        'castle_per_slot' => 5,    // je freigeschaltetem Platz nötiges Burglevel
    ],

    // =================================================================
    // 4. Gebäude
    // role: core|housing|producer|converter|storage|logistics|military|defense|research|trade
    // =================================================================
    'buildings' => [
        // ---------------- Hauptinsel ----------------
        'castle' => [
            'name' => 'Burg', 'desc' => 'Herz des Königreichs. Schaltet Inselplätze und Gebäude frei.',
            'role' => 'core', 'islands' => ['main'], 'size' => [2, 2], 'limit' => 1,
            'cost' => ['wood' => 200, 'stone' => 200], 'cost_growth' => 1.075,
            'effects' => ['score' => 25, 'route_slots' => 2, 'build_speed' => 0],
            'workers' => 4, 'sprite' => 'castle',
        ],
        'house' => [
            'name' => 'Wohnhaus', 'desc' => 'Beherbergt Einwohner, die als Arbeiter tätig sind.',
            'role' => 'housing', 'islands' => ['main', 'farm'], 'size' => [1, 1], 'limit' => 0,
            'cost' => ['wood' => 60, 'stone' => 30], 'cost_growth' => 1.055,
            'effects' => ['population' => 8], 'effect_growth' => 1.012,
            'upkeep' => ['bread' => 0.10], 'sprite' => 'house',
        ],
        'workshop' => [
            'name' => 'Werkstatt', 'desc' => 'Fertigt Bauteile aus Holz und Werkzeugen.',
            'role' => 'converter', 'islands' => ['main'], 'size' => [2, 1], 'limit' => 0,
            'cost' => ['wood' => 260, 'stone' => 180, 'iron' => 40],
            'consumes' => ['wood' => 5.0, 'tools' => 0.5], 'produces' => ['parts' => 1.5],
            'buffer' => 120, 'workers' => 6, 'sprite' => 'workshop',
        ],
        'smithy' => [
            'name' => 'Schmiede', 'desc' => 'Schmiedet Werkzeuge aus Eisen und Kohle.',
            'role' => 'converter', 'islands' => ['main'], 'size' => [2, 1], 'limit' => 0,
            'cost' => ['wood' => 220, 'stone' => 240, 'iron' => 60],
            'consumes' => ['iron' => 4.0, 'coal' => 2.0], 'produces' => ['tools' => 2.0],
            'buffer' => 150, 'workers' => 5, 'sprite' => 'smithy',
        ],
        'armory' => [
            'name' => 'Waffenschmiede', 'desc' => 'Stellt Waffen für die Truppen her.',
            'role' => 'converter', 'islands' => ['main', 'military'], 'size' => [2, 1], 'limit' => 0,
            'cost' => ['wood' => 400, 'stone' => 500, 'iron' => 300, 'tools' => 60],
            'consumes' => ['iron' => 3.0, 'tools' => 1.0, 'coal' => 1.0], 'produces' => ['weapons' => 0.8],
            'buffer' => 90, 'workers' => 7, 'requires' => ['castle' => 4], 'sprite' => 'armory',
        ],
        'research_hall' => [
            'name' => 'Forschungsgilde', 'desc' => 'Senkt Forschungskosten und schaltet Wissen frei.',
            'role' => 'research', 'islands' => ['main', 'research'], 'size' => [2, 2], 'limit' => 0,
            'cost' => ['wood' => 500, 'stone' => 600, 'crystal' => 20],
            'effects' => ['research_discount' => 1.5, 'score' => 10], 'effect_growth' => 1.010,
            'workers' => 6, 'requires' => ['castle' => 3], 'sprite' => 'research_hall',
        ],
        'barracks' => [
            'name' => 'Kaserne', 'desc' => 'Bildet Einheiten aus und beherbergt Soldaten.',
            'role' => 'military', 'islands' => ['main', 'military'], 'size' => [2, 2], 'limit' => 0,
            'cost' => ['wood' => 420, 'stone' => 380, 'iron' => 120],
            'effects' => ['army_capacity' => 20, 'score' => 8], 'effect_growth' => 1.014,
            'workers' => 4, 'requires' => ['castle' => 2], 'sprite' => 'barracks',
        ],
        'market' => [
            'name' => 'Markt', 'desc' => 'Handel mit anderen Königreichen und Goldeinnahmen.',
            'role' => 'trade', 'islands' => ['main', 'trade'], 'size' => [2, 1], 'limit' => 0,
            'cost' => ['wood' => 300, 'stone' => 220, 'gold' => 100],
            'effects' => ['trade_slots' => 2, 'gold_rate' => 0.8], 'effect_growth' => 1.013,
            'workers' => 3, 'requires' => ['castle' => 3], 'sprite' => 'market',
        ],

        // ---------------- Verteidigung ----------------
        'wall' => [
            'name' => 'Mauer', 'desc' => 'Schützt die Insel und bremst Eindringlinge.',
            'role' => 'defense', 'islands' => ['main', 'military', 'storage'], 'size' => [1, 1], 'limit' => 0,
            'cost' => ['stone' => 140, 'wood' => 40],
            'effects' => ['defense_hp' => 220, 'score' => 3], 'effect_growth' => 1.013,
            'sprite' => 'wall',
        ],
        'tower' => [
            'name' => 'Wachturm', 'desc' => 'Beschiesst angreifende Truppen aus der Ferne.',
            'role' => 'defense', 'islands' => ['main', 'military', 'storage', 'resource'], 'size' => [1, 1], 'limit' => 0,
            'cost' => ['stone' => 220, 'wood' => 120, 'iron' => 40],
            'effects' => ['defense_damage' => 12, 'defense_range' => 3.2, 'score' => 5], 'effect_growth' => 1.012,
            'workers' => 2, 'sprite' => 'tower',
        ],
        'patrol_post' => [
            'name' => 'Wachposten', 'desc' => 'Patrouillen sichern Transportwege gegen Überfälle.',
            'role' => 'defense', 'islands' => ['main', 'military', 'storage', 'farm', 'resource'], 'size' => [1, 1], 'limit' => 0,
            'cost' => ['wood' => 180, 'stone' => 90, 'bread' => 40],
            'effects' => ['patrol_strength' => 18, 'convoy_safety' => 2.5], 'effect_growth' => 1.011,
            'workers' => 3, 'sprite' => 'patrol_post',
        ],

        // ---------------- Bauerninsel ----------------
        'grain_farm' => [
            'name' => 'Getreidefeld', 'desc' => 'Grundnahrung für Einwohner und Tiere.',
            'role' => 'producer', 'islands' => ['farm'], 'size' => [2, 2], 'limit' => 0,
            'cost' => ['wood' => 80, 'stone' => 30],
            'produces' => ['grain' => 14.0], 'buffer' => 260, 'workers' => 3, 'sprite' => 'grain_farm',
        ],
        'vegetable_farm' => [
            'name' => 'Gemüsegarten', 'desc' => 'Gemüse für abwechslungsreiche Verpflegung.',
            'role' => 'producer', 'islands' => ['farm'], 'size' => [2, 1], 'limit' => 0,
            'cost' => ['wood' => 70, 'stone' => 25],
            'produces' => ['vegetables' => 10.0], 'buffer' => 220, 'workers' => 2, 'sprite' => 'vegetable_farm',
        ],
        'orchard' => [
            'name' => 'Obsthain', 'desc' => 'Obstbäume liefern süsse Erträge.',
            'role' => 'producer', 'islands' => ['farm'], 'size' => [2, 2], 'limit' => 0,
            'cost' => ['wood' => 120, 'stone' => 20],
            'produces' => ['fruit' => 8.0], 'buffer' => 200, 'workers' => 2, 'sprite' => 'orchard',
        ],
        'pasture' => [
            'name' => 'Viehweide', 'desc' => 'Tierhaltung: verbraucht Getreide, liefert Fleisch.',
            'role' => 'converter', 'islands' => ['farm'], 'size' => [2, 2], 'limit' => 0,
            'cost' => ['wood' => 160, 'stone' => 60],
            'consumes' => ['grain' => 2.5], 'produces' => ['meat' => 4.0],
            'buffer' => 180, 'workers' => 3, 'sprite' => 'pasture',
        ],
        'horse_ranch' => [
            'name' => 'Pferdezucht', 'desc' => 'Züchtet Pferde für Transport und Ritter.',
            'role' => 'converter', 'islands' => ['farm'], 'size' => [2, 2], 'limit' => 0,
            'cost' => ['wood' => 340, 'stone' => 140, 'grain' => 200],
            'consumes' => ['grain' => 3.0, 'vegetables' => 1.0], 'produces' => ['horse' => 0.35],
            'buffer' => 40, 'workers' => 4, 'requires' => ['castle' => 3], 'sprite' => 'horse_ranch',
        ],
        'mill' => [
            'name' => 'Mühle', 'desc' => 'Mahlt Getreide zu Mehl.',
            'role' => 'converter', 'islands' => ['farm'], 'size' => [1, 1], 'limit' => 0,
            'cost' => ['wood' => 200, 'stone' => 120],
            'consumes' => ['grain' => 8.0], 'produces' => ['flour' => 6.0],
            'buffer' => 200, 'workers' => 2, 'sprite' => 'mill',
        ],
        'bakery' => [
            'name' => 'Bäckerei', 'desc' => 'Backt Brot – die Verpflegung des Königreichs.',
            'role' => 'converter', 'islands' => ['farm', 'main'], 'size' => [1, 1], 'limit' => 0,
            'cost' => ['wood' => 220, 'stone' => 180],
            'consumes' => ['flour' => 4.0, 'coal' => 0.8], 'produces' => ['bread' => 5.0],
            'buffer' => 200, 'workers' => 3, 'sprite' => 'bakery',
        ],

        // ---------------- Rohstoffinsel ----------------
        'lumberjack' => [
            'name' => 'Holzfällerlager', 'desc' => 'Schlägt Holz im Inselwald.',
            'role' => 'producer', 'islands' => ['resource', 'main'], 'size' => [2, 1], 'limit' => 0,
            'cost' => ['wood' => 50, 'stone' => 20],
            'produces' => ['wood' => 12.0], 'buffer' => 240, 'workers' => 3, 'sprite' => 'lumberjack',
        ],
        'quarry' => [
            'name' => 'Steinbruch', 'desc' => 'Bricht Stein aus dem Inselfels.',
            'role' => 'producer', 'islands' => ['resource'], 'size' => [2, 2], 'limit' => 0,
            'cost' => ['wood' => 90, 'stone' => 40],
            'produces' => ['stone' => 10.0], 'buffer' => 240, 'workers' => 4, 'sprite' => 'quarry',
        ],
        'iron_mine' => [
            'name' => 'Eisenmine', 'desc' => 'Fördert Eisenerz aus der Tiefe.',
            'role' => 'producer', 'islands' => ['resource'], 'size' => [2, 2], 'limit' => 0,
            'cost' => ['wood' => 180, 'stone' => 140],
            'produces' => ['iron' => 7.0], 'buffer' => 200, 'workers' => 5, 'sprite' => 'iron_mine',
        ],
        'copper_mine' => [
            'name' => 'Kupfermine', 'desc' => 'Fördert Kupfer für feine Bauteile.',
            'role' => 'producer', 'islands' => ['resource'], 'size' => [2, 1], 'limit' => 0,
            'cost' => ['wood' => 200, 'stone' => 170],
            'produces' => ['copper' => 6.0], 'buffer' => 180, 'workers' => 4,
            'requires' => ['castle' => 2], 'sprite' => 'copper_mine',
        ],
        'coal_mine' => [
            'name' => 'Kohlegrube', 'desc' => 'Brennstoff für Schmieden und Öfen.',
            'role' => 'producer', 'islands' => ['resource'], 'size' => [2, 1], 'limit' => 0,
            'cost' => ['wood' => 160, 'stone' => 190],
            'produces' => ['coal' => 8.0], 'buffer' => 200, 'workers' => 4,
            'requires' => ['castle' => 2], 'sprite' => 'coal_mine',
        ],
        'crystal_mine' => [
            'name' => 'Kristallmine', 'desc' => 'Bricht leuchtende Himmelskristalle.',
            'role' => 'producer', 'islands' => ['resource', 'magic'], 'size' => [2, 2], 'limit' => 0,
            'cost' => ['wood' => 900, 'stone' => 1100, 'tools' => 80],
            'produces' => ['crystal' => 1.2], 'buffer' => 60, 'workers' => 6,
            'requires' => ['research' => ['mining' => 5]], 'sprite' => 'crystal_mine',
        ],
        'aether_well' => [
            'name' => 'Ätherquelle', 'desc' => 'Sammelt seltenen Ätherstaub aus den Wolken.',
            'role' => 'producer', 'islands' => ['magic', 'resource'], 'size' => [2, 2], 'limit' => 0,
            'cost' => ['stone' => 2600, 'crystal' => 300, 'parts' => 120],
            'produces' => ['aether' => 0.3], 'buffer' => 30, 'workers' => 5,
            'requires' => ['research' => ['aether_lore' => 1]], 'sprite' => 'aether_well',
        ],

        // ---------------- Lagerinsel ----------------
        'warehouse' => [
            'name' => 'Baustofflager', 'desc' => 'Lagert Holz, Stein, Eisen, Kupfer und Kohle.',
            'role' => 'storage', 'islands' => ['storage', 'main'], 'size' => [2, 2], 'limit' => 0,
            'cost' => ['wood' => 140, 'stone' => 100],
            'storage' => ['class' => 'bulk', 'amount' => 4000], 'effect_growth' => 1.014,
            'workers' => 2, 'sprite' => 'warehouse',
        ],
        'granary' => [
            'name' => 'Speicher', 'desc' => 'Lagert Getreide, Gemüse, Obst, Fleisch, Mehl und Brot.',
            'role' => 'storage', 'islands' => ['storage', 'farm'], 'size' => [2, 2], 'limit' => 0,
            'cost' => ['wood' => 160, 'stone' => 80],
            'storage' => ['class' => 'food', 'amount' => 3500], 'effect_growth' => 1.014,
            'workers' => 2, 'sprite' => 'granary',
        ],
        'goods_store' => [
            'name' => 'Warenhaus', 'desc' => 'Lagert Werkzeuge, Bauteile und Waffen.',
            'role' => 'storage', 'islands' => ['storage', 'main'], 'size' => [2, 1], 'limit' => 0,
            'cost' => ['wood' => 260, 'stone' => 200, 'tools' => 20],
            'storage' => ['class' => 'goods', 'amount' => 1200], 'effect_growth' => 1.014,
            'workers' => 2, 'requires' => ['castle' => 2], 'sprite' => 'goods_store',
        ],
        'vault' => [
            'name' => 'Schatzkammer', 'desc' => 'Sichert Kristalle, Ätherstaub und Gold.',
            'role' => 'storage', 'islands' => ['storage', 'main'], 'size' => [1, 1], 'limit' => 0,
            'cost' => ['stone' => 420, 'iron' => 120],
            'storage' => ['class' => 'precious', 'amount' => 900], 'effect_growth' => 1.015,
            'workers' => 1, 'requires' => ['castle' => 3], 'sprite' => 'vault',
        ],
        'stable' => [
            'name' => 'Stallungen', 'desc' => 'Unterkunft für Pferde des Transportwesens.',
            'role' => 'storage', 'islands' => ['storage', 'farm', 'main'], 'size' => [2, 1], 'limit' => 0,
            'cost' => ['wood' => 300, 'stone' => 120, 'grain' => 80],
            'storage' => ['class' => 'special', 'amount' => 60], 'effect_growth' => 1.013,
            'workers' => 2, 'requires' => ['castle' => 3], 'sprite' => 'stable',
        ],
        'transport_office' => [
            'name' => 'Transportkontor', 'desc' => 'Verwaltet Routen: mehr gleichzeitige Transporte.',
            'role' => 'logistics', 'islands' => ['storage', 'main', 'trade'], 'size' => [1, 1], 'limit' => 0,
            'cost' => ['wood' => 240, 'stone' => 160],
            'effects' => ['route_slots' => 2, 'carriers' => 4], 'effect_growth' => 1.012,
            'workers' => 3, 'sprite' => 'transport_office',
        ],
        'crane' => [
            'name' => 'Ladekran', 'desc' => 'Beschleunigt das Be- und Entladen aller Routen.',
            'role' => 'logistics', 'islands' => ['storage', 'harbor'], 'size' => [1, 1], 'limit' => 0,
            'cost' => ['wood' => 420, 'iron' => 180, 'parts' => 30],
            'effects' => ['load_speed' => 6.0], 'effect_growth' => 1.011,
            'workers' => 2, 'requires' => ['castle' => 4], 'sprite' => 'crane',
        ],
    ],

    // =================================================================
    // 5. Transportmittel (Routen-Ausbaustufen)
    // speed: Felder pro Sekunde | capacity: Ladung je Träger und Fahrt
    // =================================================================
    'transport_modes' => [
        'foot'      => ['name' => 'Träger zu Fuss',  'speed' => 1.00, 'capacity' => 10,  'carriers' => 1, 'reliability' => 0.90, 'cost' => [], 'sprite' => 'porter'],
        'sack'      => ['name' => 'Tragesäcke',      'speed' => 0.98, 'capacity' => 20,  'carriers' => 1, 'reliability' => 0.91, 'cost' => ['wood' => 200, 'tools' => 10], 'requires' => ['research' => ['logistics' => 1]], 'sprite' => 'porter_sack'],
        'handcart'  => ['name' => 'Handkarren',      'speed' => 0.94, 'capacity' => 45,  'carriers' => 1, 'reliability' => 0.92, 'cost' => ['wood' => 600, 'iron' => 80, 'tools' => 30], 'requires' => ['research' => ['logistics' => 3]], 'sprite' => 'handcart'],
        'horse'     => ['name' => 'Packpferde',      'speed' => 1.55, 'capacity' => 60,  'carriers' => 1, 'reliability' => 0.93, 'cost' => ['wood' => 900, 'horse' => 4, 'tools' => 60], 'requires' => ['research' => ['logistics' => 5], 'building' => 'stable'], 'sprite' => 'horse'],
        'wagon'     => ['name' => 'Pferdewagen',     'speed' => 1.35, 'capacity' => 150, 'carriers' => 1, 'reliability' => 0.94, 'cost' => ['wood' => 2200, 'iron' => 500, 'horse' => 8, 'parts' => 60], 'requires' => ['research' => ['logistics' => 8], 'building' => 'stable'], 'sprite' => 'wagon'],
        'cableway'  => ['name' => 'Seilbahn',        'speed' => 2.10, 'capacity' => 220, 'carriers' => 1, 'reliability' => 0.97, 'cost' => ['iron' => 4000, 'parts' => 400, 'crystal' => 120], 'requires' => ['research' => ['logistics' => 14]], 'sprite' => 'cableway'],
        'lift'      => ['name' => 'Lastenaufzug',    'speed' => 1.70, 'capacity' => 400, 'carriers' => 1, 'reliability' => 0.98, 'cost' => ['iron' => 9000, 'parts' => 900, 'crystal' => 350], 'requires' => ['research' => ['logistics' => 18]], 'sprite' => 'lift'],
        'airship'   => ['name' => 'Luftschiff',      'speed' => 2.80, 'capacity' => 700, 'carriers' => 1, 'reliability' => 0.99, 'cost' => ['parts' => 2500, 'aether' => 150, 'crystal' => 900], 'requires' => ['research' => ['logistics' => 24], 'building' => 'crane'], 'sprite' => 'airship'],
    ],

    'transport' => [
        'route_cost'        => ['wood' => 120, 'stone' => 80],   // Kosten einer neuen Route
        'route_cost_growth' => 1.42,                             // je bestehender Route teurer
        'carrier_cost'      => ['wood' => 60, 'bread' => 20],    // zusätzlicher Träger auf einer Route
        'carrier_growth'    => 1.30,
        'speed_growth'      => 1.015,   // +1,5 % Tempo je Routenlevel
        'capacity_growth'   => 1.012,   // +1,2 % Ladung je Routenlevel
        'base_load_time'    => 6.0,     // Sekunden Be- und Entladen je Fahrt
        'base_distance'     => 14.0,    // Felder je Inselwechsel (wenn keine Geometrie bekannt)
    ],

    'bridges' => [
        'cost'         => ['wood' => 400, 'stone' => 300],
        'cost_growth'  => 1.09,
        'capacity'     => 60.0,      // Waren pro Minute auf Level 1
        'capacity_growth' => 1.018,  // +1,8 % je Level
        'repair_factor'=> 0.35,      // Anteil der Baukosten für eine vollständige Reparatur
    ],

    // =================================================================
    // 6. Einheiten
    // =================================================================
    'units' => [
        'spearman' => ['name' => 'Speerträger', 'hp' => 120, 'damage' => 10, 'speed' => 1.00, 'carry' => 30, 'cost' => ['bread' => 40, 'weapons' => 1, 'gold' => 10], 'upkeep' => ['bread' => 0.20], 'pop' => 1, 'sprite' => 'unit_spearman'],
        'archer'   => ['name' => 'Bogenschütze','hp' => 80,  'damage' => 16, 'range' => 2.6, 'speed' => 1.05, 'carry' => 20, 'cost' => ['bread' => 45, 'wood' => 30, 'weapons' => 1, 'gold' => 14], 'upkeep' => ['bread' => 0.22], 'pop' => 1, 'sprite' => 'unit_archer'],
        'raider'   => ['name' => 'Plünderer',   'hp' => 95,  'damage' => 11, 'speed' => 1.35, 'carry' => 90, 'cost' => ['bread' => 60, 'weapons' => 1, 'gold' => 25], 'upkeep' => ['bread' => 0.30], 'pop' => 1, 'requires' => ['research' => ['tactics' => 2]], 'sprite' => 'unit_raider'],
        'sapper'   => ['name' => 'Pionier',     'hp' => 100, 'damage' => 8,  'speed' => 0.95, 'carry' => 25, 'structure_damage' => 4.0, 'cost' => ['bread' => 55, 'tools' => 4, 'gold' => 30], 'upkeep' => ['bread' => 0.25], 'pop' => 1, 'requires' => ['research' => ['tactics' => 3]], 'sprite' => 'unit_sapper'],
        'scout'    => ['name' => 'Späher',      'hp' => 60,  'damage' => 5,  'speed' => 1.70, 'carry' => 10, 'stealth' => 2.0, 'cost' => ['bread' => 35, 'gold' => 18], 'upkeep' => ['bread' => 0.15], 'pop' => 1, 'sprite' => 'unit_scout'],
        'knight'   => ['name' => 'Ritter',      'hp' => 260, 'damage' => 26, 'speed' => 1.25, 'carry' => 45, 'cost' => ['bread' => 120, 'weapons' => 3, 'horse' => 1, 'gold' => 80], 'upkeep' => ['bread' => 0.60], 'pop' => 2, 'requires' => ['research' => ['tactics' => 6]], 'sprite' => 'unit_knight'],
    ],

    'unit_training' => [
        'cost_growth'   => 1.0075,   // jede weitere Einheit desselben Typs wird minimal teurer
        'level_cost'    => ['gold' => 400, 'weapons' => 20, 'iron' => 300],
        'level_growth'  => 1.11,     // Ausbildungsstufe je Einheitentyp (unbegrenzt)
        'hp_growth'     => 1.010,    // +1,0 % Lebenspunkte je Ausbildungsstufe
        'damage_growth' => 1.008,    // +0,8 % Schaden
        'speed_growth'  => 1.005,    // +0,5 % Tempo
    ],

    // =================================================================
    // 7. Forschung (unbegrenzte Stufen)
    // =================================================================
    'research' => [
        'logistics'   => ['name' => 'Logistik',      'desc' => 'Schnellere Transporte und neue Transportmittel.', 'effect' => 'transport_speed', 'per_level' => 1.015, 'cost' => ['wood' => 400, 'stone' => 300, 'gold' => 120], 'growth' => 1.16],
        'storage_lore'=> ['name' => 'Lagerkunde',    'desc' => 'Grösserer Stauraum in allen Lagern.',            'effect' => 'storage_capacity','per_level' => 1.020, 'cost' => ['wood' => 380, 'stone' => 420, 'gold' => 110], 'growth' => 1.16],
        'mining'      => ['name' => 'Bergbau',       'desc' => 'Mehr Ausbeute aus Minen und Steinbrüchen.',      'effect' => 'mine_output',     'per_level' => 1.018, 'cost' => ['wood' => 300, 'stone' => 500, 'gold' => 140], 'growth' => 1.17],
        'agriculture' => ['name' => 'Landwirtschaft','desc' => 'Höhere Erträge auf Feldern und Weiden.',         'effect' => 'farm_output',     'per_level' => 1.018, 'cost' => ['wood' => 340, 'grain' => 400, 'gold' => 120], 'growth' => 1.17],
        'crafting'    => ['name' => 'Handwerk',      'desc' => 'Verarbeitende Betriebe arbeiten effizienter.',   'effect' => 'craft_output',    'per_level' => 1.016, 'cost' => ['iron' => 260, 'tools' => 40, 'gold' => 180], 'growth' => 1.18],
        'bridge_eng'  => ['name' => 'Brückenbau',    'desc' => 'Brücken tragen mehr Verkehr ohne Stau.',         'effect' => 'bridge_capacity', 'per_level' => 1.017, 'cost' => ['stone' => 600, 'iron' => 200, 'gold' => 160], 'growth' => 1.18],
        'tactics'     => ['name' => 'Taktik',        'desc' => 'Stärkere Truppen und neue Einheiten.',           'effect' => 'unit_damage',     'per_level' => 1.014, 'cost' => ['iron' => 320, 'weapons' => 10, 'gold' => 200], 'growth' => 1.19],
        'fortify'     => ['name' => 'Befestigung',   'desc' => 'Mauern und Türme halten mehr aus.',              'effect' => 'defense_hp',      'per_level' => 1.016, 'cost' => ['stone' => 700, 'iron' => 180, 'gold' => 170], 'growth' => 1.18],
        'aether_lore' => ['name' => 'Ätherkunde',    'desc' => 'Öffnet magische Inseln und Ätherquellen.',       'effect' => 'aether_output',   'per_level' => 1.020, 'cost' => ['crystal' => 400, 'gold' => 900], 'growth' => 1.22, 'requires' => ['research' => ['mining' => 8]]],
    ],

    // =================================================================
    // 8. Angriffsmissionen
    // =================================================================
    'missions' => [
        'raid_convoy'   => ['name' => 'Transport überfallen',  'desc' => 'Fangt einen fahrenden Konvoi ab.',                  'loot' => 0.18, 'risk' => 0.8,  'duration' => 75,  'target' => 'route'],
        'loot_storage'  => ['name' => 'Lager plündern',        'desc' => 'Entwendet einen Teil eines vollen Lagers.',          'loot' => 0.12, 'risk' => 1.2,  'duration' => 95,  'target' => 'storage'],
        'break_bridge'  => ['name' => 'Brücke beschädigen',    'desc' => 'Legt eine Brücke vorübergehend lahm.',               'loot' => 0.04, 'risk' => 1.0,  'duration' => 70,  'target' => 'bridge',   'damage' => 0.45],
        'seize_mine'    => ['name' => 'Mine besetzen',         'desc' => 'Besetzt eine Mine und nehmt die Förderung mit.',     'loot' => 0.15, 'risk' => 1.1,  'duration' => 85,  'target' => 'building', 'role' => 'producer'],
        'take_outpost'  => ['name' => 'Aussenposten erobern',  'desc' => 'Übernehmt einen Wachposten für einige Stunden.',     'loot' => 0.06, 'risk' => 1.3,  'duration' => 90,  'target' => 'building', 'role' => 'defense'],
        'intercept'     => ['name' => 'Lieferung abfangen',    'desc' => 'Schnappt euch eine wichtige Warenlieferung.',        'loot' => 0.20, 'risk' => 1.15, 'duration' => 80,  'target' => 'route'],
        'free_prisoners'=> ['name' => 'Gefangene befreien',    'desc' => 'Holt verschleppte Arbeiter zurück.',                 'loot' => 0.05, 'risk' => 1.0,  'duration' => 80,  'target' => 'building', 'role' => 'military', 'returns_pop' => true],
        'spy'           => ['name' => 'Ausspionieren',         'desc' => 'Erkundet Lager, Truppen und Verteidigung.',          'loot' => 0.00, 'risk' => 0.5,  'duration' => 55,  'target' => 'intel'],
    ],

    'combat' => [
        'tick_rate'          => 10,     // Simulationsschritte je Sekunde (Server und Client identisch)
        'max_squad'          => 20,     // Einheiten je Angriff
        'loot_cap_ratio'     => 0.25,   // höchstens 25 % eines Lagers je Angriff
        'repair_factor'      => 0.30,   // Reparaturkosten im Verhältnis zum Baupreis
        'defender_bonus'     => 1.15,   // Heimvorteil
        'max_actions'        => 200,    // Aktionen im Protokoll je Mission
        'action_cooldown'    => 6,      // Ticks zwischen zwei Spezialfähigkeiten
        'abilities' => [
            'sprint'  => ['name' => 'Sturmlauf',  'desc' => 'Kurzzeitig deutlich schneller.',      'duration' => 40, 'cooldown' => 120, 'speed' => 1.8],
            'shield'  => ['name' => 'Schildwall', 'desc' => 'Halbiert erlittenen Schaden.',        'duration' => 50, 'cooldown' => 150, 'damage_taken' => 0.5],
            'smoke'   => ['name' => 'Rauchbombe', 'desc' => 'Türme verfehlen ihre Ziele.',         'duration' => 45, 'cooldown' => 160, 'tower_accuracy' => 0.25],
            'sabotage'=> ['name' => 'Sabotage',   'desc' => 'Vierfacher Schaden an Bauwerken.',    'duration' => 35, 'cooldown' => 180, 'structure_damage' => 4.0],
        ],
    ],

    // =================================================================
    // 9. Bevölkerung, Verpflegung, Punkte
    // =================================================================
    'population' => [
        'base'            => 20,       // Einwohner ohne Wohnhäuser
        'bread_per_pop'   => 0.02,     // Brot je Einwohner und Minute
        'hunger_penalty'  => 0.55,     // Produktionsfaktor bei fehlender Verpflegung
        'idle_bonus'      => 1.0,
    ],

    'score' => [
        'per_building_level' => 4,
        'per_research_level' => 12,
        'per_unit'           => 2,
        'per_island'         => 120,
        'per_bridge_level'   => 3,
        'battle_win'         => 15,
        'battle_loss'        => -5,
    ],

    // =================================================================
    // 10. Shop (nur Spielwährung, kein Echtgeld)
    // =================================================================
    'shop' => [
        'packs' => [
            'wood_small'   => ['name' => 'Fuhre Holz',       'give' => ['wood' => 2000],   'price' => ['gold' => 150]],
            'stone_small'  => ['name' => 'Fuhre Stein',      'give' => ['stone' => 2000],  'price' => ['gold' => 150]],
            'iron_small'   => ['name' => 'Kiste Eisen',      'give' => ['iron' => 1200],   'price' => ['gold' => 180]],
            'bread_small'  => ['name' => 'Brotvorrat',       'give' => ['bread' => 800],   'price' => ['gold' => 120]],
            'horse_pack'   => ['name' => 'Pferdegespann',    'give' => ['horse' => 6],     'price' => ['gold' => 400]],
            'crystal_pack' => ['name' => 'Kristallsplitter',  'give' => ['crystal' => 120], 'price' => ['gold' => 900]],
        ],
        'sell_rates' => [   // Verkauf an den Markt: Rohstoff -> Gold je Stück
            'wood' => 0.05, 'stone' => 0.05, 'iron' => 0.09, 'copper' => 0.10, 'coal' => 0.08,
            'grain' => 0.04, 'vegetables' => 0.05, 'fruit' => 0.06, 'meat' => 0.12, 'flour' => 0.09,
            'bread' => 0.14, 'tools' => 0.35, 'parts' => 0.60, 'weapons' => 1.20, 'crystal' => 2.50,
            'aether' => 9.00, 'horse' => 12.00,
        ],
    ],

    // =================================================================
    // 11. Prestige (freiwillig, sehr spätes Spiel – löscht nichts)
    // =================================================================
    'prestige' => [
        'min_score'      => 250000,
        'points_divisor' => 50000,     // Prestigepunkte = floor(score / divisor)
        'bonus_per_point'=> 0.01,      // +1 % auf alle Produktion je Punkt
        'keeps'          => 'Alle Inseln, Gebäude und Forschungen bleiben erhalten.',
    ],
];
