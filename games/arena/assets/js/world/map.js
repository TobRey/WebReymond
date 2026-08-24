import { Assets } from '../gfx/assets.js';
import { CollisionMask } from './collision.js';

/** Eine Welt: Hintergrundbild, Kollisionsmaske, Spawnpunkte. */
export class GameMap {
  constructor(def) {
    this.def = def;
    this.id = def.id;
    this.name = def.name;
    this.width = def.width;
    this.height = def.height;
    this.spawn = def.spawn || { x: def.width / 2, y: def.height / 2 };
    this.enemySpawnAreas = def.enemySpawnAreas || [];
    this.mask = new CollisionMask(def.collision, def.width, def.height);
    this.sprite = null;
  }

  async load() {
    // maxSide 0: Die Karte behält ihre volle Auflösung. Sie wird über die
    // ganze Welt gezogen, jede Verkleinerung wäre sofort sichtbar.
    this.sprite = await Assets.load(this.def.image, 0);
    return this;
  }

  /** Zeichnet nur den sichtbaren Kartenausschnitt. */
  draw(ctx, camera) {
    if (!this.sprite) return;
    const image = this.sprite.frameAt(0);
    const sx = Math.max(0, camera.left);
    const sy = Math.max(0, camera.top);
    const sw = Math.min(this.width - sx, camera.viewWidth + 4);
    const sh = Math.min(this.height - sy, camera.viewHeight + 4);
    if (sw <= 0 || sh <= 0) return;

    // Quelle in Bildpixeln (falls das Bild nicht exakt der Weltgroesse entspricht).
    const scaleX = this.sprite.width / this.width;
    const scaleY = this.sprite.height / this.height;
    ctx.drawImage(
      image,
      sx * scaleX, sy * scaleY, sw * scaleX, sh * scaleY,
      sx, sy, sw, sh,
    );
  }
}
