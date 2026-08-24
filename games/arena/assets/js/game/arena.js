import { Assets } from '../gfx/assets.js';
import { Effects } from '../gfx/effects.js';
import { Camera } from '../core/camera.js';
import { Audio } from '../core/audio.js';
import { GameMap } from '../world/map.js';
import { playerSpawn } from '../world/spawn.js';
import { Pickups } from '../world/pickups.js';
import { Portals, PORTAL_SPRITES } from '../world/portals.js';
import { Player } from '../entities/player.js';
import { EnemyManager } from '../entities/enemy.js';
import { Projectiles } from '../combat/projectiles.js';
import { MeleeAttacks } from '../combat/melee.js';
import { WeaponController } from '../combat/weapon.js';
import { WaveController, WAVE_STATE } from './waves.js';
import { BossController } from './boss.js';
import { RunState } from './run.js';
import { Renderer } from '../gfx/renderer.js';
import { formatNumber, rand, TAU } from '../core/util.js';
import { PERK_VALUES, perkDamageMultiplier } from './perks.js';
import {
  EFFECT_VALUES, effectDamageFactor, updateEffects,
  onEffectKill, onEffectPlayerHit, onEffectWaveStart,
} from './effects.js';

/** Flammen auf brennenden Gegnern. */
const BURN_SPRITE = 'assets/sprites/feuer.gif';
/** Feuerschaden wird in diesem Takt abgerechnet. */
const BURN_TICK = 0.25;
/** Halbe Dauer eines Portalsprungs in Sekunden. */
const TELEPORT_HALF = 0.22;

/**
 * Zentrale Spielinstanz: haelt Welt, Entitäten und Regeln zusammen und
 * meldet Ereignisse (Wellenende, Tod) nach aussen an die Oberflaeche.
 */
export class Arena {
  constructor({ canvas, content, mapDef, weaponDef, character, input }) {
    this.canvas = canvas;
    this.content = content;
    this.input = input;
    this.listeners = new Map();

    this.map = new GameMap(mapDef);
    this.camera = new Camera();
    this.effects = new Effects();
    this.enemies = new EnemyManager(this.map);
    this.projectiles = new Projectiles(this.map);
    this.melee = new MeleeAttacks();
    this.pickups = new Pickups(this.map);
    this.portals = new Portals(mapDef.portals || []);
    this.character = character || (content.characters && content.characters[0]) || null;
    this.run = new RunState(content, weaponDef, this.character);
    this.weapon = new WeaponController(this);
    this.waves = new WaveController(this);
    this.bossCtrl = new BossController(this);
    this.renderer = new Renderer(canvas, this);

    this.boss = null;
    this.bossDefeated = false;
    this.bossIntro = 0;
    this.debug = false;
    this.gameOver = false;
    this.time = 0;

    // Je Gegenstand ein eigener Zeitgeber. Der erste Versuch kommt nach
    // dem halben Intervall, damit nicht gleich zu Beginn etwas herumliegt.
    this.itemDefs = (content.items || []).filter((i) => i.active !== false);
    this.itemTimers = new Map();
    for (const def of this.itemDefs) this.itemTimers.set(def.id, (def.interval || 14) * 0.5);

    // Teleport: Ablauf in Sekunden und Sperre gegen sofortiges Zurückspringen.
    this.teleport = null;
    this.portalLock = 0;

    // Gegner, die ausserhalb der Trefferkette gestorben sind (Feuer, Explosion).
    this._pendingKills = [];

    // Ultimate: Druckwelle auf Knopfdruck, danach Abklingzeit.
    this.ultTimer = 0;
    this.shockwaves = [];
  }

  on(event, handler) {
    if (!this.listeners.has(event)) this.listeners.set(event, []);
    this.listeners.get(event).push(handler);
  }

  emit(event, payload) {
    for (const handler of this.listeners.get(event) || []) handler(payload);
  }

  /**
   * Lädt nur das, was für den Start wirklich gebraucht wird.
   *
   * Alles andere - spätere Gegner, Boss, Bomben, andere Waffen - kommt im
   * Hintergrund nach, während schon gespielt wird. Auf einem langsamen
   * Mobilnetz macht das den Unterschied zwischen ein paar Sekunden und
   * einer knappen Minute Wartezeit.
   *
   * @param onProgress (geladen, gesamt) => void
   */
  async load(onProgress = null) {
    const player = this.content.player;
    const weapon = this.run.weapon;

    // Projektil der gewählten Waffe.
    const projectile = weapon.projectile && weapon.projectile !== 'pfeil' && weapon.projectile !== 'magic'
      ? 'assets/sprites/' + weapon.projectile + '.png'
      : null;

    // Gegner der ersten Welle - mehr braucht der Start nicht.
    const firstWave = this.content.enemies
      .filter((e) => !e.boss && (e.wave === 1 || !e.wave))
      .map((e) => e.sprite);

    const essential = [weapon.sprite, projectile, ...firstWave].filter(Boolean);
    let done = 0;
    const total = essential.length + 2;   // plus Karte und Charakter
    const step = () => {
      done++;
      if (onProgress) onProgress(done, total);
    };

    const characterPromise = Assets.loadCharacter(this.character || {
      id: 'default',
      sprites: {
        front: { gif: player.spriteFront, frames: [] },
        back: { gif: player.spriteBack, frames: [] },
        side: { gif: player.spriteSide, frames: [] },
      },
      dustSprite: player.spriteDust,
    }).then((set) => {
      step();
      return set;
    });

    const [, characterSprites] = await Promise.all([
      this.map.load().then(step),
      characterPromise,
      ...essential.map((src) => Assets.load(src).then(step)),
    ]);
    this.playerSprites = characterSprites;

    // Der Rest lädt im Hintergrund weiter und ist da, bevor er gebraucht wird.
    // Portale gehören zur Karte und müssen von Anfang an da sein.
    if (this.portals.count) {
      const [rot, blau] = await Promise.all([
        Assets.load(PORTAL_SPRITES.red),
        Assets.load(PORTAL_SPRITES.blue),
      ]);
      this.portals.setSprites({ red: rot, blue: blau });
    }

    this.backgroundLoad = Assets.loadAll([
      'assets/sprites/schuss.png',
      BURN_SPRITE,
      ...this.itemDefs.flatMap((i) => [i.sprite, i.openSprite]).filter(Boolean),
      'assets/sprites/explosion.gif',
      'assets/sprites/explosion1.gif',
      'assets/sprites/bombe1.gif',
      ...this.content.enemies.map((e) => e.sprite),
      ...this.content.weapons.filter((w) => w.active).map((w) => w.sprite),
    ]);

    this.player = new Player(this.run, this.map, this.playerSprites);
    const start = playerSpawn(this.map, this.player.rx, this.player.ry);
    this.player.x = start.x;
    this.player.y = start.y;

    this.camera.setBounds(this.map.width, this.map.height);
    this.renderer.resize();
    this.camera.snapTo(this.player.x, this.player.y);
    return this;
  }

  start() {
    this.waves.startWave();
  }

  /* ------------------------------------------------------------- Update */

  update(dt, time) {
    this.time = time;
    if (this.gameOver) return;

    this.run.timeAlive += dt;
    const usage = this.run.weaponUsage;
    usage[this.run.weapon.id] = (usage[this.run.weapon.id] || 0) + dt;
    if (this.bossIntro > 0) this.bossIntro = Math.max(0, this.bossIntro - dt);

    const move = this.input.sample();
    this.player.update(dt, move, this.map, this.effects);
    this.enemies.update(dt, this.player, time);
    this.weapon.update(dt);
    this.melee.update(dt, this.player, this.enemies, (enemy, attack) => this.onMeleeHit(enemy, attack));
    this.projectiles.update(
      dt,
      this.enemies,
      (enemy, p) => this.onProjectileHit(enemy, p),
      (p) => this.onProjectileEnd(p),
      this.player,
      (p) => this.damagePlayer(p.damage),
    );
    this.bossCtrl.update(dt);
    updateEffects(this.run, dt);
    this.updateEffectTimers(dt);
    this.updateUlt(dt);
    this.contactDamage(dt);
    this.updateBurn(dt);
    this.updateItems(dt);
    this.updatePortals(dt);
    this.effects.update(dt);
    this.waves.update(dt);
    this.camera.follow(this.player.x, this.player.y + 10, dt);

    if (this.player.dead && !this.gameOver) {
      this.gameOver = true;
      Audio.play('gameOver');
      this.emit('death', this.summary());
    }
  }

  /** Kontaktschaden mit eigenem Cooldown je Gegner. */
  contactDamage(dt) {
    const player = this.player;
    if (player.dead) return;
    const px = player.x;
    const py = player.y + player.footOffset * 0.35;

    this.enemies.inRadius(px, py, 46, (enemy) => {
      if (enemy.contactTimer > 0) return;
      const dx = enemy.x - px;
      const dy = enemy.y + enemy.hitboxOy - py;
      const reach = enemy.radius + player.rx * 0.9;
      if (dx * dx + dy * dy > reach * reach) return;

      enemy.contactTimer = enemy.contactCooldown || this.content.balance.contactDamageCooldown;
      this.damagePlayer(enemy.damage);

      // Fähigkeit "Dornen": ein Teil des Schadens geht zurück.
      let zurueck = 0;
      if (this.run.perk === 'thorns') {
        zurueck += enemy.damage * PERK_VALUES.thornsShare + PERK_VALUES.thornsFlat;
      }
      zurueck += this.run.stats.thorns;
      if (zurueck > 0) this.damageEnemy(enemy, zurueck, false, 40, px, py);

      // Kleiner Rückstoß auf beide Seiten macht Treffer spürbar.
      const len = Math.hypot(dx, dy) || 1;
      enemy.knockX += (dx / len) * 120;
      enemy.knockY += (dy / len) * 120;
    });
  }

  damagePlayer(amount) {
    const result = this.player.takeDamage(amount);
    if (result === 0) return;
    if (result > 0) onEffectPlayerHit(this.run);

    // Upgrade "Letzte Kartoffel": rettet einmal je Welle.
    if (this.player.dead && this.run.lastPotatoReady && this.run.hasEffect('lastPotato')) {
      this.run.lastPotatoReady = false;
      this.player.dead = false;
      this.player.invuln = 1.6;
      this.run.health = Math.max(1, Math.round(this.run.stats.maxHealth * 0.3));
      this.effects.number(this.player.x, this.player.y - 52, 'Letzte Kartoffel!', { color: '#ffd166' });
      this.effects.burst(this.player.x, this.player.y, 26, { color: '#ffd166', speed: 220, size: 4, life: 0.6 });
      this.camera.addShake(0.4);
    }

    // Fähigkeit "Zweiter Atem": einmal je Welle überlebt man den Todesstoß.
    if (this.player.dead && this.run.secondWindReady) {
      this.run.secondWindReady = false;
      this.player.dead = false;
      this.player.invuln = 1.4;
      this.run.health = Math.max(1, Math.round(this.run.stats.maxHealth * PERK_VALUES.secondWindHeal / 100));
      this.effects.number(this.player.x, this.player.y - 52, 'Zweiter Atem!', { color: '#5ee08a' });
      this.effects.burst(this.player.x, this.player.y, 26, { color: '#5ee08a', speed: 220, size: 4, life: 0.6 });
      this.camera.addShake(0.4);
      this.emit('secondWind', null);
    }
    if (result === -1) {
      this.effects.number(this.player.x, this.player.y - 30, 'Ausweichen', { color: '#7fd6c2' });
      return;
    }
    this.effects.number(this.player.x, this.player.y - 34, '-' + Math.round(result), { color: '#ff6b8a' });
    this.effects.burst(this.player.x, this.player.y, 8, { color: '#ff6b8a', speed: 120, size: 3, life: 0.3 });
    this.camera.addShake(0.22);
    Audio.play('playerHit');
    this.emit('playerHit', result);
  }

  onProjectileHit(enemy, p) {
    const crit = p.crit;
    const damage = p.damage * (crit ? 1 + p.critDamage / 100 : 1);
    this.damageEnemy(enemy, damage, crit, p.knockback, p.x, p.y);
    this.effects.burst(p.x, p.y, 5, {
      color: p.kind === 'magic' ? '#b3a6ff' : '#ffe6a3',
      speed: 130, size: 3, life: 0.22, angle: p.angle + Math.PI, spread: 1.6,
    });
    return true;
  }

  onProjectileEnd(p) {
    if (p.kind === 'grenade') {
      this.explode(p.x, p.y, p.aoeRadius, p.damage, 'assets/sprites/explosion.gif');
    }
  }

  onMeleeHit(enemy, attack) {
    const crit = Math.random() * 100 < attack.critChance;
    const damage = attack.damage * (crit ? 1 + attack.critDamage / 100 : 1);
    this.damageEnemy(enemy, damage, crit, attack.knockback, attack.x, attack.y);
  }

  /** Explosion: Schaden exakt im gezeichneten Radius. */
  explode(x, y, radius, damage, spritePath) {
    const sprite = Assets.get(spritePath);
    if (sprite) {
      this.effects.animation(x, y, sprite, { scale: (radius * 2.2) / Math.max(sprite.width, sprite.height) });
    }
    this.effects.burst(x, y, 20, { color: '#ffb347', speed: 260, size: 4, life: 0.4 });
    this.camera.addShake(0.35);
    Audio.play('explosion');

    this.enemies.inRadius(x, y, radius, (enemy) => {
      const distance = Math.hypot(enemy.x - x, enemy.y - y);
      // Am Rand der Explosion fällt der Schaden ab.
      const falloff = Math.max(0.35, 1 - distance / (radius * 1.15));
      this.damageEnemy(enemy, damage * falloff, false, 160, x, y);
    });
  }

  damageEnemy(enemy, amount, crit, knockback, fromX, fromY) {
    if (!enemy.alive || enemy.health <= 0) return;
    // Fähigkeit und Upgrade-Effekte wirken auf jeden Treffer.
    const damage = Math.max(1, amount
      * perkDamageMultiplier(this.run, this.player, enemy)
      * effectDamageFactor(this.run, enemy));
    enemy.health -= damage;
    enemy.hitFlash = 1;
    this.run.damageDealt += damage;

    this.igniteEnemy(enemy);

    // Lebensraub und heilende Krits.
    const raub = this.run.stats.lifesteal;
    let heilung = raub > 0 ? (damage * raub) / 100 : 0;
    if (crit && this.run.hasEffect('critHeal')) {
      heilung += EFFECT_VALUES.critHeal * this.run.effectLevel('critHeal');
    }
    if (heilung > 0 && !this.player.dead && this.run.health < this.run.stats.maxHealth) {
      this.run.health = Math.min(this.run.stats.maxHealth, this.run.health + heilung);
    }
    // Fähigkeit "Frost": getroffene Gegner werden träge.
    if (this.run.perk === 'frost') {
      enemy.slowFactor = PERK_VALUES.frostFactor;
      enemy.slowTime = PERK_VALUES.frostDuration;
    }
    this.effects.number(enemy.x, enemy.y - enemy.radius - 6, formatNumber(damage), { crit });
    // Eigener Trefferton des Gegners, sonst der allgemeine.
    Audio.playFirst(crit
      ? ['crit', 'enemy:' + enemy.def.id + ':hit', 'hit']
      : ['enemy:' + enemy.def.id + ':hit', 'hit']);

    if (knockback > 0) {
      const dx = enemy.x - fromX;
      const dy = enemy.y - fromY;
      const len = Math.hypot(dx, dy) || 1;
      const force = knockback * (enemy.boss ? 0.18 : 1);
      enemy.knockX += (dx / len) * force;
      enemy.knockY += (dy / len) * force;
    }

    if (enemy.health <= 0) this.killEnemy(enemy);
  }

  killEnemy(enemy) {
    if (enemy.dying) return;
    // Der Gegner bleibt noch eine Sekunde als Leiche liegen und faellt um.
    // health 0 nimmt ihn aus allen Trefferabfragen, alive haelt ihn im Bild.
    enemy.dying = true;
    enemy.deathTime = 0;
    enemy.health = 0;
    enemy.contactTimer = 999;
    this.run.kills++;

    // Fähigkeit "Lebensraub": jeder Kill heilt ein wenig.
    if (this.run.perk === 'lifesteal' && !this.player.dead) {
      const heal = enemy.boss ? PERK_VALUES.lifestealBoss : PERK_VALUES.lifestealNormal;
      const before = this.run.health;
      this.run.health = Math.min(this.run.stats.maxHealth, this.run.health + heal);
      if (this.run.health > before) {
        this.effects.number(this.player.x, this.player.y - 46, '+' + Math.round(this.run.health - before), { color: '#5ee08a' });
      }
    }

    onEffectKill(this.run, enemy);

    // Kettenreaktion: der Leichnam reisst Nachbarn mit.
    const kette = this.run.effectLevel('chainExplode');
    if (kette > 0 && !enemy.boss && Math.random() < EFFECT_VALUES.chainChance * kette) {
      const schaden = Math.max(5, enemy.maxHealth * EFFECT_VALUES.chainShare);
      this.effects.burst(enemy.x, enemy.y, 14, { color: '#ffb066', speed: 220, size: 4, life: 0.35 });
      this.enemies.inRadius(enemy.x, enemy.y, EFFECT_VALUES.chainRadius, (other) => {
        if (other === enemy || !other.alive || other.dying) return;
        other.health -= schaden;
        other.hitFlash = 1;
        this.run.damageDealt += schaden;
        this.effects.number(other.x, other.y - other.radius - 6, formatNumber(schaden), { color: '#ffb066' });
        if (other.health <= 0) this._pendingKills.push(other);
      });
      this.flushKills();
    }

    // Schatzjaeger und Midas-Hand erhoehen die Ausbeute.
    let beute = enemy.reward;
    if (enemy.boss) beute *= 1 + EFFECT_VALUES.treasure * this.run.effectLevel('treasure');
    const money = this.run.addMoney(beute);
    const midas = this.run.effectLevel('midas');
    if (midas > 0 && Math.random() < EFFECT_VALUES.midasChance * midas) {
      const bonus = this.run.addMoney(EFFECT_VALUES.midasAmount * midas);
      this.effects.number(enemy.x, enemy.y - enemy.radius - 34, '+' + bonus + ' $', { color: '#ffe08a' });
    }
    this.effects.number(enemy.x, enemy.y - enemy.radius - 22, '+' + money + ' $', { color: '#ffd166' });
    this.effects.burst(enemy.x, enemy.y, enemy.boss ? 40 : 12, {
      color: enemy.boss ? '#ff8b6b' : 'rgba(220,120,140,0.9)',
      speed: enemy.boss ? 320 : 150, size: enemy.boss ? 6 : 3, life: 0.5,
    });
    Audio.playFirst(['enemy:' + enemy.def.id + ':death', 'enemyDeath']);

    // Fähigkeit "Sprengmeister": die Leiche reißt Umstehende mit.
    // Der Schaden geht nicht über damageEnemy, sonst könnte eine Kette
    // aus Explosionen sich selbst befeuern.
    if (this.run.perk === 'blast' && !enemy.boss) {
      const radius = PERK_VALUES.blastRadius;
      const damage = Math.max(4, enemy.maxHealth * PERK_VALUES.blastShare);
      this.effects.burst(enemy.x, enemy.y, 16, { color: '#ffa14d', speed: 240, size: 4, life: 0.35 });
      this.enemies.inRadius(enemy.x, enemy.y, radius, (other) => {
        if (other === enemy || !other.alive) return;
        other.health -= damage;
        other.hitFlash = 1;
        this.run.damageDealt += damage;
        this.effects.number(other.x, other.y - other.radius - 6, formatNumber(damage), { color: '#ffa14d' });
        if (other.health <= 0) this._pendingKills.push(other);
      });
      this.flushKills();
    }

    if (enemy.boss) {
      this.run.bossKills++;
      this.bossDefeated = true;
      this.boss = null;
      // Der Boss loest sich sofort auf - seine Explosion ersetzt das Umfallen.
      enemy.alive = false;
      this.camera.addShake(0.8);
      const sprite = Assets.get('assets/sprites/explosion1.gif') || Assets.get('assets/sprites/explosion.gif');
      if (sprite) {
        this.effects.animation(enemy.x, enemy.y, sprite, { scale: 360 / Math.max(sprite.width, sprite.height) });
      }
    }
    this.emit('kill', enemy);
  }

  /* --------------------------------------------------- Verbrennung */

  /**
   * Setzt einen getroffenen Gegner in Brand.
   *
   * Der Feuerschaden wächst mit jedem Verbrennungs-Upgrade und zieht mit
   * dem Schadensfaktor des Runs mit - sonst wäre er ab Zyklus drei
   * bedeutungslos. Ein neuer Treffer frischt die Brenndauer auf, stapelt
   * sich aber nicht zu mehreren Braenden auf demselben Gegner.
   */
  igniteEnemy(enemy) {
    // Fähigkeit "Brandstifter" zündet auch ohne Verbrennungs-Upgrade.
    const base = this.run.perk === 'pyro' ? PERK_VALUES.pyroBurn : 0;
    const burn = this.run.stats.burn + base;
    if (burn <= 0 || !enemy.alive) return;
    const dps = burn * this.run.stats.damageMult;
    enemy.burnDps = Math.max(enemy.burnDps, dps);
    enemy.burnTime = this.content.balance.burnDuration || 3;
  }

  /** Rechnet den Feuerschaden aller brennenden Gegner ab. */
  updateBurn(dt) {
    // Tote erst nach der Schleife melden: killEnemy räumt die Liste auf,
    // und das darf nicht mitten im Durchlauf passieren.
    const burned = this._pendingKills;
    burned.length = 0;

    for (const enemy of this.enemies.list) {
      if (!enemy.alive || enemy.dying || enemy.burnTime <= 0) continue;

      enemy.burnTime -= dt;
      enemy.burnTick += dt;
      if (enemy.burnNumber > 0) enemy.burnNumber -= dt;

      if (enemy.burnTick < BURN_TICK) {
        if (enemy.burnTime <= 0) enemy.burnDps = 0;
        continue;
      }

      const damage = enemy.burnDps * enemy.burnTick;
      enemy.burnTick = 0;
      enemy.health -= damage;
      this.run.damageDealt += damage;

      // Nur etwa einmal pro Sekunde eine Zahl - sonst überdeckt das Feuer
      // bei vielen Gegnern alle anderen Trefferzahlen.
      if (enemy.burnNumber <= 0) {
        enemy.burnNumber = 1;
        this.effects.number(enemy.x, enemy.y - enemy.radius - 14, formatNumber(damage * (1 / BURN_TICK)), {
          color: '#ff9a3c',
        });
      }
      if (Math.random() < 0.5) {
        this.effects.burst(enemy.x + rand(-6, 6), enemy.y - enemy.radius * 0.2, 1, {
          color: '#ffb347', speed: 40, size: 2, life: 0.35, angle: -Math.PI / 2, spread: 1.1,
        });
      }

      if (enemy.burnTime <= 0) enemy.burnDps = 0;
      if (enemy.health <= 0) burned.push(enemy);
    }

    this.flushKills();
  }

  /** Erledigt Gegner, die ausserhalb der normalen Trefferkette gestorben sind. */
  flushKills() {
    const list = this._pendingKills;
    if (!list.length) return;
    const copy = list.slice();
    list.length = 0;
    for (const enemy of copy) {
      if (enemy.alive) this.killEnemy(enemy);
    }
  }

  /**
   * Effekte, die von selbst zuschlagen: Schwarzes Loch, Zeitlupe,
   * Todeswelle, Klonmaschine und der Schaden beim Rammen.
   */
  updateEffectTimers(dt) {
    const run = this.run;
    const player = this.player;
    if (player.dead) return;

    // Schwarzes Loch: zieht in festem Takt alles heran.
    const loch = run.effectLevel('blackhole');
    if (loch > 0) {
      this._blackhole = (this._blackhole || 0) + dt;
      if (this._blackhole >= EFFECT_VALUES.blackholeEvery) {
        this._blackhole = 0;
        const radius = EFFECT_VALUES.blackholeRadius;
        this.effects.burst(player.x, player.y, 20, {
          color: '#b3a6ff', speed: 200, size: 3, life: 0.5, glow: true,
        });
        this.enemies.inRadius(player.x, player.y, radius, (enemy) => {
          const dx = player.x - enemy.x;
          const dy = player.y - enemy.y;
          const len = Math.hypot(dx, dy) || 1;
          enemy.knockX += (dx / len) * EFFECT_VALUES.blackholePull * loch;
          enemy.knockY += (dy / len) * EFFECT_VALUES.blackholePull * loch;
        });
        this.emit('blackhole', null);
      }
    }

    // Zeitlupe: bei wenig Leben werden alle Gegner traege.
    if (run.hasEffect('slowmo')) {
      const knapp = run.health / Math.max(1, run.stats.maxHealth) < EFFECT_VALUES.slowmoBelow;
      for (const enemy of this.enemies.list) {
        if (!enemy.alive || enemy.dying) continue;
        if (knapp) {
          enemy.slowFactor = Math.min(enemy.slowFactor, EFFECT_VALUES.slowmoFactor);
          enemy.slowTime = Math.max(enemy.slowTime, 0.4);
        }
      }
    }

    // Todeswelle: entlaedt sich, wenn es eng wird.
    if (run.hasEffect('deathwave')) {
      this._deathwave = Math.max(0, (this._deathwave || 0) - dt);
      const knapp = run.health / Math.max(1, run.stats.maxHealth) < EFFECT_VALUES.deathwaveBelow;
      if (knapp && this._deathwave <= 0) {
        this._deathwave = EFFECT_VALUES.deathwaveCooldown;
        const radius = EFFECT_VALUES.deathwaveRadius;
        this.shockwaves.push({ x: player.x, y: player.y, radius, time: 0, life: 0.5, color: '#ff6b8a' });
        this.enemies.inRadius(player.x, player.y, radius, (enemy) => {
          const dx = enemy.x - player.x;
          const dy = enemy.y - player.y;
          const len = Math.hypot(dx, dy) || 1;
          enemy.knockX += (dx / len) * 700;
          enemy.knockY += (dy / len) * 700;
          this.damageEnemy(enemy, EFFECT_VALUES.deathwaveDamage * run.effectLevel('deathwave'),
            false, 0, player.x, player.y);
        });
        this.camera.addShake(0.5);
        Audio.playFirst(['ult', 'explosion']);
      }
    }

    // Klonmaschine: feuert regelmaessig einen zweiten Schuss.
    if (run.hasEffect('clone')) {
      this._clone = (this._clone || 0) + dt;
      if (this._clone >= EFFECT_VALUES.cloneEvery) {
        this._clone = 0;
        this.weapon.fireExtra();
      }
    }

    // Turbo: wer den Spieler rammt, nimmt Schaden.
    if (run.hasEffect('collide')) {
      const stufe = run.effectLevel('collide');
      this.enemies.inRadius(player.x, player.y + player.footOffset * 0.3, 42, (enemy) => {
        this.damageEnemy(enemy, EFFECT_VALUES.collide * stufe * dt, false, 0, player.x, player.y);
      });
    }
  }

  /* ---------------------------------------------------------- Ultimate */

  /** Anteil der Abklingzeit, der schon verstrichen ist (0..1). */
  get ultReady() {
    return this.ultTimer <= 0;
  }

  get ultProgress() {
    const dauer = this.content.balance.ultCooldown || 30;
    return Math.max(0, Math.min(1, 1 - this.ultTimer / dauer));
  }

  updateUlt(dt) {
    if (this.ultTimer > 0) this.ultTimer = Math.max(0, this.ultTimer - dt);

    // Sichtbare Druckwellen laufen aus.
    for (let i = this.shockwaves.length - 1; i >= 0; i--) {
      const w = this.shockwaves[i];
      w.time += dt;
      if (w.time >= w.life) this.shockwaves.splice(i, 1);
    }
  }

  /**
   * Druckwelle: stösst alle Gegner im Umkreis massiv weg.
   *
   * Radius, Wucht, Schaden und Abklingzeit stehen im Balancing. Der Stoss
   * geht strikt vom Spieler nach aussen, damit niemand durch die Welle
   * hindurch auf die andere Seite geschleudert wird.
   *
   * @returns true, wenn sie ausgelöst wurde
   */
  useUlt() {
    if (!this.ultReady || this.gameOver || this.player.dead) return false;
    const balance = this.content.balance;
    const radius = balance.ultRadius || 300;
    const wucht = balance.ultKnockback || 1400;
    const schaden = balance.ultDamage || 0;

    this.ultTimer = balance.ultCooldown || 30;
    const px = this.player.x;
    const py = this.player.y + this.player.footOffset * 0.3;

    this.shockwaves.push({ x: px, y: py, radius, time: 0, life: 0.55, color: '#bfe0ff' });

    let getroffen = 0;
    this.enemies.inRadius(px, py, radius, (enemy) => {
      const dx = enemy.x - px;
      const dy = enemy.y - py;
      const len = Math.hypot(dx, dy) || 1;
      // Nah am Spieler wirkt die Welle am stärksten.
      const staerke = 0.45 + 0.55 * (1 - Math.min(1, len / radius));
      const kraft = wucht * staerke * (enemy.boss ? 0.25 : 1);
      enemy.knockX += (dx / len) * kraft;
      enemy.knockY += (dy / len) * kraft;
      // Kurz betäubt, damit sie nicht sofort zurücklaufen.
      enemy.slowFactor = Math.min(enemy.slowFactor, 0.4);
      enemy.slowTime = Math.max(enemy.slowTime, 0.9);
      enemy.hitFlash = 1;
      getroffen++;
      if (schaden > 0) this.damageEnemy(enemy, schaden, false, 0, px, py);
    });

    this.effects.burst(px, py, 34, {
      color: '#9fd0ff', speed: 420, size: 4, life: 0.5, glow: true,
    });
    this.effects.burst(px, py, 18, {
      color: '#ffffff', speed: 260, size: 3, life: 0.35, glow: true,
    });
    this.camera.addShake(0.7);
    Audio.playFirst(['ult', 'explosion']);
    this.emit('ult', { getroffen, radius });
    return true;
  }

  /* ------------------------------------------------- Gegenstände */

  /**
   * Lässt hin und wieder einen Gegenstand in der Nähe erscheinen.
   *
   * "Zufällig auf der Karte" heisst in Laufweite, aber nie direkt vor den
   * Füßen - wäre es die ganze 2048er-Karte, fände man nie etwas. Jeder
   * Gegenstand bringt seine eigenen Werte mit (Dashboard: Gegenstände).
   */
  updateItems(dt) {
    const reichweite = this.run.stats.pickupRange
      * (this.run.perk === 'magnet' ? PERK_VALUES.magnetRange : 1);
    this.pickups.update(dt, this.player, reichweite, (p) => this.collectPickup(p));
    if (this.player.dead) return;

    for (const def of this.itemDefs) {
      const rest = (this.itemTimers.get(def.id) || 0) - dt;
      if (rest > 0) {
        this.itemTimers.set(def.id, rest);
        continue;
      }
      this.itemTimers.set(def.id, def.interval || 14);

      if ((def.maxOnMap ?? 3) <= 0) continue;
      if (this.pickups.countOf(def.id) >= def.maxOnMap) continue;

      // Alchemie und die Fähigkeit "Magnet" wirken auf alles, was
      // heilt - Truhen bleiben davon unberührt.
      let chance = def.chance ?? 0.35;
      if (def.effect === 'heal') {
        chance *= (this.run.stats.potionRateMult || 1)
          * (this.run.perk === 'magnet' ? PERK_VALUES.magnetPotion : 1);
      }
      if (Math.random() > chance) continue;
      this.spawnItem(def);
    }
  }

  /** Lässt einen bestimmten Gegenstand erscheinen. @returns das Objekt oder null */
  spawnItem(def) {
    const sprite = Assets.get(def.sprite);
    if (!sprite) {
      // Lädt noch im Hintergrund - dann eben beim nächsten Versuch.
      Assets.load(def.sprite);
      return null;
    }
    const openSprite = def.openSprite ? Assets.get(def.openSprite) : null;
    if (def.openSprite && !openSprite) Assets.load(def.openSprite);

    const min = def.minDistance ?? 200;
    const max = Math.max(min + 40, def.maxDistance ?? 620);
    for (let i = 0; i < 24; i++) {
      const angle = Math.random() * TAU;
      const distance = rand(min, max);
      const x = Math.min(this.map.width - 30, Math.max(30, this.player.x + Math.cos(angle) * distance));
      const y = Math.min(this.map.height - 30, Math.max(30, this.player.y + Math.sin(angle) * distance));
      if (this.map.mask.blockedEllipse(x, y, 16, 12)) continue;

      const p = this.pickups.spawn(def, sprite, openSprite, x, y);
      this.effects.burst(x, y, 10, {
        color: def.particle || '#ffd166', speed: 70, size: 2.5, life: 0.6, glow: true,
      });
      this.emit('itemSpawn', p);
      return p;
    }
    return null;
  }

  /** @returns true, wenn der Gegenstand wirklich aufgenommen wurde. */
  collectPickup(p) {
    const def = p.def;
    if (!def) return false;
    const stats = this.run.stats;

    // Manche Gegenstände bleiben liegen, wenn sie gerade nichts brächten.
    if (def.onlyWhenNeeded) {
      if (def.effect === 'heal' && this.run.health >= stats.maxHealth) return false;
      if (def.effect === 'shield' && this.run.shield >= stats.maxShield) return false;
    }

    const farbe = def.particle || '#ffd166';
    let text = '';

    switch (def.effect) {
      case 'heal': {
        const heal = Math.max(1, Math.round((stats.maxHealth * (def.value || 10)) / 100));
        const vorher = this.run.health;
        this.run.health = Math.min(stats.maxHealth, this.run.health + heal);
        text = '+' + Math.round(this.run.health - vorher);
        break;
      }
      case 'money': {
        const min = def.value || 0;
        const max = Math.max(min, def.value2 || min);
        const betrag = this.run.addMoney(Math.round(rand(min, max + 1)));
        text = '+' + betrag + ' $';
        break;
      }
      case 'shield': {
        const vorher = this.run.shield;
        this.run.shield = Math.min(stats.maxShield, this.run.shield + (def.value || 0));
        const plus = Math.round(this.run.shield - vorher);
        if (plus <= 0 && def.onlyWhenNeeded) return false;
        text = '+' + plus + ' Schild';
        break;
      }
      case 'speed': {
        this.player.boost(1 + (def.value || 20) / 100, def.value2 || 6);
        text = '+' + Math.round(def.value || 20) + '% Tempo';
        break;
      }
      case 'magnet': {
        // Zieht alles Herumliegende sofort zum Spieler.
        for (const other of this.pickups.list) {
          if (other === p || other.opened || other.def.mode === 'chest') continue;
          other.x = this.player.x + rand(-20, 20);
          other.y = this.player.y + rand(-20, 20);
        }
        text = 'Magnet!';
        break;
      }
      default:
        return false;
    }

    // Truhen bekommen einen kräftigen Ausbruch, alles andere einen kurzen.
    const wucht = def.mode === 'chest';
    this.effects.number(p.x, p.y - 30, text, { color: farbe });
    this.effects.burst(p.x, p.y, wucht ? 26 : 14, {
      color: farbe, speed: wucht ? 220 : 150, size: wucht ? 4 : 3,
      life: wucht ? 0.7 : 0.45, glow: true,
    });
    if (wucht) {
      this.effects.burst(p.x, p.y - 10, 12, {
        color: '#fff3c4', speed: 120, size: 2.5, life: 0.9,
        angle: -Math.PI / 2, spread: 1.4, glow: true,
      });
      this.camera.addShake(0.18);
    }
    if (def.sound) Audio.play(def.sound);
    this.emit('pickup', { id: def.id, effect: def.effect, text });
    return true;
  }

  /**
   * Raeumt am Wellenende ab: Alles, was noch steht, faellt um.
   *
   * Ohne Belohnung - wer die Welle aussitzt, soll davon nichts haben.
   * Der Boss bleibt ausgenommen, seinen Kampf beendet man selbst.
   */
  clearWave() {
    for (const enemy of this.enemies.list) {
      if (!enemy.alive || enemy.dying || enemy.boss) continue;
      enemy.dying = true;
      enemy.deathTime = 0;
      enemy.health = 0;
      enemy.contactTimer = 999;
      enemy.burnTime = 0;
      this.effects.burst(enemy.x, enemy.y, 6, {
        color: 'rgba(200,190,220,0.8)', speed: 90, size: 2.5, life: 0.4,
      });
    }
  }

  /* ------------------------------------------------------------ Portale */

  /**
   * Portale: rot führt zu blau und umgekehrt.
   *
   * Der Sprung läuft in zwei Hälften ab - erst zieht es den Spieler
   * zusammen, dann erscheint er am Ziel wieder. Dazwischen wird versetzt,
   * damit man den Wechsel sieht statt ihn nur zu bemerken.
   */
  updatePortals(dt) {
    if (!this.portals.count) return;
    this.portals.update(dt, this.effects, this.camera);
    if (this.portalLock > 0) this.portalLock -= dt;

    // Laufender Sprung.
    if (this.teleport) {
      this.teleport.time += dt;
      const t = this.teleport;
      if (!t.done && t.time >= TELEPORT_HALF) {
        t.done = true;
        this.finishTeleport(t);
      }
      if (t.time >= TELEPORT_HALF * 2) this.teleport = null;
      return;
    }

    if (this.portalLock > 0 || this.player.dead) return;
    const portal = this.portals.at(this.player.x, this.player.y + this.player.footOffset * 0.3, 40);
    if (portal) this.startTeleport(portal);
  }

  startTeleport(portal) {
    this.teleport = { from: portal, to: portal.partner, time: 0, done: false };
    this.player.teleport = 1;                 // Anzeige: der Spieler zieht sich zusammen
    this.camera.addShake(0.2);
    Audio.playFirst(['portal', 'upgrade']);
    this.portalFlash(portal, true);
    this.emit('teleportStart', portal);
  }

  /**
   * Der sichtbare Teil eines Portalsprungs.
   *
   * Ein einziehender Ring beim Abflug, ein aufziehender beim Ankommen,
   * dazu eine Lichtsäule und Funken in der Portalfarbe. So sieht man den
   * Wechsel, statt ihn nur zu bemerken.
   */
  portalFlash(portal, einzug) {
    const farbe = portal.kind === 'red' ? '#ff5a4d' : '#4db4ff';
    this.shockwaves.push({
      x: portal.x, y: portal.y - 14, radius: einzug ? 90 : 130,
      time: 0, life: 0.42, color: farbe, invers: einzug,
    });
    this.effects.burst(portal.x, portal.y - 20, einzug ? 22 : 30, {
      color: farbe, speed: einzug ? 150 : 240, size: 3.5, life: 0.55, glow: true,
    });
    // Lichtsäule: schmale Funken, die senkrecht aufsteigen.
    this.effects.burst(portal.x, portal.y - 6, 14, {
      color: '#ffffff', speed: 220, size: 2.5, life: 0.7,
      angle: -Math.PI / 2, spread: 0.5, glow: true,
    });
  }

  finishTeleport(t) {
    const ziel = t.to;
    const frei = this.map.mask.nearestFree(
      ziel.x, ziel.y + 26, this.player.rx, this.player.ry, 220,
    );
    this.player.x = frei.x;
    this.player.y = frei.y;
    this.player.vx = 0;
    this.player.vy = 0;
    this.camera.snapTo(this.player.x, this.player.y);
    // Sperre, damit man nicht sofort wieder zurückgezogen wird.
    this.portalLock = 1.6;
    this.camera.addShake(0.25);
    this.portalFlash(ziel, false);
    this.emit('teleportEnd', ziel);
  }

  /* --------------------------------------------------------------- Boss */

  startBossIntro() {
    const def = this.content.enemies.find((e) => e.boss) || null;
    if (!def) return;
    this.bossIntro = 2.2;
    this.bossDefeated = false;
    Audio.play('bossSpawn');

    // Der Boss erscheint am Rand des Sichtfelds, nie direkt auf dem Spieler.
    const angle = Math.random() * Math.PI * 2;
    const distance = Math.max(this.camera.viewWidth, this.camera.viewHeight) * 0.45;
    const radius = (def.hitbox && def.hitbox.r) || 50;
    const point = this.map.mask.nearestFree(
      Math.max(radius + 10, Math.min(this.map.width - radius - 10, this.player.x + Math.cos(angle) * distance)),
      Math.max(radius + 10, Math.min(this.map.height - radius - 10, this.player.y + Math.sin(angle) * distance)),
      radius, radius * 0.7, 900,
    );

    const scaling = this.waves.scaling();
    const bossSprite = Assets.get(def.sprite);
    this.boss = this.enemies.spawn(def, bossSprite, point.x, point.y, scaling);
    if (!bossSprite) {
      // Sollte das Sprite noch im Hintergrund laden, wird es nachgereicht.
      Assets.load(def.sprite).then((sprite) => {
        if (this.boss && this.boss.alive) this.boss.sprite = sprite;
      });
    }
    this.bossCtrl.reset();
    this.effects.burst(point.x, point.y, 34, { color: '#ff8b6b', speed: 280, size: 5, life: 0.6 });
    this.camera.addShake(0.6);
    this.emit('bossSpawn', this.boss);
  }

  enrageBoss() {
    if (!this.boss || !this.boss.alive) return;
    this.bossCtrl.enraged = true;
    this.boss.speed *= 1.35;
    this.boss.damage *= 1.25;
    this.camera.addShake(0.5);
    this.emit('bossEnrage', this.boss);
  }

  /* ------------------------------------------------------------- Render */

  render(dt, time, fps) {
    this.renderer.adapt(fps, dt);
    this.renderer.draw(time);
  }

  resize() {
    this.renderer.resize();
  }

  /** Zusammenfassung für den Todesbildschirm. */
  summary() {
    const run = this.run;
    return {
      timeAlive: run.timeAlive,
      wavesCleared: run.wavesCleared || 0,
      character: this.character ? this.character.name : '',
      cycle: run.cycle,
      wave: run.wave,
      kills: run.kills,
      bossKills: run.bossKills,
      money: run.money,
      damageDealt: Math.round(run.damageDealt),
      damageTaken: Math.round(run.damageTaken),
      weapon: run.weapon.name,
      favouriteWeapon: this.favouriteWeapon(),
      upgrades: run.upgrades.map((u) => ({ name: u.upgrade.name, count: u.count, rarity: u.upgrade.rarity })),
    };
  }

  /** Waffe mit der längsten Tragezeit im Run. */
  favouriteWeapon() {
    let best = null;
    let bestTime = -1;
    for (const [id, time] of Object.entries(this.run.weaponUsage)) {
      if (time > bestTime) {
        bestTime = time;
        best = id;
      }
    }
    const def = this.content.weapons.find((w) => w.id === best);
    return def ? def.name : this.run.weapon.name;
  }

  destroy() {
    this.enemies.clear();
    this.projectiles.clear();
    this.melee.clear();
    this.effects.clear();
    this.pickups.clear();
    this.bossCtrl.reset();
  }

  get waveState() {
    return this.waves.state;
  }

  get isIntermission() {
    return this.waves.state === WAVE_STATE.INTERMISSION;
  }
}
