import { Arena } from './game/arena.js';
import { GameLoop } from './core/loop.js';
import { Input } from './core/input.js';
import { Assets } from './gfx/assets.js';
import { rollChoices, RARITY_LABEL } from './game/upgrades.js';
import { formatTime, pick } from './core/util.js';
import { Audio } from './core/audio.js';

const content = window.ARENA_CONTENT;
const $ = (id) => document.getElementById(id);
const el = (tag, cls, text) => {
  const node = document.createElement(tag);
  if (cls) node.className = cls;
  if (text != null) node.textContent = text;
  return node;
};

const canvas = $('stage');
const input = new Input(canvas);
input.enabled = false;

let arena = null;
let loop = null;
let selectedMap = null;
let selectedCharacter = null;
let account = null;
let pendingSwap = null;
let hudTimer = 0;
let best = loadBest();

/* ----------------------------------------------------------------- Konto */
async function api(action, body = {}) {
  const res = await fetch('api.php?action=' + action, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
  const data = await res.json().catch(() => ({ ok: false, error: 'Serverfehler' }));
  if (!data.ok) throw new Error(data.error || 'Fehler');
  return data;
}

async function loadAccount() {
  try {
    const data = await api('account');
    account = data.account;
  } catch {
    account = null;
  }
  syncAccountUi();
}

function syncAccountUi() {
  const btn = $('btn-account');
  btn.textContent = account ? account.name : 'Anmelden';
  const badge = $('xp-badge');
  if (badge) {
    badge.textContent = account
      ? `${account.xpAvailable} von ${account.xp} Punkten frei`
      : 'Ohne Konto: kein Fortschritt';
  }
  renderBest();
}

/** Charaktere, die dieses Konto benutzen darf. */
function isUnlocked(character) {
  if (character.starter) return true;
  return !!account && account.unlocked.includes(character.id);
}

function renderAccountScreen() {
  const host = $('account-body');
  host.textContent = '';
  $('account-title').textContent = account ? 'Dein Konto' : 'Anmelden';

  if (account) {
    const rows = [
      ['Erfahrung', `${account.xp} Punkte (${account.xpAvailable} frei)`],
      ['Bester Punktestand', account.bestScore],
      ['Beste Runde', `Zyklus ${account.bestCycle}, ${account.bestWave} Wellen`],
      ['Runs', account.runs],
      ['Kills gesamt', account.totalKills],
    ];
    const grid = el('div', 'statgrid');
    for (const [label, value] of rows) {
      const box = el('div');
      box.appendChild(el('span', null, label));
      box.appendChild(el('b', null, String(value)));
      grid.appendChild(box);
    }
    host.appendChild(grid);
    const out = el('button', 'btn btn--quiet', 'Abmelden');
    out.addEventListener('click', async () => {
      await api('account.logout').catch(() => {});
      account = null;
      syncAccountUi();
      renderAccountScreen();
      toast('Abgemeldet');
    });
    host.appendChild(out);
    return;
  }

  const name = el('input', 'input');
  name.placeholder = 'Benutzername';
  name.autocomplete = 'username';
  name.maxLength = 16;
  const pass = el('input', 'input');
  pass.type = 'password';
  pass.placeholder = 'Passwort';
  pass.autocomplete = 'current-password';

  const error = el('p', 'form-error');
  const login = el('button', 'btn btn--primary btn--lg', 'Anmelden');
  const register = el('button', 'btn', 'Neues Konto anlegen');

  const submit = async (action) => {
    error.textContent = '';
    login.disabled = true;
    register.disabled = true;
    try {
      const data = await api(action, { name: name.value, password: pass.value });
      account = data.account;
      syncAccountUi();
      renderAccountScreen();
      toast('Willkommen, ' + account.name);
    } catch (err) {
      error.textContent = err.message;
    }
    login.disabled = false;
    register.disabled = false;
  };
  login.addEventListener('click', () => submit('account.login'));
  register.addEventListener('click', () => submit('account.register'));
  pass.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') submit('account.login');
  });

  host.appendChild(el('p', 'muted', 'Mit Konto werden Erfahrung, Bestwerte und freigeschaltete Charaktere gespeichert. Ohne Konto kannst du normal spielen, der Fortschritt bleibt dann aber nicht erhalten.'));
  host.appendChild(name);
  host.appendChild(pass);
  host.appendChild(login);
  host.appendChild(register);
  host.appendChild(error);
}

/* ------------------------------------------------------------ Charaktere */
function renderCharacters() {
  const host = $('character-list');
  host.textContent = '';
  const list = (content.characters || []).filter((c) => c.active !== false)
    .sort((a, b) => (a.order || 0) - (b.order || 0));

  for (const character of list) {
    const unlocked = isUnlocked(character);
    const card = el('button', 'char' + (unlocked ? '' : ' is-locked')
      + (selectedCharacter && selectedCharacter.id === character.id ? ' is-selected' : ''));

    const art = el('div', 'char__art');
    const img = el('img');
    img.src = (character.sprites && character.sprites.front && (character.sprites.front.frames?.[0] || character.sprites.front.gif)) || '';
    img.alt = '';
    if (character.tint) img.style.filter = `hue-rotate(${character.tint}deg) saturate(1.15)`;
    art.appendChild(img);
    card.appendChild(art);

    const body = el('div', 'char__body');
    body.appendChild(el('div', 'char__name', character.name));
    body.appendChild(el('div', 'char__title', character.title || ''));
    body.appendChild(el('div', 'char__desc', character.description || ''));

    const tags = el('div', 'char__tags');
    for (const text of characterHighlights(character)) tags.appendChild(el('span', 'tag', text));
    body.appendChild(tags);
    card.appendChild(body);

    if (!unlocked) {
      const lock = el('div', 'char__lock');
      lock.appendChild(el('span', null, '🔒 ' + character.unlockCost + ' Punkte'));
      card.appendChild(lock);
    }

    card.addEventListener('click', () => {
      if (unlocked) {
        selectedCharacter = character;
        renderCharacters();
        showScreen('screen-weapon');
        return;
      }
      unlockCharacter(character);
    });
    host.appendChild(card);
  }
}

/** Kurze, lesbare Zusammenfassung der Fähigkeiten. */
function characterHighlights(character) {
  const mods = character.mods || {};
  const out = [];
  const pct = (value) => (value > 1 ? '+' : '') + Math.round((value - 1) * 100) + '%';
  if (mods.maxHealth && mods.maxHealth !== 1) out.push(pct(mods.maxHealth) + ' Leben');
  if (mods.moveSpeed && mods.moveSpeed !== 1) out.push(pct(mods.moveSpeed) + ' Tempo');
  if (mods.damageMult && mods.damageMult !== 1) out.push(pct(mods.damageMult) + ' Schaden');
  if (mods.attackSpeed && mods.attackSpeed !== 1) out.push(pct(mods.attackSpeed) + ' Angriffstempo');
  if (mods.range && mods.range !== 1) out.push(pct(mods.range) + ' Reichweite');
  if (mods.projectileSpeed && mods.projectileSpeed !== 1) out.push(pct(mods.projectileSpeed) + ' Projektiltempo');
  if (mods.armor) out.push('+' + mods.armor + ' Rüstung');
  if (mods.critChance) out.push('+' + mods.critChance + '% Krit');
  if (mods.shield) out.push('+' + mods.shield + ' Schild');
  const perks = {
    lifesteal: 'Kills heilen dich',
    thorns: 'Nahkampfschaden zurück',
    luckyCards: 'Bessere Upgrade-Karten',
  };
  if (perks[character.perk]) out.push(perks[character.perk]);
  return out;
}

async function unlockCharacter(character) {
  if (!account) {
    toast('Zum Freischalten brauchst du ein Konto.', 'error');
    showScreen('screen-account');
    renderAccountScreen();
    return;
  }
  try {
    const data = await api('account.unlock', { id: character.id });
    account = data.account;
    syncAccountUi();
    renderCharacters();
    toast(character.name + ' freigeschaltet!');
    Audio.play('upgrade');
  } catch (err) {
    toast(err.message, 'error');
  }
}

/* --------------------------------------------------------- Bestenliste */
async function renderScores() {
  const host = $('score-list');
  host.textContent = '';
  $('score-own').textContent = account
    ? `Dein Bestwert: ${account.bestScore} Punkte · Zyklus ${account.bestCycle} · ${account.xp} Erfahrung`
    : 'Melde dich an, damit deine Ergebnisse gezählt werden.';
  try {
    const data = await api('leaderboard', { limit: 25 });
    if (!data.entries.length) {
      host.appendChild(el('p', 'muted', 'Noch keine Einträge - spiele den ersten Run.'));
      return;
    }
    data.entries.forEach((entry, index) => {
      const row = el('div', 'scorerow' + (account && entry.name === account.name ? ' is-me' : ''));
      row.appendChild(el('span', 'scorerow__rank', '#' + (index + 1)));
      row.appendChild(el('span', 'scorerow__name', entry.name));
      row.appendChild(el('span', 'scorerow__meta', `Zyklus ${entry.cycle} · ${entry.waves} Wellen`));
      row.appendChild(el('span', 'scorerow__score', String(entry.score)));
      host.appendChild(row);
    });
  } catch (err) {
    host.appendChild(el('p', 'form-error', err.message));
  }
}

/* ----------------------------------------------------------------- Audio */
function initAudio() {
  const cfg = content.audio || {};
  Audio.configure(cfg);
  if (cfg.musicEnabled === false) Audio.setMusicOn(false);
  syncSoundButtons();
  if (Audio.settings.musicOn) Audio.startMusic();
}

function syncSoundButtons() {
  const on = Audio.settings.musicOn;
  const icon = $('btn-sound');
  if (icon) {
    icon.textContent = on ? '♪' : '✕';
    icon.title = on ? 'Musik aus' : 'Musik an';
    icon.style.opacity = on ? '1' : '0.55';
  }
  const menuBtn = $('btn-sound-menu');
  if (menuBtn) menuBtn.textContent = 'Musik: ' + (on ? 'an' : 'aus');
}

function toggleSound() {
  Audio.setMusicOn(!Audio.settings.musicOn);
  Audio.setSfxOn(Audio.settings.musicOn);
  syncSoundButtons();
}

/* --------------------------------------------------------------- Screens */
function showScreen(id) {
  document.querySelectorAll('.screen').forEach((s) => s.classList.toggle('is-active', s.id === id));
  const inGame = !id;
  $('hud').hidden = !inGame;
  input.enabled = inGame;
}

function toast(message, kind) {
  const node = el('div', 'toast' + (kind ? ' is-' + kind : ''), message);
  $('toasts').appendChild(node);
  setTimeout(() => node.remove(), 2600);
}

function loadBest() {
  try {
    return JSON.parse(localStorage.getItem('arena.best') || 'null') || { cycle: 0, kills: 0, time: 0 };
  } catch {
    return { cycle: 0, kills: 0, time: 0 };
  }
}

function saveBest(summary) {
  if (summary.cycle > best.cycle || (summary.cycle === best.cycle && summary.kills > best.kills)) {
    best = { cycle: summary.cycle, kills: summary.kills, time: summary.timeAlive };
    try {
      localStorage.setItem('arena.best', JSON.stringify(best));
    } catch { /* privater Modus */ }
  }
  renderBest();
}

function renderBest() {
  const local = best.cycle
    ? `Bester Run: Zyklus ${best.cycle} · ${best.kills} Kills · ${formatTime(best.time)}`
    : '';
  $('menu-best').textContent = account
    ? `${account.name} · ${account.bestScore} Punkte · ${account.xp} Erfahrung`
    : local;
}

/* ----------------------------------------------------------------- Welten */
function activeMaps() {
  return content.maps.filter((m) => m.active && m.image);
}

function renderWorlds() {
  const host = $('world-list');
  host.textContent = '';
  const maps = activeMaps();
  if (!maps.length) {
    host.appendChild(el('p', 'muted', 'Keine aktive Welt. Lege im Admin-Bereich eine Map an.'));
    return;
  }
  for (const map of maps) {
    const card = el('button', 'world' + (selectedMap && selectedMap.id === map.id ? ' is-selected' : ''));
    const img = el('img', 'world__img');
    img.src = map.image;
    img.alt = map.name;
    img.loading = 'lazy';
    card.appendChild(img);
    const body = el('div', 'world__body');
    body.appendChild(el('div', 'world__name', map.name));
    body.appendChild(el('div', 'world__meta', `${map.width} × ${map.height}`));
    card.appendChild(body);
    card.addEventListener('click', () => {
      selectedMap = map;
      renderWorlds();
      renderCharacters();
      showScreen('screen-character');
    });
    host.appendChild(card);
  }
}

/* ----------------------------------------------------------------- Waffen */
function starterWeapons() {
  const active = content.weapons.filter((w) => w.active);
  const starters = active.filter((w) => w.starter);
  return starters.length ? starters : active.slice(0, 4);
}

function renderWeapons() {
  const host = $('weapon-list');
  host.textContent = '';
  for (const weapon of starterWeapons()) {
    const card = el('button', 'weapon');
    const top = el('div', 'weapon__top');
    const icon = el('img', 'weapon__icon');
    icon.src = weapon.sprite;
    icon.alt = '';
    top.appendChild(icon);
    const names = el('div');
    names.appendChild(el('div', 'weapon__name', weapon.name));
    names.appendChild(el('div', 'weapon__type', weapon.type.replace('_', ' ')));
    top.appendChild(names);
    card.appendChild(top);
    card.appendChild(el('div', 'weapon__desc', weapon.description || ''));

    const stats = el('div', 'weapon__stats');
    stats.appendChild(el('span', 'tag', Math.round(weapon.damage) + ' Schaden'));
    stats.appendChild(el('span', 'tag', (1 / weapon.cooldown).toFixed(1) + ' Angriffe/s'));
    stats.appendChild(el('span', 'tag', Math.round(weapon.range) + ' Reichweite'));
    if (weapon.aoeRadius > 0) stats.appendChild(el('span', 'tag', 'AoE ' + Math.round(weapon.aoeRadius)));
    card.appendChild(stats);

    card.addEventListener('click', () => startRun(weapon));
    host.appendChild(card);
  }
}

/* -------------------------------------------------------------- Run-Start */
async function startRun(weapon) {
  const maps = activeMaps();
  if (!maps.length) {
    toast('Keine Welt vorhanden - lege im Admin eine Map an.', 'error');
    return;
  }
  const mapDef = selectedMap && maps.find((m) => m.id === selectedMap.id) ? selectedMap : pick(maps);

  showScreen('screen-none');
  $('loading').hidden = false;
  $('loading-text').textContent = 'Lade Welt ...';

  if (loop) loop.stop();
  if (arena) arena.destroy();

  const character = selectedCharacter
    || (content.characters || []).find((c) => c.active !== false && isUnlocked(c))
    || null;
  selectedCharacter = character;
  arena = new Arena({ canvas, content, mapDef, weaponDef: weapon, character, input });
  wireArena(arena);
  await arena.load();

  $('loading').hidden = true;
  showScreen(null);
  updateHud(true);

  loop = new GameLoop({
    update: (dt, time) => arena.update(dt, time),
    render: (dt, time) => {
      arena.render(dt, time);
      hudTimer += dt;
      if (hudTimer > 0.08) {
        hudTimer = 0;
        updateHud();
      }
    },
  });
  loop.start();
  arena.start();
  Audio.duckMusic(false);
  if (Audio.settings.musicOn) Audio.startMusic();
  banner('Welle 1', false);
}

function wireArena(instance) {
  instance.on('waveEnd', (info) => {
    loop.setPaused(true);
    showUpgrades(info);
  });
  instance.on('waveStart', (info) => {
    banner(info.boss ? 'Boss!' : `Welle ${info.wave}`, info.boss);
    $('hud-boss').hidden = !info.boss;
  });
  instance.on('bossSpawn', () => {
    $('hud-boss').hidden = false;
    $('boss-name').textContent = (instance.boss && instance.boss.def.name) || 'Boss';
  });
  instance.on('bossEnrage', () => toast('Der Boss wird wütend!', 'error'));
  instance.on('death', (summary) => {
    loop.setPaused(true);
    Audio.duckMusic(true);
    saveBest(summary);
    showDeath(summary);
    submitRun(summary);
  });
}

function banner(text, boss) {
  const node = $('wave-banner');
  node.textContent = text;
  node.className = 'banner' + (boss ? ' banner--boss' : '');
  node.hidden = false;
  clearTimeout(banner._t);
  banner._t = setTimeout(() => {
    node.hidden = true;
  }, 2200);
}

/* -------------------------------------------------------------------- HUD */
function updateHud(force) {
  if (!arena) return;
  const run = arena.run;
  const stats = run.stats;

  $('hud-wave').textContent = `${run.cycle}-${run.wave}`;
  $('hud-cycle').textContent = run.wave === 4 ? 'Boss' : 'Welle';
  $('hud-timer').textContent = formatTime(Math.max(0, arena.waves.timeLeft));
  $('hud-money').textContent = run.money;

  const hpRatio = Math.max(0, run.health / stats.maxHealth);
  $('hp-fill').style.width = (hpRatio * 100).toFixed(1) + '%';
  $('hp-text').textContent = `${Math.ceil(run.health)} / ${stats.maxHealth}`;

  const shieldBar = $('shield-bar');
  if (stats.maxShield > 0) {
    shieldBar.hidden = false;
    $('shield-fill').style.width = ((run.shield / stats.maxShield) * 100).toFixed(1) + '%';
  } else {
    shieldBar.hidden = true;
  }

  const boss = arena.boss;
  if (boss && boss.alive) {
    $('hud-boss').hidden = false;
    const ratio = Math.max(0, boss.health / boss.maxHealth);
    $('boss-fill').style.width = (ratio * 100).toFixed(1) + '%';
    $('boss-hp-text').textContent = `${Math.ceil(boss.health)} / ${Math.round(boss.maxHealth)}`;
  } else if (arena.run.wave !== 4) {
    $('hud-boss').hidden = true;
  }

  if (force) {
    $('hud-weapon-icon').src = run.weapon.sprite;
    $('hud-weapon-name').textContent = run.weapon.name;
  }

  if (arena.debug) {
    $('debug-panel').hidden = false;
    $('debug-panel').textContent =
      `FPS ${loop ? loop.fps : 0}\nGegner ${arena.enemies.count}\nProjektile ${arena.projectiles.count}\n` +
      `Partikel ${arena.effects.particles.count}\nPos ${Math.round(arena.player.x)}, ${Math.round(arena.player.y)}`;
  } else {
    $('debug-panel').hidden = true;
  }
}

/* --------------------------------------------------------------- Upgrades */
function showUpgrades(info) {
  Audio.duckMusic(true);
  const cards = rollChoices(content, arena.run, content.balance.upgradeChoices || 3);
  const host = $('upgrade-cards');
  host.textContent = '';
  $('upgrade-kicker').textContent = info.boss
    ? `Boss besiegt - Zyklus ${arena.run.cycle}`
    : `Welle ${info.wave} geschafft`;

  for (const card of cards) {
    const node = el('button', `card card--${card.rarity}`);
    node.appendChild(el('div', 'card__rarity', RARITY_LABEL[card.rarity] || card.rarity));

    if (card.kind === 'weapon') {
      const img = el('img', 'card__icon');
      img.src = card.sprite;
      img.alt = '';
      node.appendChild(img);
    } else if (card.icon) {
      const img = el('img', 'card__icon');
      img.src = card.icon;
      img.alt = '';
      node.appendChild(img);
    } else {
      node.appendChild(el('div', 'card__icon--stat', statIcon(card.upgrade.stat)));
    }

    node.appendChild(el('div', 'card__title', card.title));
    node.appendChild(el('div', 'card__value', card.valueText));
    node.appendChild(el('div', 'card__desc', card.description || ''));
    node.addEventListener('click', () => chooseCard(card));
    host.appendChild(node);
  }

  $('overlay-upgrade').hidden = false;
}

function statIcon(stat) {
  return {
    damage: '⚔', attackSpeed: '⚡', moveSpeed: '👟', maxHealth: '❤', armor: '🛡',
    shield: '🔷', critChance: '🎯', critDamage: '💥', projectileSpeed: '🏹',
    range: '📏', knockback: '💨', dodge: '🌀', regen: '✚',
  }[stat] || '★';
}

function chooseCard(card) {
  if (card.kind === 'weapon') {
    pendingSwap = card.weapon;
    $('swap-text').textContent =
      `Du trägst ${arena.run.weapon.name}. ${card.weapon.name} ersetzt sie für den Rest des Runs.`;
    $('overlay-swap').hidden = false;
    return;
  }
  arena.run.addUpgrade(card.upgrade);
  Audio.play('upgrade');
  finishUpgrade();
}

function finishUpgrade() {
  Audio.duckMusic(false);
  $('overlay-upgrade').hidden = true;
  $('overlay-swap').hidden = true;
  updateHud(true);
  arena.waves.advance();
  loop.setPaused(false);
}

/* ------------------------------------------------------------------ Stats */
function showStats() {
  if (!arena) return;
  loop.setPaused(true);
  Audio.duckMusic(true);
  const grid = $('stats-grid');
  grid.textContent = '';
  for (const [label, value] of arena.run.snapshot()) {
    const box = el('div');
    box.appendChild(el('span', null, label));
    box.appendChild(el('b', null, String(value)));
    grid.appendChild(box);
  }

  const list = $('stats-upgrades');
  list.textContent = '';
  if (!arena.run.upgrades.length) {
    list.appendChild(el('span', 'muted', 'Noch keine Upgrades gewählt.'));
  }
  for (const entry of arena.run.upgrades) {
    const chip = el('div', 'upgradechip');
    chip.style.setProperty('--rarity', rarityColor(entry.upgrade.rarity));
    chip.appendChild(el('b', null, entry.upgrade.name));
    chip.appendChild(document.createTextNode(' ×' + entry.count));
    list.appendChild(chip);
  }
  $('overlay-stats').hidden = false;
}

function rarityColor(rarity) {
  return { common: '#9aa6c2', rare: '#58b6ff', epic: '#c07bff', legendary: '#ffb020' }[rarity] || '#9aa6c2';
}

/** Meldet das Ergebnis an das Konto: je geschaffter Welle ein Punkt. */
async function submitRun(summary) {
  if (!account) return;
  try {
    const data = await api('account.run', {
      result: {
        waves: summary.wavesCleared || 0,
        kills: summary.kills,
        cycle: summary.cycle,
        money: summary.money,
        time: Math.round(summary.timeAlive),
      },
    });
    if (data.account) {
      const gained = data.account.xp - account.xp;
      account = data.account;
      syncAccountUi();
      const note = $('death-xp');
      if (note) {
        note.textContent = gained > 0
          ? `+${gained} Erfahrung · insgesamt ${account.xp}`
          : `Erfahrung: ${account.xp}`;
        note.hidden = false;
      }
    }
  } catch {
    // Ohne Verbindung geht der Run nicht verloren, er zählt nur nicht.
  }
}

/* ------------------------------------------------------------------- Tod */
function showDeath(summary) {
  const grid = $('death-grid');
  grid.textContent = '';
  const rows = [
    ['Überlebt', formatTime(summary.timeAlive)],
    ['Zyklus', summary.cycle],
    ['Welle', summary.wave],
    ['Kills', summary.kills],
    ['Bosse', summary.bossKills],
    ['Geld', summary.money],
    ['Schaden', summary.damageDealt],
    ['Erlitten', summary.damageTaken],
    ['Charakter', summary.character || '-'],
    ['Wellen', summary.wavesCleared || 0],
    ['Punktestand', (summary.wavesCleared || 0) * 100 + summary.kills * 5 + summary.money],
    ['Waffe', summary.weapon],
    ['Meistgenutzt', summary.favouriteWeapon || summary.weapon],
  ];
  for (const [label, value] of rows) {
    const box = el('div');
    box.appendChild(el('span', null, label));
    box.appendChild(el('b', null, String(value)));
    grid.appendChild(box);
  }

  const list = $('death-upgrades');
  list.textContent = '';
  for (const u of summary.upgrades) {
    const chip = el('div', 'upgradechip');
    chip.style.setProperty('--rarity', rarityColor(u.rarity));
    chip.appendChild(el('b', null, u.name));
    chip.appendChild(document.createTextNode(' ×' + u.count));
    list.appendChild(chip);
  }
  $('overlay-death').hidden = false;
}

function quitToMenu() {
  if (loop) loop.stop();
  if (arena) arena.destroy();
  arena = null;
  loop = null;
  input.reset();
  ['overlay-death', 'overlay-pause', 'overlay-stats', 'overlay-upgrade', 'overlay-swap']
    .forEach((id) => { $(id).hidden = true; });
  showScreen('screen-menu');
}

/* --------------------------------------------------------------- Bindings */
$('btn-play').addEventListener('click', () => {
  selectedMap = null;
  renderCharacters();
  showScreen('screen-character');
});
$('btn-scores').addEventListener('click', () => {
  showScreen('screen-scores');
  renderScores();
});
$('btn-account').addEventListener('click', () => {
  renderAccountScreen();
  showScreen('screen-account');
});
$('btn-worlds').addEventListener('click', () => {
  renderWorlds();
  showScreen('screen-worlds');
});
document.querySelectorAll('[data-back]').forEach((b) => b.addEventListener('click', () => showScreen('screen-menu')));
document.querySelectorAll('[data-close-stats]').forEach((b) =>
  b.addEventListener('click', () => {
    $('overlay-stats').hidden = true;
    if (!arena || !arena.isIntermission) Audio.duckMusic(false);
    if (loop && !arena.isIntermission && !arena.gameOver) loop.setPaused(false);
  }),
);

$('btn-stats').addEventListener('click', showStats);
$('btn-sound').addEventListener('click', toggleSound);
$('btn-sound-menu').addEventListener('click', toggleSound);
$('btn-pause').addEventListener('click', () => {
  if (!loop) return;
  loop.setPaused(true);
  Audio.duckMusic(true);
  $('overlay-pause').hidden = false;
});
$('pause-resume').addEventListener('click', () => {
  $('overlay-pause').hidden = true;
  Audio.duckMusic(false);
  if (!arena.isIntermission && !arena.gameOver) loop.setPaused(false);
});
$('pause-debug').addEventListener('click', () => {
  arena.debug = !arena.debug;
  updateHud(true);
});
$('pause-quit').addEventListener('click', quitToMenu);
$('death-retry').addEventListener('click', () => {
  const weapon = arena.run.weapon;
  $('overlay-death').hidden = true;
  startRun(weapon);
});
$('death-menu').addEventListener('click', quitToMenu);
$('swap-yes').addEventListener('click', () => {
  arena.run.setWeapon(pendingSwap);
  arena.weapon.reset();
  Audio.play('upgrade');
  pendingSwap = null;
  finishUpgrade();
});
$('swap-no').addEventListener('click', () => {
  pendingSwap = null;
  $('overlay-swap').hidden = true;
});

window.addEventListener('resize', () => {
  if (arena) arena.resize();
  checkOrientation();
});
window.addEventListener('orientationchange', () => setTimeout(() => {
  if (arena) arena.resize();
  checkOrientation();
}, 250));

// Pausieren, sobald das Spiel aus dem Blick geraet.
document.addEventListener('visibilitychange', () => {
  if (document.hidden && loop && !loop.paused) {
    loop.setPaused(true);
    if (arena && !arena.gameOver && !arena.isIntermission) $('overlay-pause').hidden = false;
  }
});
window.addEventListener('blur', () => {
  if (loop && !loop.paused && arena && !arena.gameOver && !arena.isIntermission) {
    loop.setPaused(true);
    $('overlay-pause').hidden = false;
  }
});

// Browser-Gesten auf der Spielfläche unterbinden.
canvas.addEventListener('touchmove', (e) => e.preventDefault(), { passive: false });
document.addEventListener('gesturestart', (e) => e.preventDefault());
document.addEventListener('dblclick', (e) => e.preventDefault(), { passive: false });
window.addEventListener('keydown', (e) => {
  if (e.code === 'F2' && arena) {
    arena.debug = !arena.debug;
    updateHud(true);
  }
  if (e.code === 'Escape' && loop && arena && !arena.gameOver) {
    loop.setPaused(true);
    $('overlay-pause').hidden = false;
  }
});

function checkOrientation() {
  const portrait = window.innerHeight > window.innerWidth;
  const small = Math.min(window.innerWidth, window.innerHeight) < 560;
  $('rotate-hint').hidden = !(portrait && small && arena);
}

/* ------------------------------------------------------------------ Start */
(async function boot() {
  $('loading').hidden = false;
  $('loading-text').textContent = 'Lade Sprites ...';
  // Menü-Assets vorab laden, damit die Waffenauswahl sofort steht.
  await Assets.loadAll(content.weapons.filter((w) => w.active).map((w) => w.sprite));
  renderBest();
  renderWeapons();
  initAudio();
  loadAccount();
  $('loading').hidden = true;
  showScreen('screen-menu');
  if (Assets.missing.length) {
    console.warn('Fehlende Assets:', Assets.missing);
  }
})();

window.__assets = { Assets };

window.ARENA_DEBUG = {
  get arena() {
    return arena;
  },
  get loop() {
    return loop;
  },
  startRun,
  content,
};
