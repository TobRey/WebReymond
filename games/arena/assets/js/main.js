import { Arena } from './game/arena.js';
import { GameLoop } from './core/loop.js';
import { Input } from './core/input.js';
import { Assets } from './gfx/assets.js';
import { rollChoices, RARITY_LABEL, formatModifiers } from './game/upgrades.js';
import { Shop } from './game/shop.js';
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

/**
 * Bildpfad als CSS-url() mit vollem Adressteil.
 *
 * Ein relativer Pfad in einer selbst gesetzten CSS-Variablen wird nicht
 * gegen die Seite aufgeloest, sondern gegen die Datei, in der die Variable
 * benutzt wird - also gegen assets/css/. Das ergaebe assets/css/assets/...
 * und damit ein fehlendes Bild. Deshalb hier immer die volle Adresse.
 */
const bildUrl = (pfad) => `url('${new URL(pfad, document.baseURI).href}')`;

const canvas = $('stage');
const input = new Input(canvas);
input.enabled = false;

let arena = null;
let loop = null;
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
/* --------------------------------------------------- Charakterauswahl */
/*
 * Ein Charakter steht in der Mitte des Raums und macht sein Ruhebild,
 * mit den Pfeilen blaettert man durch. Hintergrund, Standort und Groesse
 * der Figur kommen aus dem Admin, damit die Figur auf jedem eigenen
 * Hintergrundbild wieder auf dem Podest steht.
 */
let charIndex = 0;
let charListe = [];
let charTakt = 0;     // Flipbook fuer Ruhebilder aus Einzelbildern

/** Bildquelle und Spiegelung des Ruhebildes eines Charakters. */
function idleQuelle(character) {
  const s = character.sprites || {};
  const idle = s.idle || {};
  const front = s.front || {};
  if (Array.isArray(idle.frames) && idle.frames.length) {
    return { frames: idle.frames, flip: !!idle.flip, scale: idle.scale || 1 };
  }
  if (idle.gif) return { frames: [idle.gif], flip: !!idle.flip, scale: idle.scale || 1 };
  if (front.gif) return { frames: [front.gif], flip: !!front.flip, scale: front.scale || 1 };
  if (Array.isArray(front.frames) && front.frames.length) {
    return { frames: front.frames, flip: !!front.flip, scale: front.scale || 1 };
  }
  return { frames: [''], flip: false, scale: 1 };
}

function renderCharacters() {
  charListe = (content.characters || []).filter((c) => c.active !== false)
    .sort((a, b) => (a.order || 0) - (b.order || 0));
  if (!charListe.length) return;

  if (selectedCharacter) {
    const i = charListe.findIndex((c) => c.id === selectedCharacter.id);
    if (i >= 0) charIndex = i;
  }
  charIndex = ((charIndex % charListe.length) + charListe.length) % charListe.length;
  zeigeCharakter();
}

function charWechseln(schritt) {
  if (!charListe.length) return;
  charIndex = (charIndex + schritt + charListe.length) % charListe.length;
  Audio.play('uiClick');
  zeigeCharakter();
}

function zeigeCharakter() {
  const character = charListe[charIndex];
  if (!character) return;
  const frei = isUnlocked(character);

  // Figur: entweder eine bewegte Datei oder eine Folge aus Einzelbildern.
  clearInterval(charTakt);
  const bild = $('char-bild');
  const quelle = idleQuelle(character);
  bild.style.filter = character.tint
    ? `hue-rotate(${character.tint}deg) saturate(1.15)` : '';
  bild.style.transform = quelle.flip ? 'scaleX(-1)' : '';
  bild.src = quelle.frames[0] || '';
  if (quelle.frames.length > 1) {
    // Alle Bilder vorher in den Cache holen, damit der Wechsel nicht flackert.
    for (const src of quelle.frames) {
      const vor = new Image();
      vor.src = src;
    }
    let i = 0;
    charTakt = setInterval(() => {
      i = (i + 1) % quelle.frames.length;
      bild.src = quelle.frames[i];
    }, Math.max(60, character.frameDuration || 130));
  }
  // Der Neustart der Einblendung macht den Wechsel sichtbar.
  bild.style.animation = 'none';
  void bild.offsetWidth;
  bild.style.animation = '';

  $('char-schloss').hidden = frei;
  $('char-schloss').textContent = frei ? '' : '🔒 ' + character.unlockCost + ' Punkte';

  // Werte daneben.
  const info = $('char-info');
  info.textContent = '';
  info.appendChild(el('h3', null, character.name));
  if (character.title) info.appendChild(el('div', 'charwahl__titel', character.title));
  if (character.description) info.appendChild(el('p', null, character.description));

  const tags = el('div', 'charwahl__tags');
  for (const text of characterHighlights(character)) tags.appendChild(el('span', 'tag', text));
  info.appendChild(tags);

  const perk = perkInfo(character);
  if (perk) {
    const skill = el('div', 'charwahl__perk');
    skill.appendChild(el('b', null, perk.label));
    skill.appendChild(el('span', null, perk.description));
    info.appendChild(skill);
  }

  // Punktreihe zeigt, wo man in der Liste steht.
  const punkte = $('char-punkte');
  punkte.textContent = '';
  charListe.forEach((c, i) => {
    const punkt = el('i', (i === charIndex ? 'is-aktiv' : '') + (isUnlocked(c) ? '' : ' is-gesperrt'));
    punkt.addEventListener('click', () => {
      charIndex = i;
      zeigeCharakter();
    });
    punkte.appendChild(punkt);
  });

  const knopf = $('char-waehlen');
  knopf.textContent = frei ? 'Auswählen' : 'Freischalten (' + character.unlockCost + ')';
  knopf.className = 'btn btn--xl ' + (frei ? 'btn--gold' : 'btn--stein');
}

function charBestaetigen() {
  const character = charListe[charIndex];
  if (!character) return;
  if (!isUnlocked(character)) {
    unlockCharacter(character);
    return;
  }
  selectedCharacter = character;
  Audio.play('uiClick');
  clearInterval(charTakt);
  showScreen('screen-weapon');
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
    zeigeCharakter();
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
  if (Audio.settings.musicOn) Audio.playTrack('menu');
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
  // Die Bildfolge der Charakterauswahl laeuft nur auf ihrem Bildschirm.
  if (id !== 'screen-character') clearInterval(charTakt);
  const inGame = !id;
  $('hud').hidden = !inGame;
  $('btn-ult').hidden = !inGame;
  input.enabled = inGame;
  // Ausserhalb der Runde laeuft die Menuemusik, im Kampf die Kampfmusik.
  if (!inGame) Audio.playTrack('menu');
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
/*
 * Die Karte wird ausgewuerfelt, nicht gewaehlt.
 *
 * Vorher gab es einen eigenen Bildschirm dafuer. Mit mehreren Karten wollte
 * man aber nicht jedes Mal dieselbe nehmen - jetzt entscheidet der Zufall,
 * und jede Runde beginnt woanders. Im Admin bleibt alles wie gehabt.
 */
function activeMaps() {
  return content.maps.filter((m) => m.active && m.image);
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
  const mapDef = pick(maps);

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
  Audio.play('gameStart');
  Audio.playTrack('game');
  // Welle 1 vorbereiten, dann drei Sekunden Vorlauf.
  arena.start();
  countdown(arena.run.cycle, arena.run.wave, () => arena.waves.beginSpawning());

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
    // Der volle Text macht klar, dass es weitergeht und nicht neu anfängt.
    banner(info.boss ? `Zyklus ${info.cycle} · Boss` : `Zyklus ${info.cycle} · Welle ${info.wave} von 4`, info.boss);
    $('hud-boss').hidden = !info.boss;
    // Im Kampf soll die Musik immer laufen - nicht irgendwann von allein.
    Audio.ensureMusic();
  });
  instance.on('bossSpawn', () => {
    $('hud-boss').hidden = false;
    $('boss-name').textContent = (instance.boss && instance.boss.def.name) || 'Boss';
  });
  instance.on('bossEnrage', () => toast('Der Boss wird wütend!', 'error'));
  instance.on('effectNote', (text) => toast(text));
  instance.on('death', (summary) => {
    loop.setPaused(true);
    countdownStop();
    // Nach dem Tod ist die Runde vorbei - die Menuemusik uebernimmt.
    Audio.playTrack('menu');
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

/* ------------------------------------------------------------- Countdown */
/**
 * Drei Sekunden Vorlauf vor jeder Welle und nach jeder Pause.
 *
 * Ohne den Vorlauf stand man nach dem Fortsetzen sofort wieder unter
 * Beschuss, ohne die Lage erfassen zu koennen. Der Countdown haelt die
 * Schleife an und gibt sie danach frei.
 *
 * @param welle   Wellennummer fuer die Anzeige
 * @param zyklus  Zyklusnummer fuer die Anzeige
 * @param fertig  wird nach dem Countdown aufgerufen
 */
let countdownTimer = 0;

function countdown(zyklus, welle, fertig, text) {
  countdownStop();
  if (!loop) {
    if (fertig) fertig();
    return;
  }
  loop.setPaused(true);

  const box = $('countdown');
  const zahl = $('countdown-zahl');
  const kicker = $('countdown-kicker');
  kicker.textContent = text || (welle === 4 ? `Zyklus ${zyklus} · Boss` : `Zyklus ${zyklus} · Welle ${welle} von 4`);
  box.hidden = false;

  let rest = 3;
  const zeige = (wert) => {
    zahl.classList.remove('is-tick', 'is-los');
    // Neustart der Animation erzwingen.
    void zahl.offsetWidth;
    zahl.textContent = wert;
    zahl.classList.add('is-tick');
    if (wert === 'LOS!') zahl.classList.add('is-los');
    Audio.play(wert === 'LOS!' ? 'waveClear' : 'uiClick', { volume: wert === 'LOS!' ? 1 : 0.7 });
  };

  zeige(String(rest));
  countdownTimer = setInterval(() => {
    rest -= 1;
    if (rest > 0) {
      zeige(String(rest));
      return;
    }
    if (rest === 0) {
      zeige('LOS!');
      return;
    }
    countdownStop();
    box.hidden = true;
    if (fertig) fertig();
    // Erst jetzt laeuft das Spiel wieder.
    if (arena && !arena.gameOver && !arena.isIntermission
        && $('overlay-upgrade').hidden && $('overlay-swap').hidden
        && $('overlay-pause').hidden && $('overlay-stats').hidden) {
      input.reset();
      loop.setPaused(false);
      Audio.ensureMusic();
    }
  }, 700);
}

function countdownStop() {
  clearInterval(countdownTimer);
  countdownTimer = 0;
  const box = $('countdown');
  if (box) box.hidden = true;
}

/** Laeuft gerade ein Countdown? */
function countdownAktiv() {
  return countdownTimer !== 0;
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

  // Ultimate-Knopf: Fuellstand und Restzeit.
  const ultBtn = $('btn-ult');
  if (ultBtn) {
    const bereit = arena.ultReady;
    ultBtn.classList.toggle('is-ready', bereit);
    $('ult-fuell').style.setProperty('--fuell', (arena.ultProgress * 100).toFixed(0) + '%');
    $('ult-zeit').textContent = bereit ? 'BEREIT' : Math.ceil(arena.ultTimer) + 's';
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
      node.appendChild(el('div', 'card__icon--stat',
        card.upgrade.effect ? effectIcon(card.upgrade.effect) : statIcon(card.upgrade.stat)));
    }

    node.appendChild(el('div', 'card__title', card.title));
    // Schon vorhanden? Dann zeigt die Karte die naechste Stufe.
    if (card.owned) node.appendChild(el('div', 'card__stufe', 'Stufe ' + card.level));
    node.appendChild(el('div', 'card__value', card.valueText));
    if (card.totalText) {
      node.appendChild(el('div', 'card__gesamt', 'Gesamt danach: ' + card.totalText));
    }
    node.appendChild(el('div', 'card__desc', card.description || ''));
    node.addEventListener('click', () => chooseCard(card));
    host.appendChild(node);
  }

  $('overlay-upgrade').hidden = false;
}

/** Symbol fuer Karten mit Sondereffekt. */
function effectIcon(effect) {
  return {
    lifesteal: '🩸', critHeal: '🦷', thorns: '🌵', killFrenzy: '⚡', hurtFrenzy: '💉',
    multikill: '💢', untouched: '✨', berserk: '😤', execute: '🪓', doubleShot: '🎯',
    ghostShot: '👻', chainExplode: '💣', collide: '🏃', waveHeal: '🩹', treasure: '💎',
    lastPotato: '🥔', blackhole: '🕳', slowmo: '⏳', midas: '👑', deathwave: '🌊',
    soulEater: '💀', hunger: '🍖', clone: '🧬', bloodPact: '🩸', greedCurse: '🪙',
    chaos: '🎲', goldenDice: '🎲', mutation: '🧪', potatoGod: '🥔',
    swarm: '🐝', lonewolf: '🐺', greedyBlade: '🪙', pauper: '🥣', momentum: '🏃',
    bulwark: '🗿', gambler: '🎰', snowball: '❄', criticalMass: '☄', nightmare: '🌑',
    adrenalin: '💗', rage: '🔴', harvest: '🌾', bloodMoney: '💸', guardian: '🕊',
    retaliate: '💥', banker: '🏦', roulette: '🎡', frostAura: '🧊', flameAura: '🔥',
    spikes: '🦔', timeWarp: '⏱', magnetize: '🧲', pierceAll: '🏹', echo: '🔁',
    bigShot: '🔮',
  }[effect] || '★';
}

function statIcon(stat) {
  return {
    damage: '⚔', attackSpeed: '⚡', moveSpeed: '👟', maxHealth: '❤', armor: '🛡',
    shield: '🔷', critChance: '🎯', critDamage: '💥', projectileSpeed: '🏹',
    range: '📏', knockback: '💨', dodge: '🌀', regen: '✚',
    burn: '🔥', potionRate: '🧪', pickupRange: '🧲', lifesteal: '🩸', luck: '🍀',
    money: '💰', projectileDamage: '⚙', meleeRange: '🤜', rangedAttackSpeed: '🔫',
    thorns: '🌵',
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
  $('overlay-upgrade').hidden = true;
  ladenOeffnen();
}

/* ------------------------------------------------------------------ Laden */
/*
 * Nach der Upgrade-Karte kommt der Haendler. Geld will ausgegeben werden:
 * vier Auslagen, so viele Kaeufe wie das Geld hergibt, ein Tausch gegen
 * Aufpreis und ein Schloss fuer alles, was man sich merken moechte.
 *
 * Zum Ruckeln: Jede Bewegung im Laden laeuft ueber transform und opacity.
 * Beim Kauf wird nicht die ganze Auslage neu gebaut, sondern nur die eine
 * Karte umgeschrieben - so bleibt kein Layout-Durchlauf uebrig, der auf
 * dem Handy sichtbar haengen wuerde.
 */
let shop = null;
let haendlerTakt = 0;

function ladenOeffnen() {
  const conf = content.shop || {};
  if (conf.enabled === false) {
    finishUpgrade();
    return;
  }
  shop = new Shop(content, arena.run);
  ladenBuehne();
  ladenZeichnen();
  $('shop-besitz').hidden = true;
  $('overlay-shop').hidden = false;
  Audio.duckMusic(true);
}

/** Hintergrund, Haendler und Tresen einmal je Besuch aufbauen. */
function ladenBuehne() {
  const conf = content.shop || {};
  const buehne = $('shop-buehne');
  buehne.style.setProperty('--shop-bg', conf.background ? bildUrl(conf.background) : 'none');
  buehne.style.setProperty('--shop-mx', (conf.merchantX ?? 76) + '%');
  buehne.style.setProperty('--shop-my', (conf.merchantY ?? 62) + '%');
  buehne.style.setProperty('--shop-ms', String((conf.merchantScale ?? 100) / 100));
  buehne.style.setProperty('--shop-cy', (conf.counterY ?? 100) + '%');
  buehne.style.setProperty('--shop-cs', String((conf.counterScale ?? 100) / 100));

  const tresen = $('shop-tresen');
  tresen.hidden = !conf.counter;
  if (conf.counter) tresen.src = conf.counter;

  $('shop-titel').textContent = conf.title || 'Laden';

  // Ruhebild des Haendlers: alle Bilder liegen uebereinander, sichtbar ist
  // immer genau eines. Kein Nachladen mitten in der Animation.
  const host = $('shop-haendler');
  const frames = (conf.merchantFrames || []).filter(Boolean);
  const gleich = host.dataset.frames === frames.join('|');
  if (!gleich) {
    host.dataset.frames = frames.join('|');
    host.textContent = '';
    for (const src of frames) {
      const img = new Image();
      img.src = src;
      img.alt = '';
      host.appendChild(img);
    }
  }
  clearInterval(haendlerTakt);
  const bilder = [...host.children];
  bilder.forEach((b, i) => b.classList.toggle('is-an', i === 0));
  if (bilder.length > 1) {
    let i = 0;
    haendlerTakt = setInterval(() => {
      bilder[i].classList.remove('is-an');
      i = (i + 1) % bilder.length;
      bilder[i].classList.add('is-an');
    }, Math.max(60, conf.merchantFrameDuration || 220));
  }
}

/** Baut die Auslagen neu auf - beim Oeffnen und nach jedem Tausch. */
function ladenZeichnen() {
  const host = $('shop-auslage');
  host.textContent = '';
  for (const offer of shop.offers) host.appendChild(angebotKarte(offer));
  ladenLeiste();
}

function angebotKarte(offer) {
  const node = el('div', 'angebot angebot--' + offer.rarity);
  node.dataset.id = offer.id;

  const bild = el('div', 'angebot__bild');
  if (offer.kind === 'weapon' || offer.icon) {
    const img = el('img');
    img.src = offer.kind === 'weapon' ? offer.sprite : offer.icon;
    img.alt = '';
    bild.appendChild(img);
  } else {
    bild.textContent = offer.effect ? effectIcon(offer.effect) : statIcon(offer.stat);
  }
  node.appendChild(bild);

  const kopf = el('div', 'angebot__kopf');
  kopf.appendChild(el('span', 'angebot__name', offer.title));
  kopf.appendChild(el('span', 'angebot__art', offer.rarityLabel || ''));
  if (offer.owned) kopf.appendChild(el('span', 'angebot__stufe', 'Stufe ' + offer.level));
  node.appendChild(kopf);

  const wert = el('div', 'angebot__wert', offer.valueText || '');
  node.appendChild(wert);
  if (offer.totalText) {
    node.appendChild(el('div', 'angebot__gesamt', 'Gesamt danach: ' + offer.totalText));
  } else if (offer.note) {
    node.appendChild(el('div', 'angebot__gesamt', offer.note));
  } else if (offer.description) {
    node.appendChild(el('div', 'angebot__text', offer.description));
  }

  const fuss = el('div', 'angebot__fuss');
  const preis = el('div', 'angebot__preis');
  preis.appendChild(el('span', 'coin'));
  preis.appendChild(el('b', null, String(offer.price)));
  fuss.appendChild(preis);
  node.appendChild(fuss);

  // Schloss: merkt eine Auslage vor, damit sie den Tausch ueberlebt.
  const schloss = el('button', 'angebot__schloss' + (offer.locked ? ' is-an' : ''), offer.locked ? '🔒' : '🔓');
  schloss.type = 'button';
  schloss.title = 'Für später merken';
  schloss.addEventListener('click', (e) => {
    e.stopPropagation();
    if (!shop.toggleLock(offer)) {
      toast('Du kannst höchstens ' + shop.lockLimit + ' merken.', 'error');
      return;
    }
    Audio.play('uiClick');
    schloss.classList.toggle('is-an', offer.locked);
    schloss.textContent = offer.locked ? '🔒' : '🔓';
    node.classList.toggle('is-gemerkt', offer.locked);
    ladenLeiste();
  });
  node.appendChild(schloss);
  node.classList.toggle('is-gemerkt', offer.locked);

  if (offer.sold) {
    node.classList.add('is-verkauft');
    node.appendChild(el('div', 'angebot__stempel', 'GEKAUFT'));
    schloss.hidden = true;
  } else {
    node.classList.toggle('is-zuteuer', arena.run.money < offer.price);
    node.addEventListener('click', () => kaufen(offer, node));
  }
  return node;
}

function kaufen(offer, node) {
  if (offer.sold) return;
  if (arena.run.money < offer.price) {
    toast('Zu teuer - merke es dir mit dem Schloss.', 'error');
    node.animate(
      [{ transform: 'translateX(0)' }, { transform: 'translateX(-6px)' },
       { transform: 'translateX(6px)' }, { transform: 'translateX(0)' }],
      { duration: 240, easing: 'ease-out' },
    );
    return;
  }

  const ergebnis = shop.buy(offer);
  if (!ergebnis) return;
  Audio.play('upgrade');
  muenzenFliegen(node, $('shop-geld'));

  // Nur diese eine Karte umschreiben - der Rest bleibt unberuehrt stehen.
  node.classList.add('is-gekauft', 'is-verkauft');
  const schloss = node.querySelector('.angebot__schloss');
  if (schloss) schloss.hidden = true;
  if (!node.querySelector('.angebot__stempel')) {
    node.appendChild(el('div', 'angebot__stempel', 'GEKAUFT'));
  }
  node.replaceWith(node.cloneNode(true));   // nimmt den Klick-Handler mit

  if (ergebnis.kind === 'weapon') {
    arena.weapon.reset();
    updateHud(true);
    toast(ergebnis.weapon.name + ' gekauft - ersetzt ' + ergebnis.previous.name);
  } else if (ergebnis.level > 1) {
    toast(ergebnis.upgrade.name + ' auf Stufe ' + ergebnis.level);
  }

  ladenPreiseAuffrischen();
  ladenLeiste();
}

/** Preise koennen sich nach einem Kauf verschieben (naechste Stufe). */
function ladenPreiseAuffrischen() {
  for (const offer of shop.offers) {
    if (offer.sold) continue;
    const alt = offer.price;
    offer.price = shop.price(offer);
    const node = $('shop-auslage').querySelector(`[data-id="${CSS.escape(offer.id)}"]`);
    if (!node) continue;
    if (alt !== offer.price) {
      const b = node.querySelector('.angebot__preis b');
      if (b) b.textContent = String(offer.price);
    }
    node.classList.toggle('is-zuteuer', arena.run.money < offer.price);
  }
}

function ladenLeiste() {
  const geld = $('shop-geld');
  if (geld.textContent !== String(arena.run.money)) {
    geld.textContent = String(arena.run.money);
    const box = geld.parentElement;
    box.classList.remove('is-aenderung');
    void box.offsetWidth;
    box.classList.add('is-aenderung');
  }
  const reroll = $('shop-reroll');
  reroll.textContent = 'Tauschen (' + shop.rerollPrice + ')';
  reroll.disabled = !shop.canReroll;
  reroll.style.opacity = shop.canReroll ? '' : '.5';
  $('shop-liste-btn').textContent = 'Gekauft (' + shop.bought.length + ')';
}

/**
 * Muenzen fliegen von der Karte zum Geldzaehler.
 *
 * Bewusst ueber die Web-Animations-API statt ueber CSS-Klassen: Der Browser
 * rechnet die Bahn im Compositor, es entsteht kein Layout-Durchlauf, und
 * die Elemente raeumen sich selbst wieder ab.
 */
function muenzenFliegen(von, zu) {
  if (!von || !zu || typeof von.animate !== 'function') return;
  const a = von.getBoundingClientRect();
  const b = zu.getBoundingClientRect();
  const ziel = { x: b.left + b.width / 2, y: b.top + b.height / 2 };
  for (let i = 0; i < 6; i++) {
    const muenze = el('div', 'muenze');
    const startX = a.left + a.width * (0.25 + Math.random() * 0.5);
    const startY = a.top + a.height * (0.3 + Math.random() * 0.5);
    muenze.style.left = startX + 'px';
    muenze.style.top = startY + 'px';
    document.body.appendChild(muenze);
    const anim = muenze.animate([
      { transform: 'translate3d(0,0,0) scale(1)', opacity: 1 },
      { transform: `translate3d(${(ziel.x - startX) * 0.45}px, ${(ziel.y - startY) * 0.45 - 30}px, 0) scale(1.25)`, opacity: 1, offset: 0.55 },
      { transform: `translate3d(${ziel.x - startX}px, ${ziel.y - startY}px, 0) scale(.4)`, opacity: 0 },
    ], { duration: 460 + i * 45, easing: 'cubic-bezier(.3,.7,.4,1)', fill: 'forwards' });
    anim.onfinish = () => muenze.remove();
    anim.oncancel = () => muenze.remove();
  }
}

/** Uebersicht ueber alles, was der Run bisher zusammengetragen hat. */
function besitzZeigen() {
  const host = $('shop-besitz-liste');
  host.textContent = '';

  const waffe = el('div');
  waffe.appendChild(el('span', null, 'Waffe'));
  waffe.appendChild(el('b', null, arena.run.weapon.name));
  host.appendChild(waffe);

  const sortiert = [...arena.run.upgrades].sort((a, b) => b.count - a.count);
  for (const eintrag of sortiert) {
    const zeile = el('div');
    zeile.appendChild(el('span', null, eintrag.upgrade.name));
    zeile.appendChild(el('b', null, eintrag.count > 1 ? 'Stufe ' + eintrag.count : '✓'));
    host.appendChild(zeile);
  }
  if (!sortiert.length) host.appendChild(el('div', null, 'Noch nichts gekauft.'));
  $('shop-besitz').hidden = false;
}

function ladenSchliessen() {
  clearInterval(haendlerTakt);
  $('overlay-shop').hidden = true;
  shop = null;
  finishUpgrade();
}

function finishUpgrade() {
  Audio.duckMusic(false);
  $('overlay-upgrade').hidden = true;
  $('overlay-swap').hidden = true;
  updateHud(true);
  arena.waves.advance();
  updateHud(true);
  // Die neue Welle startet erst nach dem Countdown.
  countdown(arena.run.cycle, arena.run.wave, () => arena.waves.beginSpawning());
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
    // Mehrfach gekauft heisst hoehere Stufe - inklusive Gesamtwirkung.
    chip.appendChild(document.createTextNode(
      entry.count > 1 ? ' · Stufe ' + entry.count : '',
    ));
    const wirkung = formatModifiers(entry.upgrade, entry.count);
    if (wirkung) chip.appendChild(el('small', null, wirkung));
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
    if (u.count > 1) chip.appendChild(document.createTextNode(' · Stufe ' + u.count));
    list.appendChild(chip);
  }
  $('overlay-death').hidden = false;
}

function quitToMenu() {
  clearInterval(musicWatch);
  countdownStop();
  if (loop) loop.stop();
  if (arena) arena.destroy();
  arena = null;
  loop = null;
  input.reset();
  ['overlay-death', 'overlay-pause', 'overlay-stats', 'overlay-upgrade', 'overlay-swap', 'overlay-shop']
    .forEach((id) => { $(id).hidden = true; });
  clearInterval(haendlerTakt);
  shop = null;
  showScreen('screen-menu');
}

/* --------------------------------------------------------------- Bindings */
$('btn-play').addEventListener('click', () => {
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
    || !$('overlay-shop').hidden || arena.gameOver || arena.isIntermission;
  Audio.duckMusic(blockiert);
  if (!blockiert) {
    // Nach der Pause erst drei Sekunden Vorlauf.
    Audio.ensureMusic();
    countdown(arena.run.cycle, arena.run.wave, () => arena.waves.beginSpawning(), 'Weiter');
  }
}

document.querySelectorAll('[data-close-stats]').forEach((b) => b.addEventListener('click', resumeGame));

// Tippen neben das Fenster schliesst Pause und Statistik ebenfalls.
document.querySelectorAll('.overlay[data-dismiss]').forEach((o) =>
  o.addEventListener('pointerdown', (e) => {
    if (e.target === o) resumeGame();
  }),
);

$('btn-ult').addEventListener('click', () => {
  if (!arena || !loop || loop.paused) return;
  if (!arena.useUlt()) {
    toast('Druckwelle lädt noch: ' + Math.ceil(arena.ultTimer) + ' s', 'error');
    return;
  }
  updateHud(true);
});

// ------------------------------------------------------------------- Laden
$('shop-weiter').addEventListener('click', ladenSchliessen);
$('shop-reroll').addEventListener('click', () => {
  if (!shop) return;
  if (!shop.reroll()) {
    toast('Dafür reicht das Geld nicht.', 'error');
    return;
  }
  Audio.play('uiClick');
  ladenZeichnen();
});
$('shop-liste-btn').addEventListener('click', besitzZeigen);
$('shop-liste-zu').addEventListener('click', () => { $('shop-besitz').hidden = true; });

// ------------------------------------------------------- Charakterauswahl
$('char-prev').addEventListener('click', () => charWechseln(-1));
$('char-next').addEventListener('click', () => charWechseln(1));
$('char-waehlen').addEventListener('click', charBestaetigen);

$('btn-stats').addEventListener('click', showStats);
$('btn-sound').addEventListener('click', toggleSound);
$('btn-sound-menu').addEventListener('click', toggleSound);
$('btn-pause').addEventListener('click', pauseGame);
$('pause-resume').addEventListener('click', resumeGame);

function pauseGame() {
  if (!loop || !arena) return;
  countdownStop();
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
  if (e.code === 'Space' && arena && loop && !loop.paused) {
    e.preventDefault();
    arena.useUlt();
    updateHud(true);
  }
  if (!arena && $('screen-character').classList.contains('is-active')) {
    if (e.code === 'ArrowLeft') charWechseln(-1);
    if (e.code === 'ArrowRight') charWechseln(1);
    if (e.code === 'Enter') charBestaetigen();
  }
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

/**
 * Hintergrundbilder und die Position der Figur kommen aus dem Admin.
 * Sie landen als CSS-Variablen an der Wurzel, damit das Stylesheet sie
 * ohne weiteren Umweg verwenden kann.
 */
function aussehenAnwenden() {
  const ui = content.ui || {};
  const wurzel = document.documentElement.style;
  if (ui.menuBackground) wurzel.setProperty('--menu-bg', bildUrl(ui.menuBackground));
  if (ui.charBackground) wurzel.setProperty('--char-bg', bildUrl(ui.charBackground));
  wurzel.setProperty('--char-x', (ui.charX ?? 50) + '%');
  wurzel.setProperty('--char-y', (ui.charY ?? 63) + '%');
  wurzel.setProperty('--char-scale', String((ui.charScale ?? 100) / 100));
}

function checkOrientation() {
  const portrait = window.innerHeight > window.innerWidth;
  const small = Math.min(window.innerWidth, window.innerHeight) < 560;
  $('rotate-hint').hidden = !(portrait && small && arena);
}

/* ------------------------------------------------------------------ Start */
(async function boot() {
  // Das Menü zeigt Waffenbilder als normale <img>-Elemente - die lädt der
  // Browser selbst und nach und nach. Vorab-Laden würde den Start nur bremsen.
  aussehenAnwenden();
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
