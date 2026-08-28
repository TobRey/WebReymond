// Die Anzeige im Spiel liegt bewusst im DOM und nicht auf dem Canvas.
//
// Auf 240 × 320 Pixeln bräuchte Text auf dem Canvas eine eigene Pixelschrift
// und wäre bei jeder Vergrösserung genauso grob wie die Grafik. Als DOM-Ebene
// darüber ist er in jeder Grösse scharf, übersetzbar (data-i18n) und für
// Vorleseprogramme lesbar.

import { hazardPressure } from '../game/world.js';
import { t } from '../i18n.js';

export function createHud(elements) {
  let lastHeight = -1;
  let lastGems = -1;
  let lastBest = -1;
  let lastPressure = -1;

  return {
    show() {
      elements.root.hidden = false;
    },
    hide() {
      elements.root.hidden = true;
    },

    /** Nur schreiben, was sich geändert hat – spart Layoutarbeit je Bild. */
    update(world, best) {
      const height = world.metres;
      if (height !== lastHeight) {
        elements.height.textContent = `${height} ${t('hud.metres')}`;
        lastHeight = height;
      }

      const gems = world.player.gems;
      if (gems !== lastGems) {
        elements.gems.textContent = String(gems);
        lastGems = gems;
      }

      if (best !== lastBest) {
        elements.best.textContent = String(best);
        lastBest = best;
      }

      const pressure = Math.round(hazardPressure(world) * 100);
      if (pressure !== lastPressure) {
        elements.pressure.style.width = `${pressure}%`;
        lastPressure = pressure;
      }
    },

    /** Nach einem Sprachwechsel müssen die Einheiten neu geschrieben werden. */
    invalidate() {
      lastHeight = -1;
      lastGems = -1;
      lastBest = -1;
      lastPressure = -1;
    },
  };
}
