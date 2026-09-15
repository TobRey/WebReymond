/**
 * Verfolgt erkannte Ziele über mehrere Einzelbilder hinweg.
 *
 * Warum überhaupt verfolgen? Ein Erkenner liefert bei jedem Durchlauf eine
 * frische, unsortierte Liste. Ohne Zuordnung würde jedes Ziel bei jedem
 * Durchlauf neu "geboren": Die Rahmen würden flackern, Namen springen und
 * laufende Berechnungen (Merkmalsvektor, Alter) wären sofort wieder verloren.
 *
 * Verfahren: Überlappung (IoU) zwischen alten und neuen Boxen, gierige
 * Zuordnung vom besten Paar abwärts. Das genügt für Bilder mit wenigen Zielen
 * und kostet fast nichts – im Gegensatz zu einem Kalman-Filter.
 */

/** Überlappung zweier Boxen: Schnittfläche geteilt durch Vereinigungsfläche. */
export function iou(a, b) {
  const left = Math.max(a.x, b.x);
  const top = Math.max(a.y, b.y);
  const right = Math.min(a.x + a.w, b.x + b.w);
  const bottom = Math.min(a.y + a.h, b.y + b.h);

  const overlap = Math.max(0, right - left) * Math.max(0, bottom - top);
  if (overlap === 0) return 0;

  const union = a.w * a.h + b.w * b.h - overlap;
  return union > 0 ? overlap / union : 0;
}

/** Mischt zwei Boxen – 0 liefert `from`, 1 liefert `to`. */
function lerpBox(from, to, t) {
  return {
    x: from.x + (to.x - from.x) * t,
    y: from.y + (to.y - from.y) * t,
    w: from.w + (to.w - from.w) * t,
    h: from.h + (to.h - from.h) * t,
  };
}

export class Tracker {
  /**
   * @param {object} options
   * @param {number} options.iouMatch   Mindestüberlappung für eine Zuordnung
   * @param {number} options.maxMissed  Fehlende Durchläufe bis zum Verwerfen
   * @param {number} options.minHits    Treffer, bis ein Ziel gezeichnet wird
   * @param {number} options.smoothing  Trägheit der Box (0 … <1)
   */
  constructor(options) {
    this.options = options;
    this.tracks = [];
    this.nextId = 1;
  }

  /**
   * Gleicht eine frische Trefferliste mit den bekannten Zielen ab.
   * @param {Array<{box: object, score: number, label: string, kind: string, extra?: object}>} detections
   * @returns {Array<object>} alle derzeit geführten Ziele
   */
  update(detections) {
    const now = performance.now();
    const pairs = [];

    // Alle möglichen Paarungen sammeln, die überhaupt in Frage kommen.
    this.tracks.forEach((track, ti) => {
      detections.forEach((detection, di) => {
        const score = iou(track.target, detection.box);
        if (score >= this.options.iouMatch) pairs.push({ ti, di, score });
      });
    });

    // Bestes Paar zuerst: so gewinnt die eindeutigste Zuordnung.
    pairs.sort((a, b) => b.score - a.score);

    const usedTracks = new Set();
    const usedDetections = new Set();

    for (const pair of pairs) {
      if (usedTracks.has(pair.ti) || usedDetections.has(pair.di)) continue;
      usedTracks.add(pair.ti);
      usedDetections.add(pair.di);

      const track = this.tracks[pair.ti];
      const detection = detections[pair.di];
      const t = 1 - this.options.smoothing;

      track.target = lerpBox(track.target, detection.box, t);
      track.rawBox = detection.box;
      track.score = track.score * 0.6 + detection.score * 0.4;
      track.label = detection.label;
      track.kind = detection.kind;
      track.extra = detection.extra ?? track.extra;
      track.hits += 1;
      track.missed = 0;
      track.lastSeen = now;
    }

    // Unbenutzte Treffer werden zu neuen Zielen.
    detections.forEach((detection, di) => {
      if (usedDetections.has(di)) return;
      this.tracks.push({
        id: this.nextId++,
        target: { ...detection.box },
        display: { ...detection.box },
        rawBox: detection.box,
        score: detection.score,
        label: detection.label,
        kind: detection.kind,
        extra: detection.extra ?? null,
        identity: null,
        identifiedAt: 0,
        identifyPending: false,
        hits: 1,
        missed: 0,
        firstSeen: now,
        lastSeen: now,
        /** 0 … 1: Fortschritt der Einblend-Animation. */
        lock: 0,
      });
    });

    // Nicht wiedergefundene Ziele altern und verschwinden irgendwann.
    this.tracks.forEach((track, ti) => {
      if (usedTracks.has(ti)) return;
      track.missed += 1;
    });
    this.tracks = this.tracks.filter((track) => track.missed <= this.options.maxMissed);

    return this.tracks;
  }

  /**
   * Bewegt die gezeichneten Boxen sanft auf ihre Zielposition zu.
   * Wird bei jedem Einzelbild aufgerufen, nicht nur bei jeder Erkennung –
   * dadurch wirkt die Verfolgung flüssig, obwohl selten gerechnet wird.
   *
   * @param {number} dtMs Zeit seit dem letzten Bild in Millisekunden
   */
  advance(dtMs) {
    // Zeitabhängiger Faktor: bei Bildaussetzern wird nicht zu kurz gesprungen.
    const t = 1 - Math.exp(-dtMs / 70);
    for (const track of this.tracks) {
      track.display = lerpBox(track.display, track.target, Math.min(1, t));
      if (track.lock < 1) track.lock = Math.min(1, track.lock + dtMs / 260);
    }
  }

  /** Ziele, die oft genug gesehen wurden, um sie anzuzeigen. */
  visible() {
    return this.tracks.filter((track) => track.hits >= this.options.minHits && track.missed <= 2);
  }

  reset() {
    this.tracks = [];
  }
}
