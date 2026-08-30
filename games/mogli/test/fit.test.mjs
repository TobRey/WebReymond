// Die Umrechnung beliebiger Bilder auf die Masse des Spiels.
//
// Diese Datei gibt es, weil der Admin-Bereich vorher jede Datei abgelehnt hat,
// die nicht schon exakt passte. Was hier geprüft wird, war früher eine
// Fehlermeldung.

import test from 'node:test';
import assert from 'node:assert/strict';

import { containRect, layerSize, scaleMode } from '../web/src/render/fit.js';
import { LIMITS } from '../web/src/net/assetRules.js';

test('ein Bild wird nie verzerrt, sondern eingepasst', () => {
  // Breiter als hoch: volle Breite, mittig, oben und unten Rand.
  assert.deepEqual(containRect(40, 20, 32, 32), { x: 0, y: 8, w: 32, h: 16 });
  // Höher als breit: umgekehrt.
  assert.deepEqual(containRect(20, 40, 32, 32), { x: 8, y: 0, w: 16, h: 32 });
  // Quadratisch: füllt aus.
  assert.deepEqual(containRect(200, 200, 32, 32), { x: 0, y: 0, w: 32, h: 32 });

  // Das Verhältnis bleibt erhalten - das ist der ganze Punkt.
  for (const [w, h] of [
    [1920, 1080],
    [3, 7],
    [1000, 999],
  ]) {
    const r = containRect(w, h, 32, 32);
    assert.ok(Math.abs(r.w / r.h - w / h) < 0.12, `${w}x${h} wurde verzerrt zu ${r.w}x${r.h}`);
    assert.ok(r.w <= 32 && r.h <= 32 && r.w >= 1 && r.h >= 1);
  }
});

test('winzige und riesige Quellen ergeben trotzdem ein gültiges Feld', () => {
  // 1x1 hochgeladen: darf nicht auf 0 zusammenfallen.
  const klein = containRect(1, 1, 32, 32);
  assert.deepEqual(klein, { x: 0, y: 0, w: 32, h: 32 });
  const extrem = containRect(10000, 3, 32, 32);
  assert.ok(extrem.w >= 1 && extrem.h >= 1, JSON.stringify(extrem));
  // Unsinnige Eingaben stürzen nicht ab.
  assert.deepEqual(containRect(0, 0, 16, 16), { x: 0, y: 0, w: 16, h: 16 });
});

test('eine Hintergrundebene bekommt volle Breite und die passende Höhe', () => {
  // Breitbild: wird flach, kein Beschnitt.
  assert.deepEqual(layerSize(1920, 1080, 256, 1024), {
    width: 256,
    height: 144,
    crop: { y: 0, h: 1080 },
  });
  // Schon passend: unverändert.
  assert.deepEqual(layerSize(256, 400, 256, 1024).height, 400);
});

test('ein sehr hohes Bild wird beschnitten statt gestaucht', () => {
  // Gestaucht sähe der Hintergrund falsch aus; ein Ausschnitt sieht nur anders
  // aus. Deshalb Beschnitt - und zwar mittig.
  const mass = layerSize(800, 6000, 256, 1024);
  assert.equal(mass.height, 1024, 'die Höhe muss auf die Grenze gehen');
  assert.ok(mass.crop.h < 6000, 'es muss beschnitten werden');

  // Der behaltene Ausschnitt hat dasselbe Verhältnis wie das Ziel - sonst
  // wäre es doch eine Stauchung.
  assert.ok(
    Math.abs(mass.crop.h / 800 - mass.height / mass.width) < 0.02,
    `Ausschnitt ${mass.crop.h}/800 passt nicht zu ${mass.height}/${mass.width}`,
  );
  // Mittig: oben und unten faellt gleich viel weg.
  assert.equal(mass.crop.y, Math.round((6000 - mass.crop.h) / 2));
});

test('Pixelart bleibt scharf, ein Foto wird geglättet', () => {
  // Vergrössern ist immer Pixelart: 16 -> 32 muss harte Kanten behalten.
  assert.equal(scaleMode(16, 16, 32, 32), 'pixel');
  // Glatt teilbar: jeder Zielpunkt hat genau eine Quelle.
  assert.equal(scaleMode(64, 64, 32, 32), 'pixel');
  assert.equal(scaleMode(96, 96, 32, 32), 'pixel');
  // Krumm: ohne Glätten fielen ganze Zeilen weg.
  assert.equal(scaleMode(100, 100, 32, 32), 'weich');
  assert.equal(scaleMode(1920, 1080, 256, 144), 'weich');
});

test('die Grenzen des Pakets sind aufeinander abgestimmt', () => {
  // Vier Ebenen plus vierzig Bilder müssen zusammen ins Paket passen, sonst
  // scheitert das Speichern an einer Grenze statt an etwas Verstehbarem.
  const schlimmstenfalls =
    LIMITS.backgroundLayers * LIMITS.maxImageBytes +
    ANIMATIONEN * LIMITS.framesPerAnimation * 8 * 1024;
  assert.ok(
    schlimmstenfalls < LIMITS.maxPackBytes,
    `${Math.round(schlimmstenfalls / 1024)} kB passen nicht in ${Math.round(LIMITS.maxPackBytes / 1024)} kB`,
  );
});

const ANIMATIONEN = 8;
