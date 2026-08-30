// Der GIF-Leser.
//
// Die Fälle stehen in giffaelle.mjs und werden von tools/gif-gegenprobe.mjs
// zusätzlich gegen Chromiums eigenen ImageDecoder gehalten. Hier wird geprüft,
// was ohne Browser prüfbar ist – und zwar an den Stellen, an denen ein
// GIF-Leser üblicherweise falsch liegt: Durchsichtigkeit, die beiden
// Räum-Verfahren, verschränkte Zeilen und die Auswahl nach Zeit.

import test from 'node:test';
import assert from 'node:assert/strict';

import { decodeGif, pickEvenlyInTime } from '../web/src/render/gif.js';
import { makeGif } from './gifmaker.mjs';
import { PALETTE, faelle } from './giffaelle.mjs';

/** Die Farbe eines Bildpunktes als [r,g,b,a]. */
function pixel(frame, breite, x, y) {
  const i = (y * breite + x) * 4;
  return [...frame.pixels.slice(i, i + 4)];
}
const farbe = (index) => [...PALETTE[index], 255];

const nachName = new Map(faelle().map((f) => [f.name, f.spec]));
const lies = (name) => decodeGif(makeGif(nachName.get(name)));

test('alle Fälle lassen sich lesen und haben die richtige Bildzahl', () => {
  for (const fall of faelle()) {
    const gelesen = decodeGif(makeGif(fall.spec));
    assert.equal(gelesen.width, fall.spec.width, fall.name);
    assert.equal(gelesen.height, fall.spec.height, fall.name);
    assert.equal(gelesen.frames.length, fall.spec.frames.length, fall.name);
    assert.equal(
      gelesen.frames[0].pixels.length,
      fall.spec.width * fall.spec.height * 4,
      fall.name,
    );
  }
});

test('ein durchsichtiger Punkt lässt das Bild darunter stehen', () => {
  // Der Fall, den fast jeder falsche Leser als schwarzes Loch zeichnet.
  const g = lies('durchsichtig, Vorbild bleibt');
  assert.deepEqual(pixel(g.frames[1], 16, 6, 6), farbe(2), 'das neue Rechteck fehlt');
  assert.deepEqual(pixel(g.frames[1], 16, 0, 0), farbe(1), 'darunter muss das erste Bild bleiben');
});

test('dispose 2 räumt nur den Bereich des Bildes, nicht die ganze Fläche', () => {
  const g = lies('danach räumen (dispose 2)');
  // Bild 2 setzt ein 6x6-Feld bei (2,2) und lässt es danach räumen.
  assert.deepEqual(pixel(g.frames[1], 16, 4, 4), farbe(1));
  // Bild 3 ist ganz durchsichtig: geräumter Bereich leer, Rest wie Bild 1.
  assert.equal(pixel(g.frames[2], 16, 4, 4)[3], 0, 'der geräumte Bereich muss leer sein');
  assert.deepEqual(pixel(g.frames[2], 16, 12, 12), farbe(3), 'daneben bleibt Bild 1 stehen');
});

test('dispose 3 stellt den Stand von vorher wieder her', () => {
  const g = lies('danach zurück (dispose 3)');
  assert.deepEqual(pixel(g.frames[1], 16, 2, 2), farbe(1), 'Bild 2 liegt oben');
  assert.deepEqual(pixel(g.frames[2], 16, 2, 2), farbe(2), 'danach muss Bild 1 zurück sein');
});

test('verschränkte Zeilen landen an der richtigen Stelle', () => {
  // Verschränkt und nicht verschränkt müssen dasselbe Bild ergeben - sonst
  // sieht das Ergebnis aus wie ein Kamm.
  const spec = nachName.get('verschränkt');
  const flach = { ...spec, frames: [{ ...spec.frames[0], interlace: false }] };
  assert.deepEqual(
    [...decodeGif(makeGif(spec)).frames[0].pixels],
    [...decodeGif(makeGif(flach)).frames[0].pixels],
  );
});

test('ein Teilbild wird an seinen Versatz gesetzt', () => {
  const g = lies('Teilbild mit Versatz');
  assert.deepEqual(pixel(g.frames[1], 32, 14, 7), farbe(2), 'das Teilbild sitzt falsch');
  assert.deepEqual(pixel(g.frames[1], 32, 1, 1), farbe(1), 'daneben bleibt das erste Bild');
});

test('eine eigene Farbtabelle je Bild wird benutzt', () => {
  const g = lies('eigene Farbtabelle je Bild');
  assert.deepEqual(pixel(g.frames[1], 16, 8, 8), [7, 199, 255, 255]);
});

test('die Verzögerungen werden übernommen', () => {
  const g = lies('ungleiche Verzögerungen');
  assert.deepEqual(
    g.frames.map((f) => f.delayMs),
    [40, 40, 1000],
  );
});

test('lange LZW-Ketten kommen unverfälscht an', () => {
  // Hier wächst das Wörterbuch und die Codebreite springt mehrfach.
  const g = lies('grosse Fläche, lange LZW-Ketten');
  assert.deepEqual(pixel(g.frames[0], 64, 0, 0), farbe(2));
  assert.deepEqual(pixel(g.frames[0], 64, 32, 32), farbe(1));
  assert.deepEqual(pixel(g.frames[0], 64, 63, 63), farbe(2));
});

test('kaputte Dateien werden abgelehnt statt geraten', () => {
  assert.throws(() => decodeGif(new Uint8Array([1, 2, 3])), /keine GIF-Datei|endet mitten/);
  assert.throws(() => decodeGif(new TextEncoder().encode('PNG89a....')), /keine GIF-Datei/);
  // Abgeschnitten mitten in den Bilddaten.
  const ganz = makeGif(nachName.get('ein einzelnes Bild'));
  assert.throws(() => decodeGif(ganz.slice(0, ganz.length - 12)), /endet mitten|kein Bild/);
});

test('bis zu fünf Bilder gehen alle mit, egal wie ungleich die Zeiten sind', () => {
  // Der Fall, der die Regel erzwungen hat: drei Bilder, das letzte steht 25-mal
  // so lange wie die beiden anderen. Zeitlich ist das GIF zu 93 % das letzte
  // Bild - nach Zeit ausgewählt bekäme man fünfmal dasselbe, und aus der
  // Bewegung würde ein Standbild.
  const ungleich = [{ delayMs: 40 }, { delayMs: 40 }, { delayMs: 1000 }];
  const gewaehlt = pickEvenlyInTime(ungleich, 5);
  assert.deepEqual(gewaehlt, [0, 0, 1, 1, 2]);
  assert.equal(new Set(gewaehlt).size, 3, 'kein gezeichnetes Bild darf wegfallen');

  assert.deepEqual(pickEvenlyInTime([{ delayMs: 100 }, { delayMs: 100 }], 5), [0, 0, 0, 1, 1]);
  assert.deepEqual(pickEvenlyInTime([{ delayMs: 0 }], 5), [0, 0, 0, 0, 0]);
  const fuenf = [1, 2, 3, 4, 5].map(() => ({ delayMs: 100 }));
  assert.deepEqual(pickEvenlyInTime(fuenf, 5), [0, 1, 2, 3, 4]);
});

test('bei mehr als fünf Bildern entscheidet die Zeit, nicht die Position', () => {
  // Acht Bilder: sieben blitzen nur auf, das letzte steht. Nach Position
  // gewählt käme das stehende Bild einmal vor - im GIF ist es fast alles.
  const acht = [40, 40, 40, 40, 40, 40, 40, 2000].map((delayMs) => ({ delayMs }));
  const gewaehlt = pickEvenlyInTime(acht, 5);
  assert.equal(gewaehlt.length, 5);
  assert.ok(
    gewaehlt.filter((i) => i === 7).length >= 4,
    `das stehende Bild muss überwiegen, gewählt wurde ${gewaehlt}`,
  );

  // Gleich lange Bilder: gleichmässig über den Zyklus verteilt.
  const zehn = Array.from({ length: 10 }, () => ({ delayMs: 100 }));
  assert.deepEqual(pickEvenlyInTime(zehn, 5), [1, 3, 5, 7, 9]);
  assert.ok(pickEvenlyInTime(zehn, 5).every((i) => i >= 0 && i < 10));
});
