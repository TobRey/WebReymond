import { rand, TAU } from '../core/util.js';

export const PORTAL_SPRITES = {
  red: 'assets/sprites/portal-rot.png',
  blue: 'assets/sprites/portal-blau.png',
};

/**
 * Portale auf der Karte.
 *
 * Im Karten-Editor werden rote und blaue Portale gesetzt. Verbunden wird
 * paarweise in der Reihenfolge: das erste rote mit dem ersten blauen, das
 * zweite mit dem zweiten und so weiter. Bleibt eine Farbe übrig, führt sie
 * zum letzten Gegenstück - so entsteht nie ein Portal ins Nichts.
 */
export class Portals {
  constructor(defs = []) {
    this.list = (defs || []).map((def, index) => ({
      id: def.id || 'portal' + index,
      kind: def.kind === 'blue' ? 'blue' : 'red',
      x: def.x,
      y: def.y,
      scale: def.scale || 130,
      phase: rand(0, TAU),
      sprite: null,
      partner: null,
    }));
    this.link();
  }

  get count() {
    return this.list.length;
  }

  /** Rot und Blau paarweise verbinden. */
  link() {
    const red = this.list.filter((p) => p.kind === 'red');
    const blue = this.list.filter((p) => p.kind === 'blue');
    if (!red.length || !blue.length) return;
    const paare = Math.max(red.length, blue.length);
    for (let i = 0; i < paare; i++) {
      const r = red[Math.min(i, red.length - 1)];
      const b = blue[Math.min(i, blue.length - 1)];
      if (!r.partner) r.partner = b;
      if (!b.partner) b.partner = r;
    }
  }

  setSprites(byKind) {
    for (const portal of this.list) portal.sprite = byKind[portal.kind] || null;
  }

  /** Portal, in dem die Position gerade steht - oder null. */
  at(x, y, radius = 34) {
    for (const portal of this.list) {
      if (!portal.partner) continue;
      const dx = portal.x - x;
      const dy = portal.y - y;
      if (dx * dx + dy * dy <= radius * radius) return portal;
    }
    return null;
  }

  update(dt, effects, camera) {
    for (const portal of this.list) {
      portal.phase += dt;
      // Ein paar aufsteigende Funken, aber nur im Blick.
      if (!camera.visible(portal.x, portal.y, portal.scale)) continue;
      if (Math.random() < dt * 9) {
        effects.burst(portal.x + rand(-14, 14), portal.y + rand(-4, 10), 1, {
          color: portal.kind === 'red' ? '#ff5a4d' : '#4db4ff',
          speed: 40, size: 2.5, life: 0.8, angle: -Math.PI / 2, spread: 0.9, glow: true,
        });
      }
    }
  }

  draw(ctx, camera, time) {
    for (const portal of this.list) {
      const sprite = portal.sprite;
      if (!sprite) continue;
      if (!camera.visible(portal.x, portal.y, portal.scale * 1.2)) continue;

      const h = portal.scale;
      const w = (sprite.width / sprite.height) * h;
      // Leichtes Pulsieren, damit das Portal lebt.
      const puls = 1 + Math.sin(portal.phase * 2.2) * 0.03;
      ctx.drawImage(
        sprite.frameAt(time * 1000),
        portal.x - (w * puls) / 2,
        portal.y - h * 0.86 * puls,
        w * puls,
        h * puls,
      );
    }
  }
}
