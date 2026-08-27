/**
 * Spielschleife mit fester Simulationsrate.
 *
 * Die Logik läuft in 1/60-Schritten, gerendert wird pro Frame. Dadurch
 * verhalten sich 60-Hz- und 120-Hz-Geräte identisch. Große Zeitspruenge
 * (Tab im Hintergrund) werden gekappt statt nachsimuliert.
 */
export class GameLoop {
  constructor({ update, render, step = 1 / 60, maxSteps = 5 }) {
    this.update = update;
    this.render = render;
    this.step = step;
    this.maxSteps = maxSteps;
    this.accumulator = 0;
    this.last = 0;
    this.running = false;
    this.paused = false;
    this.time = 0;
    this.fps = 0;
    this._frames = 0;
    this._fpsTime = 0;
    this._tick = this._tick.bind(this);
  }

  start() {
    if (this.running) return;
    this.running = true;
    this.last = performance.now();
    this.accumulator = 0;
    this._raf = requestAnimationFrame(this._tick);
  }

  stop() {
    this.running = false;
    if (this._raf) cancelAnimationFrame(this._raf);
  }

  setPaused(paused) {
    if (this.paused === paused) return;
    this.paused = paused;
    this.last = performance.now();
    this.accumulator = 0;
  }

  _tick(now) {
    if (!this.running) return;
    this._raf = requestAnimationFrame(this._tick);

    let delta = (now - this.last) / 1000;
    this.last = now;
    if (delta > 0.25) delta = 0.25;

    this._frames++;
    this._fpsTime += delta;
    if (this._fpsTime >= 0.5) {
      this.fps = Math.round(this._frames / this._fpsTime);
      this._frames = 0;
      this._fpsTime = 0;
    }

    if (!this.paused) {
      this.accumulator += delta;
      let steps = 0;
      while (this.accumulator >= this.step && steps < this.maxSteps) {
        this.time += this.step;
        this.update(this.step, this.time);
        this.accumulator -= this.step;
        steps++;
      }
      if (steps === this.maxSteps) this.accumulator = 0;
    }

    this.render(delta, this.time);
  }
}
