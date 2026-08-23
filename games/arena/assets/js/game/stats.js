/**
 * Rechnet Basiswerte plus gewaehlte Upgrades zu den effektiven Werten aus.
 * Prozent-Upgrades addieren sich (3x +8% = +24%), flache addieren direkt.
 */
export const STAT_LABELS = {
  damage: 'Schaden',
  attackSpeed: 'Angriffstempo',
  moveSpeed: 'Tempo',
  maxHealth: 'Max. Leben',
  armor: 'Ruestung',
  shield: 'Schild',
  critChance: 'Krit. Chance',
  critDamage: 'Krit. Schaden',
  projectileSpeed: 'Projektiltempo',
  range: 'Reichweite',
  knockback: 'Rueckstoss',
  dodge: 'Ausweichen',
  regen: 'Regeneration',
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
  };
}

export function applyUpgrade(mods, upgrade) {
  const entry = mods[upgrade.stat];
  if (!entry) return;
  if (upgrade.modType === 'flat') entry.flat += Number(upgrade.value) || 0;
  else entry.percent += Number(upgrade.value) || 0;
}

/** @returns effektive Werte fuer Spieler und Waffe */
export function resolveStats(base, mods) {
  const pct = (stat) => 1 + mods[stat].percent / 100;
  const flat = (stat) => mods[stat].flat;

  return {
    damageMult: (base.damageMult || 1) * pct('damage') + flat('damage') / 100,
    attackSpeedMult: pct('attackSpeed'),
    moveSpeed: (base.moveSpeed + flat('moveSpeed')) * pct('moveSpeed'),
    maxHealth: Math.round((base.maxHealth + flat('maxHealth')) * pct('maxHealth')),
    armor: (base.armor + flat('armor')) * pct('armor'),
    maxShield: flat('shield') * pct('shield'),
    critChance: Math.min(100, (base.critChance + flat('critChance')) * pct('critChance')),
    critDamage: (base.critDamage + flat('critDamage')) * pct('critDamage'),
    projectileSpeedMult: pct('projectileSpeed'),
    rangeMult: pct('range'),
    knockbackMult: pct('knockback'),
    dodge: Math.min(75, (base.dodge + flat('dodge')) * pct('dodge')),
    regen: (base.regen + flat('regen')) * pct('regen'),
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
