import { emptyModifiers, applyUpgrade, resolveStats, resolveWeapon } from './stats.js';
import { PERK_LABELS, PERK_VALUES } from './perks.js';

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
    // Werte, die Sondereffekte je Takt aus dem Spiel brauchen.
    this.aliveEnemies = 0;   // wie viele Gegner gerade stehen
    this.moving = false;     // laeuft der Spieler oder steht er
    // Jeder dauerhafte Zuschlag hat sein eigenes Feld und seinen eigenen
    // Deckel. Frueher liefen sie alle in bonusDamage zusammen und konnten
    // sich gegenseitig ins Unendliche schaukeln.
    this.snowballBonus = 0;    // Schneeball, waechst mit jedem Kill
    this.bloodMoneyBonus = 0;  // Blutgeld
    this.soulBonus = 0;        // Seelenfaenger, je Boss
    this.mutationBonus = 0;    // Mutation, je Welle
    this.bankerBonus = 0;      // Bankier, jede Welle neu aus dem Gold
    // Heilung aus Treffern wird je Sekunde gedeckelt - siehe heal().
    this.healBudget = 0;
  }

  /**
   * Fortschritt als Kommazahl: 0 in Welle 1 des ersten Zyklus, +1 je Zyklus.
   *
   * Vorher sprang die Schwierigkeit nur alle vier Wellen. Innerhalb eines
   * Zyklus blieb alles gleich - drei Wellen lang wurde nichts staerker, dann
   * kam ein Sprung. Jetzt waechst sie mit jeder einzelnen Welle um ein
   * Viertel Zyklus. Ueber vier Wellen kommt genau dasselbe heraus wie
   * vorher, es fuehlt sich aber durchgehend an.
   */
  get progress() {
    return (this.cycle - 1) + (this.wave - 1) / 4;
  }

  get difficulty() {
    return Math.pow(this.balance.healthScaling, this.progress);
  }

  get damageDifficulty() {
    return Math.pow(this.balance.damageScaling, this.progress);
  }

  get speedDifficulty() {
    return Math.pow(this.balance.speedScaling, this.progress);
  }

  get rewardDifficulty() {
    return Math.pow(this.balance.rewardScaling, this.progress);
  }

  /**
   * Anteil der Gegner, die als Elite erscheinen.
   *
   * Ab dem eingestellten Zyklus waechst er langsam an und ist gedeckelt.
   * Elitegegner haben deutlich mehr Leben, schlagen haerter und sind
   * groesser - so sieht man, dass es haerter wird, statt es nur zu merken.
   */
  get eliteShare() {
    const ab = this.balance.eliteFromCycle ?? 3;
    if (this.cycle < ab) return 0;
    const wachstum = this.balance.eliteGrowth ?? 0.05;
    const max = this.balance.eliteMax ?? 0.45;
    return Math.min(max, (this.progress - (ab - 1)) * wachstum);
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

  /**
   * Heilung aus Treffern - mit Obergrenze je Sekunde.
   *
   * Lebensraub und Vampirzaehne loesen bei jedem Treffer aus. Eine Waffe
   * mit fuenf Angriffen je Sekunde heilte damit fuenfmal so viel wie eine
   * langsame, und der Dolch wurde in Kombination mit Heilkarten schlicht
   * unsterblich. Das Budget begrenzt die Heilung auf einen festen Anteil
   * des Maximallebens je Sekunde - unabhaengig davon, wie oft man trifft.
   *
   * Heilflaschen, Notverband und Ernte laufen bewusst daran vorbei: Die
   * haben ihren eigenen Takt und koennen nicht hochskaliert werden.
   *
   * @returns {number} tatsaechlich geheiltes Leben
   */
  healFromHit(amount) {
    if (amount <= 0) return 0;
    const moeglich = Math.min(amount, this.healBudget);
    if (moeglich <= 0) return 0;
    this.healBudget -= moeglich;
    const vorher = this.health;
    this.health = Math.min(this.stats.maxHealth, this.health + moeglich);
    return this.health - vorher;
  }

  /** Fuellt das Heilbudget jeden Takt wieder auf. */
  tickHealBudget(dt, share) {
    const proSekunde = this.stats.maxHealth * share;
    this.healBudget = Math.min(proSekunde, this.healBudget + proSekunde * dt);
  }

  addMoney(amount) {
    // Fähigkeit "Goldrausch" und der Geld-Faktor des Charakters wirken hier.
    const perk = this.perk === 'greed' ? PERK_VALUES.greedMoney : 1;
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
