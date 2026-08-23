import { Pool } from '../core/pool.js';
import { TAU } from '../core/util.js';

/**
 * Nahkampfangriffe: Bogen-Schwung (Schwert, Dolch), volle Drehung (Axt)
 * und Stoss (Speer).
 *
 * Jeder Angriff fuehrt eine eigene Trefferliste. Dadurch kann eine
 * Axtdrehung jeden Gegner genau einmal treffen, egal wie viele Frames
 * die Klinge ueber ihm steht.
 */
export class MeleeAttacks {
  constructor() {
    this.pool = new Pool(
      () => ({
        alive: false, type: 'arc', x: 0, y: 0, angle: 0, arc: 90, range: 120,
        damage: 0, knockback: 0, critChance: 0, critDamage: 0, duration: 0.25,
        time: 0, hits: null, sprite: null, width: 46, sweptFrom: 0, sweptTo: 0,
      }),
      (a, options) => {
        Object.assign(a, options);
        a.time = 0;
        if (!a.hits) a.hits = new Set();
        else a.hits.clear();
        a.sweptFrom = a.angle - (a.arc * Math.PI) / 360;
        a.sweptTo = a.sweptFrom;
      },
      8,
    );
  }

  get list() {
    return this.pool.active;
  }

  clear() {
    this.pool.clear();
  }

  spawn(options) {
    return this.pool.spawn(options);
  }

  /** @param onHit (enemy, attack) => void */
  update(dt, player, enemies, onHit) {
    for (const a of this.pool.active) {
      a.time += dt;
      a.x = player.x;
      a.y = player.y + player.footOffset * 0.1;
      const t = Math.min(1, a.time / a.duration);

      if (a.type === 'thrust') {
        // Erst schnell raus, dann zurueck - Treffer nur in der Ausfahrphase.
        const reach = t < 0.45 ? (t / 0.45) * a.range : (1 - (t - 0.45) / 0.55) * a.range;
        a.reach = reach;
        if (t < 0.6) {
          const dirX = Math.cos(a.angle);
          const dirY = Math.sin(a.angle);
          enemies.inRadius(a.x + dirX * reach * 0.5, a.y + dirY * reach * 0.5, reach * 0.6 + 30, (enemy) => {
            if (a.hits.has(enemy.id)) return;
            const ex = enemy.x - a.x;
            const ey = enemy.y - a.y;
            const along = ex * dirX + ey * dirY;
            const side = Math.abs(-ex * dirY + ey * dirX);
            if (along > -10 && along < reach + enemy.radius * 0.6 && side < a.width / 2 + enemy.radius * 0.7) {
              a.hits.add(enemy.id);
              onHit(enemy, a);
            }
          });
        }
      } else {
        // Schwung: Winkel wandert ueber die Angriffsdauer.
        const span = (a.arc * Math.PI) / 180;
        const from = a.angle - span / 2;
        const current = from + span * t;
        a.sweptTo = current;
        a.current = current;

        // Ueberstrichener Winkel als fortlaufender Wert 0..span - nicht als
        // Differenz, sonst springt die Rechnung bei einer vollen Drehung um.
        const swept = span * t;

        enemies.inRadius(a.x, a.y, a.range + 40, (enemy) => {
          if (a.hits.has(enemy.id)) return;
          const dx = enemy.x - a.x;
          const dy = enemy.y - a.y;
          const distance = Math.hypot(dx, dy);
          if (distance > a.range + enemy.radius * 0.8) return;

          // Winkel des Gegners relativ zum Schwungbeginn, normiert auf 0..2PI.
          let passed = Math.atan2(dy, dx) - from;
          passed %= TAU;
          if (passed < 0) passed += TAU;

          const tolerance = Math.atan2(enemy.radius * 0.8, Math.max(20, distance));
          // Kurz vor dem Start liegende Gegner (passed nahe 2PI) mitnehmen.
          if (passed > TAU - tolerance) passed -= TAU;

          if (passed <= swept + tolerance && passed <= span + tolerance) {
            a.hits.add(enemy.id);
            onHit(enemy, a);
          }
        });
      }

      if (a.time >= a.duration) a.alive = false;
    }
    this.pool.sweep();
  }

  draw(ctx) {
    for (const a of this.pool.active) {
      const t = Math.min(1, a.time / a.duration);

      // Sprite-Laenge aus den Waffendaten, sonst an der Reichweite orientiert.
      const length = a.spriteScale || a.range * 0.9;

      if (a.type === 'thrust') {
        const reach = a.reach || 0;
        drawWeapon(ctx, a.sprite, a.x + Math.cos(a.angle) * reach * 0.62,
          a.y + Math.sin(a.angle) * reach * 0.62, a.angle, length);
        continue;
      }

      const span = (a.arc * Math.PI) / 180;
      const from = a.angle - span / 2;
      const current = from + span * t;

      // Schwungspur
      ctx.save();
      ctx.globalAlpha = 0.20 * (1 - t * 0.55);
      ctx.fillStyle = '#dfe7ff';
      ctx.beginPath();
      ctx.moveTo(a.x, a.y);
      ctx.arc(a.x, a.y, a.range, Math.max(from, current - span * 0.55), current);
      ctx.closePath();
      ctx.fill();
      ctx.restore();

      drawWeapon(ctx, a.sprite, a.x + Math.cos(current) * a.range * 0.66,
        a.y + Math.sin(current) * a.range * 0.66, current, length);
    }
  }
}

function drawWeapon(ctx, sprite, x, y, angle, length) {
  if (!sprite) return;
  const frame = sprite.frameAt(0);
  const w = length;
  const h = (sprite.height / sprite.width) * length;
  ctx.save();
  ctx.translate(x, y);
  ctx.rotate(angle);
  ctx.drawImage(frame, -w / 2, -h / 2, w, h);
  ctx.restore();
}
