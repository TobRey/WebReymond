<?php
declare(strict_types=1);

/**
 * Alle Startwerte des Spiels an einer Stelle. Das Admin-Dashboard
 * bearbeitet exakt diese Datenmodelle, das Spiel liest sie unveraendert.
 */
final class Defaults
{
    public const SPRITE_BASE = 'assets/sprites/';

    /** @return array<string, mixed> */
    public static function content(): array
    {
        return [
            'version' => 1,
            'player' => self::player(),
            'balance' => self::balance(),
            'audio' => self::audio(),
            'weapons' => self::weapons(),
            'enemies' => self::enemies(),
            'upgrades' => self::upgrades(),
            'maps' => self::maps(),
        ];
    }

    /** @return array<string, mixed> */
    public static function player(): array
    {
        return [
            'maxHealth' => 100,
            'moveSpeed' => 168,
            'armor' => 0,
            'damageMult' => 1.0,
            'critChance' => 5,
            'critDamage' => 60,
            'dodge' => 0,
            'regen' => 0,
            'pickupRange' => 90,
            'spriteFront' => self::SPRITE_BASE . 'playerfront.gif',
            'spriteBack' => self::SPRITE_BASE . 'playerback.gif',
            'spriteSide' => self::SPRITE_BASE . 'playerside.gif',
            'spriteDust' => self::SPRITE_BASE . 'staub.gif',
            'scale' => 78,
            'hitbox' => ['rx' => 14, 'ry' => 9, 'oy' => 24],
        ];
    }

    /** @return array<string, mixed> */
    public static function audio(): array
    {
        return [
            'musicTrack' => 'assets/audio/music-arena.mp3',
            'musicVolume' => 0.5,
            'sfxVolume' => 0.8,
            'musicEnabled' => true,
        ];
    }

    /** @return array<string, mixed> */
    public static function balance(): array
    {
        return [
            'waveDuration' => 60,
            'bossDuration' => 120,
            'enemySpawnRate' => 1.5,
            'maxEnemies' => 80,
            'healthScaling' => 1.45,
            'damageScaling' => 1.28,
            'speedScaling' => 1.035,
            'spawnRateScaling' => 1.18,
            'rewardScaling' => 1.25,
            'moneyMultiplier' => 1.0,
            'contactDamageCooldown' => 0.8,
            'bossBombCooldown' => 4.5,
            'bossBombRadius' => 170,
            'bossBombDelay' => 1.3,
            'bossBombFlightTime' => 0.85,
            'bossBombMinCooldown' => 1.8,
            'upgradeChoices' => 3,
            'rarityRareBase' => 26,
            'rarityEpicBase' => 9,
            'rarityLegendaryBase' => 2,
            'rarityCycleBonus' => 1.35,
            'weaponOfferChance' => 0.28,
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function weapons(): array
    {
        $s = self::SPRITE_BASE;
        // damage/cooldown ergeben die DPS. Nahkampf darf mehr, weil riskanter.
        // Flaechenwaffen treffen viele Ziele und machen pro Ziel weniger.
        // Die letzten beiden Zahlen sind die Darstellungsgroessen in Pixeln:
        // Waffensprite (in der Hand bzw. beim Schwung) und Projektil.
        $w = [
            ['pistole', 'Pistole', 'pistole.png', 'PROJECTILE', 'schuss',
             22, 0.40, 430, 640, 90, 8, 60, 0, 'Schneller Einzelschuss mit solidem Schaden.', 46, 16],
            ['sturmgewehr', 'Sturmgewehr', 'sturmgewehr.png', 'PROJECTILE', 'schuss',
             8, 0.11, 390, 760, 35, 6, 55, 0, 'Sehr hohe Feuerrate, dafuer wenig Schaden pro Schuss.', 54, 14],
            ['bogen', 'Bogen', 'bogen.png', 'PROJECTILE', 'pfeil',
             46, 0.78, 540, 800, 110, 12, 70, 0, 'Langsamer Schuss, hoher Einzelschaden, durchbohrt 1 Gegner.', 50, 20],
            ['armbrust', 'Armbrust', 'armbrust.png', 'PROJECTILE', 'pfeil',
             85, 1.35, 640, 980, 150, 14, 80, 0, 'Lange Nachladezeit, dafuer massiver Schaden, durchbohrt 2 Gegner.', 56, 26],
            ['zauberstab', 'Zauberstab', 'zauberstab.png', 'MAGIC', 'magic',
             28, 0.55, 500, 430, 60, 10, 65, 0, 'Magisches Geschoss mit leichter Zielsuche - trifft fast immer.', 50, 18],
            ['speer', 'Speer', 'speer.png', 'THRUST', '',
             48, 0.62, 165, 0, 130, 10, 65, 0, 'Stoss nach vorne mit schmalem Trefferbereich und hohem Schaden.', 120, 16],
            ['dolch', 'Dolch', 'dolch.png', 'MELEE_ARC', '',
             15, 0.20, 100, 0, 45, 15, 70, 0, 'Blitzschnelle Stiche auf kurze Distanz.', 58, 16],
            ['schwert', 'Schwert', 'schwert.png', 'MELEE_ARC', '',
             22, 0.50, 140, 0, 110, 8, 60, 0, 'Weiter Schwung vor dem Spieler, trifft mehrere Gegner.', 95, 16],
            ['axt', 'Axt', 'axt.png', 'MELEE_360', '',
             18, 0.95, 155, 0, 140, 8, 60, 0, 'Volle 360-Grad-Drehung, trifft alles rundherum.', 88, 16],
            ['granate', 'Granate', 'granate.png', 'GRENADE', 'granate',
             55, 1.90, 400, 420, 190, 8, 60, 130, 'Wurfgeschoss mit verzoegerter Explosion und grossem Radius.', 46, 34],
        ];

        $out = [];
        foreach ($w as $i => $row) {
            [$id, $name, $sprite, $type, $projectile, $damage, $cooldown, $range,
             $projectileSpeed, $knockback, $critChance, $critDamage, $aoe, $desc,
             $spriteScale, $projectileSize] = $row;
            $out[] = [
                'id' => $id,
                'name' => $name,
                'sprite' => $s . $sprite,
                'type' => $type,
                'projectile' => $projectile,
                'damage' => $damage,
                'cooldown' => $cooldown,
                'range' => $range,
                'projectileSpeed' => $projectileSpeed,
                'knockback' => $knockback,
                'critChance' => $critChance,
                'critDamage' => $critDamage,
                'aoeRadius' => $aoe,
                'damageType' => in_array($type, ['MELEE_ARC', 'MELEE_360', 'THRUST'], true) ? 'physical'
                    : ($type === 'MAGIC' ? 'magic' : ($type === 'GRENADE' ? 'explosive' : 'physical')),
                'arc' => $id === 'schwert' ? 85 : ($id === 'dolch' ? 46 : 360),
                'pierce' => $id === 'bogen' ? 1 : ($id === 'armbrust' ? 2 : 0),
                'spread' => $id === 'sturmgewehr' ? 7 : ($id === 'pistole' ? 2 : 0),
                'recoil' => $id === 'sturmgewehr' ? 5 : ($id === 'armbrust' ? 12 : 6),
                'spriteScale' => $spriteScale,
                'projectileSize' => $projectileSize,
                'description' => $desc,
                'active' => true,
                'starter' => in_array($id, ['pistole', 'bogen', 'schwert', 'zauberstab'], true),
                'order' => $i,
            ];
        }
        return $out;
    }

    /** @return list<array<string, mixed>> */
    public static function enemies(): array
    {
        $s = self::SPRITE_BASE;
        return [
            [
                'id' => 'enemy1', 'name' => 'Kriecher', 'sprite' => $s . 'enemy1.gif',
                'health' => 34, 'damage' => 8, 'speed' => 80, 'reward' => 3,
                'spawnWeight' => 100, 'boss' => false, 'contactCooldown' => 0.8,
                'scale' => 64, 'hitbox' => ['shape' => 'circle', 'r' => 19, 'ox' => 0, 'oy' => 4],
                'wave' => 1,
            ],
            [
                'id' => 'enemy2', 'name' => 'Schleicher', 'sprite' => $s . 'enemy2.gif',
                'health' => 72, 'damage' => 12, 'speed' => 94, 'reward' => 6,
                'spawnWeight' => 100, 'boss' => false, 'contactCooldown' => 0.8,
                'scale' => 74, 'hitbox' => ['shape' => 'circle', 'r' => 21, 'ox' => 0, 'oy' => 4],
                'wave' => 2,
            ],
            [
                'id' => 'enemy3', 'name' => 'Brecher', 'sprite' => $s . 'enemy3.gif',
                'health' => 135, 'damage' => 18, 'speed' => 72, 'reward' => 11,
                'spawnWeight' => 100, 'boss' => false, 'contactCooldown' => 0.9,
                'scale' => 96, 'hitbox' => ['shape' => 'circle', 'r' => 25, 'ox' => 0, 'oy' => 6],
                'wave' => 3,
            ],
            [
                'id' => 'boss1', 'name' => 'Bombenwerfer', 'sprite' => $s . 'boss1.gif',
                'health' => 1700, 'damage' => 26, 'speed' => 60, 'reward' => 120,
                'spawnWeight' => 0, 'boss' => true, 'contactCooldown' => 1.0,
                'scale' => 190, 'hitbox' => ['shape' => 'circle', 'r' => 46, 'ox' => 0, 'oy' => 8],
                'wave' => 4,
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function upgrades(): array
    {
        // stat, modType (percent|flat), value, rarity, weight, maxStack
        $rows = [
            ['sharp_blade', 'Scharfe Klinge', 'damage', 'percent', 8, 'common', 100, 12, 'Deine Waffe schlaegt haerter zu.'],
            ['swift_feet', 'Flinke Fuesse', 'moveSpeed', 'percent', 8, 'common', 100, 10, 'Du bewegst dich spuerbar schneller.'],
            ['tough_skin', 'Zaehe Haut', 'maxHealth', 'flat', 20, 'common', 100, 15, 'Mehr maximale Lebenspunkte.'],
            ['rapid_fire', 'Schnellfeuer', 'attackSpeed', 'percent', 7, 'common', 100, 12, 'Deine Waffe greift oefter an.'],
            ['long_arm', 'Langer Arm', 'range', 'percent', 10, 'common', 90, 8, 'Groessere Reichweite.'],
            ['tailwind', 'Rueckenwind', 'projectileSpeed', 'percent', 12, 'common', 80, 8, 'Projektile fliegen schneller.'],

            ['precision', 'Praezision', 'critChance', 'flat', 5, 'rare', 100, 10, 'Hoehere Chance auf kritische Treffer.'],
            ['impact', 'Wucht', 'knockback', 'percent', 25, 'rare', 70, 8, 'Gegner werden weiter zurueckgestossen.'],
            ['leather_hide', 'Lederhaut', 'armor', 'flat', 3, 'rare', 100, 10, 'Reduziert jeden erlittenen Treffer.'],
            ['regrowth', 'Regeneration', 'regen', 'flat', 0.9, 'rare', 90, 8, 'Heilt dich langsam ueber Zeit.'],
            ['evasion', 'Ausweichen', 'dodge', 'flat', 4, 'rare', 80, 8, 'Chance, einem Treffer komplett auszuweichen.'],

            ['berserk', 'Berserker', 'damage', 'percent', 18, 'epic', 100, 8, 'Deutlich mehr Waffenschaden.'],
            ['time_pressure', 'Zeitdruck', 'attackSpeed', 'percent', 16, 'epic', 100, 8, 'Deutlich schnellere Angriffe.'],
            ['crit_fury', 'Kritische Wut', 'critDamage', 'percent', 35, 'epic', 90, 8, 'Kritische Treffer richten mehr an.'],
            ['barrier', 'Barriere', 'shield', 'flat', 30, 'epic', 90, 6, 'Schild, der Schaden vor der Lebensleiste schluckt.'],
            ['vitality', 'Vitalitaet', 'maxHealth', 'percent', 15, 'epic', 90, 6, 'Prozentual mehr Leben.'],

            ['bloodlust', 'Blutrausch', 'damage', 'percent', 30, 'legendary', 100, 4, 'Massiver Schadensschub.'],
            ['twin_heart', 'Zwillingsherz', 'maxHealth', 'percent', 40, 'legendary', 90, 3, 'Enorm viel zusaetzliches Leben.'],
            ['time_rift', 'Zeitriss', 'attackSpeed', 'percent', 28, 'legendary', 90, 3, 'Angriffe kommen deutlich schneller.'],
            ['master_strike', 'Meisterhieb', 'critChance', 'flat', 12, 'legendary', 80, 3, 'Stark erhoehte kritische Trefferchance.'],
        ];

        $out = [];
        foreach ($rows as $r) {
            [$id, $name, $stat, $modType, $value, $rarity, $weight, $maxStack, $desc] = $r;
            $out[] = [
                'id' => $id, 'name' => $name, 'stat' => $stat, 'modType' => $modType,
                'value' => $value, 'rarity' => $rarity, 'weight' => $weight,
                'maxStack' => $maxStack, 'description' => $desc, 'icon' => '', 'active' => true,
            ];
        }
        return $out;
    }

    /** @return list<array<string, mixed>> */
    public static function maps(): array
    {
        $mask = @file_get_contents(__DIR__ . '/seed-collision.txt');
        return [[
            'id' => 'arena',
            'name' => 'Steinbruch-Arena',
            'image' => 'assets/uploads/map-arena.png',
            'width' => 2048,
            'height' => 2048,
            'active' => true,
            'spawn' => ['x' => 1024, 'y' => 1024],
            'enemySpawnAreas' => [],
            'collision' => [
                'cols' => 128,
                'rows' => 128,
                'data' => is_string($mask) ? trim($mask) : '',
            ],
            'createdAt' => time(),
        ]];
    }

    /**
     * Ergaenzt fehlende Schluessel, damit aeltere Speicherstaende weiterlaufen.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function migrate(array $data): array
    {
        $data['player'] = array_merge(self::player(), is_array($data['player'] ?? null) ? $data['player'] : []);
        $data['balance'] = array_merge(self::balance(), is_array($data['balance'] ?? null) ? $data['balance'] : []);
        $data['audio'] = array_merge(self::audio(), is_array($data['audio'] ?? null) ? $data['audio'] : []);
        // Aeltere Waffen ohne Groessenangaben bekommen sinnvolle Standardwerte.
        if (is_array($data['weapons'] ?? null)) {
            foreach ($data['weapons'] as $i => $weapon) {
                if (!isset($weapon['spriteScale'])) {
                    $melee = in_array($weapon['type'] ?? '', ['MELEE_ARC', 'MELEE_360', 'THRUST'], true);
                    $data['weapons'][$i]['spriteScale'] = $melee ? 90 : 46;
                }
                if (!isset($weapon['projectileSize'])) {
                    $data['weapons'][$i]['projectileSize'] = 16;
                }
            }
        }
        foreach (['weapons', 'enemies', 'upgrades', 'maps'] as $key) {
            if (!isset($data[$key]) || !is_array($data[$key])) {
                $data[$key] = self::$key();
            }
        }
        return $data;
    }
}
