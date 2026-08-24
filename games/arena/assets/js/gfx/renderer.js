import { Assets } from './assets.js';
import { TAU, clamp } from '../core/util.js';
import { DEATH_DURATION } from '../entities/enemy.js';

const silhouettes = new WeakMap();

/** Flammen auf brennenden Gegnern - identisch zu arena.js. */
const BURN_SPRITE = 'assets/sprites/feuer.gif';

/** Einmal gezeichneter Schattenkreis, danach nur noch skaliert kopiert. */
let shadowCanvas = null;
function shadowSprite() {
  if (shadowCanvas) return shadowCanvas;
  const size = 64;
  const canvas = document.createElement('canvas');
  canvas.width = size;
  canvas.height = size;
  const ctx = canvas.getContext('2d');
  ctx.globalAlpha = 0.28;
  ctx.fillStyle = '#000';
  ctx.beginPath();
  ctx.ellipse(size / 2, size / 2, size / 2, size / 2, 0, 0, TAU);
  ctx.fill();
  shadowCanvas = canvas;
  return shadowCanvas;
}

/** Weisse Silhouette eines Frames - für das Aufblitzen bei Treffern. */
function silhouette(frame) {
  let cached = silhouettes.get(frame);
  if (cached) return cached;
  const w = frame.width || frame.naturalWidth;
  const h = frame.height || frame.naturalHeight;
  if (!w || !h) return null;
  const canvas = document.createElement('canvas');
  canvas.width = w;
  canvas.height = h;
  const ctx = canvas.getContext('2d');
  ctx.drawImage(frame, 0, 0);
  ctx.globalCompositeOperation = 'source-in';
  ctx.fillStyle = '#fff';
  ctx.fillRect(0, 0, w, h);
  cached = canvas;
  silhouettes.set(frame, cached);
  return cached;
}

/**
 * Zeichnet die Welt. Alles läuft über genau einen Canvas-Kontext mit
 * abgeschalteter Glättung, damit die Pixel-Art scharf bleibt.
 */
export class Renderer {
  constructor(canvas, arena) {
    this.canvas = canvas;
    this.ctx = canvas.getContext('2d', { alpha: false });
    this.arena = arena;
    this.dpr = 1;
    this.zoom = 1;
    this.renderList = [];

    // Qualitätsstufe: 1 = volle Auflösung. Faellt die Bildrate, wird
    // weniger gerechnet - siehe adapt().
    this.quality = 1;
    this._qualityTimer = 0;
  }

  /**
   * Passt die Renderauflösung an die tatsächliche Leistung an.
   *
   * Der Flaschenhals ist nicht die Spiellogik (unter 3 ms pro Bild), sondern
   * die Menge an Pixeln. Auf einem schwachen Gerät wird deshalb etwas
   * gröber gezeichnet, sobald die Bildrate einbricht - und wieder feiner,
   * sobald Luft da ist. Pixel-Art verträgt das gut.
   *
   * @param fps    gemessene Bildrate
   * @param dt     Zeit seit dem letzten Bild
   */
  adapt(fps, dt) {
    if (!fps) return;
    this._qualityTimer += dt;
    if (this._qualityTimer < 2) return;
    this._qualityTimer = 0;

    const vorher = this.quality;
    if (fps < 45) this.quality = Math.max(0.55, this.quality - 0.15);
    else if (fps > 56 && this.quality < 1) this.quality = Math.min(1, this.quality + 0.1);
    if (this.quality !== vorher) this.resize();
  }

  resize() {
    const canvas = this.canvas;
    const rect = canvas.getBoundingClientRect();
    const cssW = Math.max(320, rect.width || window.innerWidth);
    const cssH = Math.max(240, rect.height || window.innerHeight);

    // Auf sehr hochaufloesenden Handys kostet DPR 3 spürbar Leistung.
    this.dpr = Math.min(window.devicePixelRatio || 1, 2) * this.quality;
    canvas.width = Math.round(cssW * this.dpr);
    canvas.height = Math.round(cssH * this.dpr);

    // Leicht hineingezoomt, aber immer genug Übersicht.
    this.zoom = clamp(Math.min(cssW, cssH) / 390, 1, 2.6);
    this.cssW = cssW;
    this.cssH = cssH;
    this.arena.camera.resize(cssW, cssH, this.zoom);
    this.ctx.imageSmoothingEnabled = false;
  }

  draw(time) {
    const { ctx, arena } = this;
    const camera = arena.camera;
    const scale = this.zoom * this.dpr;

    ctx.setTransform(1, 0, 0, 1, 0, 0);
    ctx.imageSmoothingEnabled = false;
    ctx.fillStyle = '#0a0c11';
    ctx.fillRect(0, 0, this.canvas.width, this.canvas.height);

    // Ganzzahliger Kameraversatz haelt die Pixel-Art ruhig.
    const offsetX = Math.round(-camera.left * scale);
    const offsetY = Math.round(-camera.top * scale);
    ctx.setTransform(scale, 0, 0, scale, offsetX, offsetY);

    arena.map.draw(ctx, camera);
    arena.bossCtrl.drawGround(ctx);
    arena.portals.draw(ctx, camera, time);
    arena.pickups.draw(ctx, time);
    this.drawEntities(time);
    this.drawBurning(time);
    arena.projectiles.draw(ctx, time);
    arena.melee.draw(ctx);
    arena.bossCtrl.draw(ctx, time);
    this.drawShockwaves(ctx, arena);
    arena.effects.drawParticles(ctx);
    arena.effects.drawAnimations(ctx);
    this.drawHealthbars();
    arena.effects.drawNumbers(ctx, this.zoom);
    if (arena.debug) this.drawDebug();

    ctx.setTransform(this.dpr, 0, 0, this.dpr, 0, 0);
    this.drawJoystick();
  }

  /** Entitäten nach Y sortiert, damit die Tiefenwirkung stimmt. */
  drawEntities(time) {
    const { ctx, arena } = this;
    const list = this.renderList;
    list.length = 0;

    for (const enemy of arena.enemies.list) {
      if (!enemy.alive) continue;
      if (!arena.camera.visible(enemy.x, enemy.y, enemy.scale)) continue;
      list.push(enemy);
    }
    const player = arena.player;
    list.push(player);
    list.sort((a, b) => a.y - b.y);

    for (const item of list) {
      if (item === player) this.drawPlayer(time);
      else this.drawEnemy(item, time);
    }
  }

  drawPlayer(time) {
    const { ctx, arena } = this;
    const player = arena.player;
    const sprite = player.currentSprite();
    if (!sprite) return;

    this.shadow(player.x, player.y + player.footOffset * 0.4, player.scale * 0.34, player.scale * 0.15);

    // Staubanimation nur während der Bewegung.
    const dust = arena.playerSprites.dust;
    if (dust && player.moving) {
      const frame = dust.frameAt(player.moveTime * 1000);
      const h = player.scale * 0.52;
      const w = (dust.width / dust.height) * h;
      ctx.globalAlpha = 0.75;
      ctx.drawImage(frame, player.x - w / 2, player.y + player.footOffset * 0.42 - h * 0.55, w, h);
      ctx.globalAlpha = 1;
    }

    // Beim Portalsprung zieht sich die Figur kurz zusammen und dehnt sich
    // am Ziel wieder aus - so sieht man den Wechsel, statt ihn nur zu merken.
    const sprung = player.teleport;
    // Jede Blickrichtung darf eine eigene Groesse haben.
    const eigen = player.spriteScale;
    const squash = (1 - player.squash * 0.12) * (1 - sprung * 0.55);
    const height = player.scale * eigen * squash;
    const width = (sprite.width / sprite.height) * player.scale * eigen * (1 - sprung * 0.35);
    const frame = sprite.frameAt(player.moving ? player.moveTime * 1000 : 0);
    const drawX = player.x - width / 2;
    const drawY = player.y + player.footOffset * 0.45 - height;

    ctx.save();
    if (player.invuln > 0 && Math.floor(player.invuln * 20) % 2 === 0) ctx.globalAlpha = 0.55;
    if (sprung > 0) ctx.globalAlpha = Math.max(0.15, 1 - sprung * 0.8);
    if (player.flip) {
      ctx.translate(player.x, 0);
      ctx.scale(-1, 1);
      ctx.drawImage(frame, -width / 2, drawY, width, height);
    } else {
      ctx.drawImage(frame, drawX, drawY, width, height);
    }
    ctx.restore();

    if (player.hitFlash > 0) {
      const sil = silhouette(frame);
      if (sil) {
        ctx.save();
        ctx.globalAlpha = player.hitFlash * 0.8;
        if (player.flip) {
          ctx.translate(player.x, 0);
          ctx.scale(-1, 1);
          ctx.drawImage(sil, -width / 2, drawY, width, height);
        } else {
          ctx.drawImage(sil, drawX, drawY, width, height);
        }
        ctx.restore();
      }
    }

    this.drawHeldWeapon(time);
  }

  /** Die Waffe liegt sichtbar in der Hand und zielt mit. */
  drawHeldWeapon(time) {
    const { ctx, arena } = this;
    const player = arena.player;
    const weapon = arena.run.weapon;
    const sprite = Assets.get(weapon.sprite);
    if (!sprite || arena.melee.list.length) return;

    const aim = arena.weapon.aim;
    const recoil = player.recoil * 8;
    // Größe kommt aus den Waffendaten und ist im Admin einstellbar.
    const length = weapon.spriteScale || 46;
    const h = (sprite.height / sprite.width) * length;
    // Abstand und Höhe sind im Admin einstellbar - sonst liegt die Waffe
    // je nach Sprite mal im Gesicht und mal am Fuß.
    const distance = (weapon.holdDistance ?? 20) - recoil;
    const offsetY = weapon.holdOffsetY ?? -6;

    ctx.save();
    ctx.translate(
      player.x + Math.cos(aim) * distance,
      player.y + player.footOffset * 0.1 + offsetY + Math.sin(aim) * distance * 0.6,
    );
    ctx.rotate(aim);
    if (Math.abs(aim) > Math.PI / 2) ctx.scale(1, -1); // nach links nicht auf dem Kopf
    ctx.drawImage(sprite.frameAt(0), -length * 0.3, -h / 2, length, h);
    ctx.restore();
  }

  drawEnemy(enemy, time) {
    const { ctx } = this;
    const sprite = enemy.sprite;
    if (!sprite) return;

    this.shadow(enemy.x, enemy.y + enemy.radius * 0.42, enemy.radius * 0.9, enemy.radius * 0.38);

    const spawnPop = enemy.spawnTime < 0.25 ? 0.65 + (enemy.spawnTime / 0.25) * 0.35 : 1;
    const height = enemy.scale * spawnPop;
    const width = (sprite.width / sprite.height) * height;
    const frame = sprite.frameAt(time * 1000 + enemy.animOffset);
    const drawY = enemy.y + enemy.radius * 0.45 - height;

    // Der häufigste Fall - nach rechts blickend, kein Treffer - kommt ohne
    // save/restore und ohne Zustandswechsel aus. Das ist bei achtzig
    // Gegnern pro Bild ein spürbarer Unterschied.
    if (!enemy.flip && enemy.hitFlash <= 0) {
      ctx.drawImage(frame, enemy.x - width / 2, drawY, width, height);
      return;
    }

    ctx.save();

    // Gefallene Gegner kippen zur Seite, sacken ab und blenden aus.
    if (enemy.dying) {
      const t = Math.min(1, enemy.deathTime / DEATH_DURATION);
      const kippen = enemy.deathTilt * (Math.PI / 2) * (enemy.flip ? -1 : 1);
      const boden = enemy.y + enemy.radius * 0.45;
      ctx.globalAlpha = t > 0.62 ? Math.max(0, 1 - (t - 0.62) / 0.38) : 1;
      ctx.translate(enemy.x, boden);
      ctx.rotate(kippen * 0.85);
      ctx.translate(-enemy.x, -boden + enemy.radius * 0.18 * enemy.deathTilt);
    }

    if (enemy.flip) {
      ctx.translate(enemy.x, 0);
      ctx.scale(-1, 1);
      ctx.drawImage(frame, -width / 2, drawY, width, height);
      if (enemy.hitFlash > 0) {
        const sil = silhouette(frame);
        if (sil) {
          ctx.globalAlpha = enemy.hitFlash * 0.85;
          ctx.drawImage(sil, -width / 2, drawY, width, height);
        }
      }
    } else {
      ctx.drawImage(frame, enemy.x - width / 2, drawY, width, height);
      const sil = silhouette(frame);
      if (sil) {
        ctx.globalAlpha = enemy.hitFlash * 0.85;
        ctx.drawImage(sil, enemy.x - width / 2, drawY, width, height);
      }
    }
    ctx.restore();
  }

  /**
   * Flammen auf allen brennenden Gegnern.
   *
   * Eigener Durchgang nach den Figuren: Im Gedränge stehen die Gegner
   * dicht beieinander, und ein Feuer an den Füßen würde sonst hinter dem
   * Nachbarn verschwinden. So sieht man immer, wen es erwischt hat.
   * In der letzten halben Sekunde blendet die Animation aus.
   */
  drawBurning(time) {
    const sprite = Assets.get(BURN_SPRITE);
    if (!sprite) return;
    const { ctx, arena } = this;
    const ratio = sprite.width / sprite.height;

    ctx.save();
    for (const enemy of arena.enemies.list) {
      if (!enemy.alive || enemy.dying || enemy.burnTime <= 0) continue;
      if (!arena.camera.visible(enemy.x, enemy.y, enemy.scale)) continue;

      const h = enemy.scale * 0.66;
      const w = ratio * h;
      const frame = sprite.frameAt(time * 1000 + enemy.burnPhase);
      ctx.globalAlpha = Math.min(1, enemy.burnTime * 2) * 0.9;
      ctx.drawImage(frame, enemy.x - w / 2, enemy.y + enemy.radius * 0.45 - h, w, h);
    }
    ctx.restore();
  }

  /**
   * Schatten als fertiges Bild statt gezeichneter Ellipse.
   *
   * Bei achtzig Gegnern kostete die Variante mit save/beginPath/ellipse/
   * fill/restore spürbar Bildrate. Ein einmal erzeugter Kreis, skaliert
   * gezeichnet, sieht identisch aus und ist ein einziger drawImage-Aufruf.
   */
  /** Die Druckwelle der Ultimate als aufziehender Ring. */
  drawShockwaves(ctx, arena) {
    for (const w of arena.shockwaves) {
      const t = Math.min(1, w.time / w.life);
      // "invers" zieht den Ring zusammen - so sieht ein Abflug anders aus
      // als eine Ankunft.
      const anteil = w.invers ? 1 - Math.pow(t, 0.7) : 0.15 + 0.85 * Math.pow(t, 0.55);
      const r = Math.max(2, w.radius * anteil);
      const farbe = w.color || '#bfe0ff';
      ctx.save();
      ctx.globalAlpha = (1 - t) * 0.85;
      ctx.strokeStyle = farbe;
      ctx.lineWidth = 10 * (1 - t) + 2;
      ctx.beginPath();
      ctx.arc(w.x, w.y, r, 0, TAU);
      ctx.stroke();

      ctx.globalAlpha = (1 - t) * 0.3;
      ctx.lineWidth = 22 * (1 - t) + 2;
      ctx.beginPath();
      ctx.arc(w.x, w.y, r * 0.82, 0, TAU);
      ctx.stroke();
      ctx.restore();
    }
  }

  shadow(x, y, rx, ry) {
    const sprite = shadowSprite();
    if (!sprite) return;
    this.ctx.drawImage(sprite, x - rx, y - ry, rx * 2, ry * 2);
  }

  /** Lebensleisten sitzen über dem Gegner und bleiben immer gleich groß. */
  drawHealthbars() {
    const { ctx, arena } = this;
    const px = 1 / this.zoom;
    const width = 34;
    const height = 4.5 * px + 1.5;

    for (const enemy of arena.enemies.list) {
      if (!enemy.alive || enemy.dying || enemy.health >= enemy.maxHealth) continue;
      if (enemy.boss) continue; // Boss hat die große Leiste im HUD
      if (!arena.camera.visible(enemy.x, enemy.y, enemy.scale)) continue;

      const ratio = clamp(enemy.health / enemy.maxHealth, 0, 1);
      const w = Math.min(width, enemy.radius * 2.4);
      const x = enemy.x - w / 2;
      const y = enemy.y + enemy.radius * 0.45 - enemy.scale - 8;

      ctx.fillStyle = 'rgba(6,8,12,0.72)';
      ctx.fillRect(x - 1, y - 1, w + 2, height + 2);
      ctx.fillStyle = ratio > 0.5 ? '#5ee08a' : ratio > 0.22 ? '#ffcf5c' : '#ff6b6b';
      ctx.fillRect(x, y, w * ratio, height);
    }
  }

  drawJoystick() {
    const view = this.arena.input.stickView();
    if (!view) return;
    const ctx = this.ctx;
    ctx.save();
    ctx.globalAlpha = 0.22;
    ctx.fillStyle = '#dfe7ff';
    ctx.beginPath();
    ctx.arc(view.baseX, view.baseY, view.radius, 0, TAU);
    ctx.fill();
    ctx.globalAlpha = 0.5;
    ctx.strokeStyle = '#dfe7ff';
    ctx.lineWidth = 2;
    ctx.beginPath();
    ctx.arc(view.baseX, view.baseY, view.radius, 0, TAU);
    ctx.stroke();
    ctx.globalAlpha = 0.85;
    ctx.fillStyle = '#7c6cff';
    ctx.beginPath();
    ctx.arc(view.knobX, view.knobY, view.radius * 0.42, 0, TAU);
    ctx.fill();
    ctx.restore();
  }

  drawDebug() {
    const { ctx, arena } = this;
    const camera = arena.camera;
    const mask = arena.map.mask;

    ctx.save();
    ctx.globalAlpha = 0.28;
    ctx.fillStyle = '#ff3b6b';
    const x0 = Math.max(0, (camera.left / mask.cellW) | 0);
    const x1 = Math.min(mask.cols - 1, ((camera.left + camera.viewWidth) / mask.cellW) | 0);
    const y0 = Math.max(0, (camera.top / mask.cellH) | 0);
    const y1 = Math.min(mask.rows - 1, ((camera.top + camera.viewHeight) / mask.cellH) | 0);
    for (let cy = y0; cy <= y1; cy++) {
      for (let cx = x0; cx <= x1; cx++) {
        if (mask.cellBlocked(cx, cy)) ctx.fillRect(cx * mask.cellW, cy * mask.cellH, mask.cellW, mask.cellH);
      }
    }
    ctx.restore();

    ctx.save();
    ctx.strokeStyle = '#5ee08a';
    ctx.lineWidth = 1.4;
    const p = arena.player;
    ctx.beginPath();
    ctx.ellipse(p.x, p.y, p.rx, p.ry, 0, 0, TAU);
    ctx.stroke();

    ctx.strokeStyle = '#ffcf5c';
    for (const e of arena.enemies.list) {
      if (!e.alive) continue;
      ctx.beginPath();
      ctx.arc(e.x, e.y + e.hitboxOy, e.radius, 0, TAU);
      ctx.stroke();
    }

    ctx.strokeStyle = '#7cc4ff';
    for (const proj of arena.projectiles.list) {
      ctx.beginPath();
      ctx.arc(proj.x, proj.y, 6, 0, TAU);
      ctx.stroke();
    }
    ctx.restore();
  }
}
