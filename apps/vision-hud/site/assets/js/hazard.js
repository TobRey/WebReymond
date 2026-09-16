/**
 * Gefahrenhinweise.
 *
 * AUSDRÜCKLICH KEIN SICHERHEITSSYSTEM. Das hier ist eine Handvoll
 * Faustregeln auf Basis von Boxgrösse und Wachstumsrate – keine
 * Abstandsmessung. Eine einzelne Kamera kennt keine Entfernung; was hier
 * "nah" heisst, heisst eigentlich "gross im Bild". Ein Lastwagen in fünfzig
 * Metern ist grösser als ein Fahrrad in fünf.
 *
 * Deshalb: Jede Ausgabe dieses Moduls wird in der Oberfläche und in der
 * Sprachausgabe als unverbindlicher Hinweis gekennzeichnet. Wer sich darauf
 * verlässt, ist verlassen.
 */

import { withArticle } from './labels.js';

/** Klassen (Detektornamen), bei denen eine schnelle Annäherung wirklich zählt. */
const VEHICLES = new Set([
  'Car',
  'Truck',
  'Bus',
  'Motorcycle',
  'Bicycle',
  'Train',
  'Van',
  'Taxi',
  'Ambulance',
  'Limousine',
  'Golf cart',
  'Snowmobile',
  'Segway',
  'Land vehicle',
  'Vehicle',
]);

/** Klassen, über die man stolpert. */
const OBSTACLES = new Set([
  'Chair',
  'Bench',
  'Houseplant',
  'Suitcase',
  'Backpack',
  'Dog',
  'Cat',
  'Table',
  'Stool',
  'Coffee table',
  'Ladder',
  'Wheelchair',
  'Skateboard',
  'Bicycle wheel',
  'Stairs',
]);

/** Sprechbarer Name mit Artikel – aus dem deutschen Klassennamen des Ziels. */
function nameOf(track) {
  return withArticle(track.fine?.label ?? track.labelDe ?? track.label);
}

/**
 * Prüft die laufenden Ziele auf Auffälligkeiten.
 *
 * @param {Array} tracks  Objektziele mit `motion` aus motion.js
 * @param {number} videoWidth
 * @returns {Array<{trackId: number, level: 'hoch'|'mittel', text: string, reason: string}>}
 */
export function assessHazards(tracks, videoWidth) {
  if (!videoWidth) return [];
  const found = [];

  for (const track of tracks) {
    const relativeWidth = track.display.w / videoWidth;
    const growth = track.motion?.growth ?? 0;
    const name = nameOf(track);

    // 1 Fahrzeug, das schnell grösser wird: das ist der eine Fall, der zählt.
    if (VEHICLES.has(track.label) && growth > 0.55 && relativeWidth > 0.14) {
      found.push({
        trackId: track.id,
        level: 'hoch',
        text: `${name} kommt schnell näher`,
        reason: 'waechst',
      });
      continue;
    }

    // 2 Fahrzeug überhaupt im Bild und nicht winzig.
    if (VEHICLES.has(track.label) && relativeWidth > 0.28) {
      found.push({
        trackId: track.id,
        level: 'mittel',
        text: `${name} direkt vor dir`,
        reason: 'gross',
      });
      continue;
    }

    // 3 Hindernis mittig und nah – Stolperkandidat.
    const centre = (track.display.x + track.display.w / 2) / videoWidth;
    if (OBSTACLES.has(track.label) && relativeWidth > 0.3 && centre > 0.3 && centre < 0.7) {
      found.push({
        trackId: track.id,
        level: 'mittel',
        text: `${name} im Weg`,
        reason: 'hindernis',
      });
      continue;
    }

    // 4 Irgendetwas Grosses, das sehr schnell wächst.
    if (growth > 0.9 && relativeWidth > 0.2) {
      found.push({
        trackId: track.id,
        level: 'mittel',
        text: `${name} nähert sich schnell`,
        reason: 'waechst',
      });
    }
  }

  // Höchste Stufe zuerst, höchstens drei – mehr liest im Vorbeigehen niemand.
  return found.sort((a, b) => (a.level === b.level ? 0 : a.level === 'hoch' ? -1 : 1)).slice(0, 3);
}

/**
 * Entscheidet, ob ein Hinweis laut ausgesprochen werden soll.
 * Ohne diese Sperre würde ReyRey bei jedem Einzelbild dasselbe wiederholen.
 */
export class HazardVoice {
  constructor(quietMs = 9000) {
    this.quietMs = quietMs;
    this.spokenAt = new Map();
  }

  /** @returns {string|null} Text, der gesprochen werden soll */
  next(hazards, now) {
    for (const hazard of hazards) {
      if (hazard.level !== 'hoch') continue;
      const key = `${hazard.trackId}:${hazard.reason}`;
      const last = this.spokenAt.get(key) ?? 0;
      if (now - last < this.quietMs) continue;
      this.spokenAt.set(key, now);
      return `Achtung, ${hazard.text}.`;
    }
    return null;
  }

  reset() {
    this.spokenAt.clear();
  }
}
