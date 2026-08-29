// Die Vorschau: das ECHTE Spiel mit der noch nicht gespeicherten Grafik.
//
// Sie importiert dieselben Module wie das Spiel – nichts ist hier nachgebaut.
// Das ist der ganze Sinn: eine Vorschau, die anders rechnet als das Spiel,
// beweist nichts.
//
// Dazu der Automat aus game/bot.js. Er spielt bewusst mittelmässig; kommt ER
// mit den selbst gezeichneten Kästen nicht vom Fleck, kommt auch niemand
// sonst. Damit sieht man VOR dem Speichern, ob der Turm noch besteigbar ist.

import { VIEW_H, VIEW_W, setViewHeight } from '../../src/game/constants.js';
import { createWorld, emptyInput } from '../../src/game/world.js';
import { botInput, createBot } from '../../src/game/bot.js';
import { createScene, drawScene, reloadScene } from '../../src/render/scene.js';
import { createParticles, updateParticles } from '../../src/render/particles.js';
import { applyPack, clear as clearAssets } from '../../src/render/assets.js';

let scene = null;
let particles = null;
let world = null;
let bot = null;
let input = null;
let raf = 0;

/** Die Vorschau zeigt das kleinste erlaubte Bild – das passt neben die Regler. */
function ensureSize() {
  setViewHeight(0);
}

/**
 * Setzt ein Paket in die Vorschau und beginnt von vorn.
 * @returns {Promise<void>}
 */
export async function usePack(pack) {
  ensureSize();
  await applyPack(pack);
  if (scene === null) {
    scene = createScene();
    particles = createParticles();
  } else {
    reloadScene(scene);
  }
  restart();
}

export function restart() {
  world = createWorld((Date.now() ^ 0x9e3d) >>> 0);
  bot = createBot();
  input = emptyInput();
  particles.items.length = 0;
}

/** Zeichnet ein Bild, ohne die Welt weiterzudrehen. */
function paint(canvas) {
  if (scene === null || world === null) return;
  canvas.width = VIEW_W;
  canvas.height = VIEW_H;
  const ctx = canvas.getContext('2d', { alpha: false });
  ctx.imageSmoothingEnabled = false;
  drawScene(ctx, world, scene, particles, { reducedMotion: true });
}

/**
 * Lässt den Automaten spielen und meldet, wie weit er kommt.
 *
 * @param {HTMLCanvasElement} canvas
 * @param {number} seconds
 * @param {(metres: number, running: boolean) => void} onTick
 */
export function runBot(canvas, seconds, onTick) {
  stop();
  restart();

  const until = 60 * seconds;
  let ticks = 0;
  let best = 0;

  const step = () => {
    // Vier Logikschritte je Bild: die Vorschau soll in ein paar Sekunden
    // zeigen, wofür ein Mensch eine halbe Minute braucht.
    for (let i = 0; i < 4 && ticks < until && !world.over; i += 1) {
      botInput(bot, world, input);
      world.update(input);
      updateParticles(particles);
      ticks += 1;
    }
    best = Math.max(best, world.metres);
    paint(canvas);

    const running = ticks < until && !world.over;
    onTick(best, running);
    if (running) raf = window.requestAnimationFrame(step);
    else raf = 0;
  };
  step();
}

export function stop() {
  if (raf !== 0) window.cancelAnimationFrame(raf);
  raf = 0;
}

/** Beim Verlassen des Reiters: Vorschau anhalten, Spielgrafik zurücksetzen. */
export function release() {
  stop();
  clearAssets();
}

export { paint };
