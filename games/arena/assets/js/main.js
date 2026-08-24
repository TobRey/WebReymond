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
let musicWatch = 0;
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
    img.alt = '';
    if (character.tint) img.style.filter = `hue-rotate(${character.tint}deg) saturate(1.15)`;
    const quelle = (character.sprites && character.sprites.front
      && (character.sprites.front.frames?.[0] || character.sprites.front.gif)) || '';
    stillbild(quelle).then((src) => { img.src = src; });
    art.appendChild(img);
    card.appendChild(art);

    const body = el('div', 'char__body');
    body.appendChild(el('div', 'char__name', character.name));
    body.appendChild(el('div', 'char__title', character.title || ''));
    body.appendChild(el('div', 'char__desc', character.description || ''));

    const perk = perkInfo(character);
    if (perk) {
      const skill = el('div', 'char__perk');
      skill.appendChild(el('b', null, perk.label));
      skill.appendChild(el('span', null, perk.description));
      body.appendChild(skill);
    }

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

/**
 * Erstes Bild einer Datei als Standbild.
 *
 * Auf dem Charakterbildschirm stehen fünfzehn Karten. Als animierte GIFs
 * dekodiert der Browser sie alle dauerhaft weiter - das allein macht das
 * Menü auf dem Handy zäh. Ein einmal gezeichnetes Standbild kostet nichts,
 * und weil sich die Figuren dieselbe Datei teilen, fällt die Arbeit nur
 * einmal an. Die Auflösung bleibt dabei unangetastet.
 */
const stillbilder = new Map();
function stillbild(src) {
  if (!src) return Promise.resolve('');
  if (stillbilder.has(src)) return stillbilder.get(src);

  const task = new Promise((resolve) => {
    const img = new Image();
    img.onload = () => {
      try {
        const canvas = document.createElement('canvas');
        canvas.width = img.naturalWidth;
        canvas.height = img.naturalHeight;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(img, 0, 0);
        resolve(canvas.toDataURL('image/png'));
      } catch {
        resolve(src);           // z. B. wenn der Canvas blockiert ist
      }
    };
    img.onerror = () => resolve('');
    img.src = src;
  });

  stillbilder.set(src, task);
  return task;
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
  if (mods.knockback && mods.knockback !== 1) out.push(pct(mods.knockback) + ' Rückstoß');
  if (mods.pickupRange && mods.pickupRange !== 1) out.push(pct(mods.pickupRange) + ' Aufsammeln');
  if (mods.potionRate && mods.potionRate !== 1) out.push(pct(mods.potionRate) + ' Heilflaschen');
  if (mods.money && mods.money !== 1) out.push(pct(mods.money) + ' Geld');
  if (mods.armor) out.push('+' + mods.armor + ' Rüstung');
  if (mods.critChance) out.push('+' + mods.critChance + '% Krit');
  if (mods.critDamage) out.push('+' + mods.critDamage + '% Krit-Schaden');
  if (mods.dodge) out.push('+' + mods.dodge + '% Ausweichen');
  if (mods.regen) out.push('+' + mods.regen + ' HP/s');
  if (mods.shield) out.push('+' + mods.shield + ' Schild');
  if (mods.burn) out.push('+' + mods.burn + ' Feuerschaden');
  return out;
}

/** Name und Wirkung der Spezialfähigkeit - kommt aus den Spieldaten. */
function perkInfo(character) {
  const catalogue = window.ARENA_PERKS || {};
  const entry = catalogue[character.perk || ''];
  if (!entry || !character.perk) return null;
  return entry;
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
  Audio.configure(content);
  if (cfg.musicEnabled === false) Audio.setMusicOn(false);
  syncSoundButtons();
  if (Audio.settings.musicOn) Audio.startMusic();
}

function syncSoundButtons() {
  const s = Audio.settings;
  const stumm = Audio.muted;
  const icon = $('btn-sound');
  if (icon) {
    icon.textContent = stumm ? '🔇' : '🔊';
    icon.title = stumm ? 'Ton an' : 'Ton aus';
    icon.style.opacity = stumm ? '0.55' : '1';
  }
  const menuBtn = $('btn-sound-menu');
  if (menuBtn) menuBtn.textContent = 'Ton: ' + (stumm ? 'aus' : 'an');

  // Regler in der Pause spiegeln denselben Zustand.
  const music = $('opt-music');
  const sfx = $('opt-sfx');
  const volMusic = $('vol-music');
  const volSfx = $('vol-sfx');
  if (music) music.checked = s.musicOn;
  if (sfx) sfx.checked = s.sfxOn;
  if (volMusic) {
    volMusic.value = String(Math.round((s.musicVolume ?? 1) * 100));
    volMusic.disabled = !s.musicOn;
  }
  if (volSfx) {
    volSfx.value = String(Math.round((s.sfxVolume ?? 1) * 100));
    volSfx.disabled = !s.sfxOn;
  }
}

/** Das Lautsprechersymbol schaltet alles stumm - Musik und Effekte. */
function toggleSound() {
  Audio.setMuted(!Audio.muted);
  syncSoundButtons();
  if (!Audio.muted) Audio.play('uiClick');
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
  $('loading-hint').hidden = true;
  $('loading-bar').firstElementChild.style.width = '0%';
  // Dauert es auffällig lange, sagen wir das offen statt nur zu drehen.
  clearTimeout(startRun._slowHint);
  startRun._slowHint = setTimeout(() => {
    const hint = $('loading-hint');
    hint.hidden = false;
    hint.textContent = Assets.missing.length
      ? 'Diese Dateien fehlen oder antworten nicht: ' + Assets.missing.join(', ')
      : 'Die Verbindung ist gerade langsam - das Spiel startet, sobald die Karte da ist.';
  }, 8000);

  if (loop) loop.stop();
  if (arena) arena.destroy();

  const character = selectedCharacter
    || (content.characters || []).find((c) => c.active !== false && isUnlocked(c))
    || null;
  selectedCharacter = character;
  arena = new Arena({ canvas, content, mapDef, weaponDef: weapon, character, input });
  wireArena(arena);
  await arena.load((done, total) => {
    $('loading-text').textContent = `Lade Welt ... ${done} von ${total}`;
    $('loading-bar').firstElementChild.style.width = Math.round((done / total) * 100) + '%';
  });
  clearTimeout(startRun._slowHint);
  Audio.warm();          // Grafiken sind da - jetzt dürfen die Töne laden

  $('loading').hidden = true;
  showScreen(null);
  updateHud(true);

  loop = new GameLoop({
    update: (dt, time) => arena.update(dt, time),
    render: (dt, time) => {
      // Steht ein Fenster offen, wird nicht weitergezeichnet.
      //
      // Die Overlays liegen mit backdrop-filter über der Leinwand. Zeichnet
      // die Leinwand darunter weiter, muss der Browser den Weichzeichner in
      // jedem Bild neu über den ganzen Bildschirm legen - das hat Pause,
      // Statistik und Upgrade-Auswahl auf dem Handy zäh gemacht. Das letzte
      // Bild bleibt einfach stehen.
      if (loop.paused) return;
      arena.render(dt, time, loop.fps);
      hudTimer += dt;
      if (hudTimer > 0.08) {
        hudTimer = 0;
        updateHud();
      }
    },
  });
  loop.start();
  arena.start();
  Audio.play('gameStart');
  Audio.ensureMusic();
  banner('Welle 1', false);

  // Sicherheitsnetz: Weist der Browser die Wiedergabe einmal ab, versucht
  // es das Spiel während des Runs regelmäßig erneut.
  clearInterval(musicWatch);
  musicWatch = setInterval(() => {
    if (!arena || arena.gameOver) return;
    if (loop && !loop.paused) Audio.ensureMusic();
  }, 4000);
}

function wireArena(instance) {
  instance.on('waveEnd', (info) => {
    loop.setPaused(true);
    showUpgrades(info);
  });
  instance.on('waveStart', (info) => {
    banner(info.boss ? 'Boss!' : `Welle ${info.wave}`, info.boss);
    $('hud-boss').hidden = !info.boss;
    // Im Kampf soll die Musik immer laufen - nicht irgendwann von allein.
    Audio.ensureMusic();
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
      `Partikel ${arena.effects.particles.count}\nFlaschen ${arena.pickups.count}\n` +
      `Brennend ${arena.enemies.list.filter((e) => e.burnTime > 0).length}\n` +
      `Pos ${Math.round(arena.player.x)}, ${Math.round(arena.player.y)}`;
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
    burn: '🔥', potionRate: '🧪',
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
  if (!arena || !loop) return;
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
  clearInterval(musicWatch);
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
/**
 * Zurueck ins laufende Spiel.
 *
 * Ein einziger Weg fuer alle Wege hinaus: Pause, Statistik, Fenster wieder
 * im Vordergrund. Vorher konnte man haengenbleiben, wenn ein Overlay zwar
 * zuging, die Schleife aber pausiert blieb.
 */
function resumeGame() {
  $('overlay-pause').hidden = true;
  $('overlay-stats').hidden = true;
  if (!arena || !loop) return;
  // Waehrend Upgrade-Auswahl, Waffentausch oder nach dem Tod bleibt es pausiert.
  const blockiert = !$('overlay-upgrade').hidden || !$('overlay-swap').hidden
    || arena.gameOver || arena.isIntermission;
  Audio.duckMusic(blockiert);
  if (!blockiert) {
    input.reset();
    loop.setPaused(false);
    Audio.ensureMusic();
  }
}

document.querySelectorAll('[data-close-stats]').forEach((b) => b.addEventListener('click', resumeGame));

// Tippen neben das Fenster schliesst Pause und Statistik ebenfalls.
document.querySelectorAll('.overlay[data-dismiss]').forEach((o) =>
  o.addEventListener('pointerdown', (e) => {
    if (e.target === o) resumeGame();
  }),
);

$('btn-stats').addEventListener('click', showStats);
$('btn-sound').addEventListener('click', toggleSound);
$('btn-sound-menu').addEventListener('click', toggleSound);
$('btn-pause').addEventListener('click', pauseGame);
$('pause-resume').addEventListener('click', resumeGame);

function pauseGame() {
  if (!loop || !arena) return;
  loop.setPaused(true);
  Audio.duckMusic(true);
  syncSoundButtons();
  $('overlay-pause').hidden = false;
}

// Ton-Regler in der Pause.
$('opt-music').addEventListener('change', (e) => {
  Audio.setMusicOn(e.target.checked);
  syncSoundButtons();
});
$('opt-sfx').addEventListener('change', (e) => {
  Audio.setSfxOn(e.target.checked);
  syncSoundButtons();
  if (e.target.checked) Audio.play('uiClick');
});
$('vol-music').addEventListener('input', (e) => Audio.setMusicVolume(+e.target.value / 100));
$('vol-sfx').addEventListener('input', (e) => {
  Audio.setSfxVolume(+e.target.value / 100);
});
$('vol-sfx').addEventListener('change', () => Audio.play('uiClick'));
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
  if (arena) {
    arena.resize();
    // Beim Ändern der Größe wird die Leinwand geleert. Steht das Spiel
    // gerade still, muss einmal von Hand nachgezeichnet werden - sonst
    // bliebe hinter dem Fenster nur Schwarz.
    if (loop && loop.paused) arena.render(0, loop.time, 0);
  }
  checkOrientation();
});
window.addEventListener('orientationchange', () => setTimeout(() => {
  if (arena) arena.resize();
  checkOrientation();
}, 250));

// Pausieren, sobald das Spiel aus dem Blick geraet.
document.addEventListener('visibilitychange', () => {
  if (document.hidden && loop && !loop.paused && arena && !arena.gameOver && !arena.isIntermission) {
    pauseGame();
  }
});
window.addEventListener('blur', () => {
  if (loop && !loop.paused && arena && !arena.gameOver && !arena.isIntermission) pauseGame();
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
    // Escape schaltet um: auf und wieder zu.
    if ($('overlay-pause').hidden && $('overlay-stats').hidden) pauseGame();
    else resumeGame();
  }
});

function checkOrientation() {
  const portrait = window.innerHeight > window.innerWidth;
  const small = Math.min(window.innerWidth, window.innerHeight) < 560;
  $('rotate-hint').hidden = !(portrait && small && arena);
}

/* ------------------------------------------------------------------ Start */
(async function boot() {
  // Das Menü zeigt Waffenbilder als normale <img>-Elemente - die lädt der
  // Browser selbst und nach und nach. Vorab-Laden würde den Start nur bremsen.
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
  audio: Audio,
  get arena() {
    return arena;
  },
  get loop() {
    return loop;
  },
  startRun,
  content,
};
