import { weighted, pick } from '../core/util.js';

export const RARITY_ORDER = ['common', 'rare', 'epic', 'legendary'];
export const RARITY_LABEL = {
  common: 'Gewoehnlich',
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

  const weaponChance = balance.weaponOfferChance ?? 0.28;
  const weapons = content.weapons.filter((w) => w.active && w.id !== run.weapon.id);
  if (weapons.length && Math.random() < weaponChance) {
    const weapon = pick(weapons);
    cards.push({
      kind: 'weapon',
      id: 'weapon_' + weapon.id,
      weapon,
      rarity: 'epic',
      title: weapon.name,
      description: weapon.description || 'Neue Waffe.',
      valueText: Math.round(weapon.damage) + ' Schaden · ' + (1 / weapon.cooldown).toFixed(1) + '/s',
      sprite: weapon.sprite,
    });
    usedIds.add('weapon_' + weapon.id);
  }

  const pool = content.upgrades.filter((u) => u.active !== false);
  let guard = 0;
  while (cards.length < count && guard++ < 60) {
    // Fähigkeit "Glückskarten" verschiebt die Seltenheiten nach oben.
    // Glueck (Karte "Glücksklee") und die Fähigkeit "Glückskarten"
    // verschieben die Seltenheiten nach oben.
    const glueck = (run.stats.luck || 0) / 10;
    const rarity = rollRarity(balance, run.cycle + (run.perk === 'luckyCards' ? 2 : 0) + glueck);
    let options = pool.filter(
      (u) => u.rarity === rarity && !usedIds.has(u.id) && run.stackCount(u.id) < u.maxStack,
    );
    if (!options.length) {
      options = pool.filter((u) => !usedIds.has(u.id) && run.stackCount(u.id) < u.maxStack);
    }
    if (!options.length) break;

    const upgrade = weighted(options, (u) => u.weight);
    usedIds.add(upgrade.id);
    cards.push({
      kind: 'upgrade',
      id: upgrade.id,
      upgrade,
      rarity: upgrade.rarity,
      title: upgrade.name,
      description: upgrade.description,
      valueText: formatModifiers(upgrade),
      icon: upgrade.icon,
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

/** Alle Werte einer Karte als kurze Zeile. */
export function formatModifiers(upgrade) {
  const mods = Array.isArray(upgrade.mods) && upgrade.mods.length ? upgrade.mods : [upgrade];
  return mods.map((mod) => formatModifier(mod)).join(' · ');
}

export function formatModifier(upgrade) {
  const sign = upgrade.value >= 0 ? '+' : '';
  const label = {
    damage: 'Schaden', attackSpeed: 'Angriffstempo', moveSpeed: 'Tempo',
    maxHealth: 'Max. Leben', armor: 'Rüstung', shield: 'Schild',
    critChance: 'Krit. Chance', critDamage: 'Krit. Schaden',
    projectileSpeed: 'Projektiltempo', range: 'Reichweite',
    knockback: 'Rückstoß', dodge: 'Ausweichen', regen: 'Regeneration',
    burn: 'Feuerschaden', potionRate: 'Flaschen-Chance',
  }[upgrade.stat] || upgrade.stat;

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
