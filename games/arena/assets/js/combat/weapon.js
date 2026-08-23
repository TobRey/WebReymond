import { Assets } from '../gfx/assets.js';
import { rand } from '../core/util.js';
import { Audio } from '../core/audio.js';

/**
 * Waffensteuerung: sucht automatisch ein Ziel und fuehrt je nach
 * Waffentyp das passende Angriffsverhalten aus.
 *
 * Die sechs Verhalten (PROJECTILE, MAGIC, MELEE_ARC, MELEE_360, THRUST,
 * GRENADE) sind bewusst generisch - eine neue Waffe aus dem Admin braucht
 * damit keinen neuen Code, nur Werte.
 */
export class WeaponController {
  constructor(arena) {
    this.arena = arena;
    this.cooldown = 0;
    this.aim = 0;
    this.target = null;
  }

  reset() {
    this.cooldown = 0;
    this.target = null;
  }

  update(dt) {
    const { run, player, enemies } = this.arena;
    if (player.dead) return;

    const weapon = run.weaponStats;
    this.cooldown -= dt;

    const searchRange = weapon.range + 40;
    const target = enemies.nearest(player.x, player.y + player.footOffset * 0.2, searchRange);
    this.target = target;
    if (target) {
      this.aim = Math.atan2(target.y - (player.y + player.footOffset * 0.2), target.x - player.x);
    }

    if (this.cooldown > 0 || !target) return;
    this.cooldown = weapon.cooldown;
    this.fire(weapon, target);
  }

  fire(weapon, target) {
    const { player, effects, camera, run } = this.arena;
    const originX = player.x;
    const originY = player.y + player.footOffset * 0.15;

    switch (weapon.type) {
      case 'MELEE_ARC':
      case 'MELEE_360': {
        const full = weapon.type === 'MELEE_360';
        this.arena.melee.spawn({
          type: 'arc',
          x: originX,
          y: originY,
          angle: full ? this.aim : this.aim,
          arc: full ? 360 : weapon.arc,
          range: weapon.range,
          damage: weapon.damage,
          knockback: weapon.knockback,
          critChance: weapon.critChance,
          critDamage: weapon.critDamage,
          duration: Math.min(0.42, Math.max(0.14, weapon.cooldown * 0.6)),
          spriteScale: weapon.spriteScale,
          sprite: Assets.get(weapon.sprite),
        });
        player.recoil = 0.4;
        Audio.playFirst(['weapon:' + weapon.id, 'melee']);
        break;
      }

      case 'THRUST': {
        this.arena.melee.spawn({
          type: 'thrust',
          x: originX,
          y: originY,
          angle: this.aim,
          range: weapon.range,
          width: 44,
          damage: weapon.damage,
          knockback: weapon.knockback,
          critChance: weapon.critChance,
          critDamage: weapon.critDamage,
          duration: Math.min(0.36, Math.max(0.16, weapon.cooldown * 0.55)),
          spriteScale: weapon.spriteScale,
          sprite: Assets.get(weapon.sprite),
        });
        player.recoil = 0.5;
        Audio.playFirst(['weapon:' + weapon.id, 'melee']);
        break;
      }

      case 'GRENADE': {
        const distance = Math.hypot(target.x - originX, target.y - originY);
        const flight = Math.max(0.25, Math.min(1.1, distance / Math.max(60, weapon.projectileSpeed)));
        const p = this.arena.projectiles.spawn({
          kind: 'grenade',
          x: originX,
          y: originY,
          angle: this.aim,
          vx: (target.x - originX) / flight,
          vy: (target.y - originY) / flight,
          speed: weapon.projectileSpeed,
          damage: weapon.damage,
          knockback: weapon.knockback,
          range: 99999,
          pierce: 0,
          size: weapon.projectileSize || 34,
          sprite: Assets.get(weapon.sprite),
          aoeRadius: weapon.aoeRadius || 120,
          landing: flight,
          fuse: 0.65,
          height: 0,
          owner: 'player',
          crit: Math.random() * 100 < weapon.critChance,
          critDamage: weapon.critDamage,
        });
        p.flightTime = flight;
        player.recoil = 0.6;
        Audio.playFirst(['weapon:' + weapon.id, 'shoot']);
        break;
      }

      case 'MAGIC': {
        this.spawnShot(weapon, originX, originY, this.aim, 'magic', 5.2);
        Audio.playFirst(['weapon:' + weapon.id, 'shoot']);
        break;
      }

      case 'PROJECTILE':
      default: {
        const lead = this.leadAngle(target, originX, originY, weapon.projectileSpeed);
        const spread = weapon.spread ? rand(-weapon.spread, weapon.spread) * (Math.PI / 180) : 0;
        const kind = weapon.projectile === 'pfeil' ? 'arrow' : 'bullet';
        this.spawnShot(weapon, originX, originY, lead + spread, kind, 0);
        Audio.playFirst(['weapon:' + weapon.id, 'shoot']);
        break;
      }
    }

    player.recoil = Math.max(player.recoil, Math.min(1, (weapon.recoil || 6) / 12));
    effects.burst(
      originX + Math.cos(this.aim) * 22,
      originY + Math.sin(this.aim) * 22,
      weapon.type === 'PROJECTILE' ? 3 : 0,
      { color: '#ffe6a3', speed: 90, size: 2, life: 0.16, spread: 0.8, angle: this.aim },
    );
    if ((weapon.recoil || 0) >= 12) camera.addShake(0.05);
    run.shotsFired = (run.shotsFired || 0) + 1;
  }

  spawnShot(weapon, x, y, angle, kind, homing) {
    const spriteName = kind === 'bullet' ? weapon.projectile : null;
    const sprite = spriteName ? Assets.get('assets/sprites/' + spriteName + '.png') : null;
    this.arena.projectiles.spawn({
      kind,
      x: x + Math.cos(angle) * 18,
      y: y + Math.sin(angle) * 18,
      angle,
      vx: Math.cos(angle) * weapon.projectileSpeed,
      vy: Math.sin(angle) * weapon.projectileSpeed,
      speed: weapon.projectileSpeed,
      damage: weapon.damage,
      knockback: weapon.knockback,
      range: weapon.range,
      pierce: weapon.pierce || 0,
      sprite,
      size: weapon.projectileSize || 16,
      homing,
      aoeRadius: 0,
      owner: 'player',
      crit: Math.random() * 100 < weapon.critChance,
      critDamage: weapon.critDamage,
    });
  }

  /** Einfache Vorhaltekorrektur, damit langsame Projektile nicht hinterherfliegen. */
  leadAngle(target, x, y, speed) {
    if (!speed) return Math.atan2(target.y - y, target.x - x);
    const distance = Math.hypot(target.x - x, target.y - y);
    const t = Math.min(0.5, distance / speed);
    return Math.atan2(target.y + target.vy * t - y, target.x + target.vx * t - x);
  }
}
