// Das Laufzeit-Verhalten der Elemente: bewegen, tragen, treffen, auslösen.
// Kein DOM – test/entities.test.mjs fährt jede Regel direkt in Node.
//
// Aufteilung mit player.js: der Spieler kennt nur das Kachelgitter. ALLES,
// was ein Element mit ihm macht (tragen, töten, schleudern, teleportieren),
// passiert hier, in einer festen Reihenfolge je Tick. So gibt es genau eine
// Stelle, an der sich Elementregeln treffen können – und die ist testbar.

import { EMERALD_TICKS, PHYS, TILE } from './constants.js';
import { ELEMENTS } from './elements.js';
import { kill } from './player.js';
import { elementBoxNorm } from '../render/assets.js';

/**
 * Das wirksame Rechteck eines Elements: hat sein Typ im Admin eine
 * Trefferfläche eingezeichnet bekommen, gilt nur dieser Ausschnitt –
 * anteilig aufs platzierte Rechteck übertragen, denn die Grafik wird beim
 * Zeichnen genauso gezogen.
 */
function touchRect(e) {
  const box = elementBoxNorm(e.type);
  if (box === null) return e;
  return {
    x: e.x + box.x * e.w,
    y: e.y + box.y * e.h,
    w: Math.max(1, box.w * e.w),
    h: Math.max(1, box.h * e.h),
  };
}

/** Dreieckswelle 0→1→0 über eine Periode; für Hin-und-her-Bewegungen. */
function pingpong(tick, period) {
  const t = tick % (period * 2);
  return t < period ? t / period : 2 - t / period;
}

/**
 * Baut die Laufzeit-Elemente aus einer geprüften Karte. Feste Blöcke sind
 * schon im Gitter (map.js) und tauchen hier nicht mehr auf.
 */
export function createEntities(map) {
  const cell = map.cell;
  const list = [];
  for (const element of map.elements) {
    if (ELEMENTS[element.type]?.solid !== undefined) continue;
    list.push({
      id: element.id,
      type: element.type,
      // Rechteck in Weltpixeln.
      x: element.x * cell,
      y: element.y * cell,
      w: element.w * cell,
      h: element.h * cell,
      props: element.props,
      note: element.note,
      // Laufzeitzustand
      baseX: element.x * cell,
      baseY: element.y * cell,
      tick: 0,
      dir: 1,
      alive: true,
      taken: false,
      active: false, // Checkpoint erreicht / Tür offen
      cooldown: 0,
    });
  }
  return list;
}

/** Überlappen sich zwei Rechtecke echt? */
export function hits(a, b) {
  return a.x < b.x + b.w && a.x + a.w > b.x && a.y < b.y + b.h && a.y + a.h > b.y;
}

/**
 * Bewegte Elemente einen Tick weiterschieben – VOR dem Spieler, damit die
 * Mitnahme im selben Tick wirkt und er nicht einen Tick hinterherhängt.
 */
export function updateEntities(entities, _tick) {
  for (const e of entities) {
    e.tick += 1;
    if (e.cooldown > 0) e.cooldown -= 1;

    if (e.type === 'mover' || (e.type === 'flyer' && e.alive)) {
      const range = e.props.range * TILE * (e.type === 'flyer' ? 1 : 1);
      const speed = e.props.speed / 10;
      const period = Math.max(1, Math.round(range / speed));
      const t = pingpong(e.tick, period);
      const prevX = e.x;
      const prevY = e.y;
      if (e.props.axis === 'v') {
        e.y = e.baseY + t * range;
      } else {
        e.x = e.baseX + t * range;
      }
      e.dx = e.x - prevX;
      e.dy = e.y - prevY;
    } else if (e.type === 'walker' && e.alive) {
      const speed = e.props.speed / 10;
      const range = e.props.range * TILE;
      e.x += e.dir * speed;
      if (e.x >= e.baseX + range) {
        e.x = e.baseX + range;
        e.dir = -1;
      } else if (e.x <= e.baseX) {
        e.x = e.baseX;
        e.dir = 1;
      }
    }
  }
}

/**
 * Trägt eine bewegliche Plattform den Spieler? Wird nach updateEntities und
 * vor dem Spieler-Schritt gerufen: steht er auf einer, wird er mitgeschoben
 * und ihre waagerechte Bewegung als carriedVx mitgegeben.
 */
export function carryPlayer(entities, player) {
  if (player.dead) return;
  const body = player.body;
  for (const e of entities) {
    if (e.type !== 'mover') continue;
    const feet = body.y + body.h;
    const onTop =
      body.x + body.w > e.x + 1 &&
      body.x < e.x + e.w - 1 &&
      feet >= e.y - 4 &&
      feet <= e.y + Math.max(6, (e.dy ?? 0) + 6);
    if (!onTop || body.vy < 0) continue;
    // Auf die Oberkante setzen und mitnehmen.
    body.y = e.y - body.h;
    body.vy = 0;
    player.onGround = true;
    player.coyote = PHYS.coyoteTicks;
    player.carriedVx = e.dx ?? 0;
  }
}

/**
 * Alle Berührungen NACH dem Spieler-Schritt. Gibt Ereignisse für Ton und
 * Anzeige zurück und verändert Spieler und Elemente.
 *
 * @returns {{finished: boolean}}
 */
export function touchEntities(entities, player, world, events) {
  const body = player.body;
  if (player.dead) return { finished: false };

  for (const e of entities) {
    if (!hits(body, touchRect(e))) continue;

    switch (e.type) {
      case 'spikes': {
        // Die Trefferfläche der Stacheln ist absichtlich flacher als ihr
        // Bild: nur das obere Drittel sticht. Wer daneben steht, lebt.
        const base = touchRect(e);
        const sharp = { x: base.x + 2, y: base.y, w: base.w - 4, h: base.h };
        if (hits(body, sharp)) {
          kill(player, events);
          return { finished: false };
        }
        break;
      }
      case 'emerald': {
        if (e.taken) break;
        e.taken = true;
        player.emeralds += 1;
        world.bonusTicks += Math.round(e.props.bonus * EMERALD_TICKS);
        events.push('emerald');
        break;
      }
      case 'key': {
        if (e.taken) break;
        e.taken = true;
        world.keys += 1;
        events.push('key');
        break;
      }
      case 'spring': {
        // Beim Fallen UND beim Drüberlaufen: eine Feder auf dem Weg soll
        // schleudern, nicht nur eine, auf die man fällt. Beim Aufsteigen
        // (vy < 0) dagegen nie – sonst zündet sie im Abflug gleich nochmal.
        if (body.vy > 0.5 || (player.onGround && body.vy >= 0)) {
          body.vy = -e.props.power / 10;
          player.jumping = false;
          player.onGround = false;
          events.push('spring');
        }
        break;
      }
      case 'walker':
      case 'flyer': {
        if (!e.alive) break;
        // Draufspringen: fallend und mit den Füssen in der oberen Hälfte.
        const stomp = body.vy > 0.5 && body.y + body.h < e.y + e.h * 0.6;
        if (stomp && e.props.stompable) {
          e.alive = false;
          body.vy = PHYS.stompVel;
          player.jumping = false;
          events.push('stomp');
        } else {
          kill(player, events);
          return { finished: false };
        }
        break;
      }
      case 'portal': {
        if (e.cooldown > 0) break;
        const other = entities.find((o) => o.id === e.props.targetId && o.type === 'portal');
        if (other === undefined) break;
        // In die Mitte des Ziels setzen; beide Portale kurz sperren, sonst
        // teleportiert das Ziel sofort zurück – Endlosschleife.
        body.x = other.x + (other.w - body.w) / 2;
        body.y = other.y + other.h - body.h;
        e.cooldown = 30;
        other.cooldown = 30;
        events.push('portal');
        break;
      }
      case 'checkpoint': {
        if (e.active) break;
        e.active = true;
        world.respawnAt = { x: e.x + (e.w - body.w) / 2, y: e.y + e.h - body.h };
        events.push('checkpoint');
        break;
      }
      case 'door': {
        if (e.props.locked && world.keys < 1) {
          // Zu und kein Schlüssel: die Tür ist einfach eine Kulisse.
          break;
        }
        if (e.props.locked && !e.active) {
          e.active = true; // Schlüssel verbraucht, Tür bleibt offen
          world.keys -= 1;
          events.push('unlock');
        }
        if (e.props.mode === 'exit') {
          events.push('finish');
          return { finished: true };
        }
        if (e.cooldown > 0) break;
        const targetDoor = entities.find((o) => o.id === e.props.targetId && o.type === 'door');
        if (targetDoor === undefined) break;
        body.x = targetDoor.x + (targetDoor.w - body.w) / 2;
        body.y = targetDoor.y + targetDoor.h - body.h;
        e.cooldown = 30;
        targetDoor.cooldown = 30;
        events.push('portal');
        break;
      }
      case 'flag': {
        events.push('finish');
        return { finished: true };
      }
      default:
        break;
    }
  }
  return { finished: false };
}
