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

    /** @param array<string, mixed> $in @return array<string, mixed> */
    public static function upgrade(array $in): array
    {
        $stats = ['damage', 'attackSpeed', 'moveSpeed', 'maxHealth', 'armor', 'shield', 'critChance',
                  'critDamage', 'projectileSpeed', 'range', 'knockback', 'dodge', 'regen'];
        $rarities = ['common', 'rare', 'epic', 'legendary'];
        $stat = is_string($in['stat'] ?? null) && in_array($in['stat'], $stats, true) ? $in['stat'] : 'damage';
        $rarity = is_string($in['rarity'] ?? null) && in_array($in['rarity'], $rarities, true) ? $in['rarity'] : 'common';
        $modType = ($in['modType'] ?? 'percent') === 'flat' ? 'flat' : 'percent';
        return [
            'id' => self::id($in['id'] ?? '', 'upgrade'),
            'name' => self::text($in['name'] ?? '', 40, 'Neues Upgrade'),
            'description' => self::text($in['description'] ?? '', 200),
            'icon' => self::assetPath($in['icon'] ?? '', ''),
            'stat' => $stat,
            'modType' => $modType,
            'value' => self::num($in['value'] ?? 5, -1000, 100000, 5),
            'rarity' => $rarity,
            'weight' => self::num($in['weight'] ?? 100, 0, 10000, 100),
            'maxStack' => self::int($in['maxStack'] ?? 10, 1, 999, 10),
            'active' => self::bool($in['active'] ?? true),
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
        $perks = ['', 'lifesteal', 'thorns', 'luckyCards'];
        $perk = is_string($in['perk'] ?? null) && in_array($in['perk'], $perks, true) ? $in['perk'] : '';

        $sprites = [];
        $given = is_array($in['sprites'] ?? null) ? $in['sprites'] : [];
        foreach (['front', 'back', 'side'] as $dir) {
            $entry = is_array($given[$dir] ?? null) ? $given[$dir] : [];
            $frames = [];
            if (is_array($entry['frames'] ?? null)) {
                foreach (array_slice($entry['frames'], 0, 5) as $frame) {
                    $path = self::assetPath($frame, '');
                    if ($path !== '') {
                        $frames[] = $path;
                    }
                }
            }
            $sprites[$dir] = [
                'gif' => self::assetPath($entry['gif'] ?? '', ''),
                'frames' => $frames,
            ];
        }

        $mods = is_array($in['mods'] ?? null) ? $in['mods'] : [];
        $clean = [];
        foreach ([
            'maxHealth' => [0.2, 5, 1.0], 'moveSpeed' => [0.2, 5, 1.0], 'damageMult' => [0.2, 5, 1.0],
            'attackSpeed' => [0.2, 5, 1.0], 'range' => [0.2, 5, 1.0], 'projectileSpeed' => [0.2, 5, 1.0],
            'armor' => [0, 200, 0], 'critChance' => [0, 100, 0], 'critDamage' => [0, 500, 0],
            'dodge' => [0, 75, 0], 'regen' => [0, 50, 0], 'shield' => [0, 500, 0],
        ] as $key => [$min, $max, $fallback]) {
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
        $track = is_string($in['musicTrack'] ?? null) ? trim($in['musicTrack']) : '';
        if ($track !== '' && !preg_match('#^assets/(audio|uploads)/[A-Za-z0-9._-]+$#', $track)) {
            $track = $d['musicTrack'];
        }
        $slots = Defaults::soundSlots();
        $given = is_array($in['sounds'] ?? null) ? $in['sounds'] : [];
        $sounds = [];
        foreach ($slots as $id => $slot) {
            $entry = is_array($given[$id] ?? null) ? $given[$id] : [];
            $src = is_string($entry['src'] ?? null) ? trim($entry['src']) : '';
            if ($src !== '' && !preg_match('#^assets/(audio|uploads)/[A-Za-z0-9._-]+$#', $src)) {
                $src = '';
            }
            $sounds[$id] = [
                'src' => $src,
                'volume' => self::num($entry['volume'] ?? 0.8, 0, 1, 0.8),
                'label' => $slot['label'],
            ];
        }

        return [
            'musicTrack' => $track,
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
            'maxEnemies' => [5, 400], 'healthScaling' => [1, 5], 'damageScaling' => [1, 5],
            'speedScaling' => [1, 3], 'spawnRateScaling' => [1, 5], 'rewardScaling' => [1, 5],
            'moneyMultiplier' => [0.1, 20], 'contactDamageCooldown' => [0.1, 10],
            'bossBombCooldown' => [0.5, 60], 'bossBombRadius' => [20, 900], 'bossBombDelay' => [0.1, 10],
            'bossBombFlightTime' => [0.1, 6], 'bossBombMinCooldown' => [0.3, 30],
            'upgradeChoices' => [1, 6], 'rarityRareBase' => [0, 100], 'rarityEpicBase' => [0, 100],
            'rarityLegendaryBase' => [0, 100], 'rarityCycleBonus' => [1, 4], 'weaponOfferChance' => [0, 1],
        ];
        $out = [];
        foreach ($d as $key => $fallback) {
            [$min, $max] = $limits[$key] ?? [0, 100000];
            $out[$key] = self::num($in[$key] ?? $fallback, (float) $min, (float) $max, (float) $fallback);
        }
        $out['maxEnemies'] = (int) $out['maxEnemies'];
        $out['upgradeChoices'] = (int) $out['upgradeChoices'];
        return $out;
    }
}
