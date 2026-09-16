/**
 * Zeichnet das HUD über das Kamerabild.
 *
 * Zwei Dinge, die hier leicht schiefgehen und deshalb an einer Stelle gelöst
 * sind (siehe #layout und #project):
 *
 *  1. Das Video füllt die Fläche mit `object-fit: cover`, wird also
 *     zugeschnitten. Trefferkoordinaten stammen aber aus dem *ungeschnittenen*
 *     Bild und müssen umgerechnet werden.
 *  2. Die Frontkamera wird per CSS gespiegelt. Gespiegelt würde man auch die
 *     Beschriftung lesen müssen – deshalb wird nicht die Zeichenfläche
 *     gespiegelt, sondern nur die x-Koordinate der Boxen.
 *
 * Leuchten entsteht durch doppeltes Zeichnen (breit und blass, darüber schmal
 * und hell). Das sieht aus wie ein Schein, kostet aber einen Bruchteil von
 * `shadowBlur`, das auf Mobilgeräten jede Bildrate zerlegt.
 *
 * Felder an einem Ziel, die das HUD auswertet (gesetzt von app.js):
 *   track.faint     unsicherer Treffer → gestrichelter Rahmen mit „?“
 *   track.fine      { label, score } Zweitstufe hat einen feineren Namen
 *   track.skill     { name, color } Ziel gehört zu einem aktiven Skill
 *   track.memory    { item } wiedererkannter eigener Gegenstand
 *   track.motion    Bewegung aus motion.js
 *   track.identity  Zuordnung eines Gesichts
 */

import { CONFIG } from './config.js';
import { moodFor } from './labels.js';

const { colors } = CONFIG.hud;

/**
 * Höhe der Kopfzeile bzw. der Bedienleiste in Anzeigepixeln. Beschriftungen
 * weichen diesen Bereichen aus, damit sie nicht hinter der Bedienung liegen.
 */
const SAFE_TOP = 56;
const SAFE_BOTTOM = 96;

/** Farben, die nicht aus der Grundpalette kommen. */
const HAZARD_HIGH = '#ff4d6d';
const HAZARD_MID = '#ffb648';
const MEMORY = '#c9a0ff';
const TEXT_BOX = '#4fe3ff';
const FAINT = '#7ea8ab';
const HAND = '#2bf5dd';

/** Verbindungen der 21 Hand-Landmarken (MediaPipe-Reihenfolge). */
const HAND_BONES = [
  [0, 1],
  [1, 2],
  [2, 3],
  [3, 4],
  [0, 5],
  [5, 6],
  [6, 7],
  [7, 8],
  [5, 9],
  [9, 10],
  [10, 11],
  [11, 12],
  [9, 13],
  [13, 14],
  [14, 15],
  [15, 16],
  [13, 17],
  [17, 18],
  [18, 19],
  [19, 20],
  [0, 17],
];

/** Mischt zwei Hex-Farben; t = 0 ergibt a, t = 1 ergibt b. */
function mixColor(a, b, t) {
  const pa = [1, 3, 5].map((i) => parseInt(a.slice(i, i + 2), 16));
  const pb = [1, 3, 5].map((i) => parseInt(b.slice(i, i + 2), 16));
  const out = pa.map((v, i) => Math.round(v + (pb[i] - v) * t));
  return `rgb(${out[0]},${out[1]},${out[2]})`;
}

export class Hud {
  /**
   * @param {HTMLCanvasElement} canvas
   * @param {HTMLVideoElement} video
   */
  constructor(canvas, video) {
    this.canvas = canvas;
    this.video = video;
    this.ctx = canvas.getContext('2d');
    this.mirrored = false;
    this.dpr = 1;
    this.cssWidth = 0;
    this.cssHeight = 0;
    /** Letzte Umrechnung – für die Trefferprüfung beim Tippen. */
    this.layout = null;
  }

  /** Passt die Zeichenfläche an Anzeigegrösse und Pixeldichte an. */
  resize() {
    const rect = this.canvas.getBoundingClientRect();
    // Über 2 lohnt sich die Pixeldichte optisch nicht mehr, kostet aber Leistung.
    const dpr = Math.min(globalThis.devicePixelRatio || 1, 2);

    if (rect.width === this.cssWidth && rect.height === this.cssHeight && dpr === this.dpr) return;

    this.cssWidth = rect.width;
    this.cssHeight = rect.height;
    this.dpr = dpr;
    this.canvas.width = Math.round(rect.width * dpr);
    this.canvas.height = Math.round(rect.height * dpr);
    this.ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  }

  /** Umrechnung Videopixel → Anzeigepixel, inklusive Zuschnitt durch `cover`. */
  #layout() {
    const vw = this.video.videoWidth || 16;
    const vh = this.video.videoHeight || 9;
    const scale = Math.max(this.cssWidth / vw, this.cssHeight / vh);
    this.layout = {
      vw,
      vh,
      scale,
      dx: (this.cssWidth - vw * scale) / 2,
      dy: (this.cssHeight - vh * scale) / 2,
    };
    return this.layout;
  }

  #project(box, layout) {
    const x = this.mirrored ? layout.vw - (box.x + box.w) : box.x;
    return {
      x: x * layout.scale + layout.dx,
      y: box.y * layout.scale + layout.dy,
      w: box.w * layout.scale,
      h: box.h * layout.scale,
    };
  }

  /** Öffentlich: Box in Videopixeln → Anzeigepixel (für Trefferprüfung). */
  projectBox(box) {
    return this.#project(box, this.layout ?? this.#layout());
  }

  /** Anzeigepixel → Videopixel, z. B. für einen Tipp ins Bild. */
  unproject(x, y) {
    const layout = this.layout ?? this.#layout();
    let vx = (x - layout.dx) / layout.scale;
    const vy = (y - layout.dy) / layout.scale;
    if (this.mirrored) vx = layout.vw - vx;
    return { x: vx, y: vy };
  }

  /** Welche der Boxen (Videopixel) liegt unter dem Anzeigepunkt? Kleinste gewinnt. */
  hitTest(x, y, boxes) {
    let best = null;
    let bestArea = Number.POSITIVE_INFINITY;
    for (const entry of boxes) {
      const r = this.projectBox(entry.box);
      if (x < r.x || x > r.x + r.w || y < r.y || y > r.y + r.h) continue;
      const area = r.w * r.h;
      if (area < bestArea) {
        bestArea = area;
        best = entry;
      }
    }
    return best;
  }

  clear() {
    this.ctx.clearRect(0, 0, this.cssWidth, this.cssHeight);
  }

  /**
   * Zeichnet einen kompletten Durchlauf.
   * @param {object} state
   * @param {Array} state.objects  Objekt- und Personenziele
   * @param {Array} state.faces    Gesichtsziele
   * @param {object} state.settings
   * @param {number} state.time    Zeitstempel für Animationen
   */
  render({
    objects,
    faces,
    settings,
    time,
    hazards = [],
    words = [],
    blocks = [],
    hands = [],
    scanning = 0,
  }) {
    this.resize();
    this.clear();
    const layout = this.#layout();

    // Die Anzeige lässt sich per Sprache oder Geste ganz abschalten.
    if (settings.hudVisible === false) return;

    // Erkannter Text liegt unter den Rahmen, damit er sie nicht verdeckt.
    if (blocks.length > 0) this.#drawBlocks(blocks, layout, time);
    if (words.length > 0) this.#drawWords(words, layout);

    const risky = new Map(hazards.map((hazard) => [hazard.trackId, hazard]));

    if (settings.objects) {
      // Unsichere zuerst, damit sichere Rahmen obenauf liegen.
      const ordered = [...objects].sort(
        (a, b) => Number(Boolean(b.faint)) - Number(Boolean(a.faint)),
      );
      for (const track of ordered) {
        this.#drawObject(track, layout, time, settings, risky.get(track.id));
      }
    }
    if (settings.faces) {
      for (const track of faces) this.#drawFace(track, layout, time, settings);
    }

    for (const hand of hands) this.#drawHand(hand, layout, time);

    if (scanning > 0) this.#drawScanSweep(scanning, time);
  }

  /* ---------------- Objekte und Personen ---------------- */

  #drawObject(track, layout, time, settings, hazard) {
    const rect = this.#project(track.display, layout);
    const isPerson = track.kind === 'person';
    const alpha = Math.min(1, track.lock) * (track.missed > 0 ? 0.55 : 1);

    // Unsicherer Treffer: gestrichelt, blass, mit Fragezeichen. Das ist das
    // „schwach unbekannt“ – man sieht, dass da etwas ist, ohne dass das HUD
    // so tut, als wüsste es, was.
    if (track.faint && !track.skill && !track.memory?.item) {
      this.#faintFrame(rect, alpha * 0.55);
      const name = track.fine?.label ?? track.labelDe ?? track.label;
      const percent = Math.round((track.fine?.score ?? track.score) * 100);
      this.#label(rect, `? ${String(name).toUpperCase()}`, `${percent}%`, FAINT, alpha * 0.7, true);
      return;
    }

    // Farbe nach Rang: Gefahr > Skill > eigener Gegenstand > Person > Objekt.
    const remembered = track.memory?.item ?? null;
    let color = isPerson ? colors.person : colors.object;
    if (remembered) color = MEMORY;
    if (track.skill) color = track.skill.color;
    if (hazard) color = hazard.level === 'hoch' ? HAZARD_HIGH : HAZARD_MID;

    this.#frame(rect, color, alpha, track.lock);
    if (isPerson || hazard) this.#boxScan(rect, color, alpha, time);
    if (hazard) this.#hazardRing(rect, color, time);
    if (settings.motionArrows) this.#motionArrow(rect, track, color, alpha);

    // Name: Zweitstufe schlägt Detektor, gemerkter Name schlägt beides.
    const base = track.fine?.label ?? track.labelDe ?? track.label;
    const score = track.fine?.score ?? track.score;
    const title = remembered ? remembered.name.toUpperCase() : String(base).toUpperCase();
    const text =
      settings.showScores && !remembered ? `${title} ${Math.round(score * 100)}%` : title;

    const meta = [];
    if (hazard) meta.push(hazard.text.toUpperCase());
    else if (track.skill) meta.push(track.skill.name.toUpperCase());
    else if (remembered?.note) meta.push(remembered.note.slice(0, 28).toUpperCase());
    else if (track.fine && track.labelDe && track.fine.label !== track.labelDe) {
      meta.push(String(track.labelDe).toUpperCase());
    }
    if (settings.showTrackIds) meta.push(`ID ${String(track.id).padStart(3, '0')}`);

    this.#label(rect, text, meta.join(' · '), color, alpha);
  }

  /** Gestrichelter Rahmen für unsichere Treffer. */
  #faintFrame(rect, alpha) {
    const ctx = this.ctx;
    ctx.globalAlpha = alpha;
    ctx.strokeStyle = FAINT;
    ctx.lineWidth = 1;
    ctx.setLineDash([6, 5]);
    ctx.strokeRect(rect.x, rect.y, rect.w, rect.h);
    ctx.setLineDash([]);
    ctx.globalAlpha = 1;
  }

  /**
   * Pfeil in Bewegungsrichtung. Länge und Farbe folgen dem Tempo – langsam
   * türkis, schnell bernstein. Das Richtungswort steht an der Spitze.
   */
  #motionArrow(rect, track, color, alpha) {
    const motion = track.motion;
    if (!motion?.moving) return;

    const ctx = this.ctx;
    const cx = rect.x + rect.w / 2;
    const cy = rect.y + rect.h / 2;
    const speedT = Math.min(1, motion.speed / 1.4);
    const arrowColor = mixColor('#2bf5dd', '#ffb648', speedT);

    // Frontal: kein sinnvoller Pfeil in der Bildebene – Ringe wandern stattdessen.
    if (motion.direction === 'auf dich zu' || motion.direction === 'von dir weg') {
      const growing = motion.direction === 'auf dich zu';
      ctx.globalAlpha = alpha * 0.8;
      ctx.strokeStyle = arrowColor;
      ctx.lineWidth = 2.5;
      for (const step of [0, 1, 2]) {
        const phase = (performance.now() / 700 + step / 3) % 1;
        const t = growing ? phase : 1 - phase;
        const radius = Math.min(rect.w, rect.h) * (0.18 + t * 0.36);
        ctx.globalAlpha = alpha * (1 - t) * 0.8;
        ctx.beginPath();
        ctx.arc(cx, cy, radius, 0, Math.PI * 2);
        ctx.stroke();
      }
      ctx.globalAlpha = 1;
      this.#tag(
        cx,
        cy - Math.min(rect.w, rect.h) * 0.56,
        motion.direction.toUpperCase(),
        arrowColor,
        alpha,
      );
      return;
    }

    const length = Math.min(140, 44 + motion.speed * 260);
    const dx = Math.cos(motion.angle) * length;
    const dy = Math.sin(motion.angle) * length;
    const tipX = cx + dx;
    const tipY = cy + dy;
    const head = 14;

    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';

    // Schein
    ctx.globalAlpha = alpha * 0.28;
    ctx.strokeStyle = arrowColor;
    ctx.lineWidth = 9;
    ctx.beginPath();
    ctx.moveTo(cx, cy);
    ctx.lineTo(tipX, tipY);
    ctx.stroke();

    // Schaft
    ctx.globalAlpha = alpha * 0.95;
    ctx.lineWidth = 3;
    ctx.beginPath();
    ctx.moveTo(cx, cy);
    ctx.lineTo(tipX, tipY);
    ctx.stroke();

    // Spitze
    ctx.fillStyle = arrowColor;
    ctx.beginPath();
    ctx.moveTo(tipX, tipY);
    ctx.lineTo(
      tipX - Math.cos(motion.angle - 0.45) * head,
      tipY - Math.sin(motion.angle - 0.45) * head,
    );
    ctx.lineTo(
      tipX - Math.cos(motion.angle + 0.45) * head,
      tipY - Math.sin(motion.angle + 0.45) * head,
    );
    ctx.closePath();
    ctx.fill();
    ctx.globalAlpha = 1;

    // Richtungswort neben der Spitze, etwas nach aussen versetzt.
    const labelX = tipX + Math.cos(motion.angle) * 12;
    const labelY = tipY + Math.sin(motion.angle) * 12;
    this.#tag(labelX, labelY, motion.direction.toUpperCase(), arrowColor, alpha);
  }

  /** Kleines Etikett mit dunklem Hintergrund, mittig auf (x, y). */
  #tag(x, y, text, color, alpha) {
    const ctx = this.ctx;
    ctx.font = CONFIG.hud.metaFont;
    const width = ctx.measureText(text).width + 10;
    const height = 14;
    const bx = Math.max(2, Math.min(this.cssWidth - width - 2, x - width / 2));
    const by = Math.max(2, Math.min(this.cssHeight - height - 2, y - height / 2));

    ctx.globalAlpha = alpha * 0.9;
    ctx.fillStyle = colors.shadow;
    ctx.fillRect(bx, by, width, height);
    ctx.fillStyle = color;
    ctx.textBaseline = 'middle';
    ctx.fillText(text, bx + 5, by + height / 2 + 0.5);
    ctx.globalAlpha = 1;
  }

  /** Pulsierender Ring um ein Ziel, das als Gefahr gilt. */
  #hazardRing(rect, color, time) {
    const ctx = this.ctx;
    const cx = rect.x + rect.w / 2;
    const cy = rect.y + rect.h / 2;
    const base = Math.max(rect.w, rect.h) * 0.6;
    const pulse = (time % 1100) / 1100;

    ctx.globalAlpha = (1 - pulse) * 0.55;
    ctx.strokeStyle = color;
    ctx.lineWidth = 3;
    ctx.beginPath();
    ctx.arc(cx, cy, base * (0.6 + pulse * 0.5), 0, Math.PI * 2);
    ctx.stroke();
    ctx.globalAlpha = 1;
  }

  /* ---------------- Text ---------------- */

  /** Kästchen um erkannte Wörter. */
  #drawWords(words, layout) {
    const ctx = this.ctx;
    ctx.strokeStyle = TEXT_BOX;
    ctx.lineWidth = 1;

    for (const word of words) {
      const rect = this.#project(word.box, layout);
      if (rect.w < 4 || rect.h < 4) continue;
      ctx.globalAlpha = 0.2 + word.confidence * 0.4;
      ctx.strokeRect(rect.x, rect.y, rect.w, rect.h);

      // Übersetzte Wörter werden direkt über das Original gelegt.
      if (word.replacement) {
        ctx.globalAlpha = 0.92;
        ctx.fillStyle = 'rgba(4,12,16,0.92)';
        ctx.fillRect(rect.x, rect.y, rect.w, rect.h);
        ctx.fillStyle = TEXT_BOX;
        const size = Math.max(9, Math.min(18, rect.h * 0.78));
        ctx.font = `600 ${size}px ui-monospace, Menlo, monospace`;
        ctx.textBaseline = 'middle';
        ctx.fillText(word.replacement, rect.x + 2, rect.y + rect.h / 2, rect.w - 4);
      }
    }
    ctx.globalAlpha = 1;
  }

  /**
   * Textblöcke: Eckhaken und ein „TEXT“-Etikett mit Lupensymbol.
   * Ein Tipp auf den Block öffnet die Lupe (Trefferprüfung in app.js).
   */
  #drawBlocks(blocks, layout, time) {
    const ctx = this.ctx;
    const blink = 0.55 + 0.25 * Math.sin(time / 420);

    for (const block of blocks) {
      const rect = this.#project(block.box, layout);
      if (rect.w < 12 || rect.h < 8) continue;

      const len = Math.max(8, Math.min(18, Math.min(rect.w, rect.h) * 0.3));
      ctx.globalAlpha = blink;
      ctx.strokeStyle = TEXT_BOX;
      ctx.lineWidth = 1.5;
      ctx.beginPath();
      for (const [cx, cy, sx, sy] of [
        [rect.x, rect.y, 1, 1],
        [rect.x + rect.w, rect.y, -1, 1],
        [rect.x, rect.y + rect.h, 1, -1],
        [rect.x + rect.w, rect.y + rect.h, -1, -1],
      ]) {
        ctx.moveTo(cx + sx * len, cy);
        ctx.lineTo(cx, cy);
        ctx.lineTo(cx, cy + sy * len);
      }
      ctx.stroke();

      ctx.globalAlpha = 0.9;
      const tag = `⌕ TEXT${block.lines > 1 ? ` · ${block.lines} ZEILEN` : ''}`;
      this.#tag(rect.x + rect.w / 2, Math.max(SAFE_TOP + 8, rect.y - 10), tag, TEXT_BOX, 1);
    }
    ctx.globalAlpha = 1;
  }

  /* ---------------- Hand ---------------- */

  /**
   * Skelett aus 21 Punkten, Ring, der sich beim Halten eines Zeichens füllt,
   * und der Name des erkannten Zeichens am Handgelenk.
   * @param {{landmarks: Array<{x:number,y:number}>, gesture: string|null,
   *          hold: number, custom: boolean}} hand  Landmarken in Videopixeln
   */
  #drawHand(hand, layout, time) {
    const ctx = this.ctx;
    const points = hand.landmarks.map((p) => this.#project({ x: p.x, y: p.y, w: 0, h: 0 }, layout));
    if (points.length < 21) return;

    // Knochen: breit und blass, darüber schmal und hell.
    for (const [width, alpha] of [
      [5, 0.18],
      [1.5, 0.85],
    ]) {
      ctx.globalAlpha = alpha;
      ctx.strokeStyle = HAND;
      ctx.lineWidth = width;
      ctx.lineCap = 'round';
      ctx.beginPath();
      for (const [a, b] of HAND_BONES) {
        ctx.moveTo(points[a].x, points[a].y);
        ctx.lineTo(points[b].x, points[b].y);
      }
      ctx.stroke();
    }

    // Gelenke
    ctx.globalAlpha = 0.95;
    ctx.fillStyle = HAND;
    for (let i = 0; i < points.length; i += 1) {
      const isTip = [4, 8, 12, 16, 20].includes(i);
      ctx.beginPath();
      ctx.arc(points[i].x, points[i].y, isTip ? 3.2 : 2.2, 0, Math.PI * 2);
      ctx.fill();
    }

    // Ring um die Handmitte: füllt sich, solange das Zeichen gehalten wird.
    const centre = points[9];
    const radius = Math.max(
      28,
      Math.hypot(points[0].x - points[9].x, points[0].y - points[9].y) * 1.15,
    );
    ctx.lineWidth = 2;
    ctx.globalAlpha = 0.35;
    ctx.strokeStyle = HAND;
    ctx.beginPath();
    ctx.arc(centre.x, centre.y, radius, 0, Math.PI * 2);
    ctx.stroke();

    if (hand.hold > 0) {
      ctx.globalAlpha = 0.95;
      ctx.lineWidth = 3;
      ctx.strokeStyle = hand.hold >= 1 ? '#66ffc2' : HAND;
      ctx.beginPath();
      ctx.arc(
        centre.x,
        centre.y,
        radius,
        -Math.PI / 2,
        -Math.PI / 2 + Math.PI * 2 * Math.min(1, hand.hold),
      );
      ctx.stroke();
    }

    if (hand.gesture) {
      const name = hand.gesture.toUpperCase();
      const wobble = Math.sin(time / 300) * 1.5;
      this.#tag(
        centre.x,
        centre.y + radius + 12 + wobble,
        hand.custom ? `★ ${name}` : name,
        HAND,
        1,
      );
    }
    ctx.globalAlpha = 1;
  }

  /* ---------------- Gesichter ---------------- */

  #drawFace(track, layout, time, settings) {
    const rect = this.#project(track.display, layout);
    const identity = track.identity;
    const known = Boolean(identity?.person);
    const color = known ? colors.faceKnown : colors.faceUnknown;
    const alpha = Math.min(1, track.lock) * (track.missed > 0 ? 0.55 : 1);

    this.#frame(rect, color, alpha, track.lock);
    this.#crosshair(rect, color, alpha * 0.8);
    this.#boxScan(rect, color, alpha, time);
    if (settings.motionArrows) this.#motionArrow(rect, track, color, alpha);

    let text;
    let meta;

    if (known && settings.privacy) {
      // Im Privatmodus wird niemand beim Namen genannt.
      text = 'BEKANNT';
      meta = 'PRIVATMODUS';
    } else if (known) {
      const percent = Math.round(identity.confidence * 100);
      text = settings.showScores
        ? `${identity.person.name.toUpperCase()} ${percent}%`
        : identity.person.name.toUpperCase();
      meta = `${identity.person.age} JAHRE`;
      // Ausdrücke sind grob und unsicher – das Fragezeichen sagt das.
      if (identity.expression && identity.expression !== 'neutral') {
        meta += ` · ${moodFor(identity.expression).toUpperCase()}?`;
      }
      if (identity.person.note) meta += ` · ${identity.person.note.slice(0, 24).toUpperCase()}`;
    } else if (identity) {
      text = 'UNBEKANNT';
      meta = `SCHÄTZUNG ${Math.round(identity.age)} J`;
      if (identity.expression && identity.expression !== 'neutral') {
        meta += ` · ${moodFor(identity.expression).toUpperCase()}?`;
      }
    } else {
      // Noch keine Antwort aus der teuren Stufe – Laufschrift statt leerem Rahmen.
      const dots = '.'.repeat(1 + (Math.floor(time / 320) % 3));
      text = `ANALYSE${dots}`;
      meta = `${Math.round(track.score * 100)}%`;
    }

    if (settings.showTrackIds) meta = meta ? `${meta} · #${track.id}` : `#${track.id}`;
    this.#label(rect, text, meta, color, alpha);
  }

  /* ---------------- Bausteine ---------------- */

  /** Rahmen aus vier Eckwinkeln; beim Einblenden fahren sie nach innen. */
  #frame(rect, color, alpha, lock) {
    const ctx = this.ctx;
    const side = Math.min(rect.w, rect.h);
    const len = Math.max(
      CONFIG.hud.cornerMin,
      Math.min(CONFIG.hud.cornerMax, side * CONFIG.hud.cornerRatio),
    );

    // Vor dem Einrasten sitzen die Winkel weiter aussen.
    const ease = 1 - Math.pow(1 - Math.min(1, lock), 3);
    const spread = (1 - ease) * side * 0.18;

    const x = rect.x - spread;
    const y = rect.y - spread;
    const w = rect.w + spread * 2;
    const h = rect.h + spread * 2;

    const corners = [
      [x, y, 1, 1],
      [x + w, y, -1, 1],
      [x, y + h, 1, -1],
      [x + w, y + h, -1, -1],
    ];

    // Blasser, breiter Durchgang = Schein.
    ctx.globalAlpha = alpha * 0.28;
    ctx.strokeStyle = color;
    ctx.lineWidth = CONFIG.hud.lineWidth * 3;
    ctx.lineCap = 'round';
    this.#strokeCorners(corners, len);

    ctx.globalAlpha = alpha;
    ctx.lineWidth = CONFIG.hud.lineWidth;
    this.#strokeCorners(corners, len);

    // Angedeuteter Vollrahmen zwischen den Winkeln.
    ctx.globalAlpha = alpha * 0.16;
    ctx.lineWidth = 1;
    ctx.strokeRect(x, y, w, h);
    ctx.globalAlpha = 1;
  }

  #strokeCorners(corners, len) {
    const ctx = this.ctx;
    ctx.beginPath();
    for (const [cx, cy, sx, sy] of corners) {
      ctx.moveTo(cx + sx * len, cy);
      ctx.lineTo(cx, cy);
      ctx.lineTo(cx, cy + sy * len);
    }
    ctx.stroke();
  }

  /** Wandernder Suchstrahl innerhalb der Box. */
  #boxScan(rect, color, alpha, time) {
    if (rect.h < 44) return;
    const ctx = this.ctx;
    const progress = (((time / 1800) % 1) + 1) % 1;
    const y = rect.y + rect.h * progress;

    const gradient = ctx.createLinearGradient(rect.x, y - 10, rect.x, y + 10);
    gradient.addColorStop(0, 'rgba(0,0,0,0)');
    gradient.addColorStop(0.5, color);
    gradient.addColorStop(1, 'rgba(0,0,0,0)');

    ctx.globalAlpha = alpha * 0.4;
    ctx.fillStyle = gradient;
    ctx.fillRect(rect.x + 2, y - 10, rect.w - 4, 20);
    ctx.globalAlpha = 1;
  }

  /** Fadenkreuz in der Mitte eines Gesichts. */
  #crosshair(rect, color, alpha) {
    const ctx = this.ctx;
    const cx = rect.x + rect.w / 2;
    const cy = rect.y + rect.h / 2;
    const arm = Math.min(11, rect.w * 0.12);

    ctx.globalAlpha = alpha;
    ctx.strokeStyle = color;
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.moveTo(cx - arm, cy);
    ctx.lineTo(cx - arm * 0.35, cy);
    ctx.moveTo(cx + arm * 0.35, cy);
    ctx.lineTo(cx + arm, cy);
    ctx.moveTo(cx, cy - arm);
    ctx.lineTo(cx, cy - arm * 0.35);
    ctx.moveTo(cx, cy + arm * 0.35);
    ctx.lineTo(cx, cy + arm);
    ctx.stroke();
    ctx.globalAlpha = 1;
  }

  /** Schwebende Beschriftung mit abgeschrägter Ecke. */
  #label(rect, text, meta, color, alpha, faint = false) {
    const ctx = this.ctx;
    const padX = 7;
    const height = 19;
    const gap = 6;

    ctx.font = CONFIG.hud.labelFont;
    const textWidth = ctx.measureText(text).width;

    ctx.font = CONFIG.hud.metaFont;
    const metaWidth = meta ? ctx.measureText(meta).width : 0;

    const boxWidth = Math.max(textWidth, metaWidth) + padX * 2;
    const boxHeight = meta ? height + 13 : height;

    /*
     * Platzierung in drei Stufen. Kopfzeile und Bedienleiste sind belegt;
     * eine Beschriftung, die dort landet, ist unlesbar. Also: erst über die
     * Box, sonst darunter, sonst hinein.
     */
    let bx = rect.x;
    let by = rect.y - boxHeight - gap;

    if (by < SAFE_TOP) {
      const below = rect.y + rect.h + gap;
      by = below + boxHeight > this.cssHeight - SAFE_BOTTOM ? rect.y + gap : below;
    }

    // Nicht über die seitlichen Ränder hinauslaufen lassen.
    bx = Math.max(2, Math.min(bx, this.cssWidth - boxWidth - 2));
    by = Math.max(4, Math.min(by, this.cssHeight - boxHeight - 4));

    const cut = 6;
    ctx.globalAlpha = alpha;

    ctx.beginPath();
    ctx.moveTo(bx, by);
    ctx.lineTo(bx + boxWidth, by);
    ctx.lineTo(bx + boxWidth, by + boxHeight - cut);
    ctx.lineTo(bx + boxWidth - cut, by + boxHeight);
    ctx.lineTo(bx, by + boxHeight);
    ctx.closePath();

    ctx.fillStyle = colors.shadow;
    ctx.fill();
    ctx.strokeStyle = color;
    ctx.lineWidth = 1;
    if (faint) ctx.setLineDash([4, 3]);
    ctx.stroke();
    ctx.setLineDash([]);

    // Farbiger Anschlag links – ordnet die Beschriftung ihrem Rahmen zu.
    ctx.fillStyle = color;
    ctx.fillRect(bx, by, 2, boxHeight);

    ctx.textBaseline = 'middle';
    ctx.font = CONFIG.hud.labelFont;
    ctx.fillStyle = color;
    ctx.fillText(text, bx + padX, by + height / 2 + 1);

    if (meta) {
      ctx.font = CONFIG.hud.metaFont;
      ctx.fillStyle = colors.text;
      ctx.globalAlpha = alpha * 0.72;
      ctx.fillText(meta, bx + padX, by + height + 4);
    }

    ctx.globalAlpha = 1;
  }

  /** Waagrechter Balken, der beim Scannen einmal durchs Bild läuft. */
  #drawScanSweep(progress, time) {
    const ctx = this.ctx;
    const y = this.cssHeight * progress;

    const gradient = ctx.createLinearGradient(0, y - 26, 0, y + 26);
    gradient.addColorStop(0, 'rgba(43,245,221,0)');
    gradient.addColorStop(0.5, 'rgba(43,245,221,0.55)');
    gradient.addColorStop(1, 'rgba(43,245,221,0)');
    ctx.fillStyle = gradient;
    ctx.fillRect(0, y - 26, this.cssWidth, 52);

    ctx.strokeStyle = 'rgba(43,245,221,0.9)';
    ctx.lineWidth = 1.5;
    ctx.beginPath();
    ctx.moveTo(0, y);
    ctx.lineTo(this.cssWidth, y);
    ctx.stroke();

    ctx.fillStyle = '#2bf5dd';
    ctx.font = '600 11px ui-monospace, Menlo, monospace';
    ctx.textBaseline = 'bottom';
    const blink = Math.floor(time / 260) % 4;
    ctx.fillText(`SCAN ${Math.round(progress * 100)}%${'.'.repeat(blink)}`, 14, y - 8);
  }
}

/**
 * Kleines Rundinstrument unten links. Die Punkte sind echte Ziele:
 * horizontale Bildposition → Winkel, Boxgrösse → Abstand zur Mitte.
 */
export class Radar {
  constructor(canvas) {
    this.canvas = canvas;
    this.ctx = canvas.getContext('2d');
  }

  render(tracks, videoWidth) {
    const ctx = this.ctx;
    const size = this.canvas.width;
    const mid = size / 2;

    ctx.clearRect(0, 0, size, size);

    ctx.strokeStyle = 'rgba(43,245,221,0.22)';
    ctx.lineWidth = 1;
    for (const r of [mid * 0.35, mid * 0.68, mid * 0.95]) {
      ctx.beginPath();
      ctx.arc(mid, mid, r, 0, Math.PI * 2);
      ctx.stroke();
    }
    ctx.beginPath();
    ctx.moveTo(mid, 4);
    ctx.lineTo(mid, size - 4);
    ctx.moveTo(4, mid);
    ctx.lineTo(size - 4, mid);
    ctx.stroke();

    for (const track of tracks) {
      if (track.faint) continue;
      const centreX = track.display.x + track.display.w / 2;
      const offset = (centreX / (videoWidth || 1)) * 2 - 1;
      // Grosse Ziele gelten als nah und rücken zur Mitte.
      const nearness = Math.min(1, track.display.h / ((videoWidth || 1) * 0.55));
      const radius = mid * 0.92 * (1 - nearness * 0.72);
      const angle = -Math.PI / 2 + offset * 1.05;

      ctx.fillStyle = track.kind === 'face' ? '#ffb648' : (track.skill?.color ?? '#2bf5dd');
      ctx.beginPath();
      ctx.arc(mid + Math.cos(angle) * radius, mid + Math.sin(angle) * radius, 2.6, 0, Math.PI * 2);
      ctx.fill();
    }
  }
}
