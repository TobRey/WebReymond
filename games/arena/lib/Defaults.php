<?php
declare(strict_types=1);

/**
 * Alle Startwerte des Spiels an einer Stelle. Das Admin-Dashboard
 * bearbeitet exakt diese Datenmodelle, das Spiel liest sie unverändert.
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
            'characters' => self::characters(),
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
            // Je Ereignis eine Datei und eine eigene Lautstärke.
            'sounds' => self::soundSlots(),
        ];
    }

    /**
     * Alle Ton-Ereignisse des Spiels. Ohne Datei bleibt ein Ereignis still.
     *
     * @return array<string, array{src: string, volume: float, label: string}>
     */
    public static function soundSlots(): array
    {
        $slots = [
            'shoot' => 'Schuss',
            'melee' => 'Nahkampfschlag',
            'hit' => 'Treffer am Gegner',
            'crit' => 'Kritischer Treffer',
            'enemyDeath' => 'Gegner stirbt',
            'explosion' => 'Explosion',
            'run' => 'Laufschritte',
            'playerHit' => 'Spieler wird getroffen',
            'coin' => 'Geld eingesammelt',
            'upgrade' => 'Upgrade gewählt',
            'bossSpawn' => 'Boss erscheint',
            'bossWarning' => 'Bombenwarnung',
            'gameOver' => 'Run beendet',
            'uiClick' => 'Menüklick',
        ];
        $out = [];
        foreach ($slots as $id => $label) {
            $out[$id] = ['src' => '', 'volume' => 0.8, 'label' => $label];
        }
        return $out;
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

    /**
     * Spielbare Charaktere.
     *
     * Sprites: Entweder ein animiertes GIF je Richtung oder bis zu fünf
     * Einzelbilder, aus denen das Spiel die Animation selbst baut. Bis eigene
     * Sprites hochgeladen sind, unterscheiden sich die Figuren über eine
     * Farbtönung (tint = Farbdrehung in Grad).
     *
     * @return list<array<string, mixed>>
     */
    public static function characters(): array
    {
        $s = self::SPRITE_BASE;
        $base = [
            'front' => ['gif' => $s . 'playerfront.gif', 'frames' => []],
            'back' => ['gif' => $s . 'playerback.gif', 'frames' => []],
            'side' => ['gif' => $s . 'playerside.gif', 'frames' => []],
        ];

        // id, Name, Tönung, Fähigkeitstext, Perk, Werte-Abweichungen, frei von Anfang an
        $rows = [
            ['nova', 'Nova', 0, 'Späherin', 'Schnell unterwegs und flink im Abzug.', '',
             ['moveSpeed' => 1.10, 'critChance' => 5], true],
            ['bruno', 'Bruno', 200, 'Bollwerk', 'Viel Leben und Rüstung, dafür etwas langsamer.', '',
             ['maxHealth' => 1.30, 'armor' => 3, 'moveSpeed' => 0.94], true],
            ['kira', 'Kira', 310, 'Duellantin', 'Mehr Schaden und schnellere Angriffe.', '',
             ['damageMult' => 1.12, 'attackSpeed' => 1.08], true],
            ['ruun', 'Ruun', 140, 'Magier', 'Weitere Reichweite und bessere Upgrade-Karten.', 'luckyCards',
             ['range' => 1.15, 'projectileSpeed' => 1.20], false],
            ['vera', 'Vera', 340, 'Vampirin', 'Jeder Kill heilt dich, dafür weniger Grundleben.', 'lifesteal',
             ['maxHealth' => 0.88], false],
            ['tor', 'Tor', 55, 'Wächter', 'Startet mit Schild und wirft Nahkampfschaden zurück.', 'thorns',
             ['maxHealth' => 1.20, 'shield' => 25], false],
        ];

        $out = [];
        foreach ($rows as $i => $row) {
            [$id, $name, $tint, $title, $desc, $perk, $mods, $starter] = $row;
            $out[] = [
                'id' => $id,
                'name' => $name,
                'title' => $title,
                'description' => $desc,
                'perk' => $perk,
                'tint' => $tint,
                'sprites' => $base,
                'frameDuration' => 130,
                'dustSprite' => $s . 'staub.gif',
                'scale' => 78,
                'hitbox' => ['rx' => 14, 'ry' => 9, 'oy' => 24],
                'mods' => array_merge([
                    'maxHealth' => 1.0, 'moveSpeed' => 1.0, 'damageMult' => 1.0,
                    'attackSpeed' => 1.0, 'range' => 1.0, 'projectileSpeed' => 1.0,
                    'armor' => 0, 'critChance' => 0, 'critDamage' => 0,
                    'dodge' => 0, 'regen' => 0, 'shield' => 0,
                ], $mods),
                'starter' => $starter,
                'unlockCost' => $starter ? 0 : 20,
                'active' => true,
                'order' => $i,
            ];
        }
        return $out;
    }

    /** @return list<array<string, mixed>> */
    public static function weapons(): array
    {
        $s = self::SPRITE_BASE;
        // damage/cooldown ergeben die DPS. Nahkampf darf mehr, weil riskanter.
        // Flächenwaffen treffen viele Ziele und machen pro Ziel weniger.
        // Die letzten beiden Zahlen sind die Darstellungsgrößen in Pixeln:
        // Waffensprite (in der Hand bzw. beim Schwung) und Projektil.
        $w = [
            ['pistole', 'Pistole', 'pistole.png', 'PROJECTILE', 'schuss',
             22, 0.40, 430, 640, 90, 8, 60, 0, 'Schneller Einzelschuss mit solidem Schaden.', 46, 16],
            ['sturmgewehr', 'Sturmgewehr', 'sturmgewehr.png', 'PROJECTILE', 'schuss',
             8, 0.11, 390, 760, 35, 6, 55, 0, 'Sehr hohe Feuerrate, dafür wenig Schaden pro Schuss.', 54, 14],
            ['bogen', 'Bogen', 'bogen.png', 'PROJECTILE', 'pfeil',
             46, 0.78, 540, 800, 110, 12, 70, 0, 'Langsamer Schuss, hoher Einzelschaden, durchbohrt 1 Gegner.', 50, 20],
            ['armbrust', 'Armbrust', 'armbrust.png', 'PROJECTILE', 'pfeil',
             85, 1.35, 640, 980, 150, 14, 80, 0, 'Lange Nachladezeit, dafür massiver Schaden, durchbohrt 2 Gegner.', 56, 26],
            ['zauberstab', 'Zauberstab', 'zauberstab.png', 'MAGIC', 'magic',
             28, 0.55, 500, 430, 60, 10, 65, 0, 'Magisches Geschoss mit leichter Zielsuche - trifft fast immer.', 50, 18],
            ['speer', 'Speer', 'speer.png', 'THRUST', '',
             48, 0.62, 165, 0, 130, 10, 65, 0, 'Stoß nach vorne mit schmalem Trefferbereich und hohem Schaden.', 120, 16],
            ['dolch', 'Dolch', 'dolch.png', 'MELEE_ARC', '',
             15, 0.20, 100, 0, 45, 15, 70, 0, 'Blitzschnelle Stiche auf kurze Distanz.', 58, 16],
            ['schwert', 'Schwert', 'schwert.png', 'MELEE_ARC', '',
             22, 0.50, 140, 0, 110, 8, 60, 0, 'Weiter Schwung vor dem Spieler, trifft mehrere Gegner.', 95, 16],
            ['axt', 'Axt', 'axt.png', 'MELEE_360', '',
             18, 0.95, 155, 0, 140, 8, 60, 0, 'Volle 360-Grad-Drehung, trifft alles rundherum.', 88, 16],
            ['granate', 'Granate', 'granate.png', 'GRENADE', 'granate',
             55, 1.90, 400, 420, 190, 8, 60, 130, 'Wurfgeschoss mit verzögerter Explosion und großem Radius.', 46, 34],
        ];

        $out = [];
        foreach ($w as $i => $row) {
            [$id, $name, $sprite, $type, $projectile, $damage, $cooldown, $range,
             $projectileSpeed, $knockback, $critChance, $critDamage, $aoe, $desc,
             $spriteScale, $projectileSize] = $row;
            // Nahkampfwaffen liegen etwas höher in der Hand als Schusswaffen.
            $melee = in_array($type, ['MELEE_ARC', 'MELEE_360', 'THRUST'], true);
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
                'holdOffsetY' => $melee ? -10 : -6,
                'holdDistance' => 20,
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
            ['sharp_blade', 'Scharfe Klinge', 'damage', 'percent', 8, 'common', 100, 12, 'Deine Waffe schlägt härter zu.'],
            ['swift_feet', 'Flinke Füße', 'moveSpeed', 'percent', 8, 'common', 100, 10, 'Du bewegst dich spürbar schneller.'],
            ['tough_skin', 'Zähe Haut', 'maxHealth', 'flat', 20, 'common', 100, 15, 'Mehr maximale Lebenspunkte.'],
            ['rapid_fire', 'Schnellfeuer', 'attackSpeed', 'percent', 7, 'common', 100, 12, 'Deine Waffe greift öfter an.'],
            ['long_arm', 'Langer Arm', 'range', 'percent', 10, 'common', 90, 8, 'Größere Reichweite.'],
            ['tailwind', 'Rückenwind', 'projectileSpeed', 'percent', 12, 'common', 80, 8, 'Projektile fliegen schneller.'],

            ['precision', 'Präzision', 'critChance', 'flat', 5, 'rare', 100, 10, 'Höhere Chance auf kritische Treffer.'],
            ['impact', 'Wucht', 'knockback', 'percent', 25, 'rare', 70, 8, 'Gegner werden weiter zurueckgestossen.'],
            ['leather_hide', 'Lederhaut', 'armor', 'flat', 3, 'rare', 100, 10, 'Reduziert jeden erlittenen Treffer.'],
            ['regrowth', 'Regeneration', 'regen', 'flat', 0.9, 'rare', 90, 8, 'Heilt dich langsam über Zeit.'],
            ['evasion', 'Ausweichen', 'dodge', 'flat', 4, 'rare', 80, 8, 'Chance, einem Treffer komplett auszuweichen.'],

            ['berserk', 'Berserker', 'damage', 'percent', 18, 'epic', 100, 8, 'Deutlich mehr Waffenschaden.'],
            ['time_pressure', 'Zeitdruck', 'attackSpeed', 'percent', 16, 'epic', 100, 8, 'Deutlich schnellere Angriffe.'],
            ['crit_fury', 'Kritische Wut', 'critDamage', 'percent', 35, 'epic', 90, 8, 'Kritische Treffer richten mehr an.'],
            ['barrier', 'Barriere', 'shield', 'flat', 30, 'epic', 90, 6, 'Schild, der Schaden vor der Lebensleiste schluckt.'],
            ['vitality', 'Vitalität', 'maxHealth', 'percent', 15, 'epic', 90, 6, 'Prozentual mehr Leben.'],

            ['bloodlust', 'Blutrausch', 'damage', 'percent', 30, 'legendary', 100, 4, 'Massiver Schadensschub.'],
            ['twin_heart', 'Zwillingsherz', 'maxHealth', 'percent', 40, 'legendary', 90, 3, 'Enorm viel zusaetzliches Leben.'],
            ['time_rift', 'Zeitriss', 'attackSpeed', 'percent', 28, 'legendary', 90, 3, 'Angriffe kommen deutlich schneller.'],
            ['master_strike', 'Meisterhieb', 'critChance', 'flat', 12, 'legendary', 80, 3, 'Stark erhöhte kritische Trefferchance.'],
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
     * Ergänzt fehlende Schlüssel, damit ältere Speicherstaende weiterlaufen.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function migrate(array $data): array
    {
        $data['player'] = array_merge(self::player(), is_array($data['player'] ?? null) ? $data['player'] : []);
        $data['balance'] = array_merge(self::balance(), is_array($data['balance'] ?? null) ? $data['balance'] : []);
        $data['audio'] = array_merge(self::audio(), is_array($data['audio'] ?? null) ? $data['audio'] : []);
        // Neue Ton-Ereignisse ergänzen, bestehende Einstellungen behalten.
        $slots = self::soundSlots();
        $saved = is_array($data['audio']['sounds'] ?? null) ? $data['audio']['sounds'] : [];
        foreach ($slots as $id => $slot) {
            $slots[$id] = array_merge($slot, is_array($saved[$id] ?? null) ? $saved[$id] : []);
            $slots[$id]['label'] = $slot['label'];
        }
        $data['audio']['sounds'] = $slots;
        if (!isset($data['characters']) || !is_array($data['characters']) || !count($data['characters'])) {
            $data['characters'] = self::characters();
        }
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
                if (!isset($weapon['holdOffsetY'])) {
                    $melee = in_array($weapon['type'] ?? '', ['MELEE_ARC', 'MELEE_360', 'THRUST'], true);
                    $data['weapons'][$i]['holdOffsetY'] = $melee ? -10 : -6;
                }
                if (!isset($weapon['holdDistance'])) {
                    $data['weapons'][$i]['holdDistance'] = 20;
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
