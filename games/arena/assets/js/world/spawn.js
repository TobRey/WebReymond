import { rand } from '../core/util.js';

/**
 * Sucht gültige Spawnpositionen: ausserhalb des Sichtfelds, innerhalb der
 * Karte, nicht in Hindernissen und nicht direkt auf dem Spieler.
 */
export function findSpawnPoint(map, camera, radius, attempts = 30) {
  const areas = map.enemySpawnAreas;

  for (let i = 0; i < attempts; i++) {
    let x;
    let y;

    if (areas.length && i % 3 === 0) {
      const area = areas[(Math.random() * areas.length) | 0];
      const a = Math.random() * Math.PI * 2;
      const r = Math.sqrt(Math.random()) * area.r;
      x = area.x + Math.cos(a) * r;
      y = area.y + Math.sin(a) * r;
    } else {
      // Direkt hinter dem Bildschirmrand - nah genug, um schnell im Kampf
      // zu sein, aber nie sichtbar beim Erscheinen.
      const pad = rand(30, 150) + radius;
      const halfW = camera.viewWidth / 2;
      const halfH = camera.viewHeight / 2;
      const side = (Math.random() * 4) | 0;
      if (side === 0) {          // oben
        x = camera.x + rand(-halfW - pad, halfW + pad);
        y = camera.y - halfH - pad;
      } else if (side === 1) {   // unten
        x = camera.x + rand(-halfW - pad, halfW + pad);
        y = camera.y + halfH + pad;
      } else if (side === 2) {   // links
        x = camera.x - halfW - pad;
        y = camera.y + rand(-halfH - pad, halfH + pad);
      } else {                   // rechts
        x = camera.x + halfW + pad;
        y = camera.y + rand(-halfH - pad, halfH + pad);
      }
    }

    x = Math.min(map.width - radius - 4, Math.max(radius + 4, x));
    y = Math.min(map.height - radius - 4, Math.max(radius + 4, y));

    if (map.mask.blockedEllipse(x, y, radius, radius * 0.7)) continue;
    // Am Kartenrand kann die Klemmung den Punkt ins Bild ziehen - dann verwerfen.
    if (camera.visible(x, y, radius + 20)) continue;
    return { x, y };
  }

  // Notfall: freie Zelle irgendwo auf der Karte.
  return map.mask.nearestFree(
    rand(radius + 8, map.width - radius - 8),
    rand(radius + 8, map.height - radius - 8),
    radius,
    radius * 0.7,
  );
}

/** Gültiger Startpunkt für den Spieler. */
export function playerSpawn(map, rx, ry) {
  const point = map.spawn || { x: map.width / 2, y: map.height / 2 };
  return map.mask.nearestFree(point.x, point.y, rx, ry, 600);
}
