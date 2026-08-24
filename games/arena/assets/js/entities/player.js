import { clamp } from '../core/util.js';
import { Audio } from '../core/audio.js';

/**
 * Der Spieler. Die Kollisionshitbox sitzt bewusst nur um die Füße,
 * damit der Kopf optisch vor einer Wand stehen darf.
 */
export class Player {
  constructor(run, map, sprites) {
    this.run = run;
    this.sprites = sprites;
    this.x = map.spawn.x;
    this.y = map.spawn.y;
    this.vx = 0;
    this.vy = 0;
    this.facing = 'front';   // front | back | side
    this.flip = false;
    this.moving = false;
    this.moveTime = 0;
    this.hitFlash = 0;
    this.invuln = 0;
    this.aim = 0;
    this.recoil = 0;
    this.squash = 0;
    this.dead = false;
    // Zeitlich begrenzter Temposchub (Gegenstand "Tempo").
    this.boostFactor = 1;
    this.boostTime = 0;
    // 1 = mitten im Portalsprung, 0 = normal. Steuert nur die Darstellung.
    this.teleport = 0;

    // Charakterwerte gehen vor den allgemeinen Spielerwerten.
    const character = run.character || {};
    const hb = character.hitbox || run.base.hitbox || { rx: 15, ry: 10, oy: 22 };
    this.rx = hb.rx;
    this.ry = hb.ry;
    this.footOffset = hb.oy;
    this.scale = character.scale || run.base.scale || 78;
  }

  get drawWidth() {
    const sprite = this.currentSprite();
    if (!sprite) return this.scale;
    return (sprite.width / sprite.height) * this.scale;
  }

  currentSprite() {
    if (this.facing === 'back') return this.sprites.back;
    if (this.facing === 'side') return this.sprites.side;
    return this.sprites.front;
  }

  /** Temposchub für eine begrenzte Zeit. */
  boost(factor, seconds) {
    this.boostFactor = Math.max(this.boostFactor, factor);
    this.boostTime = Math.max(this.boostTime, seconds);
  }

  update(dt, move, map, effects) {
    if (this.boostTime > 0) {
      this.boostTime -= dt;
      if (this.boostTime <= 0) this.boostFactor = 1;
    }
    if (this.teleport > 0) this.teleport = Math.max(0, this.teleport - dt * 2.4);
    const speed = this.run.stats.moveSpeed * this.boostFactor;
    this.moving = Math.hypot(move.x, move.y) > 0.01;

    if (this.moving) {
      this.moveTime += dt;
      // Blickrichtung nach der dominanten Achse.
      if (Math.abs(move.x) > Math.abs(move.y) * 1.05) {
        this.facing = 'side';
        this.flip = move.x < 0;
      } else {
        this.facing = move.y < 0 ? 'back' : 'front';
      }
    }

    this.vx = move.x * speed;
    this.vy = move.y * speed;
    map.mask.moveEntity(this, this.vx * dt, this.vy * dt, this.rx, this.ry);

    if (this.moving && effects) {
      this._dustTimer = (this._dustTimer || 0) + dt;
      if (this._dustTimer > 0.09) {
        this._dustTimer = 0;
        effects.dust(this.x, this.y + this.footOffset * 0.35);
      }
      // Schritte im Takt der Laufgeschwindigkeit.
      Audio.playThrottled('run', 24000 / Math.max(60, speed), { volume: 0.5 });
    }

    if (this.hitFlash > 0) this.hitFlash = Math.max(0, this.hitFlash - dt * 4);
    if (this.invuln > 0) this.invuln = Math.max(0, this.invuln - dt);
    if (this.recoil > 0) this.recoil = Math.max(0, this.recoil - dt * 9);
    if (this.squash > 0) this.squash = Math.max(0, this.squash - dt * 5);

    const regen = this.run.stats.regen;
    if (regen > 0 && this.run.health < this.run.stats.maxHealth) {
      this.run.health = Math.min(this.run.stats.maxHealth, this.run.health + regen * dt);
    }
  }

  /** @returns tatsaechlich erlittener Schaden (0 = ausgewichen) */
  takeDamage(amount) {
    if (this.invuln > 0 || this.dead) return 0;
    const stats = this.run.stats;
    if (stats.dodge > 0 && Math.random() * 100 < stats.dodge) return -1; // -1 = Dodge

    let damage = Math.max(1, amount - stats.armor);
    if (this.run.shield > 0) {
      const absorbed = Math.min(this.run.shield, damage);
      this.run.shield -= absorbed;
      damage -= absorbed;
    }
    this.run.health -= damage;
    this.run.damageTaken += damage;
    this.hitFlash = 1;
    this.invuln = 0.25;
    this.squash = 1;
    if (this.run.health <= 0) {
      this.run.health = 0;
      this.dead = true;
    }
    return damage;
  }

  get healthRatio() {
    return clamp(this.run.health / this.run.stats.maxHealth, 0, 1);
  }
}
