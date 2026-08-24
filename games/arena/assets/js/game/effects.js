/**
 * Sondereffekte der Upgrade-Karten.
 *
 * Jede Karte mit einem "effect" landet hier. Die Zahlen stehen gebündelt
 * an einer Stelle, damit sich das Balancing an einem Ort nachziehen lässt,
 * und die Wirkung selbst hängt an wenigen Ereignissen:
 *
 *   onKill        - ein Gegner ist gefallen
 *   onPlayerHit   - der Spieler hat etwas abbekommen
 *   onWaveStart   - eine neue Welle beginnt
 *   update        - jeden Takt
 *   damageFactor  - Zuschlag auf einen einzelnen Treffer
 *
 * Alles, was eine Stufe hat, skaliert mit der Zahl der gewählten Karten.
 */
export const EFFECT_VALUES = {
  /** Kritische Treffer heilen so viele Lebenspunkte je Stufe. */
  critHeal: 2,
  /** Kills geben diesen Zuschlag auf das Angriffstempo, so lange. */
  killFrenzy: 0.12,
  killFrenzyTime: 3,
  /** Nach einem Treffer: Zuschlag aufs Angriffstempo und Dauer. */
  hurtFrenzy: 0.2,
  hurtFrenzyTime: 2.5,
  /** Multikill: so viele Kills in diesem Fenster geben den Schadensschub. */
  multikillCount: 4,
  multikillWindow: 2.5,
  multikillBonus: 0.25,
  multikillTime: 4,
  /** Perfektionist: nach so vielen Sekunden ohne Treffer der volle Zuschlag. */
  untouchedTime: 8,
  untouchedBonus: 0.3,
  /** Berserker: hoechster Zuschlag bei fast leerer Lebensleiste. */
  berserkMax: 0.5,
  /** Henker: Zuschlag gegen Gegner unter der Schwelle. */
  executeBelow: 0.5,
  executeBonus: 0.35,
  /** Doppelschuss: Chance je Stufe. */
  doubleShot: 0.2,
  /** Geistergeschoss: zusaetzliche Durchschlaege je Stufe. */
  ghostShot: 1,
  /** Kettenreaktion: Chance, Radius und Anteil des Lebens als Schaden. */
  chainChance: 0.28,
  chainRadius: 85,
  chainShare: 0.45,
  /** Turbo: Schaden je Sekunde an beruehrenden Gegnern. */
  collide: 26,
  /** Notverband: Heilung je Welle in Prozent des Maximallebens. */
  waveHeal: 12,
  /** Schatzjaeger: Faktor auf Boss- und Truhenbeute. */
  treasure: 0.6,
  /** Schwarzes Loch: Abstand in Sekunden, Radius und Zugkraft. */
  blackholeEvery: 9,
  blackholeRadius: 420,
  blackholePull: 520,
  /** Zeitlupe: greift unter dieser Lebensschwelle, mit diesem Tempofaktor. */
  slowmoBelow: 0.35,
  slowmoFactor: 0.55,
  /** Midas: Chance je Kill und Betrag. */
  midasChance: 0.16,
  midasAmount: 12,
  /** Todeswelle: greift unter dieser Schwelle, danach Sperre. */
  deathwaveBelow: 0.25,
  deathwaveCooldown: 14,
  deathwaveRadius: 260,
  deathwaveDamage: 40,
  /** Seelenfaenger: dauerhafter Schadenszuschlag je Boss. */
  soulEater: 0.06,
  /** Hunger: je so viele Kills dauerhaft so viel Leben. */
  hungerEvery: 25,
  hungerHealth: 6,
  /** Klonmaschine: Abstand in Sekunden fuer den Zusatzschuss. */
  cloneEvery: 4,
  /** Blutpakt: Lebenskosten je Welle. */
  bloodPact: 8,
  /** Fluch der Gier: Zuschlag auf das Gegnerleben. */
  greedCurse: 0.25,
  /** Mutation: Zuschlag je Welle auf Schaden und Gegnerleben. */
  mutationDamage: 0.04,
  mutationEnemy: 0.05,
  /** Goldener Wuerfel: Zuschlag je Welle auf einen zufaelligen Wert. */
  goldenDice: 0.05,
  /** Kartoffelgott: zusaetzlicher Zuschlag auf das Gegnerleben. */
  potatoGod: 0.15,
};

/** Werte, die der Goldene Würfel und der Chaos-Kern anfassen dürfen. */
const WUERFEL_WERTE = [
  ['damage', 'percent', 6, 'Schaden'],
  ['attackSpeed', 'percent', 6, 'Angriffstempo'],
  ['moveSpeed', 'percent', 5, 'Tempo'],
  ['maxHealth', 'flat', 12, 'Leben'],
  ['critChance', 'flat', 4, 'Krit-Chance'],
  ['armor', 'flat', 2, 'Rüstung'],
];

/**
 * Schadensfaktor eines einzelnen Treffers aus allen aktiven Effekten.
 *
 * @param run    Zustand der Runde
 * @param enemy  getroffener Gegner (fuer Henker)
 */
export function effectDamageFactor(run, enemy) {
  let faktor = 1;

  const berserk = run.effectLevel('berserk');
  if (berserk > 0) {
    const fehlt = 1 - Math.max(0, run.health) / Math.max(1, run.stats.maxHealth);
    faktor += fehlt * EFFECT_VALUES.berserkMax * berserk;
  }

  const henker = run.effectLevel('execute');
  if (henker > 0 && enemy && enemy.maxHealth > 0) {
    if (enemy.health / enemy.maxHealth < EFFECT_VALUES.executeBelow) {
      faktor += EFFECT_VALUES.executeBonus * henker;
    }
  }

  // Zeitlich begrenzte Schuebe.
  faktor += run.timed.get('multikillBonus') ? EFFECT_VALUES.multikillBonus : 0;

  const perfekt = run.effectLevel('untouched');
  if (perfekt > 0) {
    const anteil = Math.min(1, run.untouchedTime / EFFECT_VALUES.untouchedTime);
    faktor += anteil * EFFECT_VALUES.untouchedBonus * perfekt;
  }

  // Dauerhaft angesammelte Zuschlaege (Seelenfaenger, Mutation).
  faktor += run.bonusDamage;
  return faktor;
}

/** Zuschlag auf das Angriffstempo aus zeitlich begrenzten Schueben. */
export function effectAttackSpeed(run) {
  let faktor = 1;
  if (run.timed.get('killFrenzy')) faktor += EFFECT_VALUES.killFrenzy * run.effectLevel('killFrenzy');
  if (run.timed.get('hurtFrenzy')) faktor += EFFECT_VALUES.hurtFrenzy * run.effectLevel('hurtFrenzy');
  return faktor;
}

/** Zuschlag auf das Gegnerleben aus Flüchen. */
export function effectEnemyHealth(run) {
  let faktor = 1;
  faktor += EFFECT_VALUES.greedCurse * run.effectLevel('greedCurse');
  faktor += EFFECT_VALUES.potatoGod * run.effectLevel('potatoGod');
  faktor += run.bonusEnemyHealth || 0;
  return faktor;
}

/** Läuft jeden Takt: Timer herunterzählen, Dauerwirkungen prüfen. */
export function updateEffects(run, dt) {
  for (const [key, rest] of run.timed) {
    const next = rest - dt;
    if (next <= 0) run.timed.delete(key);
    else run.timed.set(key, next);
  }
  run.untouchedTime += dt;
  if (run.killStreakTime > 0) {
    run.killStreakTime -= dt;
    if (run.killStreakTime <= 0) run.killStreak = 0;
  }
}

/** Ein Gegner ist gefallen. */
export function onEffectKill(run, enemy) {
  if (run.hasEffect('killFrenzy')) run.timed.set('killFrenzy', EFFECT_VALUES.killFrenzyTime);

  if (run.hasEffect('multikill')) {
    run.killStreak++;
    run.killStreakTime = EFFECT_VALUES.multikillWindow;
    if (run.killStreak >= EFFECT_VALUES.multikillCount) {
      run.killStreak = 0;
      run.timed.set('multikillBonus', EFFECT_VALUES.multikillTime);
    }
  }

  const hunger = run.effectLevel('hunger');
  if (hunger > 0 && run.kills > 0 && run.kills % EFFECT_VALUES.hungerEvery === 0) {
    run.mods.maxHealth.flat += EFFECT_VALUES.hungerHealth * hunger;
    run.recalc();
    run.health += EFFECT_VALUES.hungerHealth * hunger;
  }

  const seele = run.effectLevel('soulEater');
  if (seele > 0 && enemy && enemy.boss) run.bonusDamage += EFFECT_VALUES.soulEater * seele;
}

/** Der Spieler hat etwas abbekommen. */
export function onEffectPlayerHit(run) {
  run.untouchedTime = 0;
  if (run.hasEffect('hurtFrenzy')) run.timed.set('hurtFrenzy', EFFECT_VALUES.hurtFrenzyTime);
}

/**
 * Beginn einer Welle: Heilung, Kosten, Würfel und Mutation.
 *
 * @returns list<string> Meldungen für die Oberfläche
 */
export function onEffectWaveStart(run) {
  const meldungen = [];

  const verband = run.effectLevel('waveHeal');
  if (verband > 0) {
    const heilung = Math.round((run.stats.maxHealth * EFFECT_VALUES.waveHeal * verband) / 100);
    run.health = Math.min(run.stats.maxHealth, run.health + heilung);
    meldungen.push('Notverband: +' + heilung + ' Leben');
  }

  const pakt = run.effectLevel('bloodPact');
  if (pakt > 0) {
    run.health = Math.max(1, run.health - EFFECT_VALUES.bloodPact * pakt);
    meldungen.push('Blutpakt fordert seinen Teil');
  }

  const wuerfel = run.effectLevel('goldenDice');
  if (wuerfel > 0) {
    const wahl = WUERFEL_WERTE[(Math.random() * WUERFEL_WERTE.length) | 0];
    const [stat, typ, wert, label] = wahl;
    run.mods[stat][typ === 'flat' ? 'flat' : 'percent'] += wert * wuerfel;
    run.recalc();
    meldungen.push('Goldener Würfel: +' + wert * wuerfel + ' ' + label);
  }

  const mutation = run.effectLevel('mutation');
  if (mutation > 0) {
    run.bonusDamage += EFFECT_VALUES.mutationDamage * mutation;
    run.bonusEnemyHealth = (run.bonusEnemyHealth || 0) + EFFECT_VALUES.mutationEnemy * mutation;
    meldungen.push('Mutation: stärker - und die Gegner auch');
  }

  const chaos = run.effectLevel('chaos');
  if (chaos > 0) {
    const gut = WUERFEL_WERTE[(Math.random() * WUERFEL_WERTE.length) | 0];
    let schlecht = WUERFEL_WERTE[(Math.random() * WUERFEL_WERTE.length) | 0];
    if (schlecht === gut) schlecht = WUERFEL_WERTE[(WUERFEL_WERTE.indexOf(gut) + 1) % WUERFEL_WERTE.length];
    run.mods[gut[0]][gut[1] === 'flat' ? 'flat' : 'percent'] += gut[2] * 2 * chaos;
    run.mods[schlecht[0]][schlecht[1] === 'flat' ? 'flat' : 'percent'] -= schlecht[2] * chaos;
    run.recalc();
    meldungen.push('Chaos: +' + gut[3] + ', −' + schlecht[3]);
  }

  // Einmal je Welle wieder verfügbar.
  if (run.hasEffect('lastPotato')) run.lastPotatoReady = true;
  return meldungen;
}
