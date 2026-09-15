/**
 * Bewegungsrichtung verfolgter Ziele.
 *
 * Der Tracker kennt für jedes Ziel nur die aktuelle Box. Für eine Richtung
 * braucht es einen Verlauf – hier werden deshalb die letzten Positionen je
 * Ziel mitgeschrieben und daraus eine geglättete Geschwindigkeit berechnet.
 *
 * Die Geschwindigkeit ist in Bildbreiten pro Sekunde angegeben, nicht in
 * Pixeln. Dadurch bedeutet derselbe Zahlenwert bei jeder Auflösung dasselbe.
 */

const HISTORY = 6;
/** Darunter gilt ein Ziel als stehend – sonst zittert jede Anzeige. */
const MIN_SPEED = 0.06;

/** Schreibt Positionen fort und berechnet Geschwindigkeiten. */
export function trackMotion(tracks, videoWidth, now) {
  if (!videoWidth) return;

  for (const track of tracks) {
    const centreX = track.display.x + track.display.w / 2;
    const centreY = track.display.y + track.display.h / 2;

    track.trail ??= [];
    track.trail.push({ x: centreX, y: centreY, size: track.display.w, t: now });
    if (track.trail.length > HISTORY) track.trail.shift();

    if (track.trail.length < 3) {
      track.motion = null;
      continue;
    }

    const first = track.trail[0];
    const last = track.trail[track.trail.length - 1];
    const seconds = (last.t - first.t) / 1000;
    if (seconds <= 0.01) continue;

    const vx = (last.x - first.x) / videoWidth / seconds;
    const vy = (last.y - first.y) / videoWidth / seconds;
    // Wächst die Box, kommt das Ziel näher.
    const growth = first.size > 0 ? (last.size - first.size) / first.size / seconds : 0;

    const speed = Math.hypot(vx, vy);
    track.motion = {
      vx,
      vy,
      speed,
      growth,
      moving: speed >= MIN_SPEED || Math.abs(growth) > 0.25,
      direction: describeDirection(vx, vy, growth),
      angle: Math.atan2(vy, vx),
    };
  }
}

/** Grobe Richtung in Worten – für Sprachausgabe und Beschriftung. */
function describeDirection(vx, vy, growth) {
  if (growth > 0.35 && Math.hypot(vx, vy) < 0.25) return 'auf dich zu';
  if (growth < -0.35 && Math.hypot(vx, vy) < 0.25) return 'von dir weg';

  const horizontal = Math.abs(vx) > Math.abs(vy);
  if (horizontal) return vx > 0 ? 'nach rechts' : 'nach links';
  return vy > 0 ? 'nach unten' : 'nach oben';
}

/** Die auffälligsten Bewegungen, für ReyReys Beschreibung. */
export function movingTargets(tracks, limit = 3) {
  return tracks
    .filter((track) => track.motion?.moving)
    .sort((a, b) => b.motion.speed - a.motion.speed)
    .slice(0, limit)
    .map((track) => ({
      id: track.id,
      label: track.kind === 'face' ? 'Eine Person' : track.label,
      direction: track.motion.direction,
      speed: track.motion.speed,
    }));
}
