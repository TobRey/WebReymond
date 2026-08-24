import { emptyModifiers, applyUpgrade, resolveStats, resolveWeapon } from './stats.js';
import { PERK_LABELS } from './perks.js';

/**
 * Zustand eines Durchlaufs: Statistiken, Upgrades, Geld, Welle, Zyklus.
 * Alles, was der Spieler in einem Run aufbaut, liegt hier - nicht in der UI.
 */
export class RunState {
  constructor(content, weapon, character = null) {
    this.content = content;
    this.base = content.player;
    this.balance = content.balance;
    this.character = character;
    this.perk = (character && character.perk) || '';

    this.mods = emptyModifiers();
    this.upgrades = [];       // {upgrade, count}
    this.weapon = weapon;

    this.money = 0;
    this.kills = 0;
    this.bossKills = 0;
    this.damageDealt = 0;
    this.damageTaken = 0;
    this.timeAlive = 0;
    this.weaponUsage = {};      // Sekunden je Waffe - für die Endauswertung

    this.cycle = 1;
    this.wave = 1;            // 1..4 innerhalb des Zyklus
    this.waveIndex = 0;       // fortlaufend über alle Zyklen

    this.stats = resolveStats(this.base, this.mods, this.character);
    this.weaponStats = resolveWeapon(this.weapon, this.stats);
    this.health = this.stats.maxHealth;
    this.shield = this.stats.maxShield;
    this.wavesCleared = 0;      // zählt die Erfahrungspunkte des Runs mit
    // Fähigkeit "Zweiter Atem": einmal je Welle verfügbar.
    this.secondWindReady = this.perk === 'secondWind';

    // Sondereffekte der Upgrade-Karten: id -> Stufe.
    this.effects = new Map();
    // Dauerhafte Zuschlaege, die Effekte im Lauf der Runde ansammeln.
    this.bonusDamage = 0;
    this.bonusHealth = 0;
    // Kurzzeitige Schuebe: id -> Restzeit in Sekunden.
    this.timed = new Map();
    this.killStreak = 0;
    this.killStreakTime = 0;
    this.untouchedTime = 0;
  }

  get difficulty() {
    return Math.pow(this.balance.healthScaling, this.cycle - 1);
  }

  get damageDifficulty() {
    return Math.pow(this.balance.damageScaling, this.cycle - 1);
  }

  get speedDifficulty() {
    return Math.pow(this.balance.speedScaling, this.cycle - 1);
  }

  get rewardDifficulty() {
    return Math.pow(this.balance.rewardScaling, this.cycle - 1);
  }

  /** Wie oft ein Sondereffekt gewaehlt wurde (0 = gar nicht). */
  effectLevel(id) {
    return this.effects.get(id) || 0;
  }

  hasEffect(id) {
    return (this.effects.get(id) || 0) > 0;
  }

  addUpgrade(upgrade) {
    const existing = this.upgrades.find((u) => u.upgrade.id === upgrade.id);
    if (existing) existing.count++;
    else this.upgrades.push({ upgrade, count: 1 });

    if (upgrade.effect) {
      this.effects.set(upgrade.effect, (this.effects.get(upgrade.effect) || 0) + 1);
    }

    const beforeMax = this.stats.maxHealth;
    const beforeShield = this.stats.maxShield;
    // Eine Karte kann mehrere Werte gleichzeitig verschieben.
    const mods = Array.isArray(upgrade.mods) && upgrade.mods.length ? upgrade.mods : [upgrade];
    for (const mod of mods) applyUpgrade(this.mods, mod);
    this.recalc();

    // Mehr Maximalleben heilt auch sofort um die Differenz.
    if (this.stats.maxHealth > beforeMax) this.health += this.stats.maxHealth - beforeMax;
    if (this.stats.maxShield > beforeShield) this.shield += this.stats.maxShield - beforeShield;
    this.clampVitals();
  }

  setWeapon(weapon) {
    this.weapon = weapon;
    this.recalc();
  }

  recalc() {
    this.stats = resolveStats(this.base, this.mods, this.character);
    this.weaponStats = resolveWeapon(this.weapon, this.stats);
    this.clampVitals();
  }

  clampVitals() {
    this.health = Math.min(this.health, this.stats.maxHealth);
    this.shield = Math.min(this.shield, this.stats.maxShield);
  }

  stackCount(id) {
    const entry = this.upgrades.find((u) => u.upgrade.id === id);
    return entry ? entry.count : 0;
  }

  addMoney(amount) {
    // Fähigkeit "Goldrausch" und der Geld-Faktor des Charakters wirken hier.
    const perk = this.perk === 'greed' ? 1.6 : 1;
    const value = Math.max(0, Math.round(
      amount * (this.balance.moneyMultiplier || 1) * (this.stats.moneyMult || 1) * perk,
    ));
    this.money += value;
    return value;
  }

  /** Übersicht für den Stats-Screen. */
  snapshot() {
    const s = this.stats;
    const w = this.weaponStats;
    return [
      ['Waffe', this.weapon.name],
      ['Waffenschaden', Math.round(w.damage)],
      ['Angriffe / Sek.', (1 / w.cooldown).toFixed(2)],
      ['Reichweite', Math.round(w.range)],
      ['Projektiltempo', Math.round(w.projectileSpeed)],
      ['Tempo', Math.round(s.moveSpeed)],
      ['Leben', Math.ceil(this.health) + ' / ' + s.maxHealth],
      ['Schild', Math.ceil(this.shield) + ' / ' + Math.round(s.maxShield)],
      ['Rüstung', s.armor.toFixed(1)],
      ['Krit. Chance', w.critChance.toFixed(1) + '%'],
      ['Krit. Schaden', '+' + Math.round(w.critDamage) + '%'],
      ['Rückstoß', Math.round(w.knockback)],
      ['Ausweichen', s.dodge.toFixed(1) + '%'],
      ['Regeneration', s.regen.toFixed(1) + ' HP/s'],
      ['Feuerschaden', s.burn > 0 ? (s.burn * s.damageMult).toFixed(1) + ' HP/s' : '-'],
      ['Aufsammelreichweite', Math.round(s.pickupRange)],
      ['Fähigkeit', PERK_LABELS[this.perk] || '-'],
      ['Lebensraub', s.lifesteal > 0 ? s.lifesteal.toFixed(1) + '%' : '-'],
      ['Glück', s.luck > 0 ? '+' + Math.round(s.luck) : '-'],
      ['Dornen', s.thorns > 0 ? Math.round(s.thorns) : '-'],
      ['Geld', this.money],
      ['Charakter', this.character ? this.character.name : '-'],
      ['Zyklus', this.cycle],
      ['Welle', this.wave + ' / 4'],
      ['Schwierigkeit', '×' + this.difficulty.toFixed(2)],
    ];
  }
}
