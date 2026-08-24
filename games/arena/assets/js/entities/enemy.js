import { Pool } from '../core/pool.js';
import { SpatialHash } from '../core/spatial.js';
import { rand, TAU } from '../core/util.js';
import { FlowField } from '../world/flowfield.js';

/**
 * Gegnerverwaltung inklusive KI.
 *
 * Die Gegner laufen nicht stur auf den Spieler zu: Jeder hat einen
 * leichten eigenen Kurs (Wander-Offset), weicht Nachbarn aus (Separation)
 * und gleitet an Hindernissen entlang, statt davor stehen zu bleiben.
 */
export class EnemyManager {
  constructor(map) {
    this.map = map;
    this.hash = new SpatialHash(110);
    // Gemeinsame Wegfindung für alle Gegner.
    this.flow = new FlowField(map.mask);
    this._dir = { x: 0, y: 0 };
    this.pool = new Pool(
      () => ({
        alive: false, def: null, x: 0, y: 0, vx: 0, vy: 0, health: 1, maxHealth: 1,
        radius: 20, speed: 60, damage: 5, reward: 1, boss: false, hitFlash: 0,
        contactTimer: 0, knockX: 0, knockY: 0, flip: false, animOffset: 0,
        wander: 0, wanderTimer: 0, spawnTime: 0, sprite: null, scale: 64,
        contactCooldown: 0.8, deathTime: 0, hitboxOx: 0, hitboxOy: 0, id: 0,
        burnDps: 0, burnTime: 0, burnTick: 0, burnNumber: 0, burnPhase: 0,
        slowTime: 0, slowFactor: 1,
      }),
      (e, options) => {
        Object.assign(e, options);
        e.hitFlash = 0;
        e.contactTimer = 0;
        e.knockX = 0;
        e.knockY = 0;
        e.vx = 0;
        e.vy = 0;
        e.wander = rand(-1, 1);
        e.wanderTimer = rand(0, 2);
        e.spawnTime = 0;
        e.animOffset = rand(0, 1200);
        e.deathTime = 0;
        e.burnDps = 0;
        e.burnTime = 0;
        e.burnTick = 0;
        e.burnNumber = 0;
        e.burnPhase = rand(0, 900);
        e.slowTime = 0;
        e.slowFactor = 1;
      },
      40,
    );
    this._nextId = 1;
  }

  get list() {
    return this.pool.active;
  }

  get count() {
    return this.pool.count;
  }

  clear() {
    this.pool.clear();
  }

  spawn(def, sprite, x, y, scaling) {
    const hb = def.hitbox || {};
    return this.pool.spawn({
      id: this._nextId++,
      def,
      sprite,
      x, y,
      maxHealth: def.health * scaling.health,
      health: def.health * scaling.health,
      damage: def.damage * scaling.damage,
      speed: def.speed * scaling.speed,
      reward: def.reward * scaling.reward,
      radius: hb.r || 20,
      hitboxOx: hb.ox || 0,
      hitboxOy: hb.oy || 0,
      scale: def.scale || 64,
      boss: !!def.boss,
      contactCooldown: def.contactCooldown || 0.8,
    });
  }

  update(dt, player, time) {
    this.hash.rebuild(this.pool.active);
    const map = this.map;
    const flow = this.flow;
    const dir = this._dir;

    // Einmal pro Takt der kürzeste Weg zum Spieler - für alle zusammen.
    flow.update(dt, player.x, player.y + player.footOffset * 0.35);
    const nahDistanz = Math.max(map.mask.cellW, map.mask.cellH) * 2.5;

    for (const e of this.pool.active) {
      e.spawnTime += dt;
      if (e.hitFlash > 0) e.hitFlash = Math.max(0, e.hitFlash - dt * 5);

      // Ziel: Spieler, aber mit leichtem seitlichem Versatz.
      e.wanderTimer -= dt;
      if (e.wanderTimer <= 0) {
        e.wanderTimer = rand(0.6, 1.8);
        e.wander = rand(-0.55, 0.55);
      }

      let dx = player.x - e.x;
      let dy = player.y - e.y;
      const distance = Math.hypot(dx, dy) || 1;
      dx /= distance;
      dy /= distance;

      // Aus der Nähe direkt auf den Spieler zu, sonst dem kürzesten Weg
      // um die Wände folgen. Der Nahbereich verhindert, dass die Gegner
      // dicht vor dem Spieler noch am Zellenraster entlangzucken.
      if (distance > nahDistanz && flow.directionAt(e.x, e.y + e.hitboxOy, dir)) {
        dx = dir.x;
        dy = dir.y;
      }

      // Seitlicher Anteil sorgt für Bögen statt Ameisenstrasse.
      const wobble = e.wander * (e.boss ? 0.25 : 0.75);
      let vx = dx + -dy * wobble;
      let vy = dy + dx * wobble;

      // Separation: Nachbarn wegdruecken.
      let sepX = 0;
      let sepY = 0;
      const personal = e.radius * 1.9;
      this.hash.query(e.x, e.y, personal, (other) => {
        if (other === e || !other.alive) return;
        const ox = e.x - other.x;
        const oy = e.y - other.y;
        const d2 = ox * ox + oy * oy;
        const min = personal + other.radius * 0.6;
        if (d2 > 0.01 && d2 < min * min) {
          const d = Math.sqrt(d2);
          const push = (1 - d / min) / d;
          sepX += ox * push;
          sepY += oy * push;
        }
      });
      vx += sepX * 1.35;
      vy += sepY * 1.35;

      const len = Math.hypot(vx, vy) || 1;
      vx /= len;
      vy /= len;

      // Frost der Fähigkeit "Frost" bremst den Gegner vorübergehend.
      if (e.slowTime > 0) {
        e.slowTime -= dt;
        if (e.slowTime <= 0) e.slowFactor = 1;
      }
      const speed = e.speed * (e.slowTime > 0 ? e.slowFactor : 1);

      // Anfahren statt sofortige Richtungswechsel.
      const accel = 9;
      e.vx += (vx * speed - e.vx) * Math.min(1, accel * dt);
      e.vy += (vy * speed - e.vy) * Math.min(1, accel * dt);

      // Rückstoß klingt exponentiell ab.
      if (e.knockX || e.knockY) {
        const decay = Math.pow(0.0016, dt);
        e.knockX *= decay;
        e.knockY *= decay;
        if (Math.abs(e.knockX) < 1) e.knockX = 0;
        if (Math.abs(e.knockY) < 1) e.knockY = 0;
      }

      const stepX = (e.vx + e.knockX) * dt;
      const stepY = (e.vy + e.knockY) * dt;
      const ry = e.radius * 0.62;
      const movedX = map.mask.moveEntity(e, stepX, 0, e.radius * 0.75, ry);
      const movedY = map.mask.moveEntity(e, 0, stepY, e.radius * 0.75, ry);

      // Hängt der Gegner an einer Wand, seitlich daran entlanggleiten.
      if (!movedX && !movedY && (stepX || stepY)) {
        const side = e.wander >= 0 ? 1 : -1;
        map.mask.moveEntity(e, -dy * side * speed * dt, dx * side * speed * dt, e.radius * 0.75, ry);
        e.wander = -e.wander;
      }

      if (Math.abs(e.vx) > 6) e.flip = e.vx < 0;
      if (e.contactTimer > 0) e.contactTimer -= dt;
    }
  }

  /** Nächster lebender Gegner im Umkreis - für automatisches Zielen. */
  nearest(x, y, maxRange) {
    let best = null;
    let bestDist = maxRange * maxRange;
    for (const e of this.pool.active) {
      if (!e.alive || e.health <= 0) continue;
      const dx = e.x - x;
      const dy = e.y - y;
      const d2 = dx * dx + dy * dy;
      if (d2 < bestDist) {
        bestDist = d2;
        best = e;
      }
    }
    return best;
  }

  /** Alle Gegner in einem Radius. */
  inRadius(x, y, radius, fn) {
    this.hash.query(x, y, radius, (e) => {
      if (!e.alive || e.health <= 0) return;
      const dx = e.x - x;
      const dy = e.y - y;
      if (dx * dx + dy * dy <= (radius + e.radius) * (radius + e.radius)) fn(e);
    });
  }

  sweep() {
    this.pool.sweep();
  }
}

/** Positionsversatz der Hitbox gegenueber dem Sprite-Mittelpunkt. */
export function enemyHitboxY(e) {
  return e.y + e.hitboxOy;
}

export const ENEMY_ANIM_SPREAD = TAU;
