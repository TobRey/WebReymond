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
      if (rest.length === 4) rechtecke.push({ y: rest[1], h: rest[3] });
      else if (rest.length === 8) rechtecke.push({ y: rest[5], h: rest[7] });
    },
  };
}

const ebene = (w, h, speed) => ({ canvas: { width: w, height: h }, speed });

/** Bleibt zwischen 0 und VIEW_H eine Zeile ohne Bild? */
function luecke(rechtecke) {
  const abgedeckt = new Array(VIEW_H).fill(false);
  for (const r of rechtecke) {
    for (let y = Math.max(0, Math.ceil(r.y)); y < Math.min(VIEW_H, r.y + r.h); y += 1) {
      abgedeckt[y] = true;
    }
  }
  return abgedeckt.indexOf(false);
}

test('eine Ebene, die niedriger ist als der Bildschirm, lässt keine Lücke', () => {
  // Genau der gemeldete Fall: 801 x 1672 auf Spielbreite gebracht sind 534,
  // der Bildschirm ist 640. Vor der Reparatur blieb ab Zeile 534 alles leer.
  setViewHeight(VIEW_H_MAX);
  for (const speed of [0, 0.3, 1]) {
    for (const kameraY of [0, -17, -534, -1000.5, 250]) {
      const ctx = schreiber();
      drawBackground(ctx, { layers: [ebene(VIEW_W, 534, speed)] }, kameraY, 0);
      assert.equal(
        luecke(ctx.rechtecke),
        -1,
        `Tempo ${speed}, Kamera ${kameraY}: ab Zeile ${luecke(ctx.rechtecke)} ist nichts gezeichnet`,
      );
    }
  }
});

test('das gilt bei jeder Bildhöhe und jeder Ebenenhöhe', () => {
  for (const hoehe of [336, 400, 512, 640]) {
    setViewHeight(hoehe);
    for (const ebenenHoehe of [1, 16, 100, 333, 534, 640, 1024]) {
      for (const speed of [0, 0.12, 0.55]) {
        const ctx = schreiber();
        drawBackground(ctx, { layers: [ebene(VIEW_W, ebenenHoehe, speed)] }, -777.3, 0);
        assert.equal(
          luecke(ctx.rechtecke),
          -1,
          `Bild ${hoehe}, Ebene ${ebenenHoehe}, Tempo ${speed}: Lücke`,
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
  drawBackground(ctx, { layers: [ebene(307, 640, 0)] }, -5000, 0);
  assert.equal(ctx.rechtecke.length, 1, 'ein stehender Hintergrund gehört genau einmal hin');
  assert.equal(luecke(ctx.rechtecke), -1);
});

test('eine mitlaufende Ebene wandert wirklich', () => {
  setViewHeight(VIEW_H_MAX);
  const oben = (kameraY) => {
    const ctx = schreiber();
    drawBackground(ctx, { layers: [ebene(VIEW_W, 300, 0.5)] }, kameraY, 0);
    return Math.min(...ctx.rechtecke.map((r) => r.y));
  };
  assert.notEqual(oben(0), oben(-100), 'die Ebene steht still, obwohl sie mitlaufen soll');
});

test('eine Ebene, die nicht genau die Spielbreite hat, wird nicht gestaucht', () => {
  // Seit die Ablage breitere Ebenen erlaubt (damit ein stehender Hintergrund
  // den Schirm füllt), muss die Höhe beim Kacheln im Verhältnis mitgehen.
  setViewHeight(VIEW_H_MAX);
  const ctx = schreiber();
  drawBackground(ctx, { layers: [ebene(512, 400, 0.3)] }, 0, 0);
  // 512 breit auf 256 gezogen ist der halbe Faktor: aus 400 werden 200.
  assert.equal(ctx.rechtecke[0].h, 200, `gezeichnet wurde mit Höhe ${ctx.rechtecke[0].h}`);
});

test('setViewHeight ist danach wieder auf einem gültigen Wert', () => {
  setViewHeight(VIEW_H_MAX);
  assert.equal(VIEW_H, VIEW_H_MAX);
});
