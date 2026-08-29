// Einstiegspunkt: verdrahtet Eingabe, Schleife, Darstellung, Story, Menüs und
// Bestenliste. Der Spielzustand selbst liegt in game/world.js.

import { VERSION } from './version.js';
import { createWorld, emptyInput } from './game/world.js';
import { botInput, createBot } from './game/bot.js';
import { TILE } from './game/constants.js';
import { STORY, STORY_SEEN_KEY, nameKey } from './game/story.js';
import { createLoop } from './engine/loop.js';
import { createInput } from './engine/input.js';
import { createAudio } from './engine/audio.js';
import {
  chooseViewHeight,
  createBuffer,
  fitScreen,
  present,
  resizeBuffer,
  watchResize,
} from './engine/canvas.js';
import { createScene, drawScene, resizeScene } from './render/scene.js';
import { createHud } from './render/hud.js';
import { CREATURE_SIZE, buildCreatureAtlas } from './render/creatures.js';
import {
  createParticles,
  emitCrumble,
  emitDeath,
  emitEmerald,
  emitLandDust,
  emitVine,
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

const NAME_KEY = 'mogli.name';

const $ = (id) => document.getElementById(id);

const dom = {
  stage: $('stage'),
  screen: $('screen'),
  overlay: $('overlay'),
  panelMenu: $('panelMenu'),
  panelStory: $('panelStory'),
  panelPause: $('panelPause'),
  panelOver: $('panelOver'),
  hudRoot: $('hud'),
  hudHeight: $('hudHeight'),
  hudEmeralds: $('hudEmeralds'),
  pressure: $('pressureFill'),
  tapHint: $('tapHint'),
  name: $('playerName'),
  storyPortrait: $('storyPortrait'),
  storyWho: $('storyWho'),
  storyText: $('storyText'),
  btnStoryNext: $('btnStoryNext'),
  overHeight: $('overHeight'),
  overEmeralds: $('overEmeralds'),
  overScore: $('overScore'),
  overPersonal: $('overPersonal'),
  overBest: $('overBest'),
  overStatus: $('overStatus'),
  boardMenu: $('boardMenu'),
  boardOver: $('boardOver'),
  mute: $('mute'),
  full: $('full'),
  version: $('version'),
};

const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

// Zuerst die Bildhöhe zum Gerät wählen: Puffer, Hintergrundebenen und die
// Kamera der Vorschauwelt hängen daran und werden gleich darunter gebaut.
chooseViewHeight(dom.stage);

const buffer = createBuffer();
const scene = createScene();
const input = createInput();
const audio = createAudio();
const particles = createParticles();
const hud = createHud({
  root: dom.hudRoot,
  height: dom.hudHeight,
  emeralds: dom.hudEmeralds,
  pressure: dom.pressure,
});

/** @type {'menu'|'story'|'playing'|'paused'|'over'} */
let state = 'menu';
let world = null;
let best = personalBest();
let submitting = false;
let hintShown = false;

// ---------------------------------------------------------------------------
// Szenenwechsel
// ---------------------------------------------------------------------------

function showPanel(panel) {
  for (const p of [dom.panelMenu, dom.panelStory, dom.panelPause, dom.panelOver]) {
    p.hidden = p !== panel;
  }
  dom.overlay.hidden = panel === null;
}

function toMenu() {
  state = 'menu';
  world = null;
  hud.hide();
  dom.tapHint.hidden = true;
  showPanel(dom.panelMenu);
  input.releaseAll();
}

function startGame() {
  audio.unlock();
  enterFullscreenOnTouch();
  const name = sanitizeName(dom.name.value);
  dom.name.value = name === 'ANON' ? '' : name;
  writeString(NAME_KEY, name);

  world = createWorld((Date.now() ^ (Math.random() * 0xffffffff)) >>> 0);
  particles.items.length = 0;
  particles.shake = 0;
  state = 'playing';
  hintShown = false;
  dom.tapHint.dataset.key = '';
  dom.tapHint.textContent = t('misc.tapToStart');
  dom.tapHint.hidden = false;
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
  dom.tapHint.hidden = true;

  const score = world.score;
  const isBest = rememberPersonalBest(score);
  if (isBest) best = score;

  dom.overHeight.textContent = `${world.metres} ${t('hud.metres')}`;
  dom.overEmeralds.textContent = String(world.player.emeralds);
  dom.overPersonal.textContent = String(best);
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
// Story
// ---------------------------------------------------------------------------

const creatureAtlases = {};
let storyIndex = 0;
let storyBlink = 0;

function creatureAtlas(who) {
  if (creatureAtlases[who] === undefined) creatureAtlases[who] = buildCreatureAtlas(who);
  return creatureAtlases[who];
}

function drawPortrait(who, frame) {
  const ctx = dom.storyPortrait.getContext('2d');
  ctx.imageSmoothingEnabled = false;
  ctx.clearRect(0, 0, CREATURE_SIZE, CREATURE_SIZE);
  ctx.drawImage(
    creatureAtlas(who),
    frame * CREATURE_SIZE,
    0,
    CREATURE_SIZE,
    CREATURE_SIZE,
    0,
    0,
    CREATURE_SIZE,
    CREATURE_SIZE,
  );
}

function showStoryBeat() {
  const beat = STORY[storyIndex];
  dom.storyWho.textContent = t(nameKey(beat.who));
  dom.storyText.textContent = t(beat.text);
  dom.btnStoryNext.textContent =
    storyIndex === STORY.length - 1 ? t('story.play') : t('story.next');
  storyBlink = 0;
  drawPortrait(beat.who, 0);
}

function toStory() {
  state = 'story';
  world = null;
  storyIndex = 0;
  hud.hide();
  dom.tapHint.hidden = true;
  showStoryBeat();
  showPanel(dom.panelStory);
  input.releaseAll();
}

function storyNext() {
  if (storyIndex < STORY.length - 1) {
    storyIndex += 1;
    showStoryBeat();
  } else {
    writeString(STORY_SEEN_KEY, '1');
    startGame();
  }
}

function storySkip() {
  writeString(STORY_SEEN_KEY, '1');
  toMenu();
}

/** Blinzeln der Wesen: alle rund anderthalb Sekunden kurz die Augen zu. */
function updateStoryPortrait() {
  if (state !== 'story') return;
  storyBlink += 1;
  const cycle = storyBlink % 96;
  const frame = cycle > 88 ? 1 : 0;
  if (cycle === 89 || cycle === 0) drawPortrait(STORY[storyIndex].who, frame);
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
        emitLandDust(particles, footX, footY, 0.4);
        if (!hintShown) {
          hintShown = true;
          dom.tapHint.hidden = true;
        }
        break;
      case 'wallJump':
        audio.play('wallJump');
        emitWallJump(particles, footX, body.y + body.h / 2, world.player.facing);
        if (!hintShown) {
          hintShown = true;
          dom.tapHint.hidden = true;
        }
        break;
      case 'land':
        audio.play('land');
        emitLandDust(particles, footX, footY, 1);
        break;
      case 'turn':
        audio.play('turn');
        break;
      case 'emerald':
        audio.play('emerald');
        emitEmerald(particles, footX, body.y + body.h / 2);
        break;
      case 'vine':
        audio.play('vine');
        emitVine(particles, footX, body.y);
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
    } else if (command === 'fullscreen') {
      toggleFullscreen();
    }
  }

  const inputState = input.update();

  if (state !== 'playing' || world === null) {
    updateParticles(particles);
    updateAttract();
    updateStoryPortrait();
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
  hud.update(world);
  updateTapHint();

  if (world.over) endGame();
}

/** Der Hinweis sagt erst "los", dann "springen", und verschwindet danach. */
function updateTapHint() {
  if (hintShown || world === null) return;
  const key = world.started ? 'misc.tapToJump' : 'misc.tapToStart';
  if (dom.tapHint.dataset.key !== key) {
    dom.tapHint.dataset.key = key;
    dom.tapHint.textContent = t(key);
  }
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

// Hinter den Menüs und der Story klettert ein Automat. Ein stehendes Bild wäre
// die einfachere Lösung, aber eine Vorschau, in der sich etwas bewegt, erklärt
// das Spiel besser als jede Beschreibung.
const previewParticles = createParticles();
const previewBot = createBot();
const previewInput = emptyInput();
let previewWorld = createWorld(20260829);

function updateAttract() {
  if (previewWorld.over) {
    previewWorld = createWorld((Date.now() ^ 0x5eed) >>> 0);
    previewBot.held = false;
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

// Die Liste steht zweimal in der Seite: im Menü und im Ergebnis. Beide bekommen
// denselben Inhalt aus einer Quelle – die Daten liegen hier, nicht im DOM.
const BOARD_LIMIT = 25;
let boardEntries = [];
let boardLoading = false;

function el(tag, className, text) {
  const node = document.createElement(tag);
  if (className !== undefined) node.className = className;
  // textContent, nie innerHTML: Namen kommen von fremden Leuten.
  if (text !== undefined) node.textContent = text;
  return node;
}

function renderBoardInto(container) {
  const mode = getMode();
  container.classList.toggle('board--server', mode === 'server');
  container.classList.toggle('board--local', mode === 'local');

  const head = el('p', 'board__head');
  const modeBox = el('span', 'board__mode');
  modeBox.append(
    el('i', 'board__dot'),
    el(
      'span',
      undefined,
      boardLoading
        ? t('board.loading')
        : mode === 'server'
          ? t('board.worldwide')
          : t('board.local'),
    ),
  );
  head.append(el('span', undefined, t('menu.leaderboardTitle')), modeBox);

  const list = el('ol', 'board__list');
  if (boardEntries.length === 0) {
    list.append(el('li', 'board__empty', t('board.empty')));
  } else {
    boardEntries.forEach((entry, index) => {
      const li = el('li');
      li.append(
        el('span', 'board__rank', `${index + 1}.`),
        el('span', 'board__name', String(entry.name ?? '')),
        el('span', 'board__score', String(entry.score ?? 0)),
      );
      list.append(li);
    });
  }

  const note = el(
    'p',
    'board__note',
    mode === 'local' ? t('board.localHint') : t('board.forgeable'),
  );

  container.replaceChildren(head, list, note);
}

function renderBoards() {
  renderBoardInto(dom.boardMenu);
  renderBoardInto(dom.boardOver);
}

async function loadBoard() {
  boardLoading = true;
  renderBoards();
  const result = await fetchTop(BOARD_LIMIT);
  boardEntries = result.entries;
  boardLoading = false;
  renderBoards();
}

async function sendScore(score, seconds) {
  if (submitting) return;
  submitting = true;
  dom.overStatus.textContent = t('over.submitting');

  const result = await submit({ name: sanitizeName(dom.name.value), score, time: seconds });
  submitting = false;
  boardEntries = result.entries;
  renderBoards();

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

/**
 * Auf iPhones gibt es die Vollbild-API nicht. Dort bleibt der Knopf nicht
 * wirkungslos stehen, sondern verschwindet – und das Spiel füllt trotzdem den
 * Bildschirm, weil die Seite selbst genau so hoch ist wie das Fenster.
 */
function fullscreenSupported() {
  const root = document.documentElement;
  return typeof (root.requestFullscreen ?? root.webkitRequestFullscreen) === 'function';
}

function isFullscreen() {
  return document.fullscreenElement !== null && document.fullscreenElement !== undefined;
}

function enterFullscreen() {
  const root = document.documentElement;
  const request = root.requestFullscreen ?? root.webkitRequestFullscreen;
  if (typeof request === 'function') request.call(root).catch(() => {});
}

/**
 * Am Handy geht es beim Losspielen von allein ins Vollbild: dort ist das Spiel
 * die ganze Seite, und die Adressleiste nimmt sonst ein Stück davon weg. Am
 * Rechner bleibt es beim Knopf und der Taste F – da will man das Fenster oft
 * behalten. Der Aufruf steht in startGame(), also innerhalb eines Tipps; ohne
 * diese Nutzergeste lehnen Browser das Vollbild ab.
 */
function enterFullscreenOnTouch() {
  if (isFullscreen() || !fullscreenSupported()) return;
  if (!window.matchMedia('(pointer: coarse)').matches) return;
  enterFullscreen();
}

function toggleFullscreen() {
  if (!isFullscreen()) {
    enterFullscreen();
  } else {
    const exit = document.exitFullscreen ?? document.webkitExitFullscreen;
    if (typeof exit === 'function') exit.call(document).catch(() => {});
  }
}

/** Alles, was von der Sprache abhängt und nicht per data-i18n läuft. */
function refreshLanguage() {
  for (const button of document.querySelectorAll('[data-lang]')) {
    button.setAttribute('aria-pressed', String(button.getAttribute('data-lang') === getLang()));
  }
  dom.mute.textContent = audio.muted ? t('misc.muteOff') : t('misc.muteOn');
  dom.version.textContent = `${t('misc.version')} ${VERSION}`;
  updateFullscreenLabel();
  hud.invalidate();
  renderBoards();
  if (state === 'story') showStoryBeat();
}

function bindLanguage() {
  for (const button of document.querySelectorAll('[data-lang]')) {
    button.addEventListener('click', () => {
      audio.unlock();
      audio.play('ui');
      setLang(button.getAttribute('data-lang'));
    });
  }
  onLangChange(refreshLanguage);
}

function updateFullscreenLabel() {
  dom.full.textContent = isFullscreen() ? t('misc.fullscreenExit') : t('misc.fullscreen');
}

function bindButtons() {
  const click = (id, fn) => {
    const element = $(id);
    if (element === null) return;
    element.addEventListener('click', (event) => {
      // Gürtel und Hosenträger: die Einblendung ist für den Sprungknopf schon
      // ein toter Bereich, hier hört der Klick zusätzlich auf.
      event.stopPropagation();
      audio.unlock();
      audio.play('ui');
      fn();
    });
  };
  click('btnStart', () => (readString(STORY_SEEN_KEY, '0') === '1' ? startGame() : toStory()));
  click('btnStory', toStory);
  click('btnStoryNext', storyNext);
  click('btnStorySkip', storySkip);
  click('btnResume', resumeGame);
  click('btnRestartPause', startGame);
  click('btnMenuPause', toMenu);
  click('btnRetry', startGame);
  click('btnMenuOver', toMenu);

  dom.mute.addEventListener('click', () => {
    audio.unlock();
    toggleMute();
  });
  dom.full.addEventListener('click', () => {
    audio.unlock();
    toggleFullscreen();
  });
  document.addEventListener('fullscreenchange', () => {
    updateFullscreenLabel();
    // Die Bühne hat jetzt eine andere Grösse – neu einpassen.
    resizeNow();
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

let resizeNow = () => {};

/**
 * Passt das Bild an die Bühne an – in dieser Reihenfolge, sie hängt zusammen:
 *
 * 1. Bildhöhe zum Seitenverhältnis wählen. Ein Handy ist mehr als doppelt so
 *    hoch wie breit; bei fester Höhe bliebe oben und unten ein schwarzer
 *    Balken, und das Spiel wäre eine Briefmarke in der Mitte.
 * 2. Nur wenn sich die Höhe wirklich geändert hat: Puffer und die
 *    bildhohen Hintergrundebenen neu bauen. Das kostet ein paar Millisekunden
 *    und darf nicht bei jedem Ausfahren der Adressleiste passieren.
 * 3. Ganzzahlig auf den Bildschirm einpassen und sofort ein Bild zeichnen,
 *    damit es zwischen Grössenänderung und nächstem Bild nicht flackert.
 */
function fitToStage() {
  if (chooseViewHeight(dom.stage).changed) {
    resizeBuffer(buffer);
    resizeScene(scene);
  }
  fitScreen(dom.screen, dom.stage);
  render();
}

function boot() {
  setLang(initialLang());
  applyTranslations(document);

  dom.name.value = readString(NAME_KEY, '') ?? '';
  dom.overPersonal.textContent = String(best);
  dom.full.hidden = !fullscreenSupported();

  bindLanguage();
  bindButtons();
  bindVisibility();
  // Alles Sprachabhängige einmal setzen – setLang() oben lief, bevor der
  // Zuhörer dafür überhaupt angemeldet war.
  refreshLanguage();

  // Die gesamte Spielfläche ist der Sprungknopf – ausser dort, wo gerade eine
  // Einblendung liegt. Es gibt keine Knöpfe mehr.
  input.bindTapArea(dom.stage, dom.overlay);

  // Beim ersten Druck den Ton freischalten (Autoplay-Regel der Browser).
  window.addEventListener('keydown', () => audio.unlock(), { once: true });
  window.addEventListener('pointerdown', () => audio.unlock(), { once: true });

  resizeNow = fitToStage;
  watchResize(fitToStage);

  toMenu();
  loop.start();

  probe().then(() => loadBoard());
}

boot();
