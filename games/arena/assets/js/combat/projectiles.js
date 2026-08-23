import { Pool } from '../core/pool.js';
import { TAU } from '../core/util.js';

/**
 * Projektile aller Art. Ein Pool, ein Update, ein Zeichendurchlauf.
 * Der Pfeil wird programmatisch gezeichnet, weil kein Pfeil-Sprite
 * mitgeliefert wurde (siehe README, fehlende Assets).
 */
export class Projectiles {
  constructor(map) {
    this.map = map;
    this.pool = new Pool(
      () => ({
        alive: false, x: 0, y: 0, vx: 0, vy: 0, angle: 0, speed: 0, damage: 0,
        crit: false, knockback: 0, travelled: 0, range: 0, pierce: 0, kind: 'bullet',
        sprite: null, hits: null, homing: 0, size: 16, life: 0, color: '#ffd166',
        aoeRadius: 0, fuse: 0, landing: 0, height: 0, targetX: 0, targetY: 0, owner: 'player',
        trail: 0,
      }),
      (p, options) => {
        Object.assign(p, options);
        p.travelled = 0;
        p.life = 0;
        p.trail = 0;
        if (!p.hits) p.hits = new Set();
        else p.hits.clear();
      },
      60,
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

  spawn(options) {
    return this.pool.spawn(options);
  }

  /**
   * @param onEnemyHit (enemy, projectile) => boolean  true = Projektil verbraucht
   * @param onExpire   (projectile) => void            z. B. Granatenexplosion
   */
  update(dt, enemies, onEnemyHit, onExpire, player, onPlayerHit) {
    for (const p of this.pool.active) {
      p.life += dt;

      // Granaten fliegen auf einen Punkt und explodieren dort.
      if (p.kind === 'grenade') {
        p.x += p.vx * dt;
        p.y += p.vy * dt;
        p.landing -= dt;
        // Wurfbogen rein visuell.
        p.height = Math.max(0, Math.sin(Math.min(1, 1 - p.landing / p.flightTime) * Math.PI) * 46);
        if (p.landing <= 0) {
          p.fuse -= dt;
          p.vx = 0;
          p.vy = 0;
          p.height = 0;
          if (p.fuse <= 0) {
            p.alive = false;
            onExpire(p);
          }
        }
        continue;
      }

      if (p.homing > 0) {
        const target = enemies.nearest(p.x, p.y, 320);
        if (target) {
          const desired = Math.atan2(target.y - target.radius * 0.2 - p.y, target.x - p.x);
          let delta = (desired - p.angle) % TAU;
          if (delta > Math.PI) delta -= TAU;
          if (delta < -Math.PI) delta += TAU;
          p.angle += Math.max(-p.homing * dt, Math.min(p.homing * dt, delta));
          p.vx = Math.cos(p.angle) * p.speed;
          p.vy = Math.sin(p.angle) * p.speed;
        }
      }

      const stepX = p.vx * dt;
      const stepY = p.vy * dt;
      p.x += stepX;
      p.y += stepY;
      p.travelled += Math.hypot(stepX, stepY);

      if (p.travelled > p.range) {
        p.alive = false;
        onExpire(p);
        continue;
      }
      if (this.map.mask.blockedAt(p.x, p.y)) {
        p.alive = false;
        onExpire(p);
        continue;
      }

      if (p.owner === 'player') {
        let consumed = false;
        enemies.inRadius(p.x, p.y, 6, (enemy) => {
          if (consumed || p.hits.has(enemy.id)) return;
          p.hits.add(enemy.id);
          if (onEnemyHit(enemy, p)) {
            if (p.pierce > 0) {
              p.pierce--;
            } else {
              consumed = true;
            }
          }
        });
        if (consumed) {
          p.alive = false;
          onExpire(p);
        }
      } else if (player && !player.dead) {
        const dx = player.x - p.x;
        const dy = player.y + player.footOffset * 0.4 - p.y;
        if (dx * dx + dy * dy < 20 * 20) {
          p.alive = false;
          onPlayerHit(p);
        }
      }
    }
    this.pool.sweep();
  }

  draw(ctx, time) {
    for (const p of this.pool.active) {
      const drawY = p.y - (p.height || 0);

      const size = p.size || 16;

      if (p.kind === 'arrow') {
        drawArrow(ctx, p.x, drawY, p.angle, size / 16);
        continue;
      }
      if (p.kind === 'magic') {
        drawMagic(ctx, p.x, drawY, p.life, size / 16);
        continue;
      }

      const sprite = p.sprite;
      if (!sprite) {
        ctx.fillStyle = p.color;
        ctx.fillRect(p.x - size / 4, drawY - size / 4, size / 2, size / 2);
        continue;
      }
      const frame = sprite.frameAt(time * 1000 + p.life * 1000);
      const h = size;
      const w = (sprite.width / sprite.height) * h;
      ctx.save();
      ctx.translate(p.x, drawY);
      ctx.rotate(p.angle);
      if (p.kind === 'grenade' && p.height > 0) {
        ctx.rotate(p.life * 8);
      }
      ctx.drawImage(frame, -w / 2, -h / 2, w, h);
      ctx.restore();

      if (p.kind === 'grenade' && p.landing <= 0) {
        // Blinkender Zuender kurz vor der Explosion.
        const blink = Math.sin(p.life * 26) > 0;
        if (blink) {
          ctx.globalAlpha = 0.5;
          ctx.fillStyle = '#ff5a5a';
          ctx.beginPath();
          ctx.arc(p.x, p.y, 7, 0, TAU);
          ctx.fill();
          ctx.globalAlpha = 1;
        }
      }
    }
  }
}

/** Pfeil ohne Bilddatei: schlanker Schaft mit Spitze und Federn. */
function drawArrow(ctx, x, y, angle, scale = 1) {
  ctx.save();
  ctx.translate(x, y);
  ctx.rotate(angle);
  ctx.scale(scale, scale);
  ctx.fillStyle = '#d9c8a3';
  ctx.fillRect(-13, -1.5, 22, 3);
  ctx.fillStyle = '#e8eef7';
  ctx.beginPath();
  ctx.moveTo(15, 0);
  ctx.lineTo(7, -4.5);
  ctx.lineTo(7, 4.5);
  ctx.closePath();
  ctx.fill();
  ctx.fillStyle = '#7fd6c2';
  ctx.fillRect(-14, -4, 4, 8);
  ctx.restore();
}

function drawMagic(ctx, x, y, life, scale = 1) {
  const pulse = (5.5 + Math.sin(life * 22) * 1.2) * scale;
  ctx.globalAlpha = 0.35;
  ctx.fillStyle = '#8b7bff';
  ctx.beginPath();
  ctx.arc(x, y, pulse * 2.1, 0, TAU);
  ctx.fill();
  ctx.globalAlpha = 1;
  ctx.fillStyle = '#d9d2ff';
  ctx.beginPath();
  ctx.arc(x, y, pulse, 0, TAU);
  ctx.fill();
  ctx.fillStyle = '#ffffff';
  ctx.beginPath();
  ctx.arc(x - 1, y - 1, pulse * 0.45, 0, TAU);
  ctx.fill();
}
