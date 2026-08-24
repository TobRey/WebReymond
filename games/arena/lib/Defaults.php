<?php
declare(strict_types=1);

/**
 * Alle Startwerte des Spiels an einer Stelle. Das Admin-Dashboard
 * bearbeitet exakt diese Datenmodelle, das Spiel liest sie unverändert.
 */
final class Defaults
{
    public const SPRITE_BASE = 'assets/sprites/';

    /** Inhaltsversion. Erhöhen, wenn neue Standardinhalte nachgetragen werden sollen. */
    public const VERSION = 3;

    /** @return array<string, mixed> */
    public static function content(): array
    {
        return [
            'version' => self::VERSION,
            'player' => self::player(),
            'balance' => self::balance(),
            'audio' => self::audio(),
            'characters' => self::characters(),
            'weapons' => self::weapons(),
            'enemies' => self::enemies(),
            'upgrades' => self::upgrades(),
            'items' => self::items(),
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

    public const AUDIO_BASE = 'assets/audio/';

    /**
     * Baustein für jeden Ton im Spiel.
     *
     * Bis zu vier Varianten je Ereignis; abgespielt wird eine zufällige.
     * "chance" steuert, wie oft der Ton überhaupt kommt (100 = immer) -
     * damit klingen Dauergeräusche wie Schüsse nicht wie ein Presslufthammer.
     *
     * @param list<string> $files
     * @return array<string, mixed>
     */
    public static function soundSet(array $files = [], float $volume = 0.8, int $chance = 100): array
    {
        $variants = [];
        foreach (array_slice($files, 0, 4) as $file) {
            $variants[] = ['src' => self::AUDIO_BASE . $file, 'volume' => 1.0];
        }
        return [
            'enabled' => true,
            'chance' => $chance,
            'volume' => $volume,
            'variants' => $variants,
        ];
    }

    /**
     * Alle Ton-Ereignisse des Spiels. Ohne Datei bleibt ein Ereignis still.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function soundSlots(): array
    {
        // label, Dateien (bis zu vier), Lautstärke, Häufigkeit in Prozent
        $slots = [
            'shoot' => ['Schuss', ['laser.mp3'], 0.55, 100],
            'melee' => ['Nahkampfschlag', ['swoosh.mp3', 'short-swoosh.mp3', 'player-attack.mp3', 'player-heavy-attack.mp3'], 0.6, 100],
            'hit' => ['Treffer am Gegner', ['hit-knife.mp3', 'hit-sword.mp3'], 0.35, 45],
            'crit' => ['Kritischer Treffer', ['hit-sword.mp3'], 0.6, 100],
            'enemyDeath' => ['Gegner stirbt', ['death-enemy.mp3', 'death-enemy2.mp3'], 0.5, 70],
            'explosion' => ['Explosion', ['player-heavy-attack.mp3'], 0.7, 100],
            'run' => ['Laufschritte', ['walk.mp3'], 0.35, 100],
            'playerHit' => ['Spieler wird getroffen', ['player-damage.mp3'], 0.8, 100],
            'coin' => ['Geld eingesammelt', [], 0.6, 100],
            'potion' => ['Heilflasche eingesammelt', ['selected-upgrade.mp3'], 0.55, 100],
            'chest' => ['Truhe geöffnet', ['level-clear.mp3'], 0.6, 100],
            'portal' => ['Portalsprung', ['short-swoosh.mp3', 'swoosh.mp3'], 0.7, 100],
            'upgrade' => ['Upgrade gewählt', ['selected-upgrade.mp3'], 0.8, 100],
            'waveClear' => ['Welle geschafft', ['level-clear.mp3'], 0.7, 100],
            'bossSpawn' => ['Boss erscheint', ['boss.mp3'], 0.8, 100],
            'bossWarning' => ['Bombenwarnung', ['short-swoosh.mp3'], 0.5, 100],
            'gameStart' => ['Run startet', ['start-game.mp3'], 0.7, 100],
            'gameOver' => ['Run beendet', ['death-player.mp3', 'game-over.mp3'], 0.8, 100],
            'uiClick' => ['Menüklick', [], 0.5, 100],
        ];

        $out = [];
        foreach ($slots as $id => [$label, $files, $volume, $chance]) {
            $out[$id] = self::soundSet($files, $volume, $chance) + ['label' => $label];
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
            // 80 waren fuer Mobilgeraete zu viel - jeder Gegner kostet
            // Zeichenzeit. 60 fuellt den Bildschirm bereits gut.
            'maxEnemies' => 60,
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
            // Verbrennung: wie lange ein getroffener Gegner brennt.
            'burnDuration' => 3.0,
            'upgradeChoices' => 3,
            'rarityRareBase' => 26,
            'rarityEpicBase' => 9,
            'rarityLegendaryBase' => 2,
            'rarityCycleBonus' => 1.35,
            'weaponOfferChance' => 0.28,
        ];
    }

    /**
     * Spezialfähigkeiten der Charaktere.
     *
     * Jede Fähigkeit ist im Spiel echt umgesetzt - sie steht nicht nur im
     * Text. Die Staerke der meisten liegt in den Balancing-Werten, damit sie
     * sich im Admin nachjustieren lassen.
     *
     * @return array<string, array{label: string, description: string}>
     */
    public static function perks(): array
    {
        return [
            '' => ['label' => 'Keine', 'description' => 'Nur die Werte des Charakters zählen.'],
            'lifesteal' => ['label' => 'Lebensraub',
                'description' => 'Jeder getötete Gegner heilt dich ein wenig, Bosse deutlich mehr.'],
            'thorns' => ['label' => 'Dornen',
                'description' => 'Wer dich berührt, nimmt einen Teil des Schadens selbst.'],
            'luckyCards' => ['label' => 'Glückskarten',
                'description' => 'Deine Upgrade-Karten sind spürbar öfter selten oder besser.'],
            'berserk' => ['label' => 'Blutrausch',
                'description' => 'Je weniger Leben du hast, desto härter triffst du - bis zu +60 %.'],
            'secondWind' => ['label' => 'Zweiter Atem',
                'description' => 'Einmal je Welle überlebst du einen tödlichen Treffer mit einem Rest Leben.'],
            'pyro' => ['label' => 'Brandstifter',
                'description' => 'Deine Treffer zünden Gegner an, auch ohne Verbrennungs-Upgrade.'],
            'frost' => ['label' => 'Frost',
                'description' => 'Getroffene Gegner werden für kurze Zeit deutlich langsamer.'],
            'blast' => ['label' => 'Sprengmeister',
                'description' => 'Getötete Gegner explodieren und reißen Umstehende mit.'],
            'magnet' => ['label' => 'Magnet',
                'description' => 'Große Aufsammelreichweite, und Heilflaschen erscheinen doppelt so oft.'],
            'greed' => ['label' => 'Goldrausch',
                'description' => 'Deutlich mehr Geld für jeden Gegner.'],
            'guardian' => ['label' => 'Wächter',
                'description' => 'Nach jeder Welle füllt sich dein Schild wieder komplett auf.'],
            'sniper' => ['label' => 'Scharfschütze',
                'description' => 'Je weiter der Gegner weg ist, desto mehr Schaden richtest du an.'],
        ];
    }

    /** Standardwerte für alle Charakter-Abweichungen. @return array<string, float> */
    public static function characterMods(): array
    {
        return [
            // Faktoren: 1.0 = unverändert
            'maxHealth' => 1.0, 'moveSpeed' => 1.0, 'damageMult' => 1.0, 'attackSpeed' => 1.0,
            'range' => 1.0, 'projectileSpeed' => 1.0, 'knockback' => 1.0, 'pickupRange' => 1.0,
            'potionRate' => 1.0, 'money' => 1.0,
            // Zuschläge: 0 = unverändert
            'armor' => 0.0, 'critChance' => 0.0, 'critDamage' => 0.0,
            'dodge' => 0.0, 'regen' => 0.0, 'shield' => 0.0, 'burn' => 0.0,
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

        // id, Name, Tönung, Titel, Text, Fähigkeit, Werte, von Anfang an frei
        $rows = [
            ['nova', 'Nova', 0, 'Späherin', 'Schnell unterwegs und flink im Abzug.', '',
             ['moveSpeed' => 1.12, 'critChance' => 6, 'pickupRange' => 1.15], true],
            ['bruno', 'Bruno', 200, 'Bollwerk', 'Viel Leben und Rüstung, dafür etwas langsamer.', '',
             ['maxHealth' => 1.30, 'armor' => 3, 'moveSpeed' => 0.94, 'knockback' => 1.25], true],
            ['kira', 'Kira', 310, 'Duellantin', 'Mehr Schaden und schnellere Angriffe.', '',
             ['damageMult' => 1.12, 'attackSpeed' => 1.10, 'critDamage' => 20], true],

            ['ruun', 'Ruun', 140, 'Magier', 'Weite Reichweite und deutlich bessere Upgrade-Karten.', 'luckyCards',
             ['range' => 1.18, 'projectileSpeed' => 1.20, 'maxHealth' => 0.92], false],
            ['vera', 'Vera', 340, 'Vampirin', 'Jeder Kill heilt dich, dafür weniger Grundleben.', 'lifesteal',
             ['maxHealth' => 0.88, 'attackSpeed' => 1.05], false],
            ['tor', 'Tor', 55, 'Wächter', 'Startet mit Schild, das sich nach jeder Welle wieder füllt.', 'guardian',
             ['maxHealth' => 1.20, 'shield' => 30, 'moveSpeed' => 0.96], false],
            ['ember', 'Ember', 20, 'Brandstifterin', 'Ihre Treffer setzen Gegner ganz von allein in Brand.', 'pyro',
             ['burn' => 4, 'damageMult' => 0.94, 'moveSpeed' => 1.05], false],
            ['sela', 'Sela', 175, 'Frostläuferin', 'Getroffene Gegner werden träge und kommen kaum an sie heran.', 'frost',
             ['moveSpeed' => 1.08, 'range' => 1.10, 'maxHealth' => 0.95], false],
            ['grom', 'Grom', 15, 'Berserker', 'Je knapper sein Leben, desto härter schlägt er zu.', 'berserk',
             ['maxHealth' => 1.10, 'armor' => 2, 'attackSpeed' => 0.95], false],
            ['zeno', 'Zeno', 260, 'Sprengmeister', 'Jeder getötete Gegner reißt seine Nachbarn mit.', 'blast',
             ['damageMult' => 1.05, 'maxHealth' => 0.92, 'range' => 1.05], false],
            ['mira', 'Mira', 90, 'Sammlerin', 'Zieht alles an sich und findet doppelt so viele Heilflaschen.', 'magnet',
             ['pickupRange' => 2.0, 'potionRate' => 1.5, 'moveSpeed' => 1.04], false],
            ['dax', 'Dax', 40, 'Glücksritter', 'Verdient deutlich mehr Geld an jedem Gegner.', 'greed',
             ['money' => 1.6, 'maxHealth' => 0.95, 'dodge' => 5], false],
            ['iva', 'Iva', 210, 'Scharfschützin', 'Trifft auf Distanz härter als jeder andere.', 'sniper',
             ['range' => 1.30, 'projectileSpeed' => 1.25, 'maxHealth' => 0.86, 'critChance' => 4], false],
            ['ossa', 'Ossa', 120, 'Zähe Haut', 'Überlebt einmal je Welle einen tödlichen Treffer.', 'secondWind',
             ['maxHealth' => 1.15, 'armor' => 2, 'regen' => 0.5], false],
            ['nix', 'Nix', 290, 'Klingentänzerin', 'Wer sie berührt, blutet selbst - und sie weicht oft aus.', 'thorns',
             ['dodge' => 12, 'moveSpeed' => 1.06, 'maxHealth' => 0.94], false],
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
                'mods' => array_merge(self::characterMods(), $mods),
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
                // Eigener Ton je Waffe; leer heißt: allgemeiner Schuss- bzw. Schlagton.
                'sound' => self::weaponSound($id, $melee),
                'description' => $desc,
                'active' => true,
                'starter' => in_array($id, ['pistole', 'bogen', 'schwert', 'zauberstab'], true),
                'order' => $i,
            ];
        }
        return $out;
    }

    /** Passender Ton je Waffe aus den mitgelieferten Dateien. */
    public static function weaponSound(string $id, bool $melee): array
    {
        return match ($id) {
            'bogen', 'armbrust' => self::soundSet(['arrow.mp3'], 0.6),
            'sturmgewehr' => self::soundSet(['laser.mp3'], 0.35),
            'pistole' => self::soundSet(['laser.mp3'], 0.55),
            'granate' => self::soundSet(['short-swoosh.mp3'], 0.6),
            'schwert', 'axt' => self::soundSet(['swoosh.mp3', 'player-heavy-attack.mp3'], 0.6),
            'dolch' => self::soundSet(['short-swoosh.mp3', 'player-attack.mp3'], 0.5),
            'speer' => self::soundSet(['player-attack.mp3', 'swoosh.mp3'], 0.55),
            default => $melee ? self::soundSet(['swoosh.mp3'], 0.6) : self::soundSet(['laser.mp3'], 0.5),
        };
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
                'soundHit' => self::soundSet(['hit-knife.mp3'], 0.35, 40),
                'soundDeath' => self::soundSet(['death-enemy.mp3'], 0.35, 100),
            ],
            [
                'id' => 'enemy2', 'name' => 'Schleicher', 'sprite' => $s . 'enemy2.gif',
                'health' => 72, 'damage' => 12, 'speed' => 94, 'reward' => 6,
                'spawnWeight' => 100, 'boss' => false, 'contactCooldown' => 0.8,
                'scale' => 74, 'hitbox' => ['shape' => 'circle', 'r' => 21, 'ox' => 0, 'oy' => 4],
                'wave' => 2,
                'soundHit' => self::soundSet(['hit-knife.mp3'], 0.35, 40),
                'soundDeath' => self::soundSet(['death-enemy2.mp3'], 0.35, 100),
            ],
            [
                'id' => 'enemy3', 'name' => 'Brecher', 'sprite' => $s . 'enemy3.gif',
                'health' => 135, 'damage' => 18, 'speed' => 72, 'reward' => 11,
                'spawnWeight' => 100, 'boss' => false, 'contactCooldown' => 0.9,
                'scale' => 96, 'hitbox' => ['shape' => 'circle', 'r' => 25, 'ox' => 0, 'oy' => 6],
                'wave' => 3,
                'soundHit' => self::soundSet(['hit-sword.mp3'], 0.4, 40),
                'soundDeath' => self::soundSet(['death-enemy.mp3', 'death-enemy2.mp3'], 0.4, 100),
            ],
            [
                'id' => 'boss1', 'name' => 'Bombenwerfer', 'sprite' => $s . 'boss1.gif',
                'health' => 1700, 'damage' => 26, 'speed' => 60, 'reward' => 120,
                'spawnWeight' => 0, 'boss' => true, 'contactCooldown' => 1.0,
                'scale' => 190, 'hitbox' => ['shape' => 'circle', 'r' => 46, 'ox' => 0, 'oy' => 8],
                'wave' => 4,
                'soundHit' => self::soundSet(['hit-sword.mp3'], 0.5, 40),
                'soundDeath' => self::soundSet(['boss.mp3'], 0.5, 100),
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
            ['impact', 'Wucht', 'knockback', 'percent', 25, 'rare', 70, 8, 'Gegner werden weiter zurückgestoßen.'],
            ['leather_hide', 'Lederhaut', 'armor', 'flat', 3, 'rare', 100, 10, 'Reduziert jeden erlittenen Treffer.'],
            ['regrowth', 'Regeneration', 'regen', 'flat', 0.9, 'rare', 90, 8, 'Heilt dich langsam über Zeit.'],
            ['evasion', 'Ausweichen', 'dodge', 'flat', 4, 'rare', 80, 8, 'Chance, einem Treffer komplett auszuweichen.'],
            ['burning', 'Verbrennung', 'burn', 'flat', 3, 'rare', 95, 10, 'Getroffene Gegner fangen Feuer und verlieren weiter Leben. Jede Stufe brennt heißer.'],
            ['alchemy', 'Alchemie', 'potionRate', 'percent', 40, 'rare', 70, 6, 'Heilflaschen erscheinen deutlich häufiger auf der Karte.'],

            ['berserk', 'Berserker', 'damage', 'percent', 18, 'epic', 100, 8, 'Deutlich mehr Waffenschaden.'],
            ['time_pressure', 'Zeitdruck', 'attackSpeed', 'percent', 16, 'epic', 100, 8, 'Deutlich schnellere Angriffe.'],
            ['crit_fury', 'Kritische Wut', 'critDamage', 'percent', 35, 'epic', 90, 8, 'Kritische Treffer richten mehr an.'],
            ['barrier', 'Barriere', 'shield', 'flat', 30, 'epic', 90, 6, 'Schild, der Schaden vor der Lebensleiste schluckt.'],
            ['vitality', 'Vitalität', 'maxHealth', 'percent', 15, 'epic', 90, 6, 'Prozentual mehr Leben.'],
            ['inferno', 'Inferno', 'burn', 'flat', 9, 'epic', 85, 6, 'Deine Flammen fressen sich deutlich tiefer.'],

            ['bloodlust', 'Blutrausch', 'damage', 'percent', 30, 'legendary', 100, 4, 'Massiver Schadensschub.'],
            ['twin_heart', 'Zwillingsherz', 'maxHealth', 'percent', 40, 'legendary', 90, 3, 'Enorm viel zusätzliches Leben.'],
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


    /**
     * Gegenstände, die auf der Karte erscheinen.
     *
     * Trank und Truhe laufen über dasselbe Modell: Wie oft gewürfelt wird,
     * mit welcher Chance, wie viele gleichzeitig liegen dürfen und was beim
     * Einsammeln passiert - alles im Dashboard einstellbar.
     *
     * effect: heal (Prozent des Maximallebens), money (zufällig zwischen
     * value und value2), shield (Schildpunkte), speed (Temposchub in
     * Prozent für value2 Sekunden), magnet (zieht alle Gegenstände an).
     *
     * mode: pickup verschwindet sofort, chest bleibt offen liegen und löst
     * sich nach openTime Sekunden auf.
     *
     * @return list<array<string, mixed>>
     */
    public static function items(): array
    {
        $s = self::SPRITE_BASE;
        return [
            [
                'id' => 'heiltrank',
                'name' => 'Heiltrank',
                'description' => 'Heilt einen Teil deines Lebens.',
                'sprite' => $s . 'heiltrank.png',
                'openSprite' => '',
                'scale' => 34,
                'mode' => 'pickup',
                'openTime' => 0.0,
                'effect' => 'heal',
                'value' => 10,
                'value2' => 0,
                'onlyWhenNeeded' => true,
                'interval' => 14.0,
                'chance' => 0.35,
                'maxOnMap' => 3,
                'lifetime' => 26.0,
                'minDistance' => 200,
                'maxDistance' => 620,
                'particle' => '#ff6b6b',
                'sound' => 'potion',
                'active' => true,
            ],
            [
                'id' => 'truhe',
                'name' => 'Schatztruhe',
                'description' => 'Voller Münzen. Öffnet sich beim Berühren.',
                'sprite' => $s . 'truhe-zu.png',
                'openSprite' => $s . 'truhe-offen.png',
                'scale' => 46,
                'mode' => 'chest',
                'openTime' => 2.0,
                'effect' => 'money',
                'value' => 25,
                'value2' => 70,
                'onlyWhenNeeded' => false,
                'interval' => 26.0,
                'chance' => 0.30,
                'maxOnMap' => 2,
                'lifetime' => 40.0,
                'minDistance' => 260,
                'maxDistance' => 700,
                'particle' => '#ffd166',
                'sound' => 'chest',
                'active' => true,
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function maps(): array
    {
        $mask = @file_get_contents(__DIR__ . '/seed-collision.txt');
        return [[
            'id' => 'arena',
            'name' => 'Steinbruch-Arena',
            'image' => 'assets/uploads/map-arena.jpg',
            'width' => 2048,
            'height' => 2048,
            'active' => true,
            'spawn' => ['x' => 1024, 'y' => 1024],
            'enemySpawnAreas' => [],
            // Portale werden im Karten-Editor gesetzt. Rot und Blau bilden
            // paarweise Verbindungen: das erste Rote fuehrt zum ersten Blauen.
            'portals' => [],
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
            $old = is_array($saved[$id] ?? null) ? $saved[$id] : [];
            // Frühere Fassung hatte nur eine Datei je Ereignis.
            if (isset($old['src']) && !isset($old['variants'])) {
                $old['variants'] = $old['src'] !== '' ? [['src' => $old['src'], 'volume' => 1.0]] : [];
                unset($old['src']);
            }
            $slots[$id] = array_merge($slot, $old);
            $slots[$id]['label'] = $slot['label'];
        }
        $data['audio']['sounds'] = $slots;
        if (is_array($data['enemies'] ?? null)) {
            foreach ($data['enemies'] as $i => $enemy) {
                if (!isset($enemy['soundHit'])) {
                    $data['enemies'][$i]['soundHit'] = self::soundSet([], 0.35, 40);
                }
                if (!isset($enemy['soundDeath'])) {
                    $data['enemies'][$i]['soundDeath'] = self::soundSet([], 0.5, 100);
                }
            }
        }
        if (!isset($data['characters']) || !is_array($data['characters']) || !count($data['characters'])) {
            $data['characters'] = self::characters();
        } else {
            // Fehlende Werte ergänzen, damit ältere Charaktere alle neuen
            // Felder kennen - sonst greift im Spiel der Standard 1.0/0.
            foreach ($data['characters'] as $i => $char) {
                $mods = is_array($char['mods'] ?? null) ? $char['mods'] : [];
                $data['characters'][$i]['mods'] = array_merge(self::characterMods(), $mods);
            }
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
                if (!isset($weapon['sound'])) {
                    $melee = in_array($weapon['type'] ?? '', ['MELEE_ARC', 'MELEE_360', 'THRUST'], true);
                    $data['weapons'][$i]['sound'] = self::weaponSound((string) ($weapon['id'] ?? ''), $melee);
                }
            }
        }
        foreach (['weapons', 'enemies', 'upgrades', 'items', 'maps'] as $key) {
            if (!isset($data[$key]) || !is_array($data[$key]) || !count($data[$key])) {
                $data[$key] = self::$key();

                // Alte Heilflaschen-Werte aus dem Balancing übernehmen,
                // damit eigene Einstellungen nicht verloren gehen.
                if ($key === 'items' && isset($data['balance']['potionInterval'])) {
                    $b = $data['balance'];
                    foreach ($data['items'] as $i => $item) {
                        if ($item['id'] !== 'heiltrank') {
                            continue;
                        }
                        $data['items'][$i]['interval'] = (float) ($b['potionInterval'] ?? 14);
                        $data['items'][$i]['chance'] = (float) ($b['potionChance'] ?? 0.35);
                        $data['items'][$i]['maxOnMap'] = (int) ($b['potionMax'] ?? 3);
                        $data['items'][$i]['value'] = (float) ($b['potionHeal'] ?? 10);
                        $data['items'][$i]['lifetime'] = (float) ($b['potionLifetime'] ?? 26);
                    }
                }
            }
        }
        foreach ($data['maps'] as $i => $map) {
            if (!isset($map['portals']) || !is_array($map['portals'])) {
                $data['maps'][$i]['portals'] = [];
            }
        }

        // Einmalig: Upgrades nachtragen, die es beim letzten Speichern noch
        // nicht gab (Verbrennung, Inferno, Alchemie). Über die Version, damit
        // später gelöschte Upgrades nicht bei jedem Start zurückkommen.
        $version = (int) ($data['version'] ?? 1);
        if ($version < self::VERSION) {
            $known = [];
            foreach ($data['upgrades'] as $up) {
                if (isset($up['id'])) {
                    $known[(string) $up['id']] = true;
                }
            }
            foreach (self::upgrades() as $up) {
                if (!isset($known[$up['id']])) {
                    $data['upgrades'][] = $up;
                }
            }

            // Dasselbe für die neuen Charaktere mit Spezialfähigkeiten.
            $haveChars = [];
            foreach ($data['characters'] as $char) {
                if (isset($char['id'])) {
                    $haveChars[(string) $char['id']] = true;
                }
            }
            foreach (self::characters() as $char) {
                if (!isset($haveChars[$char['id']])) {
                    $data['characters'][] = $char;
                }
            }
        }
        $data['version'] = self::VERSION;
        return $data;
    }
}
