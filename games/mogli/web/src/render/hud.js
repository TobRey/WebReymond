// Die Anzeige im Spiel liegt bewusst im DOM und nicht auf dem Canvas.
//
// Auf 256 Pixeln Breite bräuchte Text auf dem Canvas eine eigene Pixelschrift
// und wäre bei jeder Vergrösserung genauso grob wie die Grafik. Als DOM-Ebene
// darüber ist er in jeder Grösse scharf, übersetzbar (data-i18n) und für
// Vorleseprogramme lesbar.

import { formatTicks } from '../game/world.js';

export function createHud(elements) {
  let lastTime = '';
  let lastEmeralds = -1;

  return {
    show() {
      elements.root.hidden = false;
    },
    hide() {
      elements.root.hidden = true;
    },

    /** Nur schreiben, was sich geändert hat – spart Layoutarbeit je Bild. */
    update(world) {
      // Angezeigt wird die WERTUNGSZEIT (Uhr minus Smaragd-Gutschrift): so
      // sieht man einen Smaragd sofort als Zeitsprung nach unten – das ist
      // die ganze Belohnung, also soll sie sichtbar sein.
      const time = formatTicks(world.resultTicks);
      if (time !== lastTime) {
        elements.time.textContent = time;
        lastTime = time;
      }

      const emeralds = world.player.emeralds;
      if (emeralds !== lastEmeralds) {
        elements.emeralds.textContent = String(emeralds);
        lastEmeralds = emeralds;
      }
    },

    /** Nach einem Sprachwechsel neu schreiben. */
    invalidate() {
      lastTime = '';
      lastEmeralds = -1;
    },
  };
}
