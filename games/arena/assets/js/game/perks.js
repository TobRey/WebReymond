/**
 * Spezialfähigkeiten der Charaktere.
 *
 * Die Beschreibungen kommen aus den Spieldaten (Admin), die Zahlen stehen
 * hier - so bleibt an einer Stelle nachvollziehbar, wie stark eine
 * Fähigkeit wirklich ist.
 */
export const PERK_LABELS = {
  '': 'Keine',
  lifesteal: 'Lebensraub',
  thorns: 'Dornen',
  luckyCards: 'Glückskarten',
  berserk: 'Blutrausch',
  secondWind: 'Zweiter Atem',
  pyro: 'Brandstifter',
  frost: 'Frost',
  blast: 'Sprengmeister',
  magnet: 'Magnet',
  greed: 'Goldrausch',
  guardian: 'Wächter',
  sniper: 'Scharfschütze',
};

export const PERK_VALUES = {
  /** Lebensraub: Heilung je normalem Gegner und je Boss. */
  lifestealNormal: 1.5,
  lifestealBoss: 25,
  /** Dornen: Anteil des Kontaktschadens, der zurückgeht, plus Sockel. */
  thornsShare: 0.3,
  thornsFlat: 4,
  /** Blutrausch: höchster Zuschlag bei fast leerer Lebensleiste. */
  berserkMax: 0.6,
  /** Zweiter Atem: Restleben in Prozent nach dem rettenden Treffer. */
  secondWindHeal: 25,
  /** Brandstifter: Grundfeuerschaden pro Sekunde ohne Upgrade. */
  pyroBurn: 3,
  /** Frost: Tempo-Faktor und Dauer der Verlangsamung. */
  frostFactor: 0.45,
  frostDuration: 1.6,
  /** Sprengmeister: Radius und Schadensanteil der Leichenexplosion. */
  blastRadius: 95,
  blastShare: 0.55,
  /** Magnet: Faktor auf Aufsammelreichweite und Flaschenchance. */
  magnetRange: 2,
  magnetPotion: 2,
  /** Goldrausch: Geldfaktor. */
  greedMoney: 1.6,
  /** Scharfschütze: Zuschlag bei voller Reichweite. */
  sniperMax: 0.55,
};

/**
 * Schadensfaktor, der von der Fähigkeit und der Lage abhängt.
 * Wird bei jedem Treffer auf einen Gegner ausgewertet.
 */
export function perkDamageMultiplier(run, player, enemy) {
  switch (run.perk) {
    case 'berserk': {
      // Je knapper das Leben, desto härter der Schlag.
      const missing = 1 - Math.max(0, run.health) / Math.max(1, run.stats.maxHealth);
      return 1 + missing * PERK_VALUES.berserkMax;
    }
    case 'sniper': {
      if (!enemy) return 1;
      const distance = Math.hypot(enemy.x - player.x, enemy.y - player.y);
      const reach = Math.max(120, run.weaponStats.range);
      return 1 + Math.min(1, distance / reach) * PERK_VALUES.sniperMax;
    }
    default:
      return 1;
  }
}
