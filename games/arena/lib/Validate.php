<?php
declare(strict_types=1);

/** Säubert und prüft die Daten, die aus dem Admin-Dashboard kommen. */
final class Validate
{
    public static function num(mixed $v, float $min, float $max, float $fallback): float
    {
        if (!is_numeric($v)) {
            return $fallback;
        }
        return max($min, min($max, (float) $v));
    }

    public static function int(mixed $v, int $min, int $max, int $fallback): int
    {
        return (int) round(self::num($v, $min, $max, $fallback));
    }

    public static function text(mixed $v, int $max = 120, string $fallback = ''): string
    {
        if (!is_string($v)) {
            return $fallback;
        }
        $v = trim(strip_tags($v));
        return mb_substr($v, 0, $max);
    }

    public static function bool(mixed $v): bool
    {
        return $v === true || $v === 'true' || $v === 1 || $v === '1';
    }

    /** Nur Pfade innerhalb der erlaubten Asset-Ordner. */
    public static function assetPath(mixed $v, string $fallback = ''): string
    {
        $v = is_string($v) ? trim($v) : '';
        if ($v === '') {
            return $fallback;
        }
        if (!preg_match('#^assets/(sprites|uploads)/[A-Za-z0-9._-]+$#', $v)) {
            return $fallback;
        }
        return $v;
    }

    public static function id(mixed $v, string $prefix = 'id'): string
    {
        $v = is_string($v) ? strtolower(trim($v)) : '';
        $v = preg_replace('/[^a-z0-9_-]/', '', $v) ?? '';
        if ($v === '') {
            $v = $prefix . '_' . bin2hex(random_bytes(4));
        }
        return substr($v, 0, 40);
    }

    /**
     * Ein Ton mit bis zu vier Varianten.
     *
     * @param mixed $in
     * @return array<string, mixed>
     */
    public static function soundSet(mixed $in, float $defaultVolume = 0.8): array
    {
        $in = is_array($in) ? $in : [];
        $variants = [];

        // Frühere Fassung kannte nur eine Datei.
        if (isset($in['src']) && !isset($in['variants'])) {
            $in['variants'] = is_string($in['src']) && $in['src'] !== ''
                ? [['src' => $in['src'], 'volume' => 1.0]] : [];
        }

        if (is_array($in['variants'] ?? null)) {
            foreach (array_slice($in['variants'], 0, 4) as $variant) {
                $src = is_array($variant) ? ($variant['src'] ?? '') : $variant;
                $src = is_string($src) ? trim($src) : '';
                if ($src === '' || !preg_match('#^assets/(audio|uploads)/[A-Za-z0-9._-]+$#', $src)) {
                    continue;
                }
                $variants[] = [
                    'src' => $src,
                    'volume' => self::num(is_array($variant) ? ($variant['volume'] ?? 1) : 1, 0, 1, 1.0),
                ];
            }
        }

        return [
            'enabled' => self::bool($in['enabled'] ?? true),
            'chance' => self::int($in['chance'] ?? 100, 0, 100, 100),
            'volume' => self::num($in['volume'] ?? $defaultVolume, 0, 1, $defaultVolume),
            'variants' => $variants,
        ];
    }

    /** @param array<string, mixed> $in @return array<string, mixed> */
    public static function weapon(array $in): array
    {
        $types = ['PROJECTILE', 'MAGIC', 'MELEE_ARC', 'MELEE_360', 'THRUST', 'GRENADE'];
        $type = is_string($in['type'] ?? null) && in_array($in['type'], $types, true) ? $in['type'] : 'PROJECTILE';
        return [
            'id' => self::id($in['id'] ?? '', 'weapon'),
            'name' => self::text($in['name'] ?? '', 40, 'Neue Waffe'),
            'sprite' => self::assetPath($in['sprite'] ?? '', Defaults::SPRITE_BASE . 'pistole.png'),
            'type' => $type,
            'projectile' => self::text($in['projectile'] ?? '', 20),
            'damage' => self::num($in['damage'] ?? 10, 0.1, 100000, 10),
            'cooldown' => self::num($in['cooldown'] ?? 0.5, 0.03, 30, 0.5),
            'range' => self::num($in['range'] ?? 300, 20, 4000, 300),
            'projectileSpeed' => self::num($in['projectileSpeed'] ?? 500, 0, 4000, 500),
            'knockback' => self::num($in['knockback'] ?? 60, 0, 2000, 60),
            'critChance' => self::num($in['critChance'] ?? 8, 0, 100, 8),
            'critDamage' => self::num($in['critDamage'] ?? 60, 0, 1000, 60),
            'aoeRadius' => self::num($in['aoeRadius'] ?? 0, 0, 1200, 0),
            'damageType' => self::text($in['damageType'] ?? 'physical', 20, 'physical'),
            'arc' => self::num($in['arc'] ?? 360, 5, 360, 360),
            'pierce' => self::int($in['pierce'] ?? 0, 0, 20, 0),
            'spread' => self::num($in['spread'] ?? 0, 0, 60, 0),
            'recoil' => self::num($in['recoil'] ?? 6, 0, 60, 6),
            'spriteScale' => self::num($in['spriteScale'] ?? 46, 6, 400, 46),
            'projectileSize' => self::num($in['projectileSize'] ?? 16, 3, 200, 16),
            'holdOffsetY' => self::num($in['holdOffsetY'] ?? -6, -120, 120, -6),
            'holdDistance' => self::num($in['holdDistance'] ?? 20, -60, 200, 20),
            // Start des Projektils. Abstand 0 = aus der Waffengroesse ableiten.
            'muzzleOffsetY' => self::num($in['muzzleOffsetY'] ?? -6, -160, 160, -6),
            'muzzleDistance' => self::num($in['muzzleDistance'] ?? 0, 0, 300, 0),
            // Ansatzpunkt von Schlag und Stich.
            'attackOffsetY' => self::num($in['attackOffsetY'] ?? -10, -160, 160, -10),
            'attackOffsetX' => self::num($in['attackOffsetX'] ?? 0, -160, 300, 0),
            'trailColor' => self::color($in['trailColor'] ?? '', '#ffe6ae'),
            'sound' => self::soundSet($in['sound'] ?? null, 0.6),
            'description' => self::text($in['description'] ?? '', 200),
            'active' => self::bool($in['active'] ?? true),
            'starter' => self::bool($in['starter'] ?? false),
            'order' => self::int($in['order'] ?? 99, 0, 999, 99),
        ];
    }

    /** @param array<string, mixed> $in @return array<string, mixed> */
    public static function enemy(array $in): array
    {
        $hb = is_array($in['hitbox'] ?? null) ? $in['hitbox'] : [];
        $shape = ($hb['shape'] ?? 'circle') === 'rect' ? 'rect' : 'circle';
        return [
            'id' => self::id($in['id'] ?? '', 'enemy'),
            'name' => self::text($in['name'] ?? '', 40, 'Neuer Gegner'),
            'sprite' => self::assetPath($in['sprite'] ?? '', Defaults::SPRITE_BASE . 'enemy1.gif'),
            'health' => self::num($in['health'] ?? 30, 1, 1000000, 30),
            'damage' => self::num($in['damage'] ?? 8, 0, 100000, 8),
            'speed' => self::num($in['speed'] ?? 60, 0, 1000, 60),
            'reward' => self::num($in['reward'] ?? 3, 0, 100000, 3),
            'spawnWeight' => self::num($in['spawnWeight'] ?? 100, 0, 10000, 100),
            'boss' => self::bool($in['boss'] ?? false),
            'contactCooldown' => self::num($in['contactCooldown'] ?? 0.8, 0.05, 20, 0.8),
            'scale' => self::num($in['scale'] ?? 64, 8, 600, 64),
            'wave' => self::int($in['wave'] ?? 0, 0, 99, 0),
            'soundHit' => self::soundSet($in['soundHit'] ?? null, 0.35),
            'soundDeath' => self::soundSet($in['soundDeath'] ?? null, 0.5),
            'hitbox' => [
                'shape' => $shape,
                'r' => self::num($hb['r'] ?? 20, 2, 400, 20),
                'w' => self::num($hb['w'] ?? 40, 2, 800, 40),
                'h' => self::num($hb['h'] ?? 40, 2, 800, 40),
                'ox' => self::num($hb['ox'] ?? 0, -400, 400, 0),
                'oy' => self::num($hb['oy'] ?? 0, -400, 400, 0),
            ],
        ];
    }

    /**
     * Ein Gegenstand, der auf der Karte erscheint.
     *
     * @param array<string, mixed> $in
     * @return array<string, mixed>
     */
    public static function item(array $in): array
    {
        $effects = ['heal', 'money', 'shield', 'speed', 'magnet'];
        $effect = is_string($in['effect'] ?? null) && in_array($in['effect'], $effects, true)
            ? $in['effect'] : 'heal';
        $mode = ($in['mode'] ?? 'pickup') === 'chest' ? 'chest' : 'pickup';
        $sounds = array_keys(Defaults::soundSlots());
        $sound = is_string($in['sound'] ?? null) && in_array($in['sound'], $sounds, true)
            ? $in['sound'] : '';

        $min = self::num($in['minDistance'] ?? 200, 0, 4000, 200);
        $max = self::num($in['maxDistance'] ?? 620, 0, 4000, 620);
        if ($max < $min) {
            $max = $min;
        }

        return [
            'id' => self::id($in['id'] ?? '', 'item'),
            'name' => self::text($in['name'] ?? '', 40, 'Neuer Gegenstand'),
            'description' => self::text($in['description'] ?? '', 200),
            'sprite' => self::assetPath($in['sprite'] ?? '', Defaults::SPRITE_BASE . 'heiltrank.png'),
            'openSprite' => self::assetPath($in['openSprite'] ?? '', ''),
            'scale' => self::num($in['scale'] ?? 34, 6, 400, 34),
            'mode' => $mode,
            'openTime' => self::num($in['openTime'] ?? 2, 0, 30, 2),
            'effect' => $effect,
            'value' => self::num($in['value'] ?? 10, 0, 100000, 10),
            'value2' => self::num($in['value2'] ?? 0, 0, 100000, 0),
            'onlyWhenNeeded' => self::bool($in['onlyWhenNeeded'] ?? false),
            'interval' => self::num($in['interval'] ?? 14, 0.5, 3600, 14),
            'chance' => self::num($in['chance'] ?? 0.35, 0, 1, 0.35),
            'maxOnMap' => self::int($in['maxOnMap'] ?? 3, 0, 50, 3),
            'lifetime' => self::num($in['lifetime'] ?? 26, 2, 3600, 26),
            'minDistance' => $min,
            'maxDistance' => $max,
            'particle' => self::color($in['particle'] ?? '', '#ffd166'),
            'sound' => $sound,
            'active' => self::bool($in['active'] ?? true),
        ];
    }

    /** Farbe als #rgb oder #rrggbb. */
    public static function color(mixed $v, string $fallback): string
    {
        $v = is_string($v) ? trim($v) : '';
        return preg_match('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $v) ? $v : $fallback;
    }

    /**
     * Grenzen für alle Charakter-Abweichungen.
     * Faktoren liegen um 1.0, Zuschläge starten bei 0.
     */
    public const MOD_LIMITS = [
        'maxHealth' => [0.2, 5, 1.0], 'moveSpeed' => [0.2, 5, 1.0], 'damageMult' => [0.2, 5, 1.0],
        'attackSpeed' => [0.2, 5, 1.0], 'range' => [0.2, 5, 1.0], 'projectileSpeed' => [0.2, 5, 1.0],
        'knockback' => [0.0, 5, 1.0], 'pickupRange' => [0.2, 8, 1.0], 'potionRate' => [0.0, 8, 1.0],
        'money' => [0.2, 10, 1.0],
        'armor' => [0, 200, 0.0], 'critChance' => [0, 100, 0.0], 'critDamage' => [0, 500, 0.0],
        'dodge' => [0, 75, 0.0], 'regen' => [0, 50, 0.0], 'shield' => [0, 500, 0.0],
        'burn' => [0, 500, 0.0],
    ];

    /** @param array<string, mixed> $in @return array<string, mixed> */
    public static function upgrade(array $in): array
    {
        $rarities = ['common', 'rare', 'epic', 'legendary'];
        $rarity = is_string($in['rarity'] ?? null) && in_array($in['rarity'], $rarities, true) ? $in['rarity'] : 'common';
        $effekte = array_keys(self::upgradeEffects());
        $effect = is_string($in['effect'] ?? null) && in_array($in['effect'], $effekte, true) ? $in['effect'] : '';

        // Eine Karte darf mehrere Werte gleichzeitig verschieben.
        $mods = [];
        if (is_array($in['mods'] ?? null)) {
            foreach (array_slice($in['mods'], 0, 8) as $m) {
                if (!is_array($m)) {
                    continue;
                }
                $mods[] = [
                    'stat' => self::upgradeStat($m['stat'] ?? ''),
                    'modType' => ($m['modType'] ?? 'percent') === 'flat' ? 'flat' : 'percent',
                    'value' => self::num($m['value'] ?? 0, -100000, 100000, 0),
                ];
            }
        }
        if (!$mods) {
            $mods[] = [
                'stat' => self::upgradeStat($in['stat'] ?? ''),
                'modType' => ($in['modType'] ?? 'percent') === 'flat' ? 'flat' : 'percent',
                'value' => self::num($in['value'] ?? 5, -100000, 100000, 5),
            ];
        }

        return [
            'id' => self::id($in['id'] ?? '', 'upgrade'),
            'name' => self::text($in['name'] ?? '', 40, 'Neues Upgrade'),
            'description' => self::text($in['description'] ?? '', 200),
            'icon' => self::assetPath($in['icon'] ?? '', ''),
            'stat' => $mods[0]['stat'],
            'modType' => $mods[0]['modType'],
            'value' => $mods[0]['value'],
            'mods' => $mods,
            'effect' => $effect,
            'rarity' => $rarity,
            'weight' => self::num($in['weight'] ?? 100, 0, 10000, 100),
            'maxStack' => self::int($in['maxStack'] ?? 10, 1, 999, 10),
            'active' => self::bool($in['active'] ?? true),
        ];
    }

    /** Alle Werte, die eine Upgrade-Karte verschieben darf. @return list<string> */
    public static function upgradeStats(): array
    {
        return ['damage', 'attackSpeed', 'moveSpeed', 'maxHealth', 'armor', 'shield', 'critChance',
                'critDamage', 'projectileSpeed', 'range', 'knockback', 'dodge', 'regen',
                'burn', 'potionRate', 'pickupRange', 'lifesteal', 'luck', 'money',
                'projectileDamage', 'meleeRange', 'rangedAttackSpeed', 'thorns'];
    }

    public static function upgradeStat(mixed $v): string
    {
        return is_string($v) && in_array($v, self::upgradeStats(), true) ? $v : 'damage';
    }

    /**
     * Sondereffekte, die eine Karte mitbringen kann.
     * Der Schluessel steht in den Daten, die Beschreibung im Dashboard.
     *
     * @return array<string, string>
     */
    public static function upgradeEffects(): array
    {
        return [
            '' => 'kein Sondereffekt',
            'lifesteal' => 'Lebensraub - ein Teil des Schadens heilt',
            'critHeal' => 'Kritische Treffer heilen zusätzlich',
            'thorns' => 'Berührende Gegner nehmen Schaden',
            'killFrenzy' => 'Kills geben kurz mehr Angriffstempo',
            'hurtFrenzy' => 'Treffer geben kurz mehr Angriffstempo',
            'multikill' => 'Schnelle Kills geben kurz mehr Schaden',
            'untouched' => 'Ohne Treffer wächst der Schaden',
            'berserk' => 'Wenig Leben - mehr Schaden',
            'execute' => 'Mehr Schaden gegen verletzte Gegner',
            'doubleShot' => 'Chance auf ein zweites Projektil',
            'ghostShot' => 'Projektile durchdringen Gegner',
            'chainExplode' => 'Getötete Gegner explodieren',
            'collide' => 'Kollision verursacht Schaden',
            'waveHeal' => 'Heilt nach jeder Welle',
            'treasure' => 'Bosse und Truhen geben mehr',
            'lastPotato' => 'Überlebt einmal je Welle tödlichen Schaden',
            'blackhole' => 'Zieht regelmässig Gegner an',
            'slowmo' => 'Bei wenig Leben werden Gegner langsamer',
            'midas' => 'Kills geben manchmal Bonusgeld',
            'deathwave' => 'Bei wenig Leben entlädt sich eine Druckwelle',
            'soulEater' => 'Bosskills geben dauerhaft Schaden',
            'hunger' => 'Viele Kills geben dauerhaft Leben',
            'clone' => 'Feuert regelmässig einen zweiten Schuss',
            'bloodPact' => 'Mehr Schaden, jede Welle kostet Leben',
            'greedCurse' => 'Mehr Beute, zähere Gegner',
            'chaos' => 'Jede Welle ein Vorteil und ein Nachteil',
            'goldenDice' => 'Jede Welle ein zufälliger Wert dauerhaft besser',
            'mutation' => 'Jede Welle mehr Schaden, stärkere Gegner',
            'potatoGod' => 'Alles besser - und mehr Elitegegner',

            // --- Verrückte Karten ---------------------------------------
            'swarm' => 'Mehr Schaden je lebendem Gegner',
            'lonewolf' => 'Mehr Schaden, je weniger Gegner stehen',
            'greedyBlade' => 'Mehr Schaden je 100 Geld im Beutel',
            'pauper' => 'Mehr Schaden, solange du fast pleite bist',
            'momentum' => 'Mehr Schaden in Bewegung',
            'bulwark' => 'Mehr Schaden im Stand',
            'gambler' => 'Jeder Treffer halb oder doppelt',
            'snowball' => 'Jeder Kill macht dauerhaft stärker',
            'criticalMass' => 'Krit-Chance über 100 % wird zu Schaden',
            'nightmare' => 'Mehr Schaden je Zyklus',
            'adrenalin' => 'Angriffstempo wächst mit fehlendem Leben',
            'rage' => 'Bei wenig Leben deutlich schnellere Angriffe',
            'harvest' => 'Regelmässige Heilung durch Kills',
            'bloodMoney' => 'Kills geben Geld und dauerhaft Schaden',
            'guardian' => 'Nach einem Treffer kurz unverwundbar',
            'retaliate' => 'Treffer lösen eine Druckwelle aus',
            'banker' => 'Dein Gold gibt jede Welle Schaden',
            'roulette' => 'Jede Welle ein grosser Zufallsbonus',
            'frostAura' => 'Gegner in der Nähe werden träge',
            'flameAura' => 'Gegner in der Nähe fangen Feuer',
            'spikes' => 'Dauerschaden im Nahbereich',
            'timeWarp' => 'Regelmässig kurz doppeltes Angriffstempo',
            'magnetize' => 'Alles auf der Karte fliegt dir zu',
            'pierceAll' => 'Projektile durchschlagen viel mehr Gegner',
            'echo' => 'Jeder vierte Schuss wiederholt sich',
            'bigShot' => 'Grössere Projektile mit mehr Schaden',
        ];
    }

    /**
     * Ein Charakter. Je Richtung entweder ein GIF oder bis zu fünf Einzelbilder.
     *
     * @param array<string, mixed> $in
     * @return array<string, mixed>
     */
    public static function character(array $in): array
    {
        $perks = array_keys(Defaults::perks());
        $perk = is_string($in['perk'] ?? null) && in_array($in['perk'], $perks, true) ? $in['perk'] : '';

        $sprites = [];
        $given = is_array($in['sprites'] ?? null) ? $in['sprites'] : [];
        foreach (['front', 'back', 'side', 'idle'] as $dir) {
            $entry = is_array($given[$dir] ?? null) ? $given[$dir] : [];
            $frames = [];
            $flips = [];
            $rohFlips = is_array($entry['flips'] ?? null) ? array_values($entry['flips']) : [];
            if (is_array($entry['frames'] ?? null)) {
                foreach (array_values($entry['frames']) as $i => $frame) {
                    if (count($frames) >= 5) {
                        break;
                    }
                    $path = self::assetPath($frame, '');
                    if ($path === '') {
                        continue;
                    }
                    $frames[] = $path;
                    // Jedes Einzelbild laesst sich einzeln spiegeln.
                    $flips[] = self::bool($rohFlips[$i] ?? false);
                }
            }
            $sprites[$dir] = [
                'gif' => self::assetPath($entry['gif'] ?? '', ''),
                'frames' => $frames,
                'flips' => $flips,
                // Ganze Richtung spiegeln - z. B. wenn das Sprite falsch herum ist.
                'flip' => self::bool($entry['flip'] ?? false),
                // Groesse nur dieser Richtung, unabhaengig von der Hitbox.
                'scale' => self::num($entry['scale'] ?? 1, 0.2, 4, 1),
            ];
        }

        $mods = is_array($in['mods'] ?? null) ? $in['mods'] : [];
        $clean = [];
        foreach (self::MOD_LIMITS as $key => [$min, $max, $fallback]) {
            $clean[$key] = self::num($mods[$key] ?? $fallback, (float) $min, (float) $max, (float) $fallback);
        }

        $hb = is_array($in['hitbox'] ?? null) ? $in['hitbox'] : [];
        return [
            'id' => self::id($in['id'] ?? '', 'char'),
            'name' => self::text($in['name'] ?? '', 24, 'Neuer Charakter'),
            'title' => self::text($in['title'] ?? '', 30),
            'description' => self::text($in['description'] ?? '', 200),
            'perk' => $perk,
            'tint' => self::num($in['tint'] ?? 0, 0, 360, 0),
            'sprites' => $sprites,
            'frameDuration' => self::num($in['frameDuration'] ?? 130, 20, 2000, 130),
            'dustSprite' => self::assetPath($in['dustSprite'] ?? '', Defaults::SPRITE_BASE . 'staub.gif'),
            'scale' => self::num($in['scale'] ?? 78, 8, 600, 78),
            'hitbox' => [
                'rx' => self::num($hb['rx'] ?? 14, 2, 200, 14),
                'ry' => self::num($hb['ry'] ?? 9, 2, 200, 9),
                'oy' => self::num($hb['oy'] ?? 24, -200, 200, 24),
            ],
            'mods' => $clean,
            'starter' => self::bool($in['starter'] ?? false),
            'unlockCost' => self::int($in['unlockCost'] ?? 20, 0, 10000, 20),
            'active' => self::bool($in['active'] ?? true),
            'order' => self::int($in['order'] ?? 99, 0, 999, 99),
        ];
    }

    /** @param array<string, mixed> $in @return array<string, mixed> */
    public static function map(array $in): array
    {
        $col = is_array($in['collision'] ?? null) ? $in['collision'] : [];
        $cols = self::int($col['cols'] ?? 128, 8, 512, 128);
        $rows = self::int($col['rows'] ?? 128, 8, 512, 128);
        $data = is_string($col['data'] ?? null) ? preg_replace('#[^A-Za-z0-9+/=]#', '', $col['data']) : '';
        $needed = (int) ceil($cols * $rows / 8);
        if (strlen(base64_decode($data ?? '', true) ?: '') < $needed) {
            $data = base64_encode(str_repeat("\0", $needed));
        }
        $spawn = is_array($in['spawn'] ?? null) ? $in['spawn'] : [];
        $w = self::int($in['width'] ?? 2048, 256, 16384, 2048);
        $h = self::int($in['height'] ?? 2048, 256, 16384, 2048);

        $areas = [];
        if (is_array($in['enemySpawnAreas'] ?? null)) {
            foreach (array_slice($in['enemySpawnAreas'], 0, 24) as $a) {
                if (!is_array($a)) {
                    continue;
                }
                $areas[] = [
                    'x' => self::num($a['x'] ?? 0, 0, $w, 0),
                    'y' => self::num($a['y'] ?? 0, 0, $h, 0),
                    'r' => self::num($a['r'] ?? 200, 20, max($w, $h), 200),
                ];
            }
        }

        // Portale: rot und blau, paarweise verbunden. Das erste rote führt
        // zum ersten blauen und zurück.
        $portals = [];
        if (is_array($in['portals'] ?? null)) {
            foreach (array_slice($in['portals'], 0, 16) as $p) {
                if (!is_array($p)) {
                    continue;
                }
                $portals[] = [
                    'id' => self::id($p['id'] ?? '', 'portal'),
                    'kind' => ($p['kind'] ?? 'red') === 'blue' ? 'blue' : 'red',
                    'x' => self::num($p['x'] ?? 0, 0, $w, 0),
                    'y' => self::num($p['y'] ?? 0, 0, $h, 0),
                    'scale' => self::num($p['scale'] ?? 130, 20, 600, 130),
                ];
            }
        }

        return [
            'id' => self::id($in['id'] ?? '', 'map'),
            'name' => self::text($in['name'] ?? '', 40, 'Neue Welt'),
            'image' => self::assetPath($in['image'] ?? '', ''),
            'width' => $w,
            'height' => $h,
            'active' => self::bool($in['active'] ?? true),
            'spawn' => [
                'x' => self::num($spawn['x'] ?? $w / 2, 0, $w, $w / 2),
                'y' => self::num($spawn['y'] ?? $h / 2, 0, $h, $h / 2),
            ],
            'enemySpawnAreas' => $areas,
            'portals' => $portals,
            // Stimmung: feine Teilchen ueber dem ganzen Bild, je Karte
            // eine eigene Farbe und Dichte.
            'particleColor' => self::color($in['particleColor'] ?? '', '#ffd9a0'),
            'particleAmount' => self::int($in['particleAmount'] ?? 45, 0, 200, 45),
            'collision' => ['cols' => $cols, 'rows' => $rows, 'data' => $data],
            'createdAt' => self::int($in['createdAt'] ?? time(), 0, PHP_INT_MAX, time()),
        ];
    }

    /** @param array<string, mixed> $in @return array<string, mixed> */
    public static function player(array $in): array
    {
        $d = Defaults::player();
        $hb = is_array($in['hitbox'] ?? null) ? $in['hitbox'] : [];
        return [
            'maxHealth' => self::num($in['maxHealth'] ?? $d['maxHealth'], 1, 100000, $d['maxHealth']),
            'moveSpeed' => self::num($in['moveSpeed'] ?? $d['moveSpeed'], 10, 2000, $d['moveSpeed']),
            'armor' => self::num($in['armor'] ?? 0, 0, 10000, 0),
            'damageMult' => self::num($in['damageMult'] ?? 1, 0.01, 100, 1),
            'critChance' => self::num($in['critChance'] ?? 5, 0, 100, 5),
            'critDamage' => self::num($in['critDamage'] ?? 60, 0, 2000, 60),
            'dodge' => self::num($in['dodge'] ?? 0, 0, 90, 0),
            'regen' => self::num($in['regen'] ?? 0, 0, 1000, 0),
            'pickupRange' => self::num($in['pickupRange'] ?? 90, 10, 2000, 90),
            'spriteFront' => self::assetPath($in['spriteFront'] ?? '', $d['spriteFront']),
            'spriteBack' => self::assetPath($in['spriteBack'] ?? '', $d['spriteBack']),
            'spriteSide' => self::assetPath($in['spriteSide'] ?? '', $d['spriteSide']),
            'spriteDust' => self::assetPath($in['spriteDust'] ?? '', $d['spriteDust']),
            'scale' => self::num($in['scale'] ?? $d['scale'], 8, 600, $d['scale']),
            'hitbox' => [
                'rx' => self::num($hb['rx'] ?? 15, 2, 200, 15),
                'ry' => self::num($hb['ry'] ?? 10, 2, 200, 10),
                'oy' => self::num($hb['oy'] ?? 22, -200, 200, 22),
            ],
        ];
    }

    /** @param array<string, mixed> $in @return array<string, mixed> */
    public static function audio(array $in): array
    {
        $d = Defaults::audio();
        $pfad = static function (mixed $v, string $fallback): string {
            $v = is_string($v) ? trim($v) : '';
            if ($v === '') {
                return '';
            }
            return preg_match('#^assets/(audio|uploads)/[A-Za-z0-9._-]+$#', $v) ? $v : $fallback;
        };
        $track = $pfad($in['musicTrack'] ?? null, $d['musicTrack']);
        $menu = $pfad($in['musicMenu'] ?? null, $d['musicMenu']);
        $slots = Defaults::soundSlots();
        $given = is_array($in['sounds'] ?? null) ? $in['sounds'] : [];
        $sounds = [];
        foreach ($slots as $id => $slot) {
            $sounds[$id] = self::soundSet($given[$id] ?? null, (float) $slot['volume'])
                + ['label' => $slot['label']];
        }

        return [
            'musicTrack' => $track,
            'musicMenu' => $menu,
            'musicVolume' => self::num($in['musicVolume'] ?? $d['musicVolume'], 0, 1, $d['musicVolume']),
            'sfxVolume' => self::num($in['sfxVolume'] ?? $d['sfxVolume'], 0, 1, $d['sfxVolume']),
            'musicEnabled' => self::bool($in['musicEnabled'] ?? true),
            'sounds' => $sounds,
        ];
    }

    /** @param array<string, mixed> $in @return array<string, mixed> */
    public static function balance(array $in): array
    {
        $d = Defaults::balance();
        $limits = [
            'waveDuration' => [5, 900], 'bossDuration' => [10, 1800], 'enemySpawnRate' => [0.05, 50],
            'maxEnemies' => [5, 800], 'maxEnemiesGrowth' => [1, 2], 'maxEnemiesLimit' => [10, 800], 'healthScaling' => [1, 5], 'damageScaling' => [1, 5],
            'speedScaling' => [1, 3], 'spawnRateScaling' => [1, 5], 'rewardScaling' => [1, 5],
            'moneyMultiplier' => [0.05, 20], 'contactDamageCooldown' => [0.1, 10], 'hitInvuln' => [0, 3],
            'eliteFromCycle' => [1, 99], 'eliteGrowth' => [0, 1], 'eliteMax' => [0, 1],
            'eliteHealth' => [1, 20], 'eliteDamage' => [1, 10],
            'bossBombCooldown' => [0.5, 60], 'bossBombRadius' => [20, 900], 'bossBombDelay' => [0.1, 10],
            'bossBombFlightTime' => [0.1, 6], 'bossBombMinCooldown' => [0.3, 30],
            'burnDuration' => [0.2, 60],
            'waveMixShare' => [0, 1], 'waveStartEnemies' => [0, 60],
            'ultCooldown' => [1, 600], 'ultRadius' => [20, 2000],
            'ultKnockback' => [0, 20000], 'ultDamage' => [0, 100000],
            'upgradeChoices' => [1, 6], 'rarityRareBase' => [0, 100], 'rarityEpicBase' => [0, 100],
            'rarityLegendaryBase' => [0, 100], 'rarityCycleBonus' => [1, 4],
        ];
        $out = [];
        foreach ($d as $key => $fallback) {
            [$min, $max] = $limits[$key] ?? [0, 100000];
            $out[$key] = self::num($in[$key] ?? $fallback, (float) $min, (float) $max, (float) $fallback);
        }
        $out['maxEnemies'] = (int) $out['maxEnemies'];
        $out['maxEnemiesLimit'] = (int) $out['maxEnemiesLimit'];
        $out['eliteFromCycle'] = (int) $out['eliteFromCycle'];
        $out['upgradeChoices'] = (int) $out['upgradeChoices'];
        $out['waveStartEnemies'] = (int) $out['waveStartEnemies'];
        return $out;
    }

    /**
     * Einstellungen des Ladens.
     *
     * @param array<string, mixed> $in
     * @return array<string, mixed>
     */
    public static function shop(array $in): array
    {
        $d = Defaults::shop();
        $frames = [];
        if (is_array($in['merchantFrames'] ?? null)) {
            foreach (array_values($in['merchantFrames']) as $frame) {
                if (count($frames) >= 5) {
                    break;
                }
                $path = self::assetPath($frame, '');
                if ($path !== '') {
                    $frames[] = $path;
                }
            }
        }
        if (!count($frames)) {
            $frames = $d['merchantFrames'];
        }

        return [
            'enabled' => self::bool($in['enabled'] ?? true),
            'title' => self::text($in['title'] ?? '', 40, $d['title']),
            'background' => self::assetPath($in['background'] ?? '', $d['background']),
            'counter' => self::assetPath($in['counter'] ?? '', $d['counter']),
            'merchantFrames' => $frames,
            'merchantFrameDuration' => self::num($in['merchantFrameDuration'] ?? $d['merchantFrameDuration'], 40, 3000, (float) $d['merchantFrameDuration']),
            'merchantX' => self::num($in['merchantX'] ?? $d['merchantX'], 0, 100, (float) $d['merchantX']),
            'merchantY' => self::num($in['merchantY'] ?? $d['merchantY'], 0, 140, (float) $d['merchantY']),
            'merchantScale' => self::num($in['merchantScale'] ?? $d['merchantScale'], 20, 300, (float) $d['merchantScale']),
            'counterY' => self::num($in['counterY'] ?? $d['counterY'], 0, 160, (float) $d['counterY']),
            'counterScale' => self::num($in['counterScale'] ?? $d['counterScale'], 20, 300, (float) $d['counterScale']),
            'offerCount' => self::int($in['offerCount'] ?? $d['offerCount'], 1, 8, (int) $d['offerCount']),
            'priceBase' => self::num($in['priceBase'] ?? $d['priceBase'], 1, 5000, (float) $d['priceBase']),
            'priceCommon' => self::num($in['priceCommon'] ?? $d['priceCommon'], 0.1, 20, (float) $d['priceCommon']),
            'priceRare' => self::num($in['priceRare'] ?? $d['priceRare'], 0.1, 20, (float) $d['priceRare']),
            'priceEpic' => self::num($in['priceEpic'] ?? $d['priceEpic'], 0.1, 20, (float) $d['priceEpic']),
            'priceLegendary' => self::num($in['priceLegendary'] ?? $d['priceLegendary'], 0.1, 20, (float) $d['priceLegendary']),
            'priceWeapon' => self::num($in['priceWeapon'] ?? $d['priceWeapon'], 0.1, 20, (float) $d['priceWeapon']),
            'priceCycleGrowth' => self::num($in['priceCycleGrowth'] ?? $d['priceCycleGrowth'], 1, 3, (float) $d['priceCycleGrowth']),
            'priceStackBonus' => self::num($in['priceStackBonus'] ?? $d['priceStackBonus'], 0, 4, (float) $d['priceStackBonus']),
            'rerollCost' => self::num($in['rerollCost'] ?? $d['rerollCost'], 0, 5000, (float) $d['rerollCost']),
            'rerollGrowth' => self::num($in['rerollGrowth'] ?? $d['rerollGrowth'], 1, 5, (float) $d['rerollGrowth']),
            'weaponChance' => self::num($in['weaponChance'] ?? $d['weaponChance'], 0, 1, (float) $d['weaponChance']),
            'lockLimit' => self::int($in['lockLimit'] ?? $d['lockLimit'], 0, 8, (int) $d['lockLimit']),
        ];
    }

    /**
     * Aussehen der Menuebildschirme.
     *
     * @param array<string, mixed> $in
     * @return array<string, mixed>
     */
    public static function ui(array $in): array
    {
        $d = Defaults::ui();
        return [
            'menuBackground' => self::assetPath($in['menuBackground'] ?? '', $d['menuBackground']),
            'charBackground' => self::assetPath($in['charBackground'] ?? '', $d['charBackground']),
            'charX' => self::num($in['charX'] ?? $d['charX'], 0, 100, (float) $d['charX']),
            'charY' => self::num($in['charY'] ?? $d['charY'], 0, 140, (float) $d['charY']),
            'charScale' => self::num($in['charScale'] ?? $d['charScale'], 20, 300, (float) $d['charScale']),
        ];
    }
}
