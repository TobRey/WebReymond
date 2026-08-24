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
    public const VERSION = 5;

    /** @return array<string, mixed> */
    public static function content(): array
    {
        return [
            'version' => self::VERSION,
            'player' => self::player(),
            'balance' => self::balance(),
            'shop' => self::shop(),
            'ui' => self::ui(),
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
            // Eigener Titel fuer Menue und alle Bildschirme ausserhalb des Kampfes.
            'musicMenu' => 'assets/audio/music-menu.mp3',
            // Die Musik liegt bewusst leise unter dem Spiel - die
            // Treffer- und Schussgeraeusche sollen darueber stehen.
            'musicVolume' => 0.3,
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
            // Wellen bauen aufeinander auf: Der Gegner der aktuellen Welle
            // fuehrt, die bereits bekannten kommen mit diesem Anteil dazu.
            'waveMixShare' => 0.45,
            // So viele Gegner stehen zum Wellenstart schon bereit, damit es
            // nach der Upgrade-Auswahl sofort weitergeht.
            'waveStartEnemies' => 4,
            // Ultimate: Druckwelle auf Knopfdruck.
            'ultCooldown' => 30.0,
            'ultRadius' => 300.0,
            'ultKnockback' => 1400.0,
            'ultDamage' => 0.0,
            'upgradeChoices' => 3,
            'rarityRareBase' => 26,
            'rarityEpicBase' => 9,
            'rarityLegendaryBase' => 2,
            'rarityCycleBonus' => 1.35,
        ];
    }

    /**
     * Der Laden zwischen den Wellen.
     *
     * Bilder, Anordnung und Preise sind vollstaendig einstellbar - das
     * Spiel liest nur diese Werte. "offerCount" ist die Zahl der Auslagen,
     * die Preise entstehen aus Grundpreis x Seltenheitsfaktor x Zyklus.
     *
     * @return array<string, mixed>
     */
    public static function shop(): array
    {
        $s = self::SPRITE_BASE;
        return [
            'enabled' => true,
            'title' => "Porky's Shop",
            'background' => $s . 'shop-hintergrund.jpg',
            'counter' => $s . 'shop-tresen.png',
            // Bis zu fuenf Einzelbilder ergeben das Ruhebild des Haendlers.
            'merchantFrames' => [$s . 'haendler-schwein.png'],
            'merchantFrameDuration' => 220,
            // Anordnung in Prozent der Buehne - im Admin verschiebbar.
            'merchantX' => 75.0,
            'merchantY' => 36.0,
            'merchantScale' => 115.0,
            'counterY' => 100.0,
            'counterScale' => 100.0,
            'offerCount' => 4,
            // Preis = priceBase x Faktor der Seltenheit x (1 + Zyklus-Zuschlag).
            'priceBase' => 26,
            'priceCommon' => 1.0,
            'priceRare' => 1.9,
            'priceEpic' => 3.2,
            'priceLegendary' => 5.4,
            'priceWeapon' => 4.0,
            'priceCycleBonus' => 0.35,
            // Jede weitere Stufe desselben Upgrades kostet mehr.
            'priceStackBonus' => 0.45,
            'rerollCost' => 18,
            'rerollGrowth' => 1.6,
            'weaponChance' => 0.3,
            'lockLimit' => 3,
        ];
    }

    /**
     * Aussehen der Menuebildschirme.
     *
     * @return array<string, mixed>
     */
    public static function ui(): array
    {
        $s = self::SPRITE_BASE;
        return [
            'menuBackground' => $s . 'menu-hintergrund.jpg',
            'charBackground' => $s . 'charakter-hintergrund.jpg',
            // Standort der Figur auf der Charakterauswahl, in Prozent.
            'charX' => 50.0,
            'charY' => 72.0,
            'charScale' => 100.0,
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
        // scale ist die Groesse nur dieser Richtung (1 = wie eingestellt),
        // flip spiegelt die ganze Richtung, flips einzelne Bilder.
        $richtung = static fn(string $datei): array => [
            'gif' => $datei, 'frames' => [], 'flips' => [], 'flip' => false, 'scale' => 1.0,
        ];
        $base = [
            'front' => $richtung($s . 'playerfront.gif'),
            'back' => $richtung($s . 'playerback.gif'),
            'side' => $richtung($s . 'playerside.gif'),
            // Ruhebild: leer heisst "nimm das Bild von vorne". Sobald hier
            // Einzelbilder hinterlegt sind, laeuft im Stand diese Animation.
            'idle' => $richtung(''),
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
        //
        // Balancing: Grundlage ist der wirksame Schaden pro Sekunde, also
        // Schaden / Nachladezeit x erwartete Treffer je Angriff x Trefferquote.
        // Alle Waffen landen so bei rund 65-77 - Nahkampf etwas hoeher, weil
        // man dafuer nah heran muss und Beruehrungsschaden riskiert:
        //   Pistole      69 x 1.00 Ziele x 0.90 =  62
        //   Sturmgewehr  82 x 1.00        x 0.80 =  65
        //   Bogen        64 x 1.35        x 0.80 =  69
        //   Armbrust     59 x 1.60        x 0.75 =  70
        //   Zauberstab   69 x 1.00        x 1.00 =  69   (Zielsuche trifft immer)
        //   Speer        68 x 1.30        x 0.85 =  75
        //   Dolch        89 x 1.00        x 0.85 =  76
        //   Schwert      52 x 1.80        x 0.80 =  75
        //   Axt          37 x 2.60        x 0.80 =  77
        //   Granate      32 x 2.80        x 0.85 =  77
        $w = [
            ['pistole', 'Pistole', 'pistole.png', 'PROJECTILE', 'schuss',
             25, 0.36, 430, 640, 90, 8, 60, 0, 'Schneller Einzelschuss mit solidem Schaden.', 46, 16],
            ['sturmgewehr', 'Sturmgewehr', 'sturmgewehr.png', 'PROJECTILE', 'schuss',
             9, 0.11, 390, 760, 35, 6, 55, 0, 'Sehr hohe Feuerrate, dafür wenig Schaden pro Schuss.', 54, 14],
            ['bogen', 'Bogen', 'bogen.png', 'PROJECTILE', 'pfeil',
             46, 0.72, 540, 800, 110, 12, 70, 0, 'Langsamer Schuss, hoher Einzelschaden, durchbohrt 1 Gegner.', 50, 20],
            ['armbrust', 'Armbrust', 'armbrust.png', 'PROJECTILE', 'pfeil',
             82, 1.40, 640, 980, 150, 14, 80, 0, 'Lange Nachladezeit, dafür massiver Schaden, durchbohrt 2 Gegner.', 56, 26],
            ['zauberstab', 'Zauberstab', 'zauberstab.png', 'MAGIC', 'magic',
             38, 0.55, 500, 430, 60, 10, 65, 0, 'Magisches Geschoss mit leichter Zielsuche - trifft fast immer.', 50, 18],
            ['speer', 'Speer', 'speer.png', 'THRUST', '',
             42, 0.62, 165, 0, 130, 10, 65, 0, 'Stoß nach vorne mit schmalem Trefferbereich und hohem Schaden.', 120, 16],
            ['dolch', 'Dolch', 'dolch.png', 'MELEE_ARC', '',
             17, 0.19, 100, 0, 45, 15, 70, 0, 'Blitzschnelle Stiche auf kurze Distanz.', 58, 16],
            ['schwert', 'Schwert', 'schwert.png', 'MELEE_ARC', '',
             25, 0.48, 140, 0, 110, 8, 60, 0, 'Weiter Schwung vor dem Spieler, trifft mehrere Gegner.', 95, 16],
            ['axt', 'Axt', 'axt.png', 'MELEE_360', '',
             28, 0.76, 155, 0, 140, 8, 60, 0, 'Volle 360-Grad-Drehung, trifft alles rundherum.', 88, 16],
            ['granate', 'Granate', 'granate.png', 'GRENADE', 'granate',
             50, 1.55, 400, 420, 190, 8, 60, 130, 'Wurfgeschoss mit verzögerter Explosion und großem Radius.', 46, 34],
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
                // Start des Projektils: Hoehe wie die Waffe, Abstand 0 heisst
                // "aus der Waffengroesse ableiten".
                'muzzleOffsetY' => $melee ? -10 : -6,
                'muzzleDistance' => 0,
                // Wo der Schlag oder Stich ansetzt. Ohne diese Werte sass
                // der Angriff immer auf Fusshoehe, obwohl die Waffe mittig
                // in der Hand lag - der Speer rutschte beim Stich nach unten.
                'attackOffsetY' => $melee ? -10 : -6,
                'attackOffsetX' => 0,
                // Farbe der Schwung- bzw. Stichspur.
                'trailColor' => $id === 'zauberstab' ? '#c9a8ff' : '#ffe6ae',
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
    /**
     * Alle Upgrade-Karten.
     *
     * Eine Karte kann mehrere Werte gleichzeitig verschieben (mods) und
     * zusaetzlich einen Sondereffekt mitbringen (effect), der im Spiel
     * eigene Logik hat. Reine Wertekarten kommen ohne effect aus.
     *
     * Zeilenformat:
     *   id, Name, Seltenheit, Gewicht, maxStack, Effekt, Beschreibung,
     *   [[stat, percent|flat, Wert], ...]
     *
     * @return list<array<string, mixed>>
     */
    public static function upgrades(): array
    {
        $rows = [
            // --- Grundwerte -------------------------------------------------
            ['dicke_haut', 'Dicke Haut', 'common', 100, 12, '', 'Mehr maximale Lebenspunkte.',
                [['maxHealth', 'flat', 20]]],
            ['schnelle_finger', 'Schnelle Finger', 'common', 100, 12, '', 'Du greifst spürbar öfter an.',
                [['attackSpeed', 'percent', 8]]],
            ['schwere_haende', 'Schwere Hände', 'common', 100, 12, '', 'Jeder Treffer sitzt härter.',
                [['damage', 'percent', 9]]],
            ['laufschuhe', 'Laufschuhe', 'common', 100, 10, '', 'Du bist schneller unterwegs.',
                [['moveSpeed', 'percent', 8]]],
            ['adlerauge', 'Adlerauge', 'common', 95, 10, '', 'Höhere Chance auf kritische Treffer.',
                [['critChance', 'flat', 5]]],
            ['magnet', 'Magnet', 'common', 85, 8, '', 'Gegenstände fliegen dir aus grösserer Entfernung zu.',
                [['pickupRange', 'percent', 30]]],
            ['panzerplatte', 'Panzerplatte', 'common', 95, 10, '', 'Zieht von jedem erlittenen Treffer ab.',
                [['armor', 'flat', 3]]],
            ['stahlkugeln', 'Stahlkugeln', 'common', 90, 10, '', 'Geschosse richten mehr Schaden an.',
                [['projectileDamage', 'percent', 12]]],
            ['lange_arme', 'Lange Arme', 'common', 85, 8, '', 'Grössere Reichweite im Nahkampf.',
                [['meleeRange', 'percent', 14]]],
            ['ersatzmagazin', 'Ersatzmagazin', 'common', 85, 8, '', 'Fernkampfwaffen feuern schneller.',
                [['rangedAttackSpeed', 'percent', 10]]],
            ['ruhige_hand', 'Ruhige Hand', 'common', 80, 8, '', 'Projektile fliegen schneller.',
                [['projectileSpeed', 'percent', 14]]],
            ['sparschwein', 'Sparschwein', 'common', 80, 8, '', 'Mehr Geld für alles, was du einsammelst.',
                [['money', 'percent', 15]]],
            ['gluecksklee', 'Glücksklee', 'rare', 80, 8, '', 'Bessere Karten und mehr Fundstücke.',
                [['luck', 'flat', 10]]],

            // --- Zwei Werte auf einer Karte ---------------------------------
            ['proteinshake', 'Proteinshake', 'rare', 90, 8, '', 'Mehr Leben und mehr Schaden.',
                [['maxHealth', 'flat', 18], ['damage', 'percent', 7]]],
            ['turnschuhe', 'Turnschuhe', 'rare', 85, 8, '', 'Schneller unterwegs und flinker im Ausweichen.',
                [['moveSpeed', 'percent', 7], ['dodge', 'flat', 4]]],
            ['ueberladung', 'Überladung', 'rare', 75, 6, '', 'Deutlich schnellere Angriffe - auf Kosten deiner Gesundheit.',
                [['attackSpeed', 'percent', 18], ['maxHealth', 'flat', -12]]],
            ['glaskanone', 'Glaskanone', 'epic', 80, 6, '', 'Viel mehr Schaden, dafür keine Rüstung.',
                [['damage', 'percent', 24], ['armor', 'flat', -3]]],
            ['titanruestung', 'Titanrüstung', 'rare', 75, 6, '', 'Schwer gepanzert, dafür langsamer.',
                [['armor', 'flat', 6], ['moveSpeed', 'percent', -7]]],
            ['kritische_masse', 'Kritische Masse', 'epic', 90, 8, '', 'Kritische Treffer richten deutlich mehr an.',
                [['critDamage', 'percent', 35]]],
            ['regenerator', 'Regenerator', 'rare', 85, 8, '', 'Du heilst dich langsam von selbst.',
                [['regen', 'flat', 0.9]]],

            // --- Sondereffekte ----------------------------------------------
            ['blutsauger', 'Blutsauger', 'rare', 85, 8, 'lifesteal',
                'Ein Teil deines Schadens kommt als Leben zurück.', [['lifesteal', 'flat', 2]]],
            ['vampirzaehne', 'Vampirzähne', 'epic', 80, 6, 'critHeal',
                'Kritische Treffer heilen dich zusätzlich.', []],
            ['dornenhaut', 'Dornenhaut', 'rare', 80, 8, 'thorns',
                'Wer dich berührt, nimmt selbst Schaden.', [['thorns', 'flat', 6]]],
            ['kampfrausch', 'Kampfrausch', 'rare', 85, 6, 'killFrenzy',
                'Jeder Kill gibt kurz mehr Angriffstempo.', []],
            ['kampfdroge', 'Kampfdroge', 'rare', 80, 6, 'hurtFrenzy',
                'Nach einem Treffer greifst du kurz schneller an.', []],
            ['multikill', 'Multikill', 'epic', 80, 6, 'multikill',
                'Mehrere schnelle Kills geben kurz mehr Schaden.', []],
            ['perfektionist', 'Perfektionist', 'epic', 80, 6, 'untouched',
                'Solange du nicht getroffen wirst, wächst dein Schaden.', []],
            ['berserker', 'Berserker', 'epic', 85, 6, 'berserk',
                'Je weniger Leben du hast, desto härter triffst du.', []],
            ['henker', 'Henker', 'epic', 85, 6, 'execute',
                'Mehr Schaden gegen bereits verletzte Gegner.', []],
            ['doppelschuss', 'Doppelschuss', 'epic', 80, 6, 'doubleShot',
                'Chance auf ein zusätzliches Projektil.', []],
            ['geistergeschoss', 'Geistergeschoss', 'epic', 80, 5, 'ghostShot',
                'Deine Geschosse durchdringen Gegner.', []],
            ['kettenreaktion', 'Kettenreaktion', 'epic', 80, 5, 'chainExplode',
                'Getötete Gegner können explodieren.', []],
            ['turbo', 'Turbo', 'rare', 75, 6, 'collide',
                'Schneller unterwegs - und wer dich rammt, nimmt Schaden.',
                [['moveSpeed', 'percent', 9]]],
            ['notverband', 'Notverband', 'rare', 85, 6, 'waveHeal',
                'Nach jeder Welle heilst du ein Stück.', []],
            ['sammler', 'Sammler', 'rare', 80, 8, '', 'Gegner lassen mehr Geld fallen.',
                [['money', 'percent', 22]]],
            ['schatzjaeger', 'Schatzjäger', 'epic', 75, 6, 'treasure',
                'Bosse und Truhen werfen deutlich mehr ab.', []],
            ['letzte_kartoffel', 'Letzte Kartoffel', 'legendary', 80, 3, 'lastPotato',
                'Überlebt einmal je Welle einen tödlichen Treffer.', []],
            ['schwarzes_loch', 'Schwarzes Loch', 'legendary', 75, 3, 'blackhole',
                'Zieht regelmässig alle Gegner in der Nähe zu dir.', []],
            ['zeitlupe', 'Zeitlupe', 'legendary', 75, 3, 'slowmo',
                'Bei wenig Leben werden alle Gegner träge.', []],
            ['midas_hand', 'Midas-Hand', 'epic', 80, 5, 'midas',
                'Kills werfen manchmal eine Handvoll Gold ab.', []],
            ['todeswelle', 'Todeswelle', 'legendary', 75, 3, 'deathwave',
                'Fällst du auf wenig Leben, entlädt sich eine Druckwelle.', []],
            ['seelenfaenger', 'Seelenfänger', 'legendary', 70, 4, 'soulEater',
                'Jeder Boss macht dich dauerhaft stärker.', []],
            ['unendlicher_hunger', 'Unendlicher Hunger', 'legendary', 70, 4, 'hunger',
                'Je 25 Kills wächst dein Maximalleben dauerhaft.', []],
            ['klonmaschine', 'Klonmaschine', 'legendary', 70, 3, 'clone',
                'Deine Waffe feuert regelmässig einen zweiten Schuss ab.', []],
            ['blutpakt', 'Blutpakt', 'legendary', 65, 3, 'bloodPact',
                'Viel mehr Schaden - jede Welle kostet dich Leben.',
                [['damage', 'percent', 40]]],
            ['fluch_der_gier', 'Fluch der Gier', 'legendary', 65, 3, 'greedCurse',
                'Mehr Beute, aber zähere Gegner.', [['money', 'percent', 60]]],
            ['chaos_kern', 'Chaos-Kern', 'legendary', 65, 3, 'chaos',
                'Jede Welle würfelt einen Vorteil und einen Nachteil aus.', []],
            ['goldener_wuerfel', 'Goldener Würfel', 'legendary', 65, 4, 'goldenDice',
                'Jede Welle verstärkt einen zufälligen Wert dauerhaft.', []],
            ['mutation', 'Mutation', 'legendary', 65, 4, 'mutation',
                'Jede Welle mehr Schaden - aber auch stärkere Gegner.', []],
            ['kartoffelgott', 'Kartoffelgott', 'legendary', 50, 2, 'potatoGod',
                'Alles wird besser. Die Gegner allerdings auch.',
                [['damage', 'percent', 15], ['maxHealth', 'flat', 20], ['moveSpeed', 'percent', 6],
                 ['attackSpeed', 'percent', 10], ['armor', 'flat', 2], ['critChance', 'flat', 5]]],

            // --- Feuer und Fundstücke (schon vorher vorhanden) ---------------
            ['burning', 'Verbrennung', 'rare', 95, 10, '',
                'Getroffene Gegner fangen Feuer und verlieren weiter Leben. Jede Stufe brennt heißer.',
                [['burn', 'flat', 3]]],
            ['inferno', 'Inferno', 'epic', 85, 6, '',
                'Deine Flammen fressen sich deutlich tiefer.', [['burn', 'flat', 9]]],
            ['alchemy', 'Alchemie', 'rare', 70, 6, '',
                'Heilflaschen erscheinen deutlich häufiger auf der Karte.',
                [['potionRate', 'percent', 40]]],

            // --- Weitere Grundwerte ------------------------------------------
            ['eisenhaut', 'Eisenhaut', 'common', 95, 10, '', 'Noch etwas mehr Rüstung.',
                [['armor', 'flat', 2], ['maxHealth', 'flat', 8]]],
            ['scharfschliff', 'Scharfschliff', 'common', 95, 12, '', 'Die Klinge sitzt besser.',
                [['damage', 'percent', 7], ['critChance', 'flat', 2]]],
            ['federleicht', 'Federleicht', 'common', 90, 10, '', 'Leichter auf den Beinen.',
                [['moveSpeed', 'percent', 6], ['dodge', 'flat', 3]]],
            ['weitwurf', 'Weitwurf', 'common', 85, 10, '', 'Alles fliegt ein Stück weiter.',
                [['range', 'percent', 12]]],
            ['muskelkater', 'Muskelkater', 'common', 85, 10, '', 'Mehr Wucht hinter jedem Schlag.',
                [['knockback', 'percent', 30], ['damage', 'percent', 4]]],
            ['erste_hilfe', 'Erste Hilfe', 'common', 85, 8, '', 'Du erholst dich von selbst.',
                [['regen', 'flat', 1]]],
            ['lederweste', 'Lederweste', 'common', 90, 10, '', 'Etwas Schutz, etwas Leben.',
                [['armor', 'flat', 1], ['maxHealth', 'flat', 14]]],
            ['zielwasser', 'Zielwasser', 'common', 85, 10, '', 'Kritische Treffer richten mehr an.',
                [['critDamage', 'flat', 25]]],
            ['taschendieb', 'Taschendieb', 'common', 80, 8, '', 'Du findest ständig Kleingeld.',
                [['money', 'percent', 22]]],
            ['ausdauer', 'Ausdauer', 'common', 85, 10, '', 'Mehr Puffer für lange Wellen.',
                [['maxHealth', 'flat', 15], ['regen', 'flat', 0.5]]],
            ['tanzschritt', 'Tanzschritt', 'rare', 80, 8, '', 'Du weichst deutlich öfter aus.',
                [['dodge', 'flat', 8]]],
            ['bleikugeln', 'Bleikugeln', 'rare', 80, 8, '', 'Schwerere Geschosse, härtere Treffer.',
                [['projectileDamage', 'percent', 20], ['projectileSpeed', 'percent', -8]]],
            ['schnellspanner', 'Schnellspanner', 'rare', 80, 8, '', 'Fernkampfwaffen laden spürbar schneller.',
                [['rangedAttackSpeed', 'percent', 18]]],
            ['langer_arm', 'Langer Arm', 'rare', 78, 8, '', 'Nahkampf mit deutlich mehr Reichweite.',
                [['meleeRange', 'percent', 28]]],
            ['kettenhemd', 'Kettenhemd', 'rare', 78, 8, '', 'Solider Schutz, leicht gebremst.',
                [['armor', 'flat', 5], ['moveSpeed', 'percent', -4]]],
            ['blutkonserve', 'Blutkonserve', 'rare', 75, 6, '', 'Viel mehr Leben, etwas träger.',
                [['maxHealth', 'flat', 45], ['attackSpeed', 'percent', -5]]],
            ['praezision', 'Präzision', 'rare', 75, 8, '', 'Trifft öfter kritisch und härter.',
                [['critChance', 'flat', 7], ['critDamage', 'flat', 20]]],
            ['schildgenerator', 'Schildgenerator', 'rare', 75, 8, '', 'Ein Schild, der Treffer schluckt.',
                [['shield', 'flat', 25]]],
            ['zunder', 'Zunder', 'rare', 75, 8, '', 'Deine Flammen brennen heisser.',
                [['burn', 'flat', 5], ['damage', 'percent', 4]]],
            ['staubsauger', 'Staubsauger', 'rare', 70, 6, '', 'Zieht alles aus grosser Entfernung an.',
                [['pickupRange', 'percent', 70]]],
            ['doppelgold', 'Doppelgold', 'epic', 70, 6, '', 'Gegner lassen deutlich mehr Geld fallen.',
                [['money', 'percent', 55]]],
            ['henkersbeil', 'Henkersbeil', 'epic', 70, 6, '', 'Schwer, langsam, vernichtend.',
                [['damage', 'percent', 32], ['attackSpeed', 'percent', -10]]],
            ['windschritt', 'Windschritt', 'epic', 70, 6, '', 'Deutlich schneller und flinker.',
                [['moveSpeed', 'percent', 16], ['dodge', 'flat', 6]]],
            ['bollwerk_platte', 'Bollwerkplatte', 'epic', 68, 5, '', 'Fels in der Brandung.',
                [['armor', 'flat', 8], ['maxHealth', 'flat', 30], ['moveSpeed', 'percent', -6]]],
            ['giftzahn', 'Giftzahn', 'epic', 68, 6, '', 'Krit-Treffer, die wirklich wehtun.',
                [['critDamage', 'flat', 70], ['critChance', 'flat', 4]]],

            // --- Verrückte Karten mit eigener Logik ---------------------------
            ['schwarmherz', 'Schwarmherz', 'epic', 75, 5, 'swarm',
                'Für jeden lebenden Gegner 1 % mehr Schaden. Je voller die Karte, desto härter triffst du.',
                []],
            ['einzelgaenger', 'Einzelgänger', 'epic', 72, 5, 'lonewolf',
                'Je weniger Gegner stehen, desto mehr Schaden - bis zu +45 %.', []],
            ['goldklinge', 'Goldklinge', 'epic', 70, 5, 'greedyBlade',
                'Je 100 Geld im Beutel 5 % mehr Schaden. Sparen lohnt sich.', []],
            ['armenrecht', 'Armenrecht', 'rare', 75, 4, 'pauper',
                'Solange du fast pleite bist, schlägst du 30 % härter zu.', []],
            ['schwung', 'Schwung', 'rare', 80, 6, 'momentum',
                'In Bewegung machst du mehr Schaden. Stillstehen bringt hier nichts.', []],
            ['bollwerk', 'Bollwerk', 'rare', 80, 6, 'bulwark',
                'Im Stand machst du mehr Schaden. Wer bleibt, trifft härter.', []],
            ['zocker', 'Zocker', 'legendary', 60, 1, 'gambler',
                'Jeder einzelne Treffer macht halben oder doppelten Schaden. Reiner Nervenkitzel.',
                []],
            ['schneeball', 'Schneeball', 'epic', 70, 4, 'snowball',
                'Jeder Kill macht dich dauerhaft ein Stückchen stärker.', []],
            ['ueberkritisch', 'Überkritisch', 'legendary', 60, 4, 'criticalMass',
                'Alles über 100 % Krit-Chance wird in Schaden umgemünzt.', []],
            ['albtraum', 'Albtraum', 'epic', 68, 5, 'nightmare',
                'Je tiefer im Run, desto härter triffst du - 9 % je Zyklus.', []],
            ['adrenalin', 'Adrenalin', 'rare', 78, 6, 'adrenalin',
                'Je weniger Leben, desto schneller greifst du an.', []],
            ['blutrausch_karte', 'Rage', 'epic', 70, 5, 'rage',
                'Unter 30 % Leben schlägst du 45 % schneller zu.', []],
            ['ernte', 'Ernte', 'rare', 78, 6, 'harvest',
                'Je zehn Kills heilst du dich ein Stück.', []],
            ['blutgeld', 'Blutgeld', 'epic', 70, 5, 'bloodMoney',
                'Jeder Kill bringt Münzen und macht dich dauerhaft etwas stärker.', []],
            ['schutzengel', 'Schutzengel', 'epic', 68, 4, 'guardian',
                'Nach jedem Treffer bist du kurz unverwundbar.', []],
            ['vergeltung', 'Vergeltung', 'epic', 68, 5, 'retaliate',
                'Wer dich trifft, wird von einer Druckwelle weggeschleudert.', []],
            ['bankier', 'Bankier', 'legendary', 60, 4, 'banker',
                'Zu Beginn jeder Welle wird dein Gold zu dauerhaftem Schaden - ausgegeben wird nichts.',
                []],
            ['roulette', 'Roulette', 'legendary', 58, 3, 'roulette',
                'Jede Welle verstärkt einen zufälligen Wert - aber richtig.', []],
            ['frostaura', 'Frostaura', 'epic', 70, 5, 'frostAura',
                'Alles in deiner Nähe wird träge.', []],
            ['flammenaura', 'Flammenaura', 'epic', 70, 5, 'flameAura',
                'Alles in deiner Nähe fängt von selbst Feuer.', []],
            ['igelpanzer', 'Igelpanzer', 'epic', 70, 5, 'spikes',
                'Wer dir zu nahe kommt, verliert dauernd Leben.', []],
            ['zeitriss', 'Zeitriss', 'epic', 68, 4, 'timeWarp',
                'Alle zwölf Sekunden greifst du drei Sekunden lang fast doppelt so schnell an.', []],
            ['magnetfeld', 'Magnetfeld', 'rare', 72, 3, 'magnetize',
                'Alles Herumliegende fliegt dir über die ganze Karte zu.', []],
            ['durchschlag', 'Durchschlag', 'epic', 70, 4, 'pierceAll',
                'Deine Projektile durchschlagen drei zusätzliche Gegner je Stufe.', []],
            ['echo', 'Echo', 'epic', 68, 4, 'echo',
                'Jeder vierte Schuss hallt nach und feuert sofort noch einmal.', []],
            ['wuchtgeschoss', 'Wuchtgeschoss', 'rare', 75, 5, 'bigShot',
                'Deutlich grössere Projektile, die entsprechend mehr anrichten.', []],
        ];

        $out = [];
        foreach ($rows as $r) {
            [$id, $name, $rarity, $weight, $maxStack, $effect, $desc, $mods] = $r;
            $clean = [];
            foreach ($mods as [$stat, $type, $wert]) {
                $clean[] = ['stat' => $stat, 'modType' => $type, 'value' => $wert];
            }
            // Der erste Wert steht zusaetzlich einzeln - dafuer gibt es
            // aeltere Anzeigen, die nur ein Feld kennen.
            $erste = $clean[0] ?? ['stat' => 'damage', 'modType' => 'percent', 'value' => 0];
            $out[] = [
                'id' => $id,
                'name' => $name,
                'description' => $desc,
                'stat' => $erste['stat'],
                'modType' => $erste['modType'],
                'value' => $erste['value'],
                'mods' => $clean,
                'effect' => $effect,
                'rarity' => $rarity,
                'weight' => $weight,
                'maxStack' => $maxStack,
                'icon' => '',
                'active' => true,
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
            // Feine Teilchen ueber dem Bild - Farbe und Menge je Karte.
            'particleColor' => '#ffd9a0',
            'particleAmount' => 45,
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
                // Neue Sprite-Felder nachtragen.
                foreach (['front', 'back', 'side', 'idle'] as $dir) {
                    if (!isset($data['characters'][$i]['sprites'][$dir])) {
                        // Das Ruhebild kam spaeter dazu: leer heisst
                        // "nimm weiter das Bild von vorne".
                        if ($dir === 'idle') {
                            $data['characters'][$i]['sprites']['idle'] = [
                                'gif' => '', 'frames' => [], 'flips' => [],
                                'flip' => false, 'scale' => 1.0,
                            ];
                        }
                        continue;
                    }
                    $eintrag = $data['characters'][$i]['sprites'][$dir];
                    $eintrag['flips'] ??= [];
                    $eintrag['flip'] ??= false;
                    $eintrag['scale'] ??= 1.0;
                    $data['characters'][$i]['sprites'][$dir] = $eintrag;
                }
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
                // 0 heisst: aus der gezeichneten Waffe ableiten.
                if (!isset($weapon['muzzleOffsetY'])) {
                    $data['weapons'][$i]['muzzleOffsetY'] = $data['weapons'][$i]['holdOffsetY'] ?? -6;
                }
                if (!isset($weapon['muzzleDistance'])) {
                    $data['weapons'][$i]['muzzleDistance'] = 0;
                }
                if (!isset($weapon['attackOffsetY'])) {
                    $data['weapons'][$i]['attackOffsetY'] = $data['weapons'][$i]['holdOffsetY'] ?? -10;
                }
                if (!isset($weapon['attackOffsetX'])) {
                    $data['weapons'][$i]['attackOffsetX'] = 0;
                }
                if (!isset($weapon['trailColor'])) {
                    $data['weapons'][$i]['trailColor'] = '#ffe6ae';
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
            if (!isset($map['particleColor'])) {
                $data['maps'][$i]['particleColor'] = '#ffd9a0';
            }
            if (!isset($map['particleAmount'])) {
                $data['maps'][$i]['particleAmount'] = 45;
            }
        }

        // Laden und Menue-Aussehen: fehlende Felder ergaenzen, eigene behalten.
        $data['shop'] = array_merge(self::shop(), is_array($data['shop'] ?? null) ? $data['shop'] : []);
        if (!is_array($data['shop']['merchantFrames'] ?? null) || !count($data['shop']['merchantFrames'])) {
            $data['shop']['merchantFrames'] = self::shop()['merchantFrames'];
        }
        $data['ui'] = array_merge(self::ui(), is_array($data['ui'] ?? null) ? $data['ui'] : []);

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

            // Musik einmalig leiser stellen: Sie soll unter dem Spiel liegen,
            // nicht darueber. Wer den Regler selbst angefasst hat, merkt das
            // im Spiel sofort und kann ihn wieder hochziehen.
            if ($version < 5 && isset($data['audio']['musicVolume'])
                && (float) $data['audio']['musicVolume'] > 0.3) {
                $data['audio']['musicVolume'] = 0.3;
            }

            // Einmalig das Waffen-Balancing nachziehen. Betroffen sind nur
            // die mitgelieferten Waffen und nur Schaden und Nachladezeit -
            // eigene Waffen und alle anderen Felder bleiben unangetastet.
            if ($version < 4 && is_array($data['weapons'] ?? null)) {
                $neu = [];
                foreach (self::weapons() as $w) {
                    $neu[$w['id']] = $w;
                }
                foreach ($data['weapons'] as $i => $weapon) {
                    $id = (string) ($weapon['id'] ?? '');
                    if (!isset($neu[$id])) {
                        continue;
                    }
                    $data['weapons'][$i]['damage'] = $neu[$id]['damage'];
                    $data['weapons'][$i]['cooldown'] = $neu[$id]['cooldown'];
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
