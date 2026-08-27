import { Assets } from '../gfx/assets.js';
import { TAU } from '../core/util.js';
import { Audio } from '../core/audio.js';

const BOMB_STATE = { FLYING: 0, ARMED: 1 };

/**
 * Bossverhalten: verfolgt den Spieler wie ein normaler Gegner und wirft
 * zusätzlich Bomben.
 *
 * Wichtig für die Fairness: Der Aufprall der Bombe macht keinen Schaden.
 * Erst die Explosion trifft - und nur, wer sich in diesem Moment im
 * sichtbaren Radius befindet.
 */
export class BossController {
  constructor(arena) {
    this.arena = arena;
    this.bombs = [];
    this.cooldown = 3;
    this.enraged = false;
  }

  reset() {
    this.bombs.length = 0;
    this.cooldown = 3;
    this.enraged = false;
  }

  get balance() {
    return this.arena.content.balance;
  }

  update(dt) {
    const boss = this.arena.boss;
    const player = this.arena.player;

    if (boss && boss.alive && !player.dead) {
      this.cooldown -= dt;
      if (this.cooldown <= 0) {
        this.throwBomb(boss, player);
        const base = this.balance.bossBombCooldown / (this.enraged ? 1.8 : 1);
        const scaled = base / Math.pow(1.06, this.arena.run.cycle - 1);
        this.cooldown = Math.max(this.balance.bossBombMinCooldown, scaled);
      }
    }

    for (let i = this.bombs.length - 1; i >= 0; i--) {
      const bomb = this.bombs[i];
      bomb.time += dt;

      if (bomb.state === BOMB_STATE.FLYING) {
        const t = Math.min(1, bomb.time / bomb.flight);
        bomb.x = bomb.fromX + (bomb.toX - bomb.fromX) * t;
        bomb.y = bomb.fromY + (bomb.toY - bomb.fromY) * t;
        bomb.height = Math.sin(t * Math.PI) * 90;
        if (t >= 1) {
          bomb.state = BOMB_STATE.ARMED;
          bomb.time = 0;
          bomb.height = 0;
          this.arena.effects.burst(bomb.x, bomb.y, 8, { color: 'rgba(190,180,150,0.8)', speed: 90, size: 3, life: 0.3 });
        }
        continue;
      }

      // Scharf: Warnkreis wächst, danach explodiert die Bombe.
      if (bomb.time >= bomb.delay) {
        this.explode(bomb);
        this.bombs.splice(i, 1);
      }
    }
  }

  throwBomb(boss, player) {
    const balance = this.balance;
    const flight = balance.bossBombFlightTime;
    // Auf die vorausberechnete Position werfen, damit Ausweichen sich lohnt.
    const toX = player.x + player.vx * flight * 0.55;
    const toY = player.y + player.vy * flight * 0.55;
    const map = this.arena.map;

    this.bombs.push({
      state: BOMB_STATE.FLYING,
      fromX: boss.x,
      fromY: boss.y,
      toX: Math.max(30, Math.min(map.width - 30, toX)),
      toY: Math.max(30, Math.min(map.height - 30, toY)),
      x: boss.x,
      y: boss.y,
      height: 0,
      time: 0,
      flight,
      delay: balance.bossBombDelay,
      radius: balance.bossBombRadius,
      damage: boss.damage * 1.6,
    });
    Audio.play('bossWarning');
  }

  explode(bomb) {
    const arena = this.arena;
    const player = arena.player;

    const sprite = Assets.get('assets/sprites/explosion1.gif') || Assets.get('assets/sprites/explosion.gif');
    if (sprite) {
      // Animation auf den Radius skalieren, damit Optik und Trefferzone passen.
      arena.effects.animation(bomb.x, bomb.y, sprite, {
        scale: (bomb.radius * 2.1) / Math.max(sprite.width, sprite.height),
      });
    }
    arena.effects.burst(bomb.x, bomb.y, 26, { color: '#ffb347', speed: 300, size: 5, life: 0.45 });
    arena.effects.burst(bomb.x, bomb.y, 14, { color: '#ff6b3d', speed: 180, size: 7, life: 0.6 });
    arena.camera.addShake(0.55);
    Audio.play('explosion');

    // Schaden exakt im sichtbaren Radius - kein unsichtbarer Treffer.
    const dx = player.x - bomb.x;
    const dy = player.y + player.footOffset * 0.3 - bomb.y;
    if (!player.dead && dx * dx + dy * dy <= bomb.radius * bomb.radius) {
      arena.damagePlayer(bomb.damage * arena.run.damageDifficulty);
    }
    // Gegner in der Nähe bekommen ebenfalls etwas ab.
    arena.enemies.inRadius(bomb.x, bomb.y, bomb.radius, (enemy) => {
      if (enemy.boss) return;
      arena.damageEnemy(enemy, bomb.damage * 0.35, false, 0, bomb.x, bomb.y);
    });
  }

  /** Warnkreise liegen unter den Figuren. */
  drawGround(ctx) {
    for (const bomb of this.bombs) {
      if (bomb.state !== BOMB_STATE.ARMED) continue;
      const t = Math.min(1, bomb.time / bomb.delay);
      ctx.save();
      ctx.globalAlpha = 0.16 + t * 0.22;
      ctx.fillStyle = '#ff5a4a';
      ctx.beginPath();
      ctx.arc(bomb.x, bomb.y, bomb.radius * (0.35 + t * 0.65), 0, TAU);
      ctx.fill();
      ctx.globalAlpha = 0.8;
      ctx.strokeStyle = t > 0.75 && Math.sin(bomb.time * 40) > 0 ? '#ffffff' : '#ff8b6b';
      ctx.lineWidth = 3;
      ctx.beginPath();
      ctx.arc(bomb.x, bomb.y, bomb.radius, 0, TAU);
      ctx.stroke();
      ctx.restore();
    }
  }

  /** Die Bomben selbst fliegen über den Figuren. */
  draw(ctx, time) {
    const sprite = Assets.get('assets/sprites/bombe1.gif');

    for (const bomb of this.bombs) {
      const drawY = bomb.y - bomb.height;
      if (bomb.height > 2) {
        ctx.save();
        ctx.globalAlpha = 0.3;
        ctx.fillStyle = '#000';
        ctx.beginPath();
        ctx.ellipse(bomb.x, bomb.y, 16, 7, 0, 0, TAU);
        ctx.fill();
        ctx.restore();
      }

      if (sprite) {
        const frame = sprite.frameAt(time * 1000 + bomb.time * 1000);
        const size = 54;
        const h = (sprite.height / sprite.width) * size;
        ctx.drawImage(frame, bomb.x - size / 2, drawY - h / 2, size, h);
      } else {
        // Neutraler Platzhalter, falls bombe1 fehlt.
        ctx.fillStyle = '#2b2b33';
        ctx.beginPath();
        ctx.arc(bomb.x, drawY, 15, 0, TAU);
        ctx.fill();
        ctx.fillStyle = '#ff6b3d';
        ctx.fillRect(bomb.x - 2, drawY - 22, 4, 8);
      }

      if (bomb.state === BOMB_STATE.ARMED && Math.sin(bomb.time * 26) > 0) {
        ctx.save();
        ctx.globalAlpha = 0.6;
        ctx.fillStyle = '#ff5a5a';
        ctx.beginPath();
        ctx.arc(bomb.x, drawY - 16, 4, 0, TAU);
        ctx.fill();
        ctx.restore();
      }
    }
  }
}
