import { clamp } from './util.js';

/**
 * Eingabe fuer Touch und Tastatur.
 *
 * Der Joystick ist dynamisch: Er entsteht dort, wo der Finger die
 * Spielflaeche zuerst beruehrt (linke Bildschirmhaelfte), und folgt dem
 * Finger, wenn dieser ueber den Rand hinauszieht. Das fuehlt sich auf
 * unterschiedlich grossen Handys gleich an.
 */
export class Input {
  constructor(surface, { radius = 68, deadzone = 0.14 } = {}) {
    this.surface = surface;
    this.radius = radius;
    this.deadzone = deadzone;

    this.move = { x: 0, y: 0 };
    this.magnitude = 0;
    this.stick = { active: false, id: -1, baseX: 0, baseY: 0, x: 0, y: 0 };
    this.keys = new Set();
    this.enabled = true;

    this._onDown = this._onDown.bind(this);
    this._onMove = this._onMove.bind(this);
    this._onUp = this._onUp.bind(this);
    this._onKeyDown = this._onKeyDown.bind(this);
    this._onKeyUp = this._onKeyUp.bind(this);

    surface.addEventListener('pointerdown', this._onDown, { passive: false });
    window.addEventListener('pointermove', this._onMove, { passive: false });
    window.addEventListener('pointerup', this._onUp);
    window.addEventListener('pointercancel', this._onUp);
    window.addEventListener('keydown', this._onKeyDown);
    window.addEventListener('keyup', this._onKeyUp);
  }

  destroy() {
    this.surface.removeEventListener('pointerdown', this._onDown);
    window.removeEventListener('pointermove', this._onMove);
    window.removeEventListener('pointerup', this._onUp);
    window.removeEventListener('pointercancel', this._onUp);
    window.removeEventListener('keydown', this._onKeyDown);
    window.removeEventListener('keyup', this._onKeyUp);
  }

  reset() {
    this.stick.active = false;
    this.stick.id = -1;
    this.keys.clear();
    this.move.x = 0;
    this.move.y = 0;
    this.magnitude = 0;
  }

  _onDown(e) {
    if (!this.enabled || this.stick.active) return;
    if (e.target.closest && e.target.closest('[data-ui]')) return; // HUD-Tasten nicht kapern
    this.stick.active = true;
    this.stick.id = e.pointerId;
    this.stick.baseX = e.clientX;
    this.stick.baseY = e.clientY;
    this.stick.x = e.clientX;
    this.stick.y = e.clientY;
    e.preventDefault();
  }

  _onMove(e) {
    if (!this.stick.active || e.pointerId !== this.stick.id) return;
    this.stick.x = e.clientX;
    this.stick.y = e.clientY;

    let dx = this.stick.x - this.stick.baseX;
    let dy = this.stick.y - this.stick.baseY;
    const len = Math.hypot(dx, dy);
    if (len > this.radius) {
      // Basis nachziehen, damit der Stick nie "klebt".
      const overshoot = len - this.radius;
      this.stick.baseX += (dx / len) * overshoot;
      this.stick.baseY += (dy / len) * overshoot;
      dx = (dx / len) * this.radius;
      dy = (dy / len) * this.radius;
    }
    e.preventDefault();
  }

  _onUp(e) {
    if (e.pointerId !== this.stick.id) return;
    this.stick.active = false;
    this.stick.id = -1;
  }

  _onKeyDown(e) {
    if (e.repeat) return;
    this.keys.add(e.code);
  }

  _onKeyUp(e) {
    this.keys.delete(e.code);
  }

  /** Liefert die Bewegungsrichtung als normalisierten Vektor. */
  sample() {
    let x = 0;
    let y = 0;

    if (this.stick.active) {
      const dx = this.stick.x - this.stick.baseX;
      const dy = this.stick.y - this.stick.baseY;
      const len = Math.hypot(dx, dy);
      if (len > 0) {
        const norm = clamp(len / this.radius, 0, 1);
        if (norm > this.deadzone) {
          // Deadzone herausrechnen, damit langsame Bewegungen sauber starten.
          const scaled = (norm - this.deadzone) / (1 - this.deadzone);
          x = (dx / len) * scaled;
          y = (dy / len) * scaled;
        }
      }
    }

    const k = this.keys;
    let kx = 0;
    let ky = 0;
    if (k.has('KeyA') || k.has('ArrowLeft')) kx -= 1;
    if (k.has('KeyD') || k.has('ArrowRight')) kx += 1;
    if (k.has('KeyW') || k.has('ArrowUp')) ky -= 1;
    if (k.has('KeyS') || k.has('ArrowDown')) ky += 1;
    if (kx || ky) {
      const len = Math.hypot(kx, ky);
      x = kx / len;
      y = ky / len;
    }

    const mag = Math.hypot(x, y);
    this.move.x = x;
    this.move.y = y;
    this.magnitude = mag > 1 ? 1 : mag;
    return this.move;
  }

  /** Bildschirmposition des Joysticks fuer die Darstellung. */
  stickView() {
    if (!this.stick.active) return null;
    const dx = this.stick.x - this.stick.baseX;
    const dy = this.stick.y - this.stick.baseY;
    const len = Math.hypot(dx, dy);
    const factor = len > this.radius ? this.radius / len : 1;
    return {
      baseX: this.stick.baseX,
      baseY: this.stick.baseY,
      knobX: this.stick.baseX + dx * factor,
      knobY: this.stick.baseY + dy * factor,
      radius: this.radius,
    };
  }
}
