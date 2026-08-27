import { weighted } from '../core/util.js';

export const RARITY_ORDER = ['common', 'rare', 'epic', 'legendary'];
export const RARITY_LABEL = {
  common: 'Gewöhnlich',
  rare: 'Selten',
  epic: 'Episch',
  legendary: 'Legendär',
};

/**
 * Wuerfelt die Auswahlkarten nach einer Welle.
 * Höhere Zyklen erhoehen die Chance auf seltene Karten leicht.
 */
export function rollChoices(content, run, count = 3) {
  const balance = content.balance;
  const cards = [];
  const usedIds = new Set();

  // Waffen kommen bewusst nicht mehr als Karte - die gibt es beim Haendler
  // im Laden, der direkt nach dieser Auswahl aufgeht.
  const pool = content.upgrades.filter((u) => u.active !== false);
  let guard = 0;
  while (cards.length < count && guard++ < 60) {
    // Fähigkeit "Glückskarten" verschiebt die Seltenheiten nach oben.
    // Glueck (Karte "Glücksklee") und die Fähigkeit "Glückskarten"
    // verschieben die Seltenheiten nach oben.
    const glueck = (run.stats.luck || 0) / 10;
    // Glueckskarten hoben die Seltenheit frueher um zwei ganze Zyklen an -
    // damit war Ruun ab Welle 3 dauernd bei legendaeren Karten. Ein Zyklus
    // ist immer noch deutlich spuerbar.
    const rarity = rollRarity(balance, run.cycle + (run.perk === 'luckyCards' ? 1 : 0) + glueck);
    let options = pool.filter(
      (u) => u.rarity === rarity && !usedIds.has(u.id) && run.stackCount(u.id) < u.maxStack,
    );
    if (!options.length) {
      options = pool.filter((u) => !usedIds.has(u.id) && run.stackCount(u.id) < u.maxStack);
    }
    if (!options.length) break;

    const upgrade = weighted(options, (u) => u.weight);
    usedIds.add(upgrade.id);
    const besitz = run.stackCount(upgrade.id);
    cards.push({
      kind: 'upgrade',
      id: upgrade.id,
      upgrade,
      rarity: upgrade.rarity,
      title: upgrade.name,
      description: upgrade.description,
      valueText: formatModifiers(upgrade),
      icon: upgrade.icon,
      // Schon vorhanden? Dann zeigt die Karte die naechste Stufe und den
      // Wert, den man nach dem Kauf insgesamt hat.
      owned: besitz,
      level: besitz + 1,
      totalText: besitz ? formatModifiers(upgrade, besitz + 1) : '',
    });
  }

  return cards;
}

export function rollRarity(balance, cycle) {
  const bonus = Math.pow(balance.rarityCycleBonus || 1.35, Math.max(0, cycle - 1));
  const legendary = Math.min(30, (balance.rarityLegendaryBase ?? 2) * bonus);
  const epic = Math.min(45, (balance.rarityEpicBase ?? 9) * bonus);
  const rare = Math.min(60, (balance.rarityRareBase ?? 26) * Math.min(bonus, 1.6));

  const roll = Math.random() * 100;
  if (roll < legendary) return 'legendary';
  if (roll < legendary + epic) return 'epic';
  if (roll < legendary + epic + rare) return 'rare';
  return 'common';
}

/**
 * Alle Werte einer Karte als kurze Zeile.
 * "stufen" > 1 rechnet die Summe mehrerer Stufen aus - so sieht man beim
 * zweiten Kauf direkt, was danach insgesamt herauskommt.
 */
export function formatModifiers(upgrade, stufen = 1) {
  const mods = Array.isArray(upgrade.mods) && upgrade.mods.length ? upgrade.mods : [upgrade];
  // Karten, die nur einen Sondereffekt bringen, tragen einen Wert von 0.
  // Ein "+0 % Schaden" waere irrefuehrend - dann steht dort der Effekt.
  const wirksam = mods.filter((mod) => mod && mod.stat && mod.value);
  if (!wirksam.length) return upgrade.effect ? 'Sondereffekt' : '';
  return wirksam.map((mod) => formatModifier(
    stufen > 1 ? { ...mod, value: Math.round(mod.value * stufen * 10) / 10 } : mod,
  )).join(' · ');
}

/** Deutscher Name jedes Wertes - fuer Karten, Laden und Uebersicht. */
export const STAT_LABEL = {
  damage: 'Schaden', attackSpeed: 'Angriffstempo', moveSpeed: 'Tempo',
  maxHealth: 'Max. Leben', armor: 'Rüstung', shield: 'Schild',
  critChance: 'Krit. Chance', critDamage: 'Krit. Schaden',
  projectileSpeed: 'Projektiltempo', range: 'Reichweite',
  knockback: 'Rückstoß', dodge: 'Ausweichen', regen: 'Regeneration',
  burn: 'Feuerschaden', potionRate: 'Flaschen-Chance',
  pickupRange: 'Aufsammelreichweite', lifesteal: 'Lebensraub',
  luck: 'Glück', money: 'Geld', projectileDamage: 'Geschossschaden',
  meleeRange: 'Nahkampf-Reichweite', rangedAttackSpeed: 'Fernkampf-Tempo',
  thorns: 'Dornen',
};

export function formatModifier(upgrade) {
  const sign = upgrade.value >= 0 ? '+' : '';
  const label = STAT_LABEL[upgrade.stat] || upgrade.stat;

  if (upgrade.stat === 'burn') {
    return upgrade.modType === 'percent'
      ? `${sign}${upgrade.value}% ${label}`
      : `${sign}${upgrade.value} ${label} / Sek.`;
  }
  if (upgrade.modType === 'percent') return `${sign}${upgrade.value}% ${label}`;
  if (upgrade.stat === 'regen') return `${sign}${upgrade.value} ${label} / Sek.`;
  if (upgrade.stat === 'critChance' || upgrade.stat === 'dodge') return `${sign}${upgrade.value} Punkte ${label}`;
  return `${sign}${upgrade.value} ${label}`;
}
