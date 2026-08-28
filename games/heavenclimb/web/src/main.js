// Einstiegspunkt: verdrahtet Eingabe, Schleife, Darstellung, Menüs und
// Bestenliste. Der Spielzustand selbst liegt in game/world.js.

import { VERSION } from './version.js';
import { createWorld, emptyInput } from './game/world.js';
import { botInput, createBot } from './game/bot.js';
import { TILE } from './game/constants.js';
import { createLoop } from './engine/loop.js';
import { createInput } from './engine/input.js';
import { createAudio } from './engine/audio.js';
import { createBuffer, present, watchResize } from './engine/canvas.js';
import { createScene, drawScene } from './render/scene.js';
import { createHud } from './render/hud.js';
import {
  createParticles,
  emitCrumble,
  emitDeath,
  emitGem,
  emitJumpRing,
  emitLandDust,
  emitWallDust,
  emitWallJump,
  updateParticles,
} from './render/particles.js';
import { applyTranslations, getLang, initialLang, onLangChange, setLang, t } from './i18n.js';
import { readString, writeString } from './engine/storage.js';
import {
  fetchTop,
  getMode,
  personalBest,
  probe,
  rememberPersonalBest,
  submit,
} from './net/scores.js';
import { sanitizeName } from './net/scoreRules.js';

const NAME_KEY = 'heavenclimb.name';

const $ = (id) => document.getElementById(id);

const dom = {
  stage: $('stage'),
  stagewrap: $('stagewrap'),
  screen: $('screen'),
  overlay: $('overlay'),
  panelMenu: $('panelMenu'),
  panelPause: $('panelPause'),
  panelOver: $('panelOver'),
  hudRoot: $('hud'),
  hudHeight: $('hudHeight'),
  hudGems: $('hudGems'),
  hudBest: $('hudBest'),
  pressure: $('pressureFill'),
  touch: $('touch'),
  name: $('playerName'),
  overHeight: $('overHeight'),
  overGems: $('overGems'),
  overScore: $('overScore'),
  overBest: $('overBest'),
  overStatus: $('overStatus'),
  board: $('board'),
  boardBadge: $('boardBadge'),
  boardMode: $('boardMode'),
  boardHint: $('boardHint'),
  mute: $('mute'),
  version: $('version'),
};

const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const buffer = createBuffer();
const scene = createScene();
const input = createInput();
const audio = createAudio();
const particles = createParticles();
const hud = createHud({
  root: dom.hudRoot,
  height: dom.hudHeight,
  gems: dom.hudGems,
  best: dom.hudBest,
  pressure: dom.pressure,
});

/** @type {'menu'|'playing'|'paused'|'over'} */
let state = 'menu';
let world = null;
let best = personalBest();
let submitting = false;

// ---------------------------------------------------------------------------
// Szenenwechsel
// ---------------------------------------------------------------------------

function showPanel(panel) {
  dom.panelMenu.hidden = panel !== dom.panelMenu;
  dom.panelPause.hidden = panel !== dom.panelPause;
  dom.panelOver.hidden = panel !== dom.panelOver;
  dom.overlay.hidden = panel === null;
}

function toMenu() {
  state = 'menu';
  world = null;
  hud.hide();
  showPanel(dom.panelMenu);
  input.releaseAll();
}

function startGame() {
  audio.unlock();
  const name = sanitizeName(dom.name.value);
  dom.name.value = name === 'ANON' ? '' : name;
  writeString(NAME_KEY, name);

  world = createWorld((Date.now() ^ (Math.random() * 0xffffffff)) >>> 0);
  particles.items.length = 0;
  particles.shake = 0;
  state = 'playing';
  hud.invalidate();
  hud.show();
  showPanel(null);
  input.releaseAll();
  loop.resetClock();
}

function pauseGame() {
  if (state !== 'playing') return;
  state = 'paused';
  showPanel(dom.panelPause);
  input.releaseAll();
}

function resumeGame() {
  if (state !== 'paused') return;
  state = 'playing';
  showPanel(null);
  input.releaseAll();
  loop.resetClock();
}

function endGame() {
  state = 'over';
  hud.hide();

  const score = world.score;
  const isBest = rememberPersonalBest(score);
  if (isBest) best = score;

  dom.overHeight.textContent = `${world.metres} ${t('hud.metres')}`;
  dom.overGems.textContent = String(world.player.gems);
  dom.overScore.textContent = String(score);
  dom.overBest.hidden = !isBest;
  dom.overStatus.textContent = '';
  showPanel(dom.panelOver);
  input.releaseAll();

  // Sehr kurze Läufe gar nicht erst senden: der Server weist alles unter drei
  // Sekunden als unplausibel ab, und eine Fehlermeldung dafür wäre unsinnig.
  const seconds = Math.round(world.seconds);
  if (score >= 1 && seconds >= 3) sendScore(score, seconds);
  else dom.overStatus.textContent = t('over.rankNone');
}

// ---------------------------------------------------------------------------
// Ereignisse aus der Spielwelt in Ton und Partikel übersetzen
// ---------------------------------------------------------------------------

function handleEvents(events) {
  if (events.length === 0) return;
  const body = world.player.body;
  const footX = body.x + body.w / 2;
  const footY = body.y + body.h;

  for (const event of events) {
    switch (event) {
      case 'jump':
        audio.play('jump');
        emitLandDust(particles, footX, footY, 0.5);
        break;
      case 'doubleJump':
        audio.play('doubleJump');
        emitJumpRing(particles, footX, body.y + body.h / 2);
        break;
      case 'wallJump':
        audio.play('wallJump');
        emitWallJump(particles, footX, body.y + body.h / 2, world.player.facing);
        break;
      case 'land':
        audio.play('land');
        emitLandDust(particles, footX, footY, 1);
        break;
      case 'spring':
        audio.play('spring');
        emitJumpRing(particles, footX, footY);
        break;
      case 'gem':
        audio.play('gem');
        emitGem(particles, footX, body.y + body.h / 2);
        break;
      case 'death':
        audio.play('death');
        emitDeath(particles, footX, body.y + body.h / 2);
        break;
      default:
        break;
    }
  }
}

// ---------------------------------------------------------------------------
// Schleife
// ---------------------------------------------------------------------------

function update() {
  for (const command of input.takeCommands()) {
    if (command === 'pause') {
      if (state === 'playing') pauseGame();
      else if (state === 'paused') resumeGame();
    } else if (command === 'restart') {
      if (state === 'playing' || state === 'paused' || state === 'over') startGame();
    } else if (command === 'mute') {
      toggleMute();
    } else if (command === 'confirm') {
      if (state === 'menu') startGame();
      else if (state === 'over') startGame();
    }
  }

  const inputState = input.update();

  if (state !== 'playing' || world === null) {
    updateParticles(particles);
    updateAttract();
    return;
  }

  const events = world.update(inputState);
  handleEvents(events);

  for (const cell of world.crumbled) {
    emitCrumble(particles, cell.col * TILE, cell.row * TILE);
  }

  if (world.player.sliding && !reducedMotion) {
    const body = world.player.body;
    emitWallDust(
      particles,
      body.x + (world.player.slideSide > 0 ? body.w : 0),
      body.y + body.h - 3,
      world.player.slideSide,
    );
  }

  updateParticles(particles);
  hud.update(world, best);

  if (world.over) endGame();
}

function render() {
  if (world !== null) {
    drawScene(buffer.ctx, world, scene, particles, { reducedMotion });
  } else {
    drawScene(buffer.ctx, previewWorld, scene, previewParticles, { reducedMotion });
  }
  present(dom.screen, buffer.canvas);
}

const loop = createLoop({ update, render });

// Hinter den Menüs klettert der Automat. Ein stehendes Bild wäre die
// einfachere Lösung, aber eine Vorschau, in der sich etwas bewegt, erklärt das
// Spiel besser als jede Beschreibung.
const previewParticles = createParticles();
const previewBot = createBot();
const previewInput = emptyInput();
let previewWorld = createWorld(20260828);

function updateAttract() {
  if (previewWorld.over) {
    previewWorld = createWorld((Date.now() ^ 0x5eed) >>> 0);
    previewBot.target = null;
    previewParticles.items.length = 0;
    return;
  }
  botInput(previewBot, previewWorld, previewInput);
  previewWorld.update(previewInput);
  updateParticles(previewParticles);
}

// ---------------------------------------------------------------------------
// Bestenliste
// ---------------------------------------------------------------------------

function renderBoard(entries) {
  dom.board.replaceChildren();
  if (entries.length === 0) {
    const li = document.createElement('li');
    li.className = 'board__empty';
    li.textContent = t('board.empty');
    dom.board.append(li);
    return;
  }
  entries.forEach((entry, index) => {
    const li = document.createElement('li');
    const rank = document.createElement('span');
    rank.className = 'board__rank';
    rank.textContent = `${index + 1}.`;
    const name = document.createElement('span');
    name.className = 'board__name';
    // textContent, nie innerHTML: Namen kommen von fremden Leuten.
    name.textContent = String(entry.name ?? '');
    const score = document.createElement('span');
    score.className = 'board__score';
    score.textContent = String(entry.score ?? 0);
    li.append(rank, name, score);
    dom.board.append(li);
  });
}

function renderMode() {
  const mode = getMode();
  dom.boardBadge.classList.toggle('badge--server', mode === 'server');
  dom.boardBadge.classList.toggle('badge--local', mode === 'local');
  dom.boardMode.textContent = mode === 'server' ? t('board.worldwide') : t('board.local');
  dom.boardHint.hidden = mode !== 'local';
}

async function loadBoard() {
  dom.boardMode.textContent = t('board.loading');
  const result = await fetchTop(25);
  renderMode();
  renderBoard(result.entries);
}

async function sendScore(score, seconds) {
  if (submitting) return;
  submitting = true;
  dom.overStatus.textContent = t('over.submitting');

  const result = await submit({ name: sanitizeName(dom.name.value), score, time: seconds });
  submitting = false;
  renderMode();
  renderBoard(result.entries);

  if (result.ok) {
    dom.overStatus.textContent =
      result.rank > 0 ? t('over.submitted', { rank: result.rank }) : t('over.rankNone');
  } else {
    const reasonKey = `error.${result.error ?? 'unknown'}`;
    const reason = t(reasonKey) === reasonKey ? t('error.unknown') : t(reasonKey);
    dom.overStatus.textContent = t('over.submitError', { reason });
  }
}

// ---------------------------------------------------------------------------
// Oberfläche verdrahten
// ---------------------------------------------------------------------------

function toggleMute() {
  const muted = audio.toggleMute();
  dom.mute.textContent = muted ? t('misc.muteOff') : t('misc.muteOn');
}

function bindLanguage() {
  for (const button of document.querySelectorAll('[data-lang]')) {
    button.addEventListener('click', () => {
      audio.unlock();
      audio.play('ui');
      setLang(button.getAttribute('data-lang'));
    });
  }
  onLangChange(() => {
    for (const button of document.querySelectorAll('[data-lang]')) {
      button.setAttribute('aria-pressed', String(button.getAttribute('data-lang') === getLang()));
    }
    dom.mute.textContent = audio.muted ? t('misc.muteOff') : t('misc.muteOn');
    hud.invalidate();
    renderMode();
  });
}

function bindButtons() {
  const click = (id, fn) => {
    const element = $(id);
    if (element === null) return;
    element.addEventListener('click', () => {
      audio.unlock();
      audio.play('ui');
      fn();
    });
  };
  click('btnStart', startGame);
  click('btnResume', resumeGame);
  click('btnRestartPause', startGame);
  click('btnMenuPause', toMenu);
  click('btnRetry', startGame);
  click('btnMenuOver', toMenu);
  click('btnReload', loadBoard);
  dom.mute.addEventListener('click', () => {
    audio.unlock();
    toggleMute();
  });
}

function bindTouch() {
  input.bindTouchButton($('tLeft'), 'left');
  input.bindTouchButton($('tRight'), 'right');
  input.bindTouchButton($('tDown'), 'down');
  input.bindTouchButton($('tJump'), 'jump');

  const coarse = window.matchMedia('(pointer: coarse)').matches;
  if (coarse) dom.touch.hidden = false;

  // Erst bei echter Berührung einblenden, bei der ersten Taste wieder weg –
  // so stört die Bedienhilfe niemanden am Schreibtisch.
  window.addEventListener(
    'pointerdown',
    (event) => {
      if (event.pointerType === 'touch') dom.touch.hidden = false;
    },
    { passive: true },
  );
  window.addEventListener('keydown', () => {
    if (!coarse) dom.touch.hidden = true;
  });
}

function bindVisibility() {
  document.addEventListener('visibilitychange', () => {
    if (document.hidden && state === 'playing') pauseGame();
    else loop.resetClock();
  });
}

// ---------------------------------------------------------------------------
// Start
// ---------------------------------------------------------------------------

function boot() {
  setLang(initialLang());
  applyTranslations(document);

  dom.version.textContent = `${t('misc.version')} ${VERSION}`;
  dom.name.value = readString(NAME_KEY, '') ?? '';
  dom.hudBest.textContent = String(best);
  dom.mute.textContent = audio.muted ? t('misc.muteOff') : t('misc.muteOn');

  bindLanguage();
  bindButtons();
  bindTouch();
  bindVisibility();

  // Beim ersten Tastendruck den Ton freischalten (Autoplay-Regel der Browser).
  window.addEventListener('keydown', () => audio.unlock(), { once: true });
  window.addEventListener('pointerdown', () => audio.unlock(), { once: true });

  watchResize(dom.screen, dom.stagewrap, () => render());

  toMenu();
  loop.start();

  probe().then(() => loadBoard());
}

boot();
