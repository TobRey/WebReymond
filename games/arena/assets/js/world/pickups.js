import { Pool } from '../core/pool.js';
import { rand, TAU } from '../core/util.js';

/**
 * Gegenstände, die auf der Karte liegen.
 *
 * Zwei Verhaltensweisen, beide über dieselbe Verwaltung:
 *
 * - **pickup** (Heiltrank): fliegt in Aufsammelreichweite entgegen und
 *   verschwindet beim Berühren.
 * - **chest** (Truhe): öffnet sich beim Berühren, zeigt das offene Bild und
 *   löst sich nach ein paar Sekunden auf. Sie fliegt nicht mit.
 *
 * Was ein Gegenstand bewirkt, steht in den Spieldaten - hier geht es nur
 * darum, wo er liegt und wann er verschwindet.
 */
export class Pickups {
  constructor(map) {
    this.map = map;
    this.pool = new Pool(
      () => ({
        alive: false, def: null, x: 0, y: 0, vx: 0, vy: 0,
        life: 0, maxLife: 1, bob: 0, magnet: false,
        sprite: null, openSprite: null, scale: 34,
        opened: false, openTimer: 0, glow: 0,
      }),
      (p, o) => Object.assign(p, o, {
        vx: 0, vy: 0, magnet: false, bob: rand(0, TAU),
        opened: false, openTimer: 0, glow: 0,
      }),
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

  /** Wie viele Stück eines bestimmten Gegenstands gerade liegen. */
  countOf(id) {
    let n = 0;
    for (const p of this.pool.active) {
      if (p.def && p.def.id === id) n++;
    }
    return n;
  }

  spawn(def, sprite, openSprite, x, y) {
    return this.pool.spawn({
      def, sprite, openSprite, x, y,
      scale: def.scale || 34,
      life: def.lifetime || 26,
      maxLife: def.lifetime || 26,
    });
  }

  /**
   * @param onCollect (pickup) => boolean - true, wenn der Gegenstand
   *        wirklich aufgenommen wurde. Sonst bleibt er liegen.
   */
  update(dt, player, pickupRange, onCollect) {
    for (const p of this.pool.active) {
      p.bob += dt * 3.2;

      // Geöffnete Truhe: nur noch der Countdown bis zum Verschwinden.
      if (p.opened) {
        p.openTimer -= dt;
        if (p.openTimer <= 0) p.alive = false;
        continue;
      }

      p.life -= dt;
      if (p.life <= 0) {
        p.alive = false;
        continue;
      }

      const dx = player.x - p.x;
      const dy = player.y + player.footOffset * 0.3 - p.y;
      const distance = Math.hypot(dx, dy) || 1;

      if (distance < 26) {
        if (!onCollect(p)) continue;
        if (p.def.mode === 'chest' && p.def.openTime > 0) {
          // Truhe bleibt offen stehen und verschwindet gleich.
          p.opened = true;
          p.openTimer = p.def.openTime;
          p.glow = 1;
        } else {
          p.alive = false;
        }
        continue;
      }

      // Truhen fliegen nicht mit - man geht zu ihnen hin.
      if (p.def.mode === 'chest') continue;

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
      const sprite = p.opened && p.openSprite ? p.openSprite : p.sprite;
      if (!sprite) continue;

      // Die letzten Sekunden blinkt der Gegenstand, bevor er verschwindet.
      if (!p.opened && p.life < 4 && Math.floor(p.life * 6) % 2 === 0) continue;

      const h = p.scale;
      const w = (sprite.width / sprite.height) * h;
      const lift = p.opened ? 0 : Math.sin(p.bob) * 3;

      ctx.save();
      ctx.globalAlpha = 0.24;
      ctx.fillStyle = '#000';
      ctx.beginPath();
      ctx.ellipse(p.x, p.y + h * 0.34, w * 0.3, h * 0.12, 0, 0, TAU);
      ctx.fill();
      ctx.restore();

      // Die geöffnete Truhe blendet zum Schluss aus.
      const rest = p.opened && p.def.openTime > 0 ? p.openTimer / p.def.openTime : 1;
      if (rest < 0.4) ctx.globalAlpha = Math.max(0, rest / 0.4);
      ctx.drawImage(sprite.frameAt(time * 1000), p.x - w / 2, p.y - h / 2 + lift, w, h);
      ctx.globalAlpha = 1;
    }
  }
}
