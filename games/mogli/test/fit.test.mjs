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

const ZIEL = { width: 256, coverHeight: 640, maxHeight: 1024 };

test('ein stehender Hintergrund deckt den höchsten Bildschirm ohne Vergrösserung', () => {
  // Der gemeldete Fall: ein Foto im Verhältnis 1:2. Auf Spielbreite gebracht
  // waere es 534 hoch - der Bildschirm ist bis zu 640. Es muss also groesser
  // gerechnet werden, sonst bleibt unten ein Streifen leer oder das Bild wird
  // zur Laufzeit hochgezogen und weich.
  const mass = layerSize(801, 1672, ZIEL);
  assert.ok(mass.height >= 640, `nur ${mass.height} hoch, der Bildschirm ist 640`);
  assert.ok(mass.width >= 256, `nur ${mass.width} breit, der Schacht ist 256`);
  // Und nicht mehr als noetig: eine unnötig grosse Datei will niemand.
  assert.ok(mass.height <= 700, `${mass.height} ist mehr als gebraucht`);
  assert.ok(
    Math.abs(mass.width / mass.height - 801 / 1672) < 0.02,
    'das Verhältnis muss erhalten bleiben',
  );
});

test('ein breites Bild wird nicht ins Absurde gezogen', () => {
  // 16:9 muesste 1139 breit werden, um 640 hoch zu sein. Davon saehe man ein
  // Viertel, und die Datei waere riesig. Deshalb die Breitengrenze.
  const mass = layerSize(1920, 1080, ZIEL);
  assert.ok(mass.width <= 512, `${mass.width} ist zu breit`);
  assert.ok(mass.width >= 256, 'schmaler als der Schacht darf sie nie sein');
});

test('auch eine winzige Quelle spannt über den ganzen Schacht', () => {
  const mass = layerSize(32, 32, ZIEL);
  assert.equal(mass.width, 256);
  assert.equal(mass.height, 256);
});

test('ein sehr hohes Bild wird beschnitten statt gestaucht', () => {
  const mass = layerSize(800, 6000, ZIEL);
  assert.equal(mass.height, 1024, 'die Höhe muss auf die Grenze gehen');
  assert.ok(mass.crop.h < 6000, 'es muss beschnitten werden');
  // Der behaltene Ausschnitt hat dasselbe Verhältnis wie das Ziel - sonst
  // waere es doch eine Stauchung.
  assert.ok(
    Math.abs(mass.crop.h / 800 - mass.height / mass.width) < 0.02,
    `Ausschnitt ${mass.crop.h}/800 passt nicht zu ${mass.height}/${mass.width}`,
  );
  // Mittig: oben und unten faellt gleich viel weg.
  assert.equal(mass.crop.y, Math.round((6000 - mass.crop.h) / 2));
});

test('eine Ebene ist nie kleiner als das Sichtfeld, egal welche Quelle', () => {
  // Das ist die Eigenschaft, deren Fehlen den Fehler ausgemacht hat.
  for (const [w, h] of [
    [801, 1672],
    [1920, 1080],
    [32, 32],
    [1, 1],
    [4000, 3000],
    [100, 5000],
    [3, 7],
  ]) {
    const mass = layerSize(w, h, ZIEL);
    assert.ok(mass.width >= ZIEL.width, `${w}x${h} ergibt nur ${mass.width} Breite`);
    assert.ok(mass.width <= 512 && mass.height <= ZIEL.maxHeight, `${w}x${h} sprengt die Grenzen`);
    assert.ok(mass.height >= 1, `${w}x${h} ergibt Höhe ${mass.height}`);
  }
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
  // Gerechnet wird in base64, denn so geht das Paket über die Leitung.
  const alsBase64 = (bytes) => Math.ceil(bytes / 3) * 4;
  const schlimmstenfalls = alsBase64(
    LIMITS.backgroundLayers * LIMITS.maxLayerBytes +
      ANIMATIONEN * LIMITS.framesPerAnimation * 8 * 1024,
  );
  assert.ok(
    schlimmstenfalls < LIMITS.maxPackBytes,
    `${Math.round(schlimmstenfalls / 1024)} kB passen nicht in ${Math.round(LIMITS.maxPackBytes / 1024)} kB`,
  );

  // Und das Paket muss durch einen gewoehnlichen PHP-Upload passen: acht
  // Megabyte sind der uebliche post_max_size, darunter soll Luft bleiben.
  assert.ok(
    LIMITS.maxPackBytes <= 6 * 1024 * 1024,
    'das Paket ist zu gross fuer einen gewoehnlichen Webspace',
  );

  // Eine Ebene darf mehr wiegen als ein Sprite - sonst muesste sie so weit
  // verkleinert werden, dass sie den Bildschirm nicht mehr deckt.
  assert.ok(LIMITS.maxLayerBytes > LIMITS.maxImageBytes);
});

const ANIMATIONEN = 8;
