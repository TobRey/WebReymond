export const clamp = (v, min, max) => (v < min ? min : v > max ? max : v);
export const lerp = (a, b, t) => a + (b - a) * t;
export const dist2 = (ax, ay, bx, by) => {
  const dx = bx - ax;
  const dy = by - ay;
  return dx * dx + dy * dy;
};
export const dist = (ax, ay, bx, by) => Math.sqrt(dist2(ax, ay, bx, by));
export const rand = (min, max) => min + Math.random() * (max - min);
export const randInt = (min, max) => Math.floor(rand(min, max + 1));
export const pick = (arr) => arr[(Math.random() * arr.length) | 0];
export const TAU = Math.PI * 2;

/** Kuerzester Winkelabstand zwischen zwei Richtungen. */
export function angleDelta(a, b) {
  let d = (b - a) % TAU;
  if (d > Math.PI) d -= TAU;
  if (d < -Math.PI) d += TAU;
  return d;
}

/** Zieht ein Element gewichtet aus einer Liste. */
export function weighted(items, weightOf) {
  let total = 0;
  for (const item of items) total += Math.max(0, weightOf(item));
  if (total <= 0) return items.length ? items[0] : null;
  let roll = Math.random() * total;
  for (const item of items) {
    roll -= Math.max(0, weightOf(item));
    if (roll <= 0) return item;
  }
  return items[items.length - 1];
}

/** Formatiert Zeit als m:ss. */
export function formatTime(seconds) {
  const s = Math.max(0, Math.ceil(seconds));
  return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
}

export function formatNumber(n) {
  const v = Math.round(n);
  return v >= 10000 ? (v / 1000).toFixed(1).replace('.0', '') + 'k' : String(v);
}
