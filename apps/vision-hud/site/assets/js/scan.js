/**
 * Scan-Modus: einmal alles zusammentragen, was gerade zu sehen ist.
 *
 * Im laufenden Betrieb arbeiten die Stufen absichtlich sparsam – pro Runde
 * wird höchstens ein Gesicht ausgewertet, Text gar nicht. Ein Scan hebt diese
 * Sparsamkeit für einen Moment auf: Jedes sichtbare Gesicht wird zugeordnet,
 * der Text im Bild gelesen, Bewegungen und Gefahren geprüft. Das dauert ein
 * bis drei Sekunden und ist deshalb nichts für den Dauerbetrieb.
 */

import { movingTargets } from './motion.js';
import { describeWhen } from './memory.js';
import { looksLikeText } from './ocr.js';

/** Fügt eine Aufzählung mit „und“ zusammen. */
function joinList(parts) {
  if (parts.length === 0) return '';
  if (parts.length === 1) return parts[0];
  return `${parts.slice(0, -1).join(', ')} und ${parts[parts.length - 1]}`;
}

/**
 * @param {object} deps
 * @returns {Promise<{report: string, details: object}>}
 */
export async function runScan(deps) {
  const { engine, video, faces, objects, matcher, settings, ocr, hazards } = deps;

  const details = {
    people: [],
    objects: [],
    text: '',
    movements: [],
    hazards: [],
    known: [],
  };

  /* --- 1 Alle Gesichter zuordnen, nicht nur eines --- */
  const faceTracks = faces.visible();
  for (const track of faceTracks.slice(0, 6)) {
    try {
      const result = await engine.identify(video, track.target);
      if (!result) continue;

      const { person, distance } = matcher.match(result.descriptor, settings.matchThreshold);
      track.identity = {
        person,
        distance,
        confidence: Math.max(0, Math.min(1, 1 - (distance / settings.matchThreshold) * 0.42)),
        age: result.age,
        gender: result.gender,
        expression: result.expression,
      };
      track.identifiedAt = performance.now();

      details.people.push({
        name: settings.privacy ? null : (person?.name ?? null),
        age: person?.age ?? Math.round(result.age),
        estimated: !person,
        expression: result.expression,
      });
    } catch {
      /* Ein Gesicht, das sich nicht auswerten lässt, hält den Scan nicht auf. */
    }
  }

  /* --- 2 Gegenstände zusammenfassen --- */
  const objectTracks = objects.visible();
  const tally = new Map();
  for (const track of objectTracks) {
    // Nur sichere Treffer: Ein Bericht voller Vermutungen ist keiner.
    if (track.kind === 'person' || track.faint) continue;
    const name = track.fine?.label ?? track.labelDe ?? track.label;
    tally.set(name, (tally.get(name) ?? 0) + 1);
  }
  details.objects = [...tally.entries()]
    .sort((a, b) => b[1] - a[1])
    .map(([label, count]) => ({ label, count }));

  /* --- 3 Bekannte eigene Gegenstände --- */
  details.known = objectTracks
    .filter((track) => track.memory?.item)
    .map((track) => ({ name: track.memory.item.name, note: track.memory.item.note }));

  /* --- 4 Bewegung und Gefahren --- */
  details.movements = movingTargets([...objectTracks, ...faceTracks], 3);
  details.hazards = hazards ?? [];

  /* --- 5 Text im Bild --- */
  if (ocr) {
    try {
      const read = await ocr.read(video);
      // Im Scan streng prüfen: Ein Bericht mit Zeichensalat ist schlimmer
      // als einer ganz ohne Text.
      if (read.confidence > 0.6 && looksLikeText(read.text, true)) {
        details.text = read.text.slice(0, 220);
      }
    } catch {
      /* Texterkennung ist optional – ein Scan ohne sie ist immer noch nützlich. */
    }
  }

  return { report: composeReport(details, settings), details };
}

/** Baut aus den Rohdaten einen sprechbaren Absatz. */
function composeReport(details, settings) {
  const parts = [];

  if (details.people.length > 0) {
    const named = details.people.filter((p) => p.name).map((p) => `${p.name} (${p.age})`);
    const unknown = details.people.length - named.length;
    const bits = [];
    if (named.length > 0) bits.push(joinList(named));
    if (unknown > 0) {
      bits.push(unknown === 1 ? 'eine unbekannte Person' : `${unknown} unbekannte Personen`);
    }
    parts.push(`Personen: ${joinList(bits)}`);
  } else {
    parts.push('Keine Personen im Bild');
  }

  if (details.objects.length > 0) {
    const listed = details.objects
      .slice(0, 6)
      .map(({ label, count }) =>
        count === 1 ? label.toLowerCase() : `${count} ${label.toLowerCase()}`,
      );
    parts.push(`Gegenstände: ${joinList(listed)}`);
  }

  if (details.known.length > 0) {
    parts.push(`Davon kenne ich: ${joinList(details.known.map((k) => k.name))}`);
  }

  if (details.movements.length > 0) {
    parts.push(`Bewegung: ${joinList(details.movements.map((m) => `${m.label} ${m.direction}`))}`);
  }

  if (details.text) parts.push(`Text im Bild: ${details.text}`);

  if (details.hazards.length > 0) {
    parts.push(
      `Achtung: ${joinList(details.hazards.map((h) => h.text))} – unverbindlicher Hinweis`,
    );
  }

  if (settings.privacy) parts.push('Privatmodus: Namen bleiben verborgen');

  return `${parts.join('. ')}.`;
}

/** Kurzfassung für die Sprechblase über einem wiedererkannten Gegenstand. */
export function describeMemory(item) {
  const when = describeWhen(item.lastSeenAt);
  return item.note
    ? `${item.name} – ${item.note} (zuletzt ${when})`
    : `${item.name} (zuletzt ${when})`;
}
