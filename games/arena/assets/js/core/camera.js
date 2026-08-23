import { clamp } from './util.js';

/**
 * Kamera mit weicher Verfolgung. Sie zeigt nie ueber den Kartenrand hinaus;
 * ist die Karte kleiner als der Bildschirm, wird sie mittig gezeigt.
 */
export class Camera {
  constructor() {
    this.x = 0;
    this.y = 0;
    this.zoom = 1;
    this.viewWidth = 0;
    this.viewHeight = 0;
    this.bounds = { w: 2048, h: 2048 };
    this.shake = 0;
    this.shakeX = 0;
    this.shakeY = 0;
    this.smooth = 7.5;
  }

  resize(width, height, zoom) {
    this.zoom = zoom;
    this.viewWidth = width / zoom;
    this.viewHeight = height / zoom;
  }

  setBounds(w, h) {
    this.bounds.w = w;
    this.bounds.h = h;
  }

  snapTo(x, y) {
    this.x = x;
    this.y = y;
    this._clamp();
  }

  follow(x, y, dt) {
    // Exponentielle Annaeherung - framerateunabhaengig.
    const t = 1 - Math.exp(-this.smooth * dt);
    this.x += (x - this.x) * t;
    this.y += (y - this.y) * t;
    this._clamp();

    if (this.shake > 0) {
      this.shake = Math.max(0, this.shake - dt * 3.2);
      const amount = this.shake * this.shake * 16;
      this.shakeX = (Math.random() * 2 - 1) * amount;
      this.shakeY = (Math.random() * 2 - 1) * amount;
    } else {
      this.shakeX = 0;
      this.shakeY = 0;
    }
  }

  addShake(amount) {
    this.shake = Math.min(1.6, this.shake + amount);
  }

  _clamp() {
    const halfW = this.viewWidth / 2;
    const halfH = this.viewHeight / 2;
    this.x = this.bounds.w <= this.viewWidth ? this.bounds.w / 2 : clamp(this.x, halfW, this.bounds.w - halfW);
    this.y = this.bounds.h <= this.viewHeight ? this.bounds.h / 2 : clamp(this.y, halfH, this.bounds.h - halfH);
  }

  get left() {
    return this.x - this.viewWidth / 2 + this.shakeX;
  }

  get top() {
    return this.y - this.viewHeight / 2 + this.shakeY;
  }

  /** Sichtbarkeitstest fuer Offscreen-Culling. */
  visible(x, y, margin = 80) {
    return (
      x > this.left - margin &&
      x < this.left + this.viewWidth + margin &&
      y > this.top - margin &&
      y < this.top + this.viewHeight + margin
    );
  }
}
