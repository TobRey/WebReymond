// Macht aus einer gespeicherten Karte die laufende Spielwelt: das
// Kollisionsgitter und die Elementliste. Ersetzt seit 3.0 den prozeduralen
// Turmgenerator – Levels werden im Editor gebaut, nicht gewürfelt.
//
// Kein DOM: Tests und die Engine laufen damit direkt in Node.

import { PHYS, T, TILE } from './constants.js';
import { ELEMENTS } from './elements.js';
import { validateMap } from '../net/mapRules.js';

/**
 * Baut die Laufzeit-Ebene. Sie hat dieselbe Schnittstelle, die physics.js
 * schon immer erwartet (tileAt, setTile, touchCrumble, crumbleProgress,
 * updateCrumbling) – deshalb musste an der Kollisionsrechnung fast nichts
 * geändert werden.
 *
 * Gerastert wird ins 16er-Gitter: eine Zelle des Editors (16/32/48 px) ist
 * ein Vielfaches davon, feste Rechtecke gehen also glatt auf.
 */
export function buildLevel(map) {
  const cols = (map.cols * map.cell) / TILE;
  const rows = (map.rows * map.cell) / TILE;
  const grid = new Uint8Array(cols * rows);
  /** Je Bröckelzelle der Restwert bis zum Zerfall, -1 = unberührt. */
  const crumbleState = new Map();
  const crumbleDelay = new Map();

  const at = (col, row) => row * cols + col;

  const fillRect = (element, tile) => {
    const scale = map.cell / TILE;
    const x0 = element.x * scale;
    const y0 = element.y * scale;
    const x1 = x0 + element.w * scale;
    const y1 = y0 + element.h * scale;
    for (let row = y0; row < y1; row += 1) {
      for (let col = x0; col < x1; col += 1) {
        grid[at(col, row)] = tile;
        if (tile === T.CRUMBLE) {
          const delay = (element.props.delay ?? 4) * 6; // Zehntelsekunden → Ticks
          crumbleDelay.set(at(col, row), Math.max(6, delay));
        }
      }
    }
  };

  for (const element of map.elements) {
    const solid = ELEMENTS[element.type]?.solid;
    if (solid === 'SOLID') fillRect(element, T.SOLID);
    else if (solid === 'PLATFORM') fillRect(element, T.PLATFORM);
    else if (solid === 'CRUMBLE') fillRect(element, T.CRUMBLE);
  }

  return {
    cols,
    rows,
    widthPx: cols * TILE,
    heightPx: rows * TILE,

    tileAt(col, row) {
      if (col < 0 || col >= cols || row < 0 || row >= rows) return T.EMPTY;
      return grid[at(col, row)];
    },

    setTile(col, row, tile) {
      if (col < 0 || col >= cols || row < 0 || row >= rows) return;
      grid[at(col, row)] = tile;
    },

    /** Betretener Bröckelblock: Uhr starten, falls noch nicht geschehen. */
    touchCrumble(col, row) {
      const key = at(col, row);
      if (grid[key] !== T.CRUMBLE || crumbleState.has(key)) return;
      crumbleState.set(key, crumbleDelay.get(key) ?? PHYS.crumbleTicks);
    },

    /** 0…1, wie weit der Zerfall ist – für die Darstellung. */
    crumbleProgress(col, row) {
      const key = at(col, row);
      if (!crumbleState.has(key)) return 0;
      const total = crumbleDelay.get(key) ?? PHYS.crumbleTicks;
      return 1 - crumbleState.get(key) / total;
    },

    /**
     * Ein Tick Zerfall. Gibt die in diesem Tick verschwundenen Zellen zurück
     * (für Staubwolken).
     */
    updateCrumbling() {
      const gone = [];
      for (const [key, left] of crumbleState) {
        if (left > 1) {
          crumbleState.set(key, left - 1);
        } else {
          crumbleState.delete(key);
          grid[key] = T.EMPTY;
          gone.push({ col: key % cols, row: Math.floor(key / cols) });
        }
      }
      return gone;
    },
  };
}

/**
 * Die eingebaute Demo-Karte.
 *
 * Sie hat zwei Aufgaben: das ZIP ist ohne Editor sofort spielbar, und sie
 * führt jedes Element einmal vor – wer sie im Editor öffnet, sieht an einem
 * Beispiel, wie alles benutzt wird. Sie läuft durch dieselbe Prüfung wie jede
 * Nutzerkarte; ein Test besteht darauf.
 */
export function demoMap() {
  const e = [];
  let n = 0;
  const add = (type, x, y, extra = {}) => {
    n += 1;
    e.push({ id: `demo-${n}`, type, x, y, ...extra });
  };

  // Der Boden: vier Stücke mit schmalen Gruben dazwischen. Die Landezonen
  // sind bewusst grosszügig – der erste Wurf hatte nach der ersten Grube nur
  // drei Zellen Boden vor drei Zellen Stacheln, und selbst ein Läufer mit
  // Vorausschau starb dort im Takt.
  add('ground', 0, 19, { w: 26, h: 2 });
  add('ground', 28, 19, { w: 16, h: 2 });
  add('ground', 46, 19, { w: 30, h: 2 });
  add('ground', 80, 19, { w: 40, h: 2 });

  // Stufen, Podeste, Wege oben herum
  add('ground', 18, 16, { w: 4, h: 1 });
  add('platform', 33, 15, { w: 5, h: 1 });
  add('mover', 41, 13, { props: { axis: 'h', range: 4, speed: 8 } });
  add('platform', 56, 12, { w: 6, h: 1 });
  add('crumble', 62, 16, { w: 3, h: 1, props: { delay: 5 } });
  add('ground', 100, 15, { w: 6, h: 1 });

  // Gefahren – kurz und mit Anlauf davor
  add('spikes', 35, 18, { w: 2, h: 1 });
  // NICHT unter die Bröckelbrücke (62–65): ein Sprung über Stacheln, deren
  // Anlauf unter einer Decke liegt, stösst sich den Kopf – der Probelauf im
  // Test ist genau daran gestorben, im Kreis, Tod um Tod.
  add('spikes', 72, 18, { w: 2, h: 1 });
  add('walker', 50, 18, { props: { range: 6, speed: 6, stompable: true } });
  // Nicht über die Stacheln: wer dort drüberspringt, muss landen können,
  // ohne im Flug in den Flieger zu laufen. Doppelfallen sind gemein, aber
  // die Demo soll einladen, nicht strafen.
  add('flyer', 86, 12, { props: { axis: 'v', range: 3, speed: 7, stompable: true } });

  // Die Feder steht IN der dritten Grube: wer hineinfällt, wird
  // hinausgeschleudert – die Grube ist eine Überraschung, keine Strafe.
  add('spring', 77, 18, { props: { power: 105 } });
  e.push({ id: 'demo-feder-2', type: 'spring', x: 78, y: 18, props: { power: 105 } });

  // Sammeln und Wege
  add('emerald', 20, 14);
  add('emerald', 35, 13);
  add('emerald', 58, 10);
  add('emerald', 82, 15);
  add('emerald', 103, 13);
  add('key', 58, 9);
  add('door', 108, 16, { props: { locked: true, mode: 'exit' } });

  // Das Portalpaar steht auf Podesten NEBEN dem Weg, nicht darauf: beim
  // ersten Wurf stand Portal A auf dem Boden, und jeder, der einfach nach
  // rechts lief, wurde ungefragt durch die halbe Karte teleportiert.
  add('portal', 57, 10, { props: { targetId: 'demo-portal-b' } });
  e.push({ id: 'demo-portal-b', type: 'portal', x: 90, y: 13, props: { targetId: e.at(-1).id } });
  add('ground', 90, 15, { w: 2, h: 1 });

  add('checkpoint', 60, 17);
  add('flag', 116, 16);

  const result = validateMap({
    version: 1,
    id: 'dschungelpfad',
    name: 'Dschungelpfad',
    cols: 120,
    rows: 21,
    cell: 16,
    spawn: { x: 2, y: 17 },
    elements: e,
  });
  if (!result.ok) throw new Error(`Demo-Karte ungültig: ${result.error} (${result.detail ?? ''})`);
  return result.map;
}
