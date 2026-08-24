/**
 * Rechnet Basiswerte plus gewählte Upgrades zu den effektiven Werten aus.
 * Prozent-Upgrades addieren sich (3x +8% = +24%), flache addieren direkt.
 */
export const STAT_LABELS = {
  damage: 'Schaden',
  attackSpeed: 'Angriffstempo',
  moveSpeed: 'Tempo',
  maxHealth: 'Max. Leben',
  armor: 'Rüstung',
  shield: 'Schild',
  critChance: 'Krit. Chance',
  critDamage: 'Krit. Schaden',
  projectileSpeed: 'Projektiltempo',
  range: 'Reichweite',
  knockback: 'Rückstoß',
  dodge: 'Ausweichen',
  regen: 'Regeneration',
  burn: 'Feuerschaden',
  potionRate: 'Heilflaschen',
  pickupRange: 'Aufsammelreichweite',
};

export function emptyModifiers() {
  return {
    damage: { percent: 0, flat: 0 },
    attackSpeed: { percent: 0, flat: 0 },
    moveSpeed: { percent: 0, flat: 0 },
    maxHealth: { percent: 0, flat: 0 },
    armor: { percent: 0, flat: 0 },
    shield: { percent: 0, flat: 0 },
    critChance: { percent: 0, flat: 0 },
    critDamage: { percent: 0, flat: 0 },
    projectileSpeed: { percent: 0, flat: 0 },
    range: { percent: 0, flat: 0 },
    knockback: { percent: 0, flat: 0 },
    dodge: { percent: 0, flat: 0 },
    regen: { percent: 0, flat: 0 },
    burn: { percent: 0, flat: 0 },
    potionRate: { percent: 0, flat: 0 },
    pickupRange: { percent: 0, flat: 0 },
  };
}

export function applyUpgrade(mods, upgrade) {
  const entry = mods[upgrade.stat];
  if (!entry) return;
  if (upgrade.modType === 'flat') entry.flat += Number(upgrade.value) || 0;
  else entry.percent += Number(upgrade.value) || 0;
}

/**
 * Effektive Werte aus Basiswerten, Charakterfähigkeit und Upgrades.
 * Charakterwerte sind Multiplikatoren bzw. flache Zuschläge und wirken
 * vor den Upgrades.
 */
export function resolveStats(base, mods, character = null) {
  const pct = (stat) => 1 + mods[stat].percent / 100;
  const flat = (stat) => mods[stat].flat;
  const cm = (character && character.mods) || {};
  const mult = (key) => (typeof cm[key] === 'number' ? cm[key] : 1);
  const add = (key) => (typeof cm[key] === 'number' ? cm[key] : 0);

  return {
    damageMult: (base.damageMult || 1) * mult('damageMult') * pct('damage') + flat('damage') / 100,
    attackSpeedMult: pct('attackSpeed') * mult('attackSpeed'),
    moveSpeed: (base.moveSpeed * mult('moveSpeed') + flat('moveSpeed')) * pct('moveSpeed'),
    maxHealth: Math.round((base.maxHealth * mult('maxHealth') + flat('maxHealth')) * pct('maxHealth')),
    armor: (base.armor + add('armor') + flat('armor')) * pct('armor'),
    maxShield: add('shield') + flat('shield') * pct('shield'),
    critChance: Math.min(100, (base.critChance + add('critChance') + flat('critChance')) * pct('critChance')),
    critDamage: (base.critDamage + add('critDamage') + flat('critDamage')) * pct('critDamage'),
    projectileSpeedMult: pct('projectileSpeed') * mult('projectileSpeed'),
    rangeMult: pct('range') * mult('range'),
    knockbackMult: pct('knockback') * mult('knockback'),
    dodge: Math.min(75, (base.dodge + add('dodge') + flat('dodge')) * pct('dodge')),
    regen: (base.regen + add('regen') + flat('regen')) * pct('regen'),
    // Feuerschaden pro Sekunde. Ohne Verbrennung und ohne Brandstifter-
    // Fähigkeit ist er 0 - dann brennt auch nichts.
    burn: Math.max(0, (add('burn') + flat('burn')) * pct('burn')),
    // Faktor auf die Spawnchance der Heilflaschen (1 = unverändert).
    potionRateMult: (pct('potionRate') + flat('potionRate') / 100) * mult('potionRate'),
    // Reichweite, ab der Heilflaschen zum Spieler fliegen.
    pickupRange: Math.max(20, (base.pickupRange || 90) * mult('pickupRange') * pct('pickupRange')
      + flat('pickupRange')),
    // Geld-Faktor des Charakters.
    moneyMult: mult('money'),
  };
}

/** Waffenwerte inklusive Spieler-Boni. */
export function resolveWeapon(weapon, stats) {
  return {
    ...weapon,
    damage: weapon.damage * stats.damageMult,
    cooldown: Math.max(0.03, weapon.cooldown / stats.attackSpeedMult),
    range: weapon.range * stats.rangeMult,
    projectileSpeed: weapon.projectileSpeed * stats.projectileSpeedMult,
    knockback: weapon.knockback * stats.knockbackMult,
    critChance: Math.min(100, weapon.critChance + stats.critChance),
    critDamage: weapon.critDamage + stats.critDamage,
    aoeRadius: weapon.aoeRadius * (1 + (stats.rangeMult - 1) * 0.5),
  };
}
