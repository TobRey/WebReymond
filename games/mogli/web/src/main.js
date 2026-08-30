// Einstiegspunkt: verdrahtet Eingabe, Schleife, Darstellung, Story, Menüs,
// Levelliste und Bestenliste. Der Spielzustand selbst liegt in game/world.js.

import { VERSION } from './version.js';
import { createWorld, emptyInput, formatTicks } from './game/world.js';
import { demoMap } from './game/map.js';
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
import { createScene, drawScene, reloadScene, resizeScene } from './render/scene.js';
import { loadPack } from './render/assets.js';
import { createHud } from './render/hud.js';
import { buildCreatureAtlas, CREATURE_SIZE } from './render/creatures.js';
import {
  createParticles,
  emitCrumble,
  emitDeath,
  emitEmerald,
  emitLandDust,
  emitVine,
  emitWallJump,
  updateParticles,
} from './render/particles.js';
import { applyTranslations, getLang, initialLang, onLangChange, setLang, t } from './i18n.js';
import { guarded, ready, report, watchForErrors } from './engine/crash.js';
import { readString, writeString } from './engine/storage.js';
import { fetchTop, getMode, probe, submit } from './net/scores.js';
import { sanitizeName } from './net/scoreRules.js';
import { bestTicksFor, isUnlocked, loadMaps, rememberResult } from './net/maps.js';

// Ganz oben, vor allem anderen: von hier an landet jeder Fehler sichtbar auf
// dem Bildschirm statt in einer Konsole, die auf einem Handy niemand sieht.
watchForErrors();

const NAME_KEY = 'mogli.name';

/**
 * Holt ein Element und merkt sich, wenn es fehlt.
 *
 * Das passiert genau dann, wenn index.html und main.js aus verschiedenen
 * Ständen kommen. Statt null kommt ein loses Ersatzstück zurück: die fehlende
 * Kleinigkeit fehlt dann wirklich, aber sie reisst den Start nicht mit. Was
 * fehlt, steht im Meldungsstreifen.
 */
const missing = [];
const $ = (id) => {
  const element = document.getElementById(id);
  if (element !== null) return element;
  missing.push(id);
  return document.createElement('input');
};

const dom = {
  stage: $('stage'),
  screen: $('screen'),
  overlay: $('overlay'),
  panelMenu: $('panelMenu'),
  panelStory: $('panelStory'),
  panelPause: $('panelPause'),
  panelOver: $('panelOver'),
  hudRoot: $('hud'),
  hudTime: $('hudTime'),
  hudEmeralds: $('hudEmeralds'),
  pad: $('pad'),
  padLeft: $('padLeft'),
  padRight: $('padRight'),
  padJump: $('padJump'),
  tapHint: $('tapHint'),
  name: $('playerName'),
  levelList: $('levelList'),
  mapSource: $('mapSource'),
  storyPortrait: $('storyPortrait'),
  storyWho: $('storyWho'),
  storyText: $('storyText'),
  btnStoryNext: $('btnStoryNext'),
  overTime: $('overTime'),
  overEmeralds: $('overEmeralds'),
  overDeaths: $('overDeaths'),
  overBest: $('overBest'),
  overStatus: $('overStatus'),
  btnNext: $('btnNext'),
  boardOver: $('boardOver'),
  mute: $('mute'),
  full: $('full'),
  version: $('version'),
};

if (missing.length > 0) {
  report(
    `HTML und JavaScript passen nicht zusammen – es fehlt: ${missing.join(', ')}. ` +
      'Bitte alle Dateien neu hochladen und die Seite hart neu laden.',
  );
}

const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

// Zuerst die Bildhöhe zum Gerät wählen: Puffer und Hintergrundebenen hängen
// daran und werden gleich darunter gebaut.
chooseViewHeight(dom.stage);

const buffer = createBuffer();
const scene = createScene();
const input = createInput();
const audio = createAudio();
const particles = createParticles();
const hud = createHud({
  root: dom.hudRoot,
  time: dom.hudTime,
  emeralds: dom.hudEmeralds,
});

/** @type {'menu'|'story'|'playing'|'paused'|'over'} */
let state = 'menu';
let world = null;
let maps = [demoMap()];
let mapsSource = 'builtin';
let currentIndex = 0;
let hintShown = false;
/** true, wenn das Spiel per ?map=… direkt gestartet wurde (Editor-Test). */
let directTest = false;

// ---------------------------------------------------------------------------
// Szenenwechsel
// ---------------------------------------------------------------------------

function showPanel(panel) {
  for (const p of [dom.panelMenu, dom.panelStory, dom.panelPause, dom.panelOver]) {
    p.hidden = p !== panel;
  }
  dom.overlay.hidden = panel === null;
  dom.pad.hidden = panel !== null;
}

function toMenu() {
  state = 'menu';
  world = null;
  hud.hide();
  dom.tapHint.hidden = true;
  renderLevelList();
  showPanel(dom.panelMenu);
  input.releaseAll();
}

function startGame(index = currentIndex) {
  audio.unlock();
  enterFullscreenOnTouch();
  const name = sanitizeName(dom.name.value);
  dom.name.value = name === 'ANON' ? '' : name;
  writeString(NAME_KEY, name);

  currentIndex = index;
  world = createWorld(maps[index]);
  particles.items.length = 0;
  particles.shake = 0;
  state = 'playing';
  hintShown = false;
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

function finishGame() {
  state = 'over';
  hud.hide();
  dom.tapHint.hidden = true;

  const map = maps[currentIndex];
  const ticks = world.resultTicks;
  const isBest = rememberResult(map.id, ticks);

  dom.overTime.textContent = formatTicks(ticks);
  dom.overEmeralds.textContent = String(world.player.emeralds);
  dom.overDeaths.textContent = String(world.deaths);
  dom.overBest.hidden = !isBest;
  dom.overStatus.textContent = '';
  dom.btnNext.hidden = currentIndex + 1 >= maps.length;
  showPanel(dom.panelOver);
  input.releaseAll();

  sendTime(map.id, ticks);
}

// ---------------------------------------------------------------------------
// Levelliste
// ---------------------------------------------------------------------------

function el(tag, className, text) {
  const node = document.createElement(tag);
  if (className !== undefined) node.className = className;
  // textContent, nie innerHTML: Kartennamen kommen aus dem Editor.
  if (text !== undefined) node.textContent = text;
  return node;
}

function renderLevelList() {
  dom.levelList.replaceChildren();
  maps.forEach((map, index) => {
    const unlocked = isUnlocked(maps, index);
    const button = el('button', 'level');
    button.type = 'button';
    button.disabled = !unlocked;
    if (!unlocked) button.title = t('menu.locked');

    const best = bestTicksFor(map.id);
    button.append(
      el('span', 'level__name', `${index + 1}. ${map.name}`),
      best !== null
        ? el('span', 'level__time', formatTicks(best))
        : el('span', 'level__time level__time--none', unlocked ? t('menu.noTime') : '🔒'),
    );
    button.addEventListener('click', () => {
      audio.unlock();
      audio.play('ui');
      if (readString(STORY_SEEN_KEY, '0') === '1') startGame(index);
      else {
        currentIndex = index;
        storyThenPlay = true;
        toStory();
      }
    });
    dom.levelList.append(button);
  });

  dom.mapSource.textContent = mapsSource === 'builtin' ? t('menu.editorHint') : '';
}

// ---------------------------------------------------------------------------
// Story
// ---------------------------------------------------------------------------

const creatureAtlases = {};
let storyIndex = 0;
let storyBlink = 0;
// Warum die Geschichte gerade offen ist: vor dem ersten Levelstart soll
// "Überspringen" ins Spiel führen, aus dem Menü heraus zurück ins Menü.
let storyThenPlay = false;

function creatureAtlas(who) {
  if (creatureAtlases[who] === undefined) creatureAtlases[who] = buildCreatureAtlas(who);
  return creatureAtlases[who];
}

function drawPortrait(who, frame) {
  const canvas = dom.storyPortrait;
  const ctx = canvas.getContext('2d');
  if (ctx === null) return;
  ctx.imageSmoothingEnabled = false;
  ctx.clearRect(0, 0, canvas.width, canvas.height);
  // buildCreatureAtlas liefert direkt ein Canvas: alle Bilder nebeneinander,
  // jedes CREATURE_SIZE breit.
  const atlas = creatureAtlas(who);
  ctx.drawImage(
    atlas,
    frame * CREATURE_SIZE,
    0,
    CREATURE_SIZE,
    CREATURE_SIZE,
    0,
    0,
    canvas.width,
    canvas.height,
  );
}

function showStoryBeat() {
  const beat = STORY[storyIndex];
  dom.storyWho.textContent = t(nameKey(beat.who));
  dom.storyText.textContent = t(beat.text);
  dom.btnStoryNext.textContent =
    storyIndex === STORY.length - 1 ? t('story.play') : t('story.next');
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
    if (storyThenPlay) startGame(currentIndex);
    else toMenu();
  }
}

function storySkip() {
  writeString(STORY_SEEN_KEY, '1');
  if (storyThenPlay) startGame(currentIndex);
  else toMenu();
}

/** Blinzeln der Wesen: alle rund anderthalb Sekunden kurz die Augen zu. */
function updateStoryPortrait() {
  if (state !== 'story') return;
  storyBlink += 1;
  const frame = storyBlink % 90 < 6 ? 1 : 0;
  drawPortrait(STORY[storyIndex].who, frame);
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
        hideHint();
        break;
      case 'land':
        audio.play('land');
        emitLandDust(particles, footX, footY, 1);
        break;
      case 'emerald':
        audio.play('emerald');
        emitEmerald(particles, footX, body.y + body.h / 2);
        break;
      case 'key':
      case 'unlock':
      case 'checkpoint':
        audio.play('emerald');
        break;
      case 'spring':
      case 'portal':
        audio.play('vine');
        emitVine(particles, footX, body.y);
        break;
      case 'stomp':
        audio.play('wallJump');
        emitWallJump(particles, footX, footY, world.player.facing);
        break;
      case 'death':
        audio.play('death');
        emitDeath(particles, footX, body.y + body.h / 2);
        break;
      case 'respawn':
        loop.resetClock();
        break;
      case 'finish':
        audio.play('emerald');
        break;
      default:
        break;
    }
  }
}

function hideHint() {
  if (hintShown) return;
  hintShown = true;
  dom.tapHint.hidden = true;
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

  if (world.started) hideHint();

  const events = world.update(inputState);
  handleEvents(events);

  for (const cell of world.crumbled) {
    emitCrumble(particles, cell.col * TILE, cell.row * TILE);
  }

  updateParticles(particles);
  hud.update(world);

  if (world.finished) finishGame();
}

function render() {
  drawScene(buffer.ctx, world ?? previewWorld, scene, world ? particles : previewParticles, {
    reducedMotion,
  });
  present(dom.screen, buffer.canvas);
}

const loop = createLoop({ update, render });

// Hinter den Menüs steht die erste Karte, und ihre Elemente bewegen sich:
// Plattformen fahren, Gegner patrouillieren. Das erklärt das Spiel besser als
// ein Standbild – und kostet nichts, die Welt existiert sowieso.
const previewParticles = createParticles();
let previewWorld = createWorld(demoMap());
previewWorld.started = true;
const previewInput = emptyInput();

function updateAttract() {
  previewWorld.update(previewInput);
  updateParticles(previewParticles);
}

// ---------------------------------------------------------------------------
// Bestenliste (je Level, nach Zeit)
// ---------------------------------------------------------------------------

function renderBoardInto(container, entries) {
  const mode = getMode();
  container.replaceChildren();

  const head = el('div', 'board__head');
  head.append(
    el('span', 'board__mode', mode === 'server' ? t('board.worldwide') : t('board.local')),
  );
  container.append(head);

  const list = el('ol', 'board__list');
  if (entries.length === 0) {
    list.append(el('li', 'board__empty', t('board.empty')));
  }
  entries.forEach((entry, index) => {
    const item = el('li');
    item.append(
      el('span', 'board__rank', `${index + 1}.`),
      el('span', 'board__name', entry.name),
      el('span', 'board__score', formatTicks(entry.ticks)),
    );
    list.append(item);
  });
  container.append(list);

  if (mode === 'local') container.append(el('p', 'board__note', t('board.localHint')));
}

async function sendTime(mapId, ticks) {
  dom.overStatus.textContent = t('over.submitting');
  const name = sanitizeName(dom.name.value.length > 0 ? dom.name.value : readString(NAME_KEY, ''));
  const result = await submit(name, mapId, ticks);
  if (state !== 'over') return;
  if (result.error !== undefined) {
    const key = `error.${result.error}`;
    dom.overStatus.textContent = t('over.submitError').replace(
      '{reason}',
      t(key) === key ? t('error.unknown') : t(key),
    );
  } else {
    dom.overStatus.textContent =
      result.rank > 0 ? t('over.submitted').replace('{rank}', String(result.rank)) : '';
  }
  const top = await fetchTop(mapId, 10);
  if (state === 'over') renderBoardInto(dom.boardOver, top.entries);
}

// ---------------------------------------------------------------------------
// Ton, Vollbild, Sprache
// ---------------------------------------------------------------------------

function toggleMute() {
  const muted = audio.toggleMute();
  dom.mute.textContent = muted ? t('misc.muteOff') : t('misc.muteOn');
}

/**
 * Auf iPhones gibt es die Vollbild-API nicht. Dort bleibt der Knopf nicht
 * wirkungslos stehen, sondern verschwindet – und das Spiel füllt trotzdem
 * den Bildschirm, weil die Seite selbst genau so hoch ist wie das Fenster.
 */
function fullscreenSupported() {
  const root = document.documentElement;
  return typeof (root.requestFullscreen ?? root.webkitRequestFullscreen) === 'function';
}

function isFullscreen() {
  return document.fullscreenElement !== null && document.fullscreenElement !== undefined;
}

/**
 * Ein abgelehntes Vollbild ist kein Grund, irgendetwas abzubrechen – aber der
 * Rückgabewert ist nicht überall ein Promise. Das alte webkitRequestFullscreen
 * gibt undefined zurück; ein .catch() darauf wirft (siehe 2.0.1).
 */
function ignoreRejection(result) {
  if (result !== null && typeof result === 'object' && typeof result.catch === 'function') {
    result.catch(() => {});
  }
}

function enterFullscreen() {
  const root = document.documentElement;
  const request = root.requestFullscreen ?? root.webkitRequestFullscreen;
  if (typeof request === 'function') ignoreRejection(request.call(root));
}

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
    if (typeof exit === 'function') ignoreRejection(exit.call(document));
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
  if (state === 'menu') renderLevelList();
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
      event.stopPropagation();
      audio.unlock();
      audio.play('ui');
      fn();
    });
  };
  click('btnStory', () => {
    storyThenPlay = false;
    toStory();
  });
  click('btnStoryNext', storyNext);
  click('btnStorySkip', storySkip);
  click('btnResume', resumeGame);
  click('btnRestartPause', () => startGame());
  click('btnMenuPause', toMenu);
  click('btnRetry', () => startGame());
  click('btnNext', () => startGame(currentIndex + 1));
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

function fitToStage() {
  if (chooseViewHeight(dom.stage).changed) {
    resizeBuffer(buffer);
    resizeScene(scene);
  }
  fitScreen(dom.screen, dom.stage);
  render();
}

/**
 * REIHENFOLGE: erst verdrahten, dann verzieren – die Lehre aus 2.0.1. Alles,
 * was auf eine Eingabe reagiert, zuerst; jeder Block für sich abgesichert.
 */
function boot() {
  guarded('Knöpfe', bindButtons);
  guarded('Steuerkreuz', () => {
    input.bindHoldButton(dom.padLeft, 'left');
    input.bindHoldButton(dom.padRight, 'right');
    input.bindHoldButton(dom.padJump, 'jump');
  });
  guarded('Sprachwahl', bindLanguage);
  guarded('Sichtbarkeit', bindVisibility);
  guarded('Ton', () => {
    window.addEventListener('keydown', () => audio.unlock(), { once: true });
    window.addEventListener('pointerdown', () => audio.unlock(), { once: true });
  });

  // Ab hier ist das Menü bedienbar; der Wachhund in index.html darf schweigen.
  ready();

  guarded('Sprache', () => {
    setLang(initialLang());
    applyTranslations(document);
    refreshLanguage();
  });

  guarded('Menütexte', () => {
    dom.name.value = readString(NAME_KEY, '') ?? '';
    dom.full.hidden = !fullscreenSupported();
  });

  guarded('Bildgrösse', () => {
    resizeNow = fitToStage;
    watchResize(fitToStage);
  });

  toMenu();
  loop.start();

  probe();

  // Karten laden – und erst DANACH auf einen ?map=…-Direktstart reagieren:
  // der Editor öffnet das Spiel so zum Probespielen.
  loadMaps().then((result) => {
    maps = result.maps;
    mapsSource = result.source;
    // Die Vorschau hinter dem Menü zeigt die erste echte Karte.
    previewWorld = createWorld(maps[0]);
    previewWorld.started = true;

    const wanted = new URLSearchParams(window.location.search).get('map');
    const index = wanted === null ? -1 : maps.findIndex((m) => m.id === wanted);
    if (index >= 0) {
      directTest = true;
      writeString(STORY_SEEN_KEY, '1');
      startGame(index);
    } else if (state === 'menu') {
      renderLevelList();
    }
  });

  // Eigene Grafik aus dem Admin-Bereich. Das Spiel läuft dabei schon – ein
  // Paket darf den Start nicht aufhalten.
  loadPack().then((changed) => {
    if (!changed) return;
    reloadScene(scene);
    render();
  });
}

guarded('Start', boot);

// `directTest` ist bewusst exportfrei benutzt – die Variable dokumentiert nur
// den Zustand für Lesende; der Editor-Test verhält sich sonst wie ein Spiel.
void directTest;
