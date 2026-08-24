import { Assets } from '../gfx/assets.js';
import { Effects } from '../gfx/effects.js';
import { Camera } from '../core/camera.js';
import { Audio } from '../core/audio.js';
import { GameMap } from '../world/map.js';
import { playerSpawn } from '../world/spawn.js';
import { Pickups } from '../world/pickups.js';
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

/** Flammen auf brennenden Gegnern. */
const BURN_SPRITE = 'assets/sprites/feuer.gif';
/** Heilflasche, die auf der Karte liegt. */
const POTION_SPRITE = 'assets/sprites/heiltrank.png';
/** Feuerschaden wird in diesem Takt abgerechnet. */
const BURN_TICK = 0.25;

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

    // Heilflaschen: erster Versuch nach dem halben Intervall, damit nicht
    // gleich zu Beginn eine Flasche vor den Fuessen liegt.
    this.potionTimer = (content.balance.potionInterval || 14) * 0.5;

    // Gegner, die ausserhalb der Trefferkette gestorben sind (Feuer, Explosion).
    this._pendingKills = [];
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
    this.backgroundLoad = Assets.loadAll([
      'assets/sprites/schuss.png',
      BURN_SPRITE,
      POTION_SPRITE,
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
    this.contactDamage(dt);
    this.updateBurn(dt);
    this.updatePotions(dt);
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
      if (this.run.perk === 'thorns') {
        this.damageEnemy(enemy, enemy.damage * PERK_VALUES.thornsShare + PERK_VALUES.thornsFlat, false, 40, px, py);
      }

      // Kleiner Rückstoß auf beide Seiten macht Treffer spürbar.
      const len = Math.hypot(dx, dy) || 1;
      enemy.knockX += (dx / len) * 120;
      enemy.knockY += (dy / len) * 120;
    });
  }

  damagePlayer(amount) {
    const result = this.player.takeDamage(amount);
    if (result === 0) return;

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
    // Blutrausch und Scharfschütze wirken auf jeden Treffer.
    const damage = Math.max(1, amount * perkDamageMultiplier(this.run, this.player, enemy));
    enemy.health -= damage;
    enemy.hitFlash = 1;
    this.run.damageDealt += damage;

    this.igniteEnemy(enemy);
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
    enemy.alive = false;
    enemy.health = 0;
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

    const money = this.run.addMoney(enemy.reward);
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
      this.camera.addShake(0.8);
      const sprite = Assets.get('assets/sprites/explosion1.gif') || Assets.get('assets/sprites/explosion.gif');
      if (sprite) {
        this.effects.animation(enemy.x, enemy.y, sprite, { scale: 360 / Math.max(sprite.width, sprite.height) });
      }
    }
    this.enemies.sweep();
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
      if (!enemy.alive || enemy.burnTime <= 0) continue;

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

  /* --------------------------------------------------- Heilflaschen */

  /**
   * Lässt hin und wieder eine Heilflasche in der Nähe erscheinen.
   *
   * "Zufällig auf der Karte" heisst hier: in Laufweite, aber nie direkt vor
   * den Fuessen. Wäre es die ganze 2048er-Karte, fände man nie eine.
   */
  updatePotions(dt) {
    const balance = this.content.balance;
    const max = balance.potionMax ?? 3;
    const reichweite = this.run.stats.pickupRange
      * (this.run.perk === 'magnet' ? PERK_VALUES.magnetRange : 1);
    this.pickups.update(dt, this.player, reichweite, (p) => this.collectPickup(p));
    if (max <= 0 || this.player.dead) return;

    this.potionTimer -= dt;
    if (this.potionTimer > 0) return;
    this.potionTimer = balance.potionInterval || 14;

    if (this.pickups.count >= max) return;
    const chance = (balance.potionChance ?? 0.35) * (this.run.stats.potionRateMult || 1)
      * (this.run.perk === 'magnet' ? PERK_VALUES.magnetPotion : 1);
    if (Math.random() > chance) return;
    this.spawnPotion();
  }

  spawnPotion() {
    const sprite = Assets.get(POTION_SPRITE);
    if (!sprite) {
      // Lädt noch im Hintergrund - dann eben beim nächsten Versuch.
      Assets.load(POTION_SPRITE);
      return null;
    }
    for (let i = 0; i < 24; i++) {
      const angle = Math.random() * TAU;
      const distance = rand(200, 620);
      const x = Math.min(this.map.width - 30, Math.max(30, this.player.x + Math.cos(angle) * distance));
      const y = Math.min(this.map.height - 30, Math.max(30, this.player.y + Math.sin(angle) * distance));
      if (this.map.mask.blockedEllipse(x, y, 14, 10)) continue;
      const p = this.pickups.spawn(x, y, sprite, {
        kind: 'heal',
        life: this.content.balance.potionLifetime || 26,
      });
      this.effects.burst(x, y, 8, { color: '#ff6b6b', speed: 60, size: 2, life: 0.5 });
      this.emit('pickupSpawn', p);
      return p;
    }
    return null;
  }

  /** @returns true, wenn der Gegenstand wirklich aufgenommen wurde. */
  collectPickup(p) {
    if (p.kind !== 'heal') return false;
    const stats = this.run.stats;
    // Bei vollem Leben bleibt die Flasche liegen, statt sich zu verschwenden.
    if (this.run.health >= stats.maxHealth) return false;

    const percent = this.content.balance.potionHeal ?? 10;
    const heal = Math.max(1, Math.round((stats.maxHealth * percent) / 100));
    const before = this.run.health;
    this.run.health = Math.min(stats.maxHealth, this.run.health + heal);
    const gained = Math.round(this.run.health - before);

    this.effects.number(this.player.x, this.player.y - 46, '+' + gained, { color: '#5ee08a' });
    this.effects.burst(this.player.x, this.player.y, 14, { color: '#5ee08a', speed: 150, size: 3, life: 0.45 });
    Audio.play('potion');
    this.emit('pickup', { kind: 'heal', amount: gained });
    return true;
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
