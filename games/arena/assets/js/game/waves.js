import { Assets } from '../gfx/assets.js';
import { findSpawnPoint } from '../world/spawn.js';
import { weighted } from '../core/util.js';

/**
 * Wellen und Zyklen.
 *
 * Ein Zyklus besteht aus drei normalen Wellen und einem Bosskampf.
 * Nach jeder Welle pausiert das Spiel für die Upgrade-Auswahl.
 * Danach beginnt der nächste Zyklus mit hoeheren Werten.
 */
export const WAVE_STATE = {
  RUNNING: 'running',
  INTERMISSION: 'intermission',
  FINISHED: 'finished',
};

export class WaveController {
  constructor(arena) {
    this.arena = arena;
    this.state = WAVE_STATE.RUNNING;
    this.timeLeft = 0;
    this.spawnAccumulator = 0;
    this.bossActive = false;
    this.bossEnraged = false;
    this.pendingUpgrade = false;
  }

  get run() {
    return this.arena.run;
  }

  get balance() {
    return this.arena.content.balance;
  }

  get isBossWave() {
    return this.run.wave === 4;
  }

  /** Skalierungsfaktoren des aktuellen Zyklus. */
  scaling() {
    const run = this.run;
    return {
      health: run.difficulty,
      damage: run.damageDifficulty,
      speed: run.speedDifficulty,
      reward: run.rewardDifficulty,
    };
  }

  startWave() {
    this.state = WAVE_STATE.RUNNING;
    this.spawnAccumulator = 0;
    this.bossEnraged = false;
    this.timeLeft = this.isBossWave ? this.balance.bossDuration : this.balance.waveDuration;

    if (this.isBossWave) {
      this.arena.startBossIntro();
    }
    this.arena.emit('waveStart', { wave: this.run.wave, cycle: this.run.cycle, boss: this.isBossWave });
  }

  update(dt) {
    if (this.state !== WAVE_STATE.RUNNING) return;

    this.timeLeft -= dt;

    if (this.isBossWave) {
      this.updateBossWave(dt);
      return;
    }

    if (this.timeLeft <= 0) {
      this.endWave();
      return;
    }
    this.spawnTick(dt, 1);
  }

  updateBossWave(dt) {
    // Ein leichter Nachschub normaler Gegner haelt den Druck hoch.
    this.spawnTick(dt, 0.3);

    if (this.timeLeft <= 0 && !this.bossEnraged) {
      // Zeit abgelaufen: Der Boss wird wütend statt einfach zu verschwinden.
      this.bossEnraged = true;
      this.arena.enrageBoss();
    }
    if (!this.arena.boss || !this.arena.boss.alive) {
      if (this.arena.bossDefeated) this.endWave();
    }
  }

  spawnTick(dt, factor) {
    const balance = this.balance;
    const enemies = this.arena.enemies;
    if (enemies.count >= balance.maxEnemies) return;

    const rate = balance.enemySpawnRate
      * Math.pow(balance.spawnRateScaling, this.run.cycle - 1)
      * (1 + (this.run.wave - 1) * 0.16)
      * factor;

    this.spawnAccumulator += rate * dt;
    let budget = 4;
    while (this.spawnAccumulator >= 1 && budget-- > 0 && enemies.count < balance.maxEnemies) {
      this.spawnAccumulator -= 1;
      this.spawnEnemy();
    }
  }

  spawnEnemy() {
    const def = this.pickEnemy();
    if (!def) return;
    const sprite = Assets.get(def.sprite);
    const radius = (def.hitbox && def.hitbox.r) || 20;
    const point = findSpawnPoint(this.arena.map, this.arena.camera, radius);
    const enemy = this.arena.enemies.spawn(def, sprite, point.x, point.y, this.scaling());
    this.arena.effects.burst(enemy.x, enemy.y, 6, { color: 'rgba(120,140,190,0.7)', speed: 70, size: 3, life: 0.3 });
  }

  /** Welle 1-3 bevorzugt den zugeordneten Gegner, sonst gewichteter Zufall. */
  pickEnemy() {
    const pool = this.arena.content.enemies.filter((e) => !e.boss && e.spawnWeight > 0);
    if (!pool.length) return null;
    const forWave = pool.filter((e) => e.wave === this.run.wave);
    if (forWave.length) return weighted(forWave, (e) => e.spawnWeight);
    // Bosswelle oder unbelegte Welle: alles bisher Freigeschaltete.
    const unlocked = pool.filter((e) => !e.wave || e.wave <= Math.max(1, this.run.wave));
    return weighted(unlocked.length ? unlocked : pool, (e) => e.spawnWeight);
  }

  endWave() {
    this.state = WAVE_STATE.INTERMISSION;
    // Jede geschaffte Welle ist später genau ein Erfahrungspunkt.
    this.run.wavesCleared = (this.run.wavesCleared || 0) + 1;
    this.arena.emit('waveEnd', {
      wave: this.run.wave,
      cycle: this.run.cycle,
      boss: this.isBossWave,
    });
  }

  /** Wird nach der Upgrade-Auswahl aufgerufen. */
  advance() {
    const run = this.run;
    run.waveIndex++;
    if (run.wave >= 4) {
      run.wave = 1;
      run.cycle++;
    } else {
      run.wave++;
    }
    this.arena.bossDefeated = false;
    this.startWave();
  }
}
