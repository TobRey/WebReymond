import { Pool } from '../core/pool.js';
import { rand, TAU } from '../core/util.js';

/**
 * Kurzlebige Effekte: Partikel, Schadenszahlen, Einmal-Animationen.
 * Alles läuft über Pools und harte Obergrenzen, damit ein Bosskampf
 * die Framerate nicht kippt.
 */
const MAX_PARTICLES = 220;
const MAX_NUMBERS = 60;

export class Effects {
  constructor() {
    this.particles = new Pool(
      () => ({ x: 0, y: 0, vx: 0, vy: 0, life: 0, max: 1, size: 3, color: '#fff', drag: 0.9, alive: false, glow: false }),
      (p, o) => Object.assign(p, o),
      120,
    );
    this.numbers = new Pool(
      () => ({ x: 0, y: 0, vy: 0, life: 0, max: 1, text: '', crit: false, color: '#fff', alive: false }),
      (n, o) => Object.assign(n, o),
      40,
    );
    this.anims = new Pool(
      () => ({ x: 0, y: 0, sprite: null, time: 0, scale: 1, alive: false, rotation: 0, alpha: 1 }),
      (a, o) => Object.assign(a, o, { time: 0 }),
      12,
    );
  }

  clear() {
    this.particles.clear();
    this.numbers.clear();
    this.anims.clear();
  }

  burst(x, y, count, { color = '#ffd166', speed = 120, size = 3, life = 0.4, spread = TAU, angle = 0, glow = false } = {}) {
    const free = MAX_PARTICLES - this.particles.count;
    const n = Math.min(count, Math.max(0, free));
    for (let i = 0; i < n; i++) {
      const a = angle + rand(-spread / 2, spread / 2);
      const v = rand(speed * 0.45, speed);
      this.particles.spawn({
        x, y,
        vx: Math.cos(a) * v,
        vy: Math.sin(a) * v,
        life: life * rand(0.7, 1.25),
        max: life,
        size: size * rand(0.7, 1.3),
        color,
        drag: 0.86,
        glow,
      });
    }
  }

  /** Staubwoelkchen unter einer Bewegung. */
  dust(x, y) {
    if (this.particles.count > MAX_PARTICLES - 4) return;
    this.particles.spawn({
      x: x + rand(-6, 6), y: y + rand(-2, 2),
      vx: rand(-14, 14), vy: rand(-10, -2),
      life: 0.34, max: 0.34, size: rand(2, 4),
      color: 'rgba(196,186,160,0.55)', drag: 0.9, glow: false,
    });
  }

  number(x, y, text, { crit = false, color = '#ffffff' } = {}) {
    if (this.numbers.count >= MAX_NUMBERS) {
      // Aeltester Eintrag weicht - lieber aktuelle Treffer zeigen.
      this.numbers.active[0].alive = false;
      this.numbers.sweep();
    }
    this.numbers.spawn({
      x: x + rand(-6, 6), y,
      vy: crit ? -78 : -58,
      life: crit ? 0.85 : 0.65,
      max: crit ? 0.85 : 0.65,
      text, crit, color,
    });
  }

  animation(x, y, sprite, { scale = 1, rotation = 0, alpha = 1 } = {}) {
    if (!sprite) return null;
    return this.anims.spawn({ x, y, sprite, scale, rotation, alpha });
  }

  update(dt) {
    for (const p of this.particles.active) {
      p.life -= dt;
      if (p.life <= 0) {
        p.alive = false;
        continue;
      }
      const drag = Math.pow(p.drag, dt * 60);
      p.vx *= drag;
      p.vy *= drag;
      p.x += p.vx * dt;
      p.y += p.vy * dt;
    }
    this.particles.sweep();

    for (const n of this.numbers.active) {
      n.life -= dt;
      if (n.life <= 0) {
        n.alive = false;
        continue;
      }
      n.y += n.vy * dt;
      n.vy *= Math.pow(0.9, dt * 60);
    }
    this.numbers.sweep();

    for (const a of this.anims.active) {
      a.time += dt * 1000;
      if (!a.sprite.frameOnce(a.time)) a.alive = false;
    }
    this.anims.sweep();
  }

  drawParticles(ctx) {
    for (const p of this.particles.active) {
      const t = Math.max(0, p.life / p.max);
      ctx.globalAlpha = Math.min(1, t);
      ctx.fillStyle = p.color;
      const s = p.size * (0.5 + t * 0.5);
      ctx.fillRect(p.x - s / 2, p.y - s / 2, s, s);
    }
    ctx.globalAlpha = 1;
  }

  drawAnimations(ctx) {
    for (const a of this.anims.active) {
      const frame = a.sprite.frameOnce(a.time);
      if (!frame) continue;
      const w = a.sprite.width * a.scale;
      const h = a.sprite.height * a.scale;
      ctx.globalAlpha = a.alpha;
      if (a.rotation) {
        ctx.save();
        ctx.translate(a.x, a.y);
        ctx.rotate(a.rotation);
        ctx.drawImage(frame, -w / 2, -h / 2, w, h);
        ctx.restore();
      } else {
        ctx.drawImage(frame, a.x - w / 2, a.y - h / 2, w, h);
      }
    }
    ctx.globalAlpha = 1;
  }

  drawNumbers(ctx, zoom) {
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    for (const n of this.numbers.active) {
      const t = n.life / n.max;
      ctx.globalAlpha = Math.min(1, t * 1.6);
      const size = (n.crit ? 19 : 14) / zoom * 1.0;
      ctx.font = `700 ${size}px "Space Grotesk", system-ui, sans-serif`;
      ctx.lineWidth = 3 / zoom;
      ctx.strokeStyle = 'rgba(0,0,0,0.65)';
      ctx.strokeText(n.text, n.x, n.y);
      ctx.fillStyle = n.crit ? '#ffd166' : n.color;
      ctx.fillText(n.text, n.x, n.y);
    }
    ctx.globalAlpha = 1;
  }
}
