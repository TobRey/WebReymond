import { weighted, pick } from '../core/util.js';
import { rollRarity, formatModifiers, RARITY_LABEL } from './upgrades.js';

/**
 * Der Laden zwischen den Wellen.
 *
 * Reine Rechenlogik ohne DOM: Das Fenster in main.js fragt hier Angebote,
 * Preise und Kaufergebnisse ab. So bleibt die Oberflaeche austauschbar und
 * die Regeln lassen sich einzeln pruefen.
 *
 * Ablauf: Nach der Upgrade-Auswahl wird ein Laden geoeffnet. Er wuerfelt
 * so viele Angebote, wie im Admin eingestellt sind. Gekauft wird, solange
 * Geld da ist. Wer etwas nicht bezahlen kann, merkt es sich mit dem Schloss
 * vor - gemerkte Auslagen bleiben beim naechsten Tausch stehen.
 */

const RARITY_FACTOR = {
  common: 'priceCommon',
  rare: 'priceRare',
  epic: 'priceEpic',
  legendary: 'priceLegendary',
};

export class Shop {
  constructor(content, run) {
    this.content = content;
    this.run = run;
    this.config = content.shop || {};
    this.offers = [];
    this.rerolls = 0;
    // Was in diesem Ladenbesuch ueber den Tresen ging.
    this.bought = [];
    this.fill();
  }

  get slotCount() {
    return Math.max(1, Math.min(8, this.config.offerCount || 4));
  }

  get lockLimit() {
    const limit = this.config.lockLimit;
    return typeof limit === 'number' ? limit : 3;
  }

  /** Kosten des naechsten Tauschs - jeder weitere wird teurer. */
  get rerollPrice() {
    const base = this.config.rerollCost ?? 18;
    const growth = this.config.rerollGrowth ?? 1.6;
    return Math.round(base * Math.pow(growth, this.rerolls));
  }

  get canReroll() {
    // Verkaufte Plaetze zaehlen wie freie: Wer alles gekauft hat, darf gegen
    // Aufpreis neue Ware kommen lassen. Nur wenn alles gemerkt ist, gibt es
    // nichts mehr zu tauschen.
    return this.offers.some((o) => !o.locked)
      && this.run.money >= this.rerollPrice;
  }

  /** Fuellt alle freien Plaetze neu. Gemerkte Angebote bleiben stehen. */
  fill() {
    // Alles, was gerade ausliegt, ist tabu - so kommt beim Tausch wirklich
    // etwas Neues und nicht zufaellig zweimal dasselbe.
    const belegt = new Set(this.offers.map((o) => o.id));
    const neu = [];
    let waffen = 0;
    for (let i = 0; i < this.slotCount; i++) {
      const alt = this.offers[i];
      if (alt && alt.locked && !alt.sold) {
        // Preise koennen sich durch zwischenzeitliche Kaeufe verschieben.
        alt.price = this.price(alt);
        neu[i] = alt;
        if (alt.kind === 'weapon') waffen++;
        continue;
      }
      const angebot = this.rollOffer(belegt, waffen === 0);
      if (angebot) {
        belegt.add(angebot.id);
        if (angebot.kind === 'weapon') waffen++;
      }
      neu[i] = angebot;
    }
    this.offers = neu.filter(Boolean);
  }

  /**
   * Wuerfelt ein einzelnes Angebot, das noch nicht ausliegt.
   * Hoechstens eine Waffe je Auslage - sonst steht der Laden schnell voller
   * Waffen, von denen man ohnehin nur eine tragen kann.
   */
  rollOffer(belegt, waffeErlaubt = true) {
    const waffenChance = this.config.weaponChance ?? 0.3;
    const waffen = !waffeErlaubt ? [] : (this.content.weapons || []).filter(
      (w) => w.active !== false && w.id !== this.run.weapon.id && !belegt.has('weapon_' + w.id),
    );
    if (waffen.length && Math.random() < waffenChance) {
      const weapon = pick(waffen);
      const angebot = {
        kind: 'weapon',
        id: 'weapon_' + weapon.id,
        weapon,
        rarity: 'epic',
        title: weapon.name,
        rarityLabel: 'Waffe',
        description: weapon.description || 'Neue Waffe.',
        valueText: Math.round(weapon.damage) + ' Schaden · '
          + (1 / weapon.cooldown).toFixed(1) + '/s',
        note: 'Ersetzt ' + this.run.weapon.name,
        sprite: weapon.sprite,
        level: 1,
        owned: 0,
        locked: false,
        sold: false,
      };
      angebot.price = this.price(angebot);
      return angebot;
    }

    const pool = (this.content.upgrades || []).filter(
      (u) => u.active !== false
        && !belegt.has(u.id)
        && this.run.stackCount(u.id) < u.maxStack,
    );
    if (!pool.length) return null;

    // Wie bei den Karten steigt die Seltenheit mit dem Zyklus.
    const glueck = (this.run.stats.luck || 0) / 10;
    const rarity = rollRarity(this.content.balance, this.run.cycle + glueck);
    let optionen = pool.filter((u) => u.rarity === rarity);
    if (!optionen.length) optionen = pool;

    const upgrade = weighted(optionen, (u) => u.weight);
    const besitz = this.run.stackCount(upgrade.id);
    const angebot = {
      kind: 'upgrade',
      id: upgrade.id,
      upgrade,
      rarity: upgrade.rarity,
      rarityLabel: RARITY_LABEL[upgrade.rarity] || upgrade.rarity,
      title: upgrade.name,
      description: upgrade.description || '',
      valueText: formatModifiers(upgrade),
      totalText: besitz ? formatModifiers(upgrade, besitz + 1) : '',
      note: '',
      icon: upgrade.icon,
      effect: upgrade.effect,
      stat: upgrade.stat,
      owned: besitz,
      level: besitz + 1,
      locked: false,
      sold: false,
    };
    angebot.price = this.price(angebot);
    return angebot;
  }

  /**
   * Preis eines Angebots.
   *
   * Besser heisst teurer: Der Seltenheitsfaktor multipliziert den Grundpreis,
   * spaetere Zyklen schlagen auf, und jede weitere Stufe desselben Upgrades
   * kostet zusaetzlich.
   */
  price(offer) {
    const c = this.config;
    const basis = c.priceBase ?? 26;
    const faktor = offer.kind === 'weapon'
      ? (c.priceWeapon ?? 4)
      : (c[RARITY_FACTOR[offer.rarity]] ?? 1);
    // Die Preise wachsen mit demselben Exponenten wie die Beute. Wuerde
    // das Geld exponentiell und der Preis nur linear steigen, koennte man
    // ab einem gewissen Punkt jede Welle alles kaufen.
    const zyklus = Math.pow(c.priceCycleGrowth ?? 1.13, this.run.progress);
    const stufe = offer.kind === 'weapon'
      ? 1
      : 1 + (c.priceStackBonus ?? 0.45) * this.run.stackCount(offer.id);
    return Math.max(1, Math.round(basis * faktor * zyklus * stufe));
  }

  canBuy(offer) {
    return !!offer && !offer.sold && this.run.money >= offer.price;
  }

  /**
   * Kauft ein Angebot. Gibt zurueck, was passiert ist, damit die Oberflaeche
   * die passende Meldung und Animation zeigen kann.
   */
  buy(offer) {
    if (!this.canBuy(offer)) return null;
    this.run.money -= offer.price;
    offer.sold = true;
    offer.locked = false;

    if (offer.kind === 'weapon') {
      const alt = this.run.weapon;
      this.run.setWeapon(offer.weapon);
      this.bought.push({
        kind: 'weapon', title: offer.weapon.name, price: offer.price,
        detail: 'ersetzt ' + alt.name, sprite: offer.weapon.sprite,
      });
      return { kind: 'weapon', weapon: offer.weapon, previous: alt };
    }

    this.run.addUpgrade(offer.upgrade);
    const stufe = this.run.stackCount(offer.upgrade.id);
    this.bought.push({
      kind: 'upgrade', title: offer.upgrade.name, price: offer.price,
      detail: stufe > 1 ? 'Stufe ' + stufe : offer.valueText,
      rarity: offer.rarity, icon: offer.icon, effect: offer.effect, stat: offer.stat,
    });
    // Der Platz kann jetzt nachgefuellt werden - dafuer ist der naechste
    // Tausch zustaendig, damit man nicht endlos billig weiterkauft.
    return { kind: 'upgrade', upgrade: offer.upgrade, level: stufe };
  }

  toggleLock(offer) {
    if (!offer || offer.sold) return false;
    if (!offer.locked) {
      const gemerkt = this.offers.filter((o) => o.locked && !o.sold).length;
      if (this.lockLimit && gemerkt >= this.lockLimit) return false;
    }
    offer.locked = !offer.locked;
    return true;
  }

  /** Tauscht alle nicht gemerkten Auslagen gegen neue. */
  reroll() {
    if (!this.canReroll) return false;
    this.run.money -= this.rerollPrice;
    this.rerolls++;
    // Verkaufte Plaetze zaehlen wie freie und werden neu bestueckt.
    for (const offer of this.offers) {
      if (offer.sold) offer.locked = false;
    }
    this.fill();
    return true;
  }
}
