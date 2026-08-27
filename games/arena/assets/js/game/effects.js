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
  /**
   * Obergrenze fuer Heilung aus Treffern, als Anteil des Maximallebens je
   * Sekunde. Ohne sie war jede schnelle Waffe mit Lebensraub oder
   * Vampirzaehnen eine Unsterblichkeitsmaschine: Der Dolch schlaegt fuenfmal
   * je Sekunde zu, also heilte er auch fuenfmal. Der Deckel macht die
   * Heilung unabhaengig von der Angriffsrate.
   */
  healCapShare: 0.05,
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
  /** Seelenfaenger: dauerhafter Schadenszuschlag je Boss, mit Deckel. */
  soulEater: 0.06,
  soulEaterMax: 0.8,
  /** Hunger: je so viele Kills dauerhaft so viel Leben. */
  hungerEvery: 25,
  hungerHealth: 6,
  /** Klonmaschine: Abstand in Sekunden fuer den Zusatzschuss. */
  cloneEvery: 4,
  /** Blutpakt: Lebenskosten je Welle. */
  bloodPact: 8,
  /** Fluch der Gier: Zuschlag auf das Gegnerleben. */
  greedCurse: 0.25,
  /** Mutation: Zuschlag je Welle auf Schaden und Gegnerleben, mit Deckel. */
  mutationDamage: 0.04,
  mutationEnemy: 0.05,
  mutationMax: 1.2,
  /** Goldener Wuerfel: Zuschlag je Welle auf einen zufaelligen Wert. */
  goldenDice: 0.05,
  /** Kartoffelgott: zusaetzlicher Zuschlag auf das Gegnerleben. */
  potatoGod: 0.15,

  /* ---------------------------------------------------- Neue Sondereffekte */
  /** Schwarmherz: Zuschlag je lebendem Gegner, gedeckelt. */
  swarm: 0.01,
  swarmMax: 0.6,
  /** Einzelgaenger: voller Zuschlag, wenn ausser einem niemand mehr steht. */
  lonewolfMax: 0.45,
  lonewolfCount: 8,
  /** Goldklinge: Zuschlag je hundert Geld im Beutel, gedeckelt. */
  greedyBlade: 0.05,
  greedyBladeMax: 0.5,
  /** Armenrecht: Zuschlag, solange weniger als so viel Geld da ist. */
  pauperBelow: 60,
  pauperBonus: 0.3,
  /** Schwung: Zuschlag in Bewegung. */
  momentum: 0.16,
  /** Bollwerk: Zuschlag im Stand. */
  bulwark: 0.22,
  /** Zocker: halber oder doppelter Schaden, 50:50. */
  gamblerLow: 0.5,
  gamblerHigh: 2,
  /** Schneeball: dauerhafter Zuschlag je Kill, gedeckelt (auch insgesamt). */
  snowball: 0.0025,
  snowballMax: 0.35,
  snowballHartMax: 0.9,
  /** Kritische Masse: Krit-Chance ueber 100 wird zu Schaden. */
  criticalMass: 0.01,
  /** Albtraum: Zuschlag je Zyklus, gedeckelt. */
  nightmare: 0.09,
  nightmareMax: 1.2,
  /** Adrenalin: Angriffstempo waechst mit fehlendem Leben. */
  adrenalin: 0.4,
  /** Rage: unter dieser Schwelle dieser Zuschlag aufs Angriffstempo. */
  rageBelow: 0.3,
  rageBonus: 0.45,
  /** Ernte: je so viele Kills so viel Heilung. */
  harvestEvery: 10,
  harvestHeal: 5,
  /** Blutgeld: Zusatzgeld je Kill und dauerhafter Schaden je Kill. */
  bloodMoneyCoins: 2,
  bloodMoneyDamage: 0.0012,
  bloodMoneyMax: 0.5,
  /** Schutzengel: Unverwundbarkeit nach einem Treffer. */
  guardian: 0.9,
  /** Vergeltung: Druckwelle beim Treffer. */
  retaliateRadius: 220,
  retaliateDamage: 30,
  retaliateKnockback: 900,
  /**
   * Bankier.
   *
   * Frueher wurde der Zuschlag jede Welle draufgerechnet und wuchs mit dem
   * Geld mit - wer Millionen hatte, war nach drei Wellen unsterblich. Jetzt
   * wird er jede Welle neu berechnet statt angesammelt und ist gedeckelt.
   */
  bankerPer: 250,
  bankerBonus: 0.03,
  bankerMax: 0.6,
  /** Roulette: Zuschlag des Wellenbonus. */
  roulette: 2.5,
  /** Frostaura: Radius und Tempofaktor. */
  frostAuraRadius: 170,
  frostAuraFactor: 0.6,
  /** Flammenaura: Radius. */
  flameAuraRadius: 150,
  /** Igel: Radius und Schaden je Sekunde. */
  spikesRadius: 130,
  spikesDamage: 14,
  /** Zeitriss: alle so viele Sekunden so lange doppeltes Angriffstempo. */
  timeWarpEvery: 12,
  timeWarpTime: 3,
  timeWarpBonus: 0.8,
  /** Magnetfeld: alles auf der Karte fliegt zu. */
  magnetizeRadius: 1400,
  /** Durchschlag: zusaetzliche Durchschlaege je Stufe. */
  pierceAll: 3,
  /** Echo: jeder so vielte Angriff feuert doppelt. */
  echoEvery: 4,
  /** Wuchtgeschoss: groessere Projektile mit mehr Schaden. */
  bigShotSize: 0.4,
  bigShotDamage: 0.18,
};

/**
 * Wie stark eine weitere Stufe desselben Effekts noch zaehlt.
 *
 * Frueher wurde der Deckel eines Effekts einfach mit der Stufe
 * multipliziert: Acht Karten "Berserker" ergaben +400 % statt +50 %. Zusammen
 * mit einem halben Dutzend anderer Karten kam so ein Schadensfaktor von
 * zwanzig heraus - und ab da war der Run nicht mehr zu verlieren. Jede
 * weitere Stufe zaehlt jetzt nur noch gut zur Haelfte. Stapeln lohnt sich
 * weiter, laeuft aber nicht mehr davon.
 */
export function stufenFaktor(level) {
  return level <= 1 ? level : 1 + (level - 1) * 0.55;
}

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
    faktor += fehlt * EFFECT_VALUES.berserkMax * stufenFaktor(berserk);
  }

  const henker = run.effectLevel('execute');
  if (henker > 0 && enemy && enemy.maxHealth > 0) {
    if (enemy.health / enemy.maxHealth < EFFECT_VALUES.executeBelow) {
      faktor += EFFECT_VALUES.executeBonus * stufenFaktor(henker);
    }
  }

  // Zeitlich begrenzte Schuebe.
  faktor += run.timed.get('multikillBonus') ? EFFECT_VALUES.multikillBonus : 0;

  const perfekt = run.effectLevel('untouched');
  if (perfekt > 0) {
    const anteil = Math.min(1, run.untouchedTime / EFFECT_VALUES.untouchedTime);
    faktor += anteil * EFFECT_VALUES.untouchedBonus * stufenFaktor(perfekt);
  }

  // Schwarmherz: je mehr Gegner stehen, desto haerter triffst du.
  const schwarm = run.effectLevel('swarm');
  if (schwarm > 0) {
    const st = stufenFaktor(schwarm);
    faktor += Math.min(
      EFFECT_VALUES.swarmMax * st,
      (run.aliveEnemies || 0) * EFFECT_VALUES.swarm * st,
    );
  }

  // Einzelgaenger: das Gegenteil - stark, solange kaum jemand da ist.
  const einzel = run.effectLevel('lonewolf');
  if (einzel > 0) {
    const leere = Math.max(0, EFFECT_VALUES.lonewolfCount - (run.aliveEnemies || 0));
    faktor += (leere / EFFECT_VALUES.lonewolfCount) * EFFECT_VALUES.lonewolfMax * stufenFaktor(einzel);
  }

  // Goldklinge und Armenrecht haengen am Geldbeutel.
  const klinge = run.effectLevel('greedyBlade');
  if (klinge > 0) {
    const st = stufenFaktor(klinge);
    faktor += Math.min(
      EFFECT_VALUES.greedyBladeMax * st,
      (run.money / 100) * EFFECT_VALUES.greedyBlade * st,
    );
  }
  const arm = run.effectLevel('pauper');
  if (arm > 0 && run.money < EFFECT_VALUES.pauperBelow) {
    faktor += EFFECT_VALUES.pauperBonus * stufenFaktor(arm);
  }

  // Schwung und Bollwerk schliessen sich gegenseitig aus - beide zusammen
  // ergeben immer genau einen Zuschlag, je nachdem ob man laeuft.
  const schwung = run.effectLevel('momentum');
  if (schwung > 0 && run.moving) faktor += EFFECT_VALUES.momentum * stufenFaktor(schwung);
  const bollwerk = run.effectLevel('bulwark');
  if (bollwerk > 0 && !run.moving) faktor += EFFECT_VALUES.bulwark * stufenFaktor(bollwerk);

  // Albtraum: je weiter der Run, desto haerter.
  const albtraum = run.effectLevel('nightmare');
  if (albtraum > 0) {
    const st = stufenFaktor(albtraum);
    faktor += Math.min(
      EFFECT_VALUES.nightmareMax * st,
      (run.cycle - 1) * EFFECT_VALUES.nightmare * st,
    );
  }

  // Kritische Masse: alles ueber 100 % Krit-Chance wird zu Schaden.
  const masse = run.effectLevel('criticalMass');
  if (masse > 0) {
    // Ueberschuss gedeckelt: Krit-Chance laesst sich sonst beliebig
    // hochstapeln und damit auch dieser Zuschlag.
    const ueber = Math.min(150, Math.max(0, (run.weaponStats.critChance || 0) - 100));
    faktor += ueber * EFFECT_VALUES.criticalMass * stufenFaktor(masse);
  }

  // Dauerhaft angesammelte Zuschlaege. Jeder hat seinen eigenen Deckel und
  // sein eigenes Feld - so kann keiner davonlaufen und keiner ueberschreibt
  // die anderen.
  faktor += run.bonusDamage
    + (run.snowballBonus || 0)
    + (run.bloodMoneyBonus || 0)
    + (run.soulBonus || 0)
    + (run.mutationBonus || 0)
    + (run.bankerBonus || 0);

  // Zocker wuerfelt zum Schluss: halb oder doppelt.
  if (run.hasEffect('gambler')) {
    faktor *= Math.random() < 0.5 ? EFFECT_VALUES.gamblerLow : EFFECT_VALUES.gamblerHigh;
  }
  return faktor;
}

/** Zuschlag auf das Angriffstempo aus zeitlich begrenzten Schueben. */
export function effectAttackSpeed(run) {
  let faktor = 1;
  if (run.timed.get('killFrenzy')) faktor += EFFECT_VALUES.killFrenzy * stufenFaktor(run.effectLevel('killFrenzy'));
  if (run.timed.get('hurtFrenzy')) faktor += EFFECT_VALUES.hurtFrenzy * stufenFaktor(run.effectLevel('hurtFrenzy'));
  if (run.timed.get('timeWarp')) faktor += EFFECT_VALUES.timeWarpBonus * stufenFaktor(run.effectLevel('timeWarp'));

  const adrenalin = run.effectLevel('adrenalin');
  if (adrenalin > 0) {
    const fehlt = 1 - Math.max(0, run.health) / Math.max(1, run.stats.maxHealth);
    faktor += fehlt * EFFECT_VALUES.adrenalin * stufenFaktor(adrenalin);
  }

  const rage = run.effectLevel('rage');
  if (rage > 0 && run.health / Math.max(1, run.stats.maxHealth) < EFFECT_VALUES.rageBelow) {
    faktor += EFFECT_VALUES.rageBonus * stufenFaktor(rage);
  }
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
    run.health = Math.min(run.stats.maxHealth, run.health + EFFECT_VALUES.hungerHealth * hunger);
  }

  const seele = run.effectLevel('soulEater');
  if (seele > 0 && enemy && enemy.boss) {
    run.soulBonus = Math.min(
      EFFECT_VALUES.soulEaterMax * stufenFaktor(seele),
      (run.soulBonus || 0) + EFFECT_VALUES.soulEater * seele,
    );
  }

  // Ernte: regelmaessige kleine Heilung.
  const ernte = run.effectLevel('harvest');
  if (ernte > 0 && run.kills > 0 && run.kills % EFFECT_VALUES.harvestEvery === 0) {
    run.health = Math.min(run.stats.maxHealth, run.health + EFFECT_VALUES.harvestHeal * ernte);
  }

  // Schneeball: jeder Kill macht dich ein Stueckchen staerker - bis zur
  // Obergrenze, sonst waere ein langer Run irgendwann sinnlos leicht.
  // Der Zuschlag steht in einem eigenen Feld und wird im Schadensfaktor
  // dazugerechnet, damit er sich nicht mit anderen Boni verrechnet.
  const schnee = run.effectLevel('snowball');
  if (schnee > 0) {
    run.snowballBonus = Math.min(
      Math.min(EFFECT_VALUES.snowballMax * stufenFaktor(schnee), EFFECT_VALUES.snowballHartMax),
      (run.snowballBonus || 0) + EFFECT_VALUES.snowball * schnee,
    );
  }

  // Blutgeld: Kills zahlen doppelt - in Muenzen und in Schlagkraft.
  const blut = run.effectLevel('bloodMoney');
  if (blut > 0) {
    run.addMoney(EFFECT_VALUES.bloodMoneyCoins * blut);
    run.bloodMoneyBonus = Math.min(
      EFFECT_VALUES.bloodMoneyMax * stufenFaktor(blut),
      (run.bloodMoneyBonus || 0) + EFFECT_VALUES.bloodMoneyDamage * blut,
    );
  }
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
    run.mutationBonus = Math.min(
      EFFECT_VALUES.mutationMax * stufenFaktor(mutation),
      (run.mutationBonus || 0) + EFFECT_VALUES.mutationDamage * mutation,
    );
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

  // Bankier: Geld arbeitet fuer dich - ohne dass es weniger wird. Der
  // Zuschlag wird jede Welle neu bestimmt und nicht angesammelt.
  const bank = run.effectLevel('banker');
  if (bank > 0) {
    const roh = Math.floor(run.money / EFFECT_VALUES.bankerPer)
      * EFFECT_VALUES.bankerBonus * bank;
    run.bankerBonus = Math.min(EFFECT_VALUES.bankerMax * stufenFaktor(bank), roh);
    if (run.bankerBonus > 0) {
      meldungen.push('Bankier: +' + Math.round(run.bankerBonus * 100) + ' % Schaden aus deinem Gold');
    }
  }

  // Roulette: ein zufaelliger Wert, aber richtig.
  const roulette = run.effectLevel('roulette');
  if (roulette > 0) {
    const wahl = WUERFEL_WERTE[(Math.random() * WUERFEL_WERTE.length) | 0];
    const [stat, typ, wert, label] = wahl;
    const menge = Math.round(wert * EFFECT_VALUES.roulette * roulette);
    run.mods[stat][typ === 'flat' ? 'flat' : 'percent'] += menge;
    run.recalc();
    meldungen.push('Roulette: +' + menge + ' ' + label);
  }

  // Einmal je Welle wieder verfügbar.
  if (run.hasEffect('lastPotato')) run.lastPotatoReady = true;
  return meldungen;
}
