/**
 * Object Pool. Projektile, Partikel und Schadenszahlen entstehen und
 * verschwinden ständig - ohne Pool würde der Garbage Collector auf
 * Mobilgeräten regelmaessig ruckeln.
 */
export class Pool {
  constructor(factory, reset, initial = 0) {
    this.factory = factory;
    this.reset = reset;
    this.free = [];
    this.active = [];
    for (let i = 0; i < initial; i++) this.free.push(factory());
  }

  spawn(...args) {
    const obj = this.free.pop() || this.factory();
    this.reset(obj, ...args);
    obj.alive = true;
    this.active.push(obj);
    return obj;
  }

  /** Entfernt alle als tot markierten Objekte in einem Durchlauf. */
  sweep() {
    const active = this.active;
    let write = 0;
    for (let i = 0; i < active.length; i++) {
      const obj = active[i];
      if (obj.alive) {
        active[write++] = obj;
      } else {
        this.free.push(obj);
      }
    }
    active.length = write;
  }

  clear() {
    for (const obj of this.active) {
      obj.alive = false;
      this.free.push(obj);
    }
    this.active.length = 0;
  }

  get count() {
    return this.active.length;
  }
}
