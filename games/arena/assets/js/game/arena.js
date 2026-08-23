import { Assets } from '../gfx/assets.js';
import { Effects } from '../gfx/effects.js';
import { Camera } from '../core/camera.js';
import { Audio } from '../core/audio.js';
import { GameMap } from '../world/map.js';
import { playerSpawn } from '../world/spawn.js';
import { Player } from '../entities/player.js';
import { EnemyManager } from '../entities/enemy.js';
import { Projectiles } from '../combat/projectiles.js';
import { MeleeAttacks } from '../combat/melee.js';
import { WeaponController } from '../combat/weapon.js';
import { WaveController, WAVE_STATE } from './waves.js';
import { BossController } from './boss.js';
import { RunState } from './run.js';
import { Renderer } from '../gfx/renderer.js';
import { formatNumber } from '../core/util.js';

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
  }

  on(event, handler) {
    if (!this.listeners.has(event)) this.listeners.set(event, []);
    this.listeners.get(event).push(handler);
  }

  emit(event, payload) {
    for (const handler of this.listeners.get(event) || []) handler(payload);
  }

  /** Lädt alle Assets, die dieser Run braucht. */
  async load() {
    const player = this.content.player;
    const sprites = [
      this.run.weapon.sprite,
      'assets/sprites/schuss.png',
      'assets/sprites/explosion.gif',
      'assets/sprites/explosion1.gif',
      'assets/sprites/bombe1.gif',
      ...this.content.enemies.map((e) => e.sprite),
      ...this.content.weapons.filter((w) => w.active).map((w) => w.sprite),
    ];
    const [, , characterSprites] = await Promise.all([
      this.map.load(),
      Assets.loadAll(sprites),
      Assets.loadCharacter(this.character || {
        id: 'default',
        sprites: {
          front: { gif: player.spriteFront, frames: [] },
          back: { gif: player.spriteBack, frames: [] },
          side: { gif: player.spriteSide, frames: [] },
        },
        dustSprite: player.spriteDust,
      }),
    ]);
    this.playerSprites = characterSprites;

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
        this.damageEnemy(enemy, enemy.damage * 0.3 + 4, false, 40, px, py);
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
    const damage = Math.max(1, amount);
    enemy.health -= damage;
    enemy.hitFlash = 1;
    this.run.damageDealt += damage;

    this.effects.number(enemy.x, enemy.y - enemy.radius - 6, formatNumber(damage), { crit });
    Audio.play(crit ? 'crit' : 'hit');

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
      const heal = enemy.boss ? 25 : 1.5;
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
    Audio.play('enemyDeath');

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
    this.boss = this.enemies.spawn(def, Assets.get(def.sprite), point.x, point.y, scaling);
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

  render(dt, time) {
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
    this.bossCtrl.reset();
  }

  get waveState() {
    return this.waves.state;
  }

  get isIntermission() {
    return this.waves.state === WAVE_STATE.INTERMISSION;
  }
}
