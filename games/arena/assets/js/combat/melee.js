import { Pool } from '../core/pool.js';
import { TAU } from '../core/util.js';

/**
 * Nahkampfangriffe: Bogen-Schwung (Schwert, Dolch), volle Drehung (Axt)
 * und Stoß (Speer).
 *
 * Jeder Angriff fuehrt eine eigene Trefferliste. Dadurch kann eine
 * Axtdrehung jeden Gegner genau einmal treffen, egal wie viele Frames
 * die Klinge über ihm steht.
 */
export class MeleeAttacks {
  constructor() {
    this.pool = new Pool(
      () => ({
        alive: false, type: 'arc', x: 0, y: 0, angle: 0, arc: 90, range: 120,
        damage: 0, knockback: 0, critChance: 0, critDamage: 0, duration: 0.25,
        time: 0, hits: null, sprite: null, width: 46, sweptFrom: 0, sweptTo: 0,
        // Ansatzpunkt des Schlages, aus den Waffendaten.
        offsetY: -10, offsetX: 0, trailColor: '', funken: null,
      }),
      (a, options) => {
        a.offsetY = -10;
        a.offsetX = 0;
        a.trailColor = '';
        Object.assign(a, options);
        a.time = 0;
        if (!a.hits) a.hits = new Set();
        else a.hits.clear();
        a.sweptFrom = a.angle - (a.arc * Math.PI) / 360;
        a.sweptTo = a.sweptFrom;
        // Ein paar Funken an der Klingenspitze, einmal je Schlag ausgewuerfelt.
        if (!a.funken) a.funken = [];
        a.funken.length = 0;
        const anzahl = a.type === 'thrust' ? 5 : 7;
        for (let i = 0; i < anzahl; i++) {
          a.funken.push({
            p: Math.random(),                 // Position entlang des Schwungs
            r: 0.82 + Math.random() * 0.3,    // Abstand vom Mittelpunkt
            s: 1.4 + Math.random() * 2.2,     // Groesse
            v: 0.6 + Math.random() * 0.8,     // wie schnell sie verglueht
          });
        }
      },
      8,
    );
  }

  get list() {
    return this.pool.active;
  }

  clear() {
    this.pool.clear();
  }

  spawn(options) {
    return this.pool.spawn(options);
  }

  /** @param onHit (enemy, attack) => void */
  update(dt, player, enemies, onHit) {
    for (const a of this.pool.active) {
      a.time += dt;
      // Der Angriff setzt dort an, wo die Waffe auch in der Hand liegt.
      // Ohne den Hoehenversatz sackte der Speer beim Stich um zehn Pixel
      // nach unten, obwohl er im Stand mittig sass.
      const vor = a.offsetX || 0;
      a.x = player.x + Math.cos(a.angle) * vor;
      a.y = player.y + player.footOffset * 0.1 + (a.offsetY || 0)
        + Math.sin(a.angle) * vor * 0.6;
      const t = Math.min(1, a.time / a.duration);

      if (a.type === 'thrust') {
        // Erst schnell raus, dann zurück - Treffer nur in der Ausfahrphase.
        const reach = t < 0.45 ? (t / 0.45) * a.range : (1 - (t - 0.45) / 0.55) * a.range;
        a.reach = reach;
        if (t < 0.6) {
          const dirX = Math.cos(a.angle);
          const dirY = Math.sin(a.angle);
          enemies.inRadius(a.x + dirX * reach * 0.5, a.y + dirY * reach * 0.5, reach * 0.6 + 30, (enemy) => {
            if (a.hits.has(enemy.id)) return;
            const ex = enemy.x - a.x;
            const ey = enemy.y - a.y;
            const along = ex * dirX + ey * dirY;
            const side = Math.abs(-ex * dirY + ey * dirX);
            if (along > -10 && along < reach + enemy.radius * 0.6 && side < a.width / 2 + enemy.radius * 0.7) {
              a.hits.add(enemy.id);
              onHit(enemy, a);
            }
          });
        }
      } else {
        // Schwung: Winkel wandert über die Angriffsdauer.
        const span = (a.arc * Math.PI) / 180;
        const from = a.angle - span / 2;
        const current = from + span * t;
        a.sweptTo = current;
        a.current = current;

        // Ueberstrichener Winkel als fortlaufender Wert 0..span - nicht als
        // Differenz, sonst springt die Rechnung bei einer vollen Drehung um.
        const swept = span * t;

        enemies.inRadius(a.x, a.y, a.range + 40, (enemy) => {
          if (a.hits.has(enemy.id)) return;
          const dx = enemy.x - a.x;
          const dy = enemy.y - a.y;
          const distance = Math.hypot(dx, dy);
          if (distance > a.range + enemy.radius * 0.8) return;

          // Winkel des Gegners relativ zum Schwungbeginn, normiert auf 0..2PI.
          let passed = Math.atan2(dy, dx) - from;
          passed %= TAU;
          if (passed < 0) passed += TAU;

          const tolerance = Math.atan2(enemy.radius * 0.8, Math.max(20, distance));
          // Kurz vor dem Start liegende Gegner (passed nahe 2PI) mitnehmen.
          if (passed > TAU - tolerance) passed -= TAU;

          if (passed <= swept + tolerance && passed <= span + tolerance) {
            a.hits.add(enemy.id);
            onHit(enemy, a);
          }
        });
      }

      if (a.time >= a.duration) a.alive = false;
    }
    this.pool.sweep();
  }

  /**
   * Der sichtbare Schlag.
   *
   * Frueher lag hinter der Waffe ein weisser Tortenstueck-Keil - flach und
   * hart. Jetzt zieht eine Sichel mit: hinten duenn und fast durchsichtig,
   * vorne breit und hell, mit einer scharfen Vorderkante an der Klinge und
   * ein paar Funken an der Spitze. Alles im Additiv-Modus, damit es
   * leuchtet statt zu decken.
   *
   * Kosten: ein Dutzend Boegen je Schlag, und es gibt hoechstens eine
   * Handvoll Schlaege gleichzeitig - das faellt neben achtzig Gegnern
   * nicht ins Gewicht.
   */
  draw(ctx) {
    for (const a of this.pool.active) {
      const t = Math.min(1, a.time / a.duration);
      const farbe = a.trailColor || '#ffe6ae';

      // Sprite-Länge aus den Waffendaten, sonst an der Reichweite orientiert.
      const length = a.spriteScale || a.range * 0.9;

      if (a.type === 'thrust') {
        const reach = a.reach || 0;
        stichSpur(ctx, a, reach, t, farbe);
        drawWeapon(ctx, a.sprite, a.x + Math.cos(a.angle) * reach * 0.62,
          a.y + Math.sin(a.angle) * reach * 0.62, a.angle, length);
        continue;
      }

      const span = (a.arc * Math.PI) / 180;
      const from = a.angle - span / 2;
      const current = from + span * t;

      schwungSpur(ctx, a, from, current, span, t, farbe);

      drawWeapon(ctx, a.sprite, a.x + Math.cos(current) * a.range * 0.66,
        a.y + Math.sin(current) * a.range * 0.66, current, length);
    }
  }
}

/**
 * Sichelfoermige Spur hinter einem Schwung.
 *
 * Ein einziger Pfad, gefuellt mit einem Farbverlauf: aussen ein sauberer
 * Kreisbogen, innen eine Linie aus zwei Dutzend Punkten, deren Abstand zur
 * Klinge hin waechst. In Streifen gezeichnet gab es sichtbare Kanten -
 * eine Flaeche mit Verlauf hat keine.
 */
function schwungSpur(ctx, a, from, current, span, t, farbe) {
  // Die Spur ist immer nur ein Stueck des Schwungs lang, sonst wirkt eine
  // volle Axtdrehung wie ein geschlossener Ring.
  const spurLaenge = Math.min(span, Math.PI * 0.62);
  const start = Math.max(from, current - spurLaenge);
  if (current - start < 0.02) return;

  const aussen = a.range;
  const innen = a.range * 0.42;
  const punkte = 24;
  const verblassen = 1 - t * 0.3;

  ctx.save();
  ctx.globalCompositeOperation = 'lighter';

  ctx.beginPath();
  ctx.arc(a.x, a.y, aussen, start, current);
  for (let i = punkte; i >= 0; i--) {
    const k = i / punkte;                       // 0 = Spurende, 1 = Klinge
    const w = start + (current - start) * k;
    const dicke = 0.10 + 0.90 * k * k;
    const r = aussen - (aussen - innen) * dicke;
    ctx.lineTo(a.x + Math.cos(w) * r, a.y + Math.sin(w) * r);
  }
  ctx.closePath();

  // Verlauf entlang der Sehne: hinten fast durchsichtig, vorne hell.
  const g = ctx.createLinearGradient(
    a.x + Math.cos(start) * aussen, a.y + Math.sin(start) * aussen,
    a.x + Math.cos(current) * aussen, a.y + Math.sin(current) * aussen,
  );
  g.addColorStop(0, rgba(farbe, 0));
  g.addColorStop(0.55, rgba(farbe, 0.14 * verblassen));
  g.addColorStop(1, rgba(farbe, 0.55 * verblassen));
  ctx.fillStyle = g;
  ctx.fill();

  // Scharfe Vorderkante direkt an der Klinge.
  ctx.globalAlpha = 0.8 * verblassen;
  ctx.strokeStyle = '#fffdf4';
  ctx.lineWidth = 2.5;
  ctx.beginPath();
  ctx.arc(a.x, a.y, aussen * 0.97, Math.max(start, current - 0.20), current);
  ctx.stroke();

  ctx.globalAlpha = 0.4 * verblassen;
  ctx.strokeStyle = farbe;
  ctx.lineWidth = 9;
  ctx.beginPath();
  ctx.arc(a.x, a.y, aussen * 0.97, Math.max(start, current - 0.34), current);
  ctx.stroke();

  funken(ctx, a, start, current, aussen, verblassen, farbe);
  ctx.restore();
}

/** #rrggbb plus Deckkraft als rgba() - fuer die Verlaeufe. */
function rgba(hex, alpha) {
  const h = (hex || '#ffe6ae').replace('#', '');
  const voll = h.length === 3 ? h[0] + h[0] + h[1] + h[1] + h[2] + h[2] : h;
  const zahl = parseInt(voll, 16) || 0xffe6ae;
  return 'rgba(' + ((zahl >> 16) & 255) + ',' + ((zahl >> 8) & 255) + ','
    + (zahl & 255) + ',' + alpha.toFixed(3) + ')';
}

/** Funken entlang der Spur - kleine helle Punkte, die verglühen. */
function funken(ctx, a, start, current, radius, verblassen, farbe) {
  ctx.fillStyle = farbe;
  for (const f of a.funken) {
    const winkel = start + (current - start) * f.p;
    const rest = Math.max(0, 1 - f.p * f.v);
    if (rest <= 0.02) continue;
    ctx.globalAlpha = rest * 0.8 * verblassen;
    const r = radius * f.r;
    ctx.beginPath();
    ctx.arc(a.x + Math.cos(winkel) * r, a.y + Math.sin(winkel) * r, f.s * rest, 0, TAU);
    ctx.fill();
  }
}

/** Spur hinter einem Stoss: ein schmaler, nach vorne spitzer Streifen. */
function stichSpur(ctx, a, reach, t, farbe) {
  if (reach < 6) return;
  const dirX = Math.cos(a.angle);
  const dirY = Math.sin(a.angle);
  const nx = -dirY;
  const ny = dirX;
  const breite = (a.width || 44) * 0.34;
  const verblassen = (1 - t * 0.35) * (t < 0.45 ? 1 : 0.55);

  ctx.save();
  ctx.globalCompositeOperation = 'lighter';

  // Streifen von der Faust bis zur Spitze, vorne spitz zulaufend.
  ctx.globalAlpha = 0.32 * verblassen;
  ctx.fillStyle = farbe;
  ctx.beginPath();
  ctx.moveTo(a.x + dirX * reach, a.y + dirY * reach);
  ctx.lineTo(a.x + nx * breite, a.y + ny * breite);
  ctx.lineTo(a.x - nx * breite, a.y - ny * breite);
  ctx.closePath();
  ctx.fill();

  // Heller Kern auf der Achse.
  ctx.globalAlpha = 0.6 * verblassen;
  ctx.strokeStyle = '#fffdf4';
  ctx.lineWidth = 2.5;
  ctx.beginPath();
  ctx.moveTo(a.x + dirX * reach * 0.15, a.y + dirY * reach * 0.15);
  ctx.lineTo(a.x + dirX * reach, a.y + dirY * reach);
  ctx.stroke();

  // Funken an der Spitze.
  ctx.fillStyle = farbe;
  for (const f of a.funken) {
    const rest = Math.max(0, 1 - f.p * f.v);
    if (rest <= 0.02) continue;
    const laenge = reach * (0.7 + f.p * 0.32);
    const seite = (f.r - 0.95) * breite * 3;
    ctx.globalAlpha = rest * 0.75 * verblassen;
    ctx.beginPath();
    ctx.arc(a.x + dirX * laenge + nx * seite, a.y + dirY * laenge + ny * seite,
      f.s * rest, 0, TAU);
    ctx.fill();
  }
  ctx.restore();
}

function drawWeapon(ctx, sprite, x, y, angle, length) {
  if (!sprite) return;
  const frame = sprite.frameAt(0);
  const w = length;
  const h = (sprite.height / sprite.width) * length;
  ctx.save();
  ctx.translate(x, y);
  ctx.rotate(angle);
  ctx.drawImage(frame, -w / 2, -h / 2, w, h);
  ctx.restore();
}
