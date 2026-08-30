// Deckt eine Hintergrundebene wirklich den ganzen Bildschirm?
//
// Diese Datei gibt es wegen eines gemeldeten Fehlers: ein eingesetztes Foto
// stand "nur oben im Bild und deckt nicht alles ab". Die Ursache war die
// Zeichenschleife - sie lief nur nach oben und liess unten alles frei, was
// höher lag als die Ebene selbst. Eingebaute Ebenen sind immer bildhoch,
// deshalb ist es jahrelang niemandem aufgefallen.
//
// Geprüft wird ohne Browser: ein winziger Ersatz für den Zeichenkontext
// schreibt mit, welche Rechtecke gemalt würden, und der Test rechnet nach, ob
// sie zusammen den Bildschirm bedecken.

import test from 'node:test';
import assert from 'node:assert/strict';

import { VIEW_H, VIEW_H_MAX, VIEW_W, setViewHeight } from '../web/src/game/constants.js';
import { drawBackground } from '../web/src/render/background.js';

/** Ein Zeichenkontext, der nichts zeichnet, sondern Buch führt. */
function schreiber() {
  const rechtecke = [];
  return {
    rechtecke,
    globalAlpha: 1,
    fillStyle: '',
    createLinearGradient: () => ({ addColorStop() {} }),
    fillRect() {},
    drawImage(_bild, ...rest) {
      // Nur die Form mit Ziel-Rechteck interessiert hier.
      if (rest.length === 4) {
        rechtecke.push({ x: rest[0], y: rest[1], w: rest[2], h: rest[3] });
      } else if (rest.length === 8) {
        rechtecke.push({ x: rest[4], y: rest[5], w: rest[6], h: rest[7] });
      }
    },
  };
}

const ebene = (w, h, speed) => ({ canvas: { width: w, height: h }, speed });

/** Bleibt zwischen 0 und VIEW_W eine SPALTE ohne Bild? (seit 3.0 waagerecht) */
function luecke(rechtecke) {
  const abgedeckt = new Array(VIEW_W).fill(false);
  for (const r of rechtecke) {
    for (let x = Math.max(0, Math.ceil(r.x)); x < Math.min(VIEW_W, r.x + r.w); x += 1) {
      abgedeckt[x] = true;
    }
  }
  return abgedeckt.indexOf(false);
}

test('eine Ebene, die schmaler ist als der Bildschirm, lässt keine Lücke', () => {
  // Die Lehre aus 2.2.1, um 90 Grad gedreht: schmale Ebenen müssen sich
  // waagerecht wiederholen, bis der ganze Ausschnitt gedeckt ist.
  setViewHeight(VIEW_H_MAX);
  for (const speed of [0, 0.3, 1]) {
    for (const kameraX of [0, 17, 534, 1000.5, -250]) {
      const ctx = schreiber();
      drawBackground(ctx, { layers: [ebene(120, VIEW_H_MAX, speed)] }, kameraX);
      assert.equal(
        luecke(ctx.rechtecke),
        -1,
        `Tempo ${speed}, Kamera ${kameraX}: ab Spalte ${luecke(ctx.rechtecke)} ist nichts gezeichnet`,
      );
    }
  }
});

test('das gilt bei jeder Bildhöhe und jeder Ebenengrösse', () => {
  for (const hoehe of [336, 400, 512, 640]) {
    setViewHeight(hoehe);
    for (const ebenenBreite of [1, 16, 100, 333, 534, 640, 1024]) {
      for (const speed of [0, 0.12, 0.55]) {
        const ctx = schreiber();
        drawBackground(ctx, { layers: [ebene(ebenenBreite, hoehe, speed)] }, 777.3);
        assert.equal(
          luecke(ctx.rechtecke),
          -1,
          `Bild ${hoehe}, Ebene ${ebenenBreite}, Tempo ${speed}: Lücke`,
        );
      }
    }
  }
});

test('eine Ebene mit Tempo 0 wird einmal gezeichnet, nicht wiederholt', () => {
  // Wiederholen ergäbe bei einem stehenden Bild keinen Sinn, sondern nur eine
  // Naht quer durchs Bild.
  setViewHeight(VIEW_H_MAX);
  const ctx = schreiber();
  drawBackground(ctx, { layers: [ebene(307, 640, 0)] }, 5000);
  assert.equal(ctx.rechtecke.length, 1, 'ein stehender Hintergrund gehört genau einmal hin');
  assert.equal(luecke(ctx.rechtecke), -1);
});

test('eine mitlaufende Ebene wandert wirklich – seitwärts, seit 3.0', () => {
  setViewHeight(VIEW_H_MAX);
  const links = (kameraX) => {
    const ctx = schreiber();
    drawBackground(ctx, { layers: [ebene(300, VIEW_H, 0.5)] }, kameraX);
    return Math.min(...ctx.rechtecke.map((r) => r.x));
  };
  assert.notEqual(links(0), links(100), 'die Ebene steht still, obwohl sie mitlaufen soll');
});

test('eine Ebene, die nicht genau bildhoch ist, wird nicht gestaucht', () => {
  // Beim waagerechten Kacheln wird auf Bildhöhe gebracht; die Breite muss im
  // Verhältnis mitgehen, sonst ist das Muster gequetscht.
  setViewHeight(VIEW_H_MAX);
  const ctx = schreiber();
  drawBackground(ctx, { layers: [ebene(400, VIEW_H_MAX * 2, 0.3)] }, 0);
  // Doppelt so hoch wie das Bild: der Faktor ist ½, aus 400 werden 200 breit.
  assert.equal(ctx.rechtecke[0].w, 200, `gezeichnet wurde mit Breite ${ctx.rechtecke[0].w}`);
});

test('setViewHeight ist danach wieder auf einem gültigen Wert', () => {
  setViewHeight(VIEW_H_MAX);
  assert.equal(VIEW_H, VIEW_H_MAX);
});
