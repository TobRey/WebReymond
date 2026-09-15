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
 */

import { CONFIG } from './config.js';
import { labelFor, moodFor } from './labels.js';

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
    return {
      vw,
      vh,
      scale,
      dx: (this.cssWidth - vw * scale) / 2,
      dy: (this.cssHeight - vh * scale) / 2,
    };
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
  render({ objects, faces, settings, time, hazards = [], words = [], scanning = 0 }) {
    this.resize();
    this.clear();
    const layout = this.#layout();

    // Die Anzeige lässt sich per Sprache oder Geste ganz abschalten.
    if (settings.hudVisible === false) return;

    // Erkannter Text liegt unter den Rahmen, damit er sie nicht verdeckt.
    if (words.length > 0) this.#drawWords(words, layout);

    const risky = new Map(hazards.map((hazard) => [hazard.trackId, hazard]));

    if (settings.objects) {
      for (const track of objects) {
        this.#drawObject(track, layout, time, settings, risky.get(track.id));
      }
    }
    if (settings.faces) {
      for (const track of faces) this.#drawFace(track, layout, time, settings);
    }

    if (scanning > 0) this.#drawScanSweep(scanning, time);
  }

  /* ---------------- Objekte und Personen ---------------- */

  #drawObject(track, layout, time, settings, hazard) {
    const rect = this.#project(track.display, layout);
    const isPerson = track.kind === 'person';

    // Ein eigener, wiedererkannter Gegenstand bekommt seine eigene Farbe,
    // eine Gefahrenmeldung übersteuert alles andere.
    const remembered = track.memory?.item ?? null;
    let color = isPerson ? colors.person : colors.object;
    if (remembered) color = MEMORY;
    if (hazard) color = hazard.level === 'hoch' ? HAZARD_HIGH : HAZARD_MID;

    const alpha = Math.min(1, track.lock) * (track.missed > 0 ? 0.55 : 1);

    this.#frame(rect, color, alpha, track.lock);
    if (isPerson || hazard) this.#boxScan(rect, color, alpha, time);
    if (hazard) this.#hazardRing(rect, color, time);
    if (settings.motionArrows) this.#motionArrow(rect, track, color, alpha);

    const percent = Math.round(track.score * 100);
    const title = remembered ? remembered.name.toUpperCase() : labelFor(track.label);
    const text = settings.showScores && !remembered ? `${title} ${percent}%` : title;

    const meta = [];
    if (hazard) meta.push(hazard.text.toUpperCase());
    else if (remembered?.note) meta.push(remembered.note.slice(0, 28).toUpperCase());
    else if (track.motion?.moving) meta.push(track.motion.direction.toUpperCase());
    if (settings.showTrackIds) meta.push(`ID ${String(track.id).padStart(3, '0')}`);

    this.#label(rect, text, meta.join(' · '), color, alpha);
  }

  /** Pfeil in Bewegungsrichtung, Länge nach Geschwindigkeit. */
  #motionArrow(rect, track, color, alpha) {
    const motion = track.motion;
    if (!motion?.moving) return;

    const ctx = this.ctx;
    const cx = rect.x + rect.w / 2;
    const cy = rect.y + rect.h / 2;
    const length = Math.min(58, 18 + motion.speed * 130);

    // Kommt etwas frontal näher, gibt es keine sinnvolle Richtung in der
    // Bildebene – dann wächst stattdessen ein Ring nach aussen.
    if (motion.direction === 'auf dich zu' || motion.direction === 'von dir weg') {
      const growing = motion.direction === 'auf dich zu';
      ctx.globalAlpha = alpha * 0.75;
      ctx.strokeStyle = color;
      ctx.lineWidth = 2;
      for (const step of [0, 1]) {
        const radius =
          Math.min(rect.w, rect.h) * (growing ? 0.24 + step * 0.13 : 0.5 - step * 0.13);
        ctx.beginPath();
        ctx.arc(cx, cy, radius, 0, Math.PI * 2);
        ctx.stroke();
      }
      ctx.globalAlpha = 1;
      return;
    }

    const dx = Math.cos(motion.angle) * length;
    const dy = Math.sin(motion.angle) * length;
    const tipX = cx + dx;
    const tipY = cy + dy;
    const head = 8;

    ctx.globalAlpha = alpha * 0.85;
    ctx.strokeStyle = color;
    ctx.fillStyle = color;
    ctx.lineWidth = 2.5;
    ctx.lineCap = 'round';

    ctx.beginPath();
    ctx.moveTo(cx, cy);
    ctx.lineTo(tipX, tipY);
    ctx.stroke();

    ctx.beginPath();
    ctx.moveTo(tipX, tipY);
    ctx.lineTo(
      tipX - Math.cos(motion.angle - 0.42) * head,
      tipY - Math.sin(motion.angle - 0.42) * head,
    );
    ctx.lineTo(
      tipX - Math.cos(motion.angle + 0.42) * head,
      tipY - Math.sin(motion.angle + 0.42) * head,
    );
    ctx.closePath();
    ctx.fill();
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

  /** Kästchen um erkannte Wörter. */
  #drawWords(words, layout) {
    const ctx = this.ctx;
    ctx.strokeStyle = TEXT_BOX;
    ctx.lineWidth = 1;

    for (const word of words) {
      const rect = this.#project(word.box, layout);
      if (rect.w < 4 || rect.h < 4) continue;
      ctx.globalAlpha = 0.25 + word.confidence * 0.45;
      ctx.strokeRect(rect.x, rect.y, rect.w, rect.h);

      // Übersetzte Wörter werden direkt über das Original gelegt.
      if (word.replacement) {
        ctx.globalAlpha = 0.9;
        ctx.fillStyle = 'rgba(4,12,16,0.9)';
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
  #label(rect, text, meta, color, alpha) {
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
    ctx.stroke();

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
      const centreX = track.display.x + track.display.w / 2;
      const offset = (centreX / (videoWidth || 1)) * 2 - 1;
      // Grosse Ziele gelten als nah und rücken zur Mitte.
      const nearness = Math.min(1, track.display.h / ((videoWidth || 1) * 0.55));
      const radius = mid * 0.92 * (1 - nearness * 0.72);
      const angle = -Math.PI / 2 + offset * 1.05;

      ctx.fillStyle = track.kind === 'face' ? '#ffb648' : '#2bf5dd';
      ctx.beginPath();
      ctx.arc(mid + Math.cos(angle) * radius, mid + Math.sin(angle) * radius, 2.6, 0, Math.PI * 2);
      ctx.fill();
    }
  }
}
