import { Pool } from '../core/pool.js';
import { rand, TAU } from '../core/util.js';

/**
 * Einsammelbare Gegenstände auf der Karte - aktuell die Heilflasche.
 *
 * Flaschen erscheinen selten und in der Nähe des Spielers, damit man sie
 * überhaupt findet. Kommt man in Aufsammelreichweite, fliegen sie einem
 * entgegen; eingesammelt werden sie erst bei Berührung. Wer volles Leben
 * hat, lässt sie liegen - so verschenkt man keine Heilung.
 */
export class Pickups {
  constructor(map) {
    this.map = map;
    this.pool = new Pool(
      () => ({
        alive: false, kind: 'heal', x: 0, y: 0, vx: 0, vy: 0,
        life: 0, maxLife: 1, bob: 0, magnet: false, sprite: null, scale: 34,
      }),
      (p, o) => Object.assign(p, o, { vx: 0, vy: 0, magnet: false, bob: rand(0, TAU) }),
      8,
    );
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

  spawn(x, y, sprite, { kind = 'heal', life = 26, scale = 34 } = {}) {
    return this.pool.spawn({ kind, x, y, sprite, scale, life, maxLife: life });
  }

  /**
   * @param onCollect (pickup) => boolean - true, wenn der Gegenstand
   *        wirklich aufgenommen wurde. Sonst bleibt er liegen.
   */
  update(dt, player, pickupRange, onCollect) {
    for (const p of this.pool.active) {
      p.life -= dt;
      if (p.life <= 0) {
        p.alive = false;
        continue;
      }
      p.bob += dt * 3.2;

      const dx = player.x - p.x;
      const dy = player.y + player.footOffset * 0.3 - p.y;
      const distance = Math.hypot(dx, dy) || 1;

      if (distance < 26) {
        if (onCollect(p)) p.alive = false;
        continue;
      }

      // Innerhalb der Aufsammelreichweite zieht es die Flasche zum Spieler.
      if (distance < pickupRange) {
        p.magnet = true;
        const pull = 190 + (1 - distance / pickupRange) * 320;
        p.vx += (dx / distance) * pull * dt * 4;
        p.vy += (dy / distance) * pull * dt * 4;
        const drag = Math.pow(0.02, dt);
        p.vx *= drag;
        p.vy *= drag;
        p.x += p.vx * dt;
        p.y += p.vy * dt;
      } else {
        p.magnet = false;
      }
    }
    this.pool.sweep();
  }

  draw(ctx, time) {
    for (const p of this.pool.active) {
      const sprite = p.sprite;
      if (!sprite) continue;

      // Die letzten Sekunden blinkt die Flasche, bevor sie verschwindet.
      if (p.life < 4 && Math.floor(p.life * 6) % 2 === 0) continue;

      const h = p.scale;
      const w = (sprite.width / sprite.height) * h;
      const lift = Math.sin(p.bob) * 3;

      ctx.save();
      ctx.globalAlpha = 0.24;
      ctx.fillStyle = '#000';
      ctx.beginPath();
      ctx.ellipse(p.x, p.y + h * 0.34, w * 0.3, h * 0.12, 0, 0, TAU);
      ctx.fill();
      ctx.restore();

      ctx.drawImage(sprite.frameAt(time * 1000), p.x - w / 2, p.y - h / 2 + lift, w, h);
    }
  }
}
