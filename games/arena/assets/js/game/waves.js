import { Assets } from '../gfx/assets.js';
import { findSpawnPoint } from '../world/spawn.js';
import { weighted } from '../core/util.js';
import { Audio } from '../core/audio.js';
import { onEffectWaveStart, effectEnemyHealth } from './effects.js';

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
    // Zwischen Countdown und Wellenbeginn steht die Welle still.
    this.waiting = false;
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
      health: run.difficulty * effectEnemyHealth(run),
      damage: run.damageDifficulty,
      speed: run.speedDifficulty,
      reward: run.rewardDifficulty,
    };
  }

  startWave() {
    this.state = WAVE_STATE.RUNNING;
    this.spawnAccumulator = 0;
    this.bossEnraged = false;

    // Fähigkeit "Wächter": Schild ist zu jeder Welle wieder voll.
    if (this.run.perk === 'guardian') this.run.shield = this.run.stats.maxShield;
    // Fähigkeit "Zweiter Atem" steht einmal je Welle bereit.
    if (this.run.perk === 'secondWind') this.run.secondWindReady = true;
    this.timeLeft = this.isBossWave ? this.balance.bossDuration : this.balance.waveDuration;

    // Karten mit Wellenwirkung: heilen, kosten, wuerfeln.
    const meldungen = onEffectWaveStart(this.run);
    for (const text of meldungen) this.arena.emit('effectNote', text);

    // Die Welle ist vorbereitet, laeuft aber erst nach dem Countdown los.
    this.waiting = true;
    this.waitTime = 0;
    this.arena.emit('waveStart', { wave: this.run.wave, cycle: this.run.cycle, boss: this.isBossWave });
  }

  /**
   * Gibt die vorbereitete Welle frei - nach dem Countdown.
   *
   * Erst hier erscheinen Boss und Starttruppe, damit waehrend der drei
   * Sekunden Vorlauf niemand von hinten angreift.
   */
  beginSpawning() {
    if (!this.waiting) return;
    this.waiting = false;
    this.waitTime = 0;

    if (this.isBossWave) this.arena.startBossIntro();

    // Nach der Upgrade-Auswahl ist das Feld leergeräumt. Ohne Starthilfe
    // stünde man ein paar Sekunden allein herum, bis der erste Gegner
    // hereingelaufen kommt.
    const start = this.balance.waveStartEnemies ?? 4;
    const fehlend = Math.min(
      start - this.arena.enemies.countAlive,
      this.balance.maxEnemies - this.arena.enemies.countAlive,
    );
    for (let i = 0; i < fehlend; i++) this.spawnEnemy();
  }

  update(dt) {
    if (this.state !== WAVE_STATE.RUNNING) return;

    // Sicherheitsnetz: Laeuft das Spiel, obwohl die Welle noch auf ihren
    // Countdown wartet, wird sie nach kurzer Zeit von selbst freigegeben.
    // Ohne das koennte eine Welle haengen bleiben, wenn der Countdown
    // unterbrochen wird.
    if (this.waiting) {
      this.waitTime = (this.waitTime || 0) + dt;
      if (this.waitTime > 1.5) this.beginSpawning();
      return;
    }

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
    if (enemies.countAlive >= balance.maxEnemies) return;

    const rate = balance.enemySpawnRate
      * Math.pow(balance.spawnRateScaling, this.run.cycle - 1)
      * (1 + (this.run.wave - 1) * 0.16)
      * factor;

    this.spawnAccumulator += rate * dt;
    let budget = 4;
    while (this.spawnAccumulator >= 1 && budget-- > 0 && enemies.countAlive < balance.maxEnemies) {
      this.spawnAccumulator -= 1;
      this.spawnEnemy();
    }
  }

  spawnEnemy() {
    const def = this.pickEnemy();
    if (!def) return;
    let sprite = Assets.get(def.sprite);
    if (!sprite) {
      // Sprite lädt noch im Hintergrund - diesen Spawn auslassen und
      // das Laden anstoßen, der nächste Versuch klappt dann.
      Assets.load(def.sprite);
      return;
    }
    const radius = (def.hitbox && def.hitbox.r) || 20;
    const point = findSpawnPoint(this.arena.map, this.arena.camera, radius);
    const enemy = this.arena.enemies.spawn(def, sprite, point.x, point.y, this.scaling());
    this.arena.effects.burst(enemy.x, enemy.y, 6, { color: 'rgba(120,140,190,0.7)', speed: 70, size: 3, life: 0.3 });
  }

  /** Welle 1-3 bevorzugt den zugeordneten Gegner, sonst gewichteter Zufall. */
  /**
   * Wer in dieser Welle erscheint.
   *
   * Früher kam ausschliesslich der Gegnertyp der jeweiligen Welle. Das hatte
   * zwei unschöne Folgen: In Welle 2 starben die Gegner aus Welle 1 einfach
   * aus, und nach dem Boss fing Zyklus 2 wieder nur mit dem schwächsten
   * Gegner an - es wirkte, als würde das Spiel von vorne beginnen.
   *
   * Jetzt bleibt alles im Spiel, was schon aufgetreten ist. Der Typ der
   * aktuellen Welle führt, die übrigen kommen mit einem Bruchteil ihres
   * Gewichts dazu (Balancing: "Anteil älterer Gegner"). Ab Zyklus 2 sind
   * ohnehin alle bekannt, dann mischt jede Welle das gesamte Feld.
   */
  pickEnemy() {
    const pool = this.arena.content.enemies.filter((e) => !e.boss && e.spawnWeight > 0);
    if (!pool.length) return null;

    const wave = this.run.wave;
    const alleBekannt = this.run.cycle > 1 || this.isBossWave;
    const bekannt = alleBekannt
      ? pool
      : pool.filter((e) => !e.wave || e.wave <= Math.max(1, wave));
    const auswahl = bekannt.length ? bekannt : pool;

    const anteil = this.balance.waveMixShare ?? 0.45;
    return weighted(auswahl, (e) => {
      // Der Gegner dieser Welle bestimmt das Bild, die anderen ergänzen.
      const passt = !e.wave || e.wave === wave || (alleBekannt && e.wave === ((wave - 1) % 3) + 1);
      return e.spawnWeight * (passt ? 1 : anteil);
    });
  }

  endWave() {
    this.state = WAVE_STATE.INTERMISSION;
    // Jede geschaffte Welle ist später genau ein Erfahrungspunkt.
    this.run.wavesCleared = (this.run.wavesCleared || 0) + 1;
    Audio.play('waveClear');
    // Die Welle ist vorbei - was von ihr übrig ist, fällt um.
    this.arena.clearWave();
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
